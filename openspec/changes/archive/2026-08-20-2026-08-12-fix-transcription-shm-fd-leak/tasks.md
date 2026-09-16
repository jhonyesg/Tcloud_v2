# Tasks: Fuga de file descriptors a /dev/shm paraliza la transcripción

## 0. Mitigación operativa (CRÍTICO — antes de cualquier deploy)

- [x] **Confirmar el diagnóstico en el servidor actual** (2026-08-12 09:47 -05):
  - [x] `df -h /dev/shm` → 100% (40G usados / 40G total, 0 libres). ✓
  - [x] `find /proc/*/fd -lname "/dev/shm/tcloud-transcription/*" | wc -l`
    → **1499 fds** abiertos. ✓ (>>50, confirma fuga masiva)
  - [x] Cola Redis `tcloud_queues:transcription` = **155 jobs**.
- [x] **Pausar el dispatcher automático** sin matar workers:
  - [x] `redis-cli HSET transcriptor:settings dispatch_paused true` ✓
  - Verificado: `HGET ... dispatch_paused` → `true`.
- [x] **Liberar `/dev/shm`** (vía remount no destructivo):
  - [x] `sudo mount -o remount,size=40G /dev/shm` ✓
  - [x] Resultado: `Avail: 723M` (de 0 antes). Mejora parcial porque los fds
    huérfanos siguen reteniendo páginas en kernel; se requiere reinicio de los
    12 workers para liberación completa.
- [ ] **Reiniciar los 12 workers** (operación ADMINISTRATIVA, fuera de código):
  - Sin este paso los fds huérfanos siguen reteniendo tmpfs. El fix #1
    previene la recurrencia, pero para vaciar la basura actual hay que matar
    los procesos `queue:work` y dejarlos re-spawnear.
  - Comando tentativo (verificar supervisor/systemd del deployment):
    `systemctl restart tcloud-transcription-workers` o
    `supervisorctl restart tcloud-transcription:*`.
- [ ] **Validar end-to-end con UN solo job** antes de reabrir el grifo:
  - [ ] `dispatch_paused = false` (solo DESPUÉS del fix #1 desplegado).
  - [ ] `php app/artisan transcription:tick --dry-run` debe mostrar
    "encolaria N jobs" sin error.
  - [ ] Esperar 2 min al próximo tick y confirmar que la cola decrece
    (un job procesándose = `redis-cli ... ZCARD tcloud_queues:transcription:reserved`).
- [x] **Confirmación registrada**: el problema reaparece si NO se reinicia workers
  Y si NO se aplica fix #1 antes de reactivar dispatch. Procedemos al fix de
  raíz con `dispatch_paused=true` sostenido.

## 1. Fix del fd leak en `TranscriptorApiClient` (RAÍZ DEL PROBLEMA)

> **Requiere deploy antes de reactivar el tráfico** (ver Task 0).

- [x] Refactor `submitRequest()` en
  `app/app/Services/Ia/TranscriptorApiClient.php`:
  - [x] Eliminar `fopen($audioPath, 'r')` del builder.
  - [x] Cambiar la firma a `submitRequest(string $audioPath, $stream)` donde el
    caller pasa el resource ya abierto.
  - [x] Mover la lógica de retry/timeout/Content-Type al builder; el `attach` solo
    recibe `$stream`.
- [x] En `submitNoCallback()` y `submit()` (mismo archivo):
  - [x] Nuevo helper `openAudioStream()` que abre el archivo y valida `!== false`,
    lanza `RuntimeException` si falla.
  - [x] Caller abre el stream con `openAudioStream()` y lo pasa a `submitRequest`.
  - [x] Envuelto el `->post(...)` en `try { ... } finally { closeStream($stream); }`.
  - [x] Nuevo helper `closeStream()` que valida `is_resource()` antes de `fclose`
    (defensivo: Guzzle no debería cerrar pero si lo hace, no TypeError).
- [ ] Tests:
  - [ ] Test unitario: invocar `submitNoCallback` 100 veces con un wav real
    pequeño; tras cada llamada, contar `lsof -p $$ | grep $audioPath` debe ser 0.
  - [ ] Test de excepción: simular 5xx → retry → finalmente 200; verificar que el
    stream se cerró en el finally.
  - [ ] Test de `ConnectionException`: simular fallo de conexión en los 3
    intentos; verificar que el stream se cerró (no leak en throw: false).
- [x] `php -l app/app/Services/Ia/TranscriptorApiClient.php` → No syntax errors.

## 2. Pre-flight de espacio en `/dev/shm`

- [x] Nueva migración: añadir columna `requeue_after_at TIMESTAMP NULL` a
  `transcriptions`. Default `NULL`. Index
  `transcriptions_state_requeue_after_at_idx (state, requeue_after_at)`.
  - Archivo: `app/database/migrations/2026_08_12_120000_add_requeue_after_at_to_transcriptions_table.php`
- [x] Nuevas settings `min_shm_free_bytes` (default 200_000_000) y
  `requeue_after_minutes` (default 5) en `transcriptor:settings` (Redis hash).
  - Schema añadido en `app/app/Services/Ia/TranscriptorSettings.php`.
- [x] Modelo `Transcription`: añadido `requeue_after_at` a `$fillable` y `$casts`.
- [x] En `TranscriptionSubmitService::submit()` justo antes de `$this->converter->convert(...)`:
  - [x] Resolver `$tmpDir` (la lógica actual ya decide `/dev/shm` vs fallback).
  - [x] Leer `$free = @disk_free_space($tmpDir)`.
  - [x] Si `$free !== false && $free < $minShmFree`, llamar `markRequeueable(...)`
    con mensaje claro y `return ['ok' => false, 'requeueable' => true]`.
  - [x] Log de WARNING con contexto (free_bytes, threshold, file_id).
  - [x] Log de WARNING adicional cuando se cae al fallback `sys_get_temp_dir()`.
- [x] Nuevo método privado `markRequeueable(Transcription $t, string $msg)` que
  pone `state = error`, `error_message = $msg`, `finished_at = NULL`,
  `retries = retries + 1`, **`requeue_after_at = now()->addMinutes(N)`**.
  NO incrementa a `dead` aunque supere `max_retries`.
- [x] `TranscriptionTickCommand` modificado para excluir de la query de dispatch
  los jobs con `requeue_after_at IS NOT NULL AND requeue_after_at > NOW()`.
  Usa `where(fn => whereNull OR where <= now)` para mantener la query simple.
- [x] `php -l` sin errores en todos los archivos modificados.
- [ ] Tests:
  - [ ] Test con `disk_free_space` mockeado a 50 MB, umbral 200 MB → job NO se
    dispatcha (state=error, requeue_after_at futuro).
  - [ ] Test con `disk_free_space` mockeado a 500 MB → job procede normal.
  - [ ] Test de tick: con un job `requeue_after_at = NOW() + 10min` en BD, el
    tick NO lo dispatcha (excluido del query).

## 3. Cleanup de huérfanos en `/dev/shm/tcloud-transcription/`

- [x] Nuevo comando `app/app/Console/Commands/TranscriptionCleanupOrphanWavCommand.php`:
  - [x] Signature: `transcription:cleanup-orphan-wav
    {--max-age=30 : Edad mínima en minutos para considerar un WAV como huérfano}
    {--dry-run : Solo lista, no borra}`.
  - [x] Listar archivos en `/dev/shm/tcloud-transcription/` con `glob()`.
  - [x] Para cada archivo con `mtime > max-age min`:
    - [x] Verificar con `lsof +D` que NO aparece en la lista (sin fd abierto).
    - [x] Si `lsof` no está disponible, fallback: `fuser $path 2>/dev/null` y
      comprobar exit code.
    - [x] Si está limpio, `unlink($path)` y log info.
    - [x] Si tiene fd abierto, log warning con path.
  - [x] Reporte final: `Encontrados: X | Borrados: Y | Omitidos (fd abierto): Z
    | Omitidos (mtime < N min): W`.
  - [x] Validado manualmente con `--dry-run --max-age=1`:
    "Encontrados: 0 | Borrados [dry-run]: 0 | Omitidos (mtime < 1 min): 12".
    Los 12 son archivos recién creados por ffmpeg en curso.
- [x] Agendar en `app/routes/console.php`:
  `Schedule::command('transcription:cleanup-orphan-wav')->everyFifteenMinutes()->withoutOverlapping(60)`.
- [x] `php -l` sin errores.
- [ ] Tests:
  - [ ] Crear 3 wavs: uno viejo sin fd, uno viejo con fd (abrir y mantener), uno
    nuevo. Ejecutar comando. Solo el primero debe borrarse.
  - [ ] `--dry-run` no debe borrar nada.

## 4. Monitoreo: `transcription:check-shm-health` + endpoint

- [x] Nuevo comando
  `app/app/Console/Commands/TranscriptionCheckShmHealthCommand.php`:
  - [x] Signature: `transcription:check-shm-health`.
  - [x] Leer `disk_free_space('/dev/shm')` y `disk_total_space('/dev/shm')`.
  - [x] Calcular `percent = used / total * 100`.
  - [x] `Cache::put('transcriptor:shm:status', [...], 600)`.
  - [x] Si `percent >= $threshold` (default 80, configurable vía setting
    `shm_warn_percent`), `Log::warning(...)`.
  - [x] Validado manualmente: comando corre y reporta "/dev/shm: 99.9% usado
    (40926 MB / 40960 MB), dir_writable=yes, threshold=80%".
- [x] Nueva setting `shm_warn_percent` (default 80) en TranscriptorSettings.
- [x] Agendar en `routes/console.php` cada 10 min:
  `Schedule::command('transcription:check-shm-health')->everyTenMinutes()->withoutOverlapping(30)`.
- [x] Nuevo endpoint `GET /ia/api-transcriptor/shm-status` (solo admin) en
  `app/routes/web.php`:
  - [x] Controller method `shmStatus(TranscriptorSettings)` en
    `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`.
  - [x] Lee cache `transcriptor:shm:status`. Si no existe (cold start), calcula
    y popula en línea.
  - [x] Devuelve `{total, used, free, percent, dir_writable, threshold, status}`.
  - [x] Validado via `php artisan route:list --path=api-transcriptor | grep shm`:
    ruta registrada.
- [x] `php -l` sin errores en todos los archivos modificados.
- [ ] Tests:
  - [ ] Test del comando: `disk_free_space` mockeado al 85% → log warning
    emitido, cache poblada.
  - [ ] Test del endpoint: con cache poblada, devuelve los valores cacheados.
    Sin cache y mock al 90%, calcula y cachea en línea.

## 5. Verificación end-to-end post-deploy

### Validación ESTÁTICA (realizada antes de deploy)
- [x] `php -l` sin errores en:
  - `app/app/Services/Ia/TranscriptorApiClient.php` (Task 1)
  - `app/app/Services/Ia/TranscriptionSubmitService.php` (Task 2)
  - `app/app/Console/Commands/TranscriptionTickCommand.php` (Task 2)
  - `app/app/Models/Transcription.php` (Task 2)
  - `app/database/migrations/2026_08_12_120000_add_requeue_after_at_to_transcriptions_table.php` (Task 2)
  - `app/app/Services/Ia/TranscriptorSettings.php` (Tasks 2, 4)
  - `app/app/Console/Commands/TranscriptionCleanupOrphanWavCommand.php` (Task 3)
  - `app/app/Console/Commands/TranscriptionCheckShmHealthCommand.php` (Task 4)
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (Task 4)
  - `app/routes/console.php` (Tasks 3, 4)
  - `app/routes/web.php` (Task 4)
  - `app/resources/views/ia/api-transcriptor/index.blade.php` (Tour)
- [x] Comandos registrados en artisan:
  - `transcription:cleanup-orphan-wav`
  - `transcription:check-shm-health`
- [x] Ruta registrada: `GET ia/api-transcriptor/shm-status`
- [x] Scheduler activo:
  - `*/15 * * * * transcription:cleanup-orphan-wav`
  - `*/10 * * * * transcription:check-shm-health`
- [x] `transcription:check-shm-health` corre OK y reporta el estado real
  (99.9% en este momento, threshold 80%).
- [x] `transcription:cleanup-orphan-wav --dry-run --max-age=1` corre OK
  y reporta correctamente 12 archivos como "Omitidos (mtime < 1 min)".

### Validación RUNTIME (post-deploy, requiere admin)
- [ ] **Reiniciar los 12 workers** (operación ADMINISTRATIVA):
  - Necesario para liberar los ~1500 fds huérfanos acumulados antes del fix.
  - Comando: `systemctl restart tcloud-transcription-workers` o equivalente
    (supervisord, docker compose, etc.).
- [ ] **Confirmar que la fuga NO se reproduce**:
  - [ ] Aplicar la migración: `php app/artisan migrate`.
  - [ ] Poner `dispatch_paused = false` en Redis (HSET).
  - [ ] Procesar 100+ jobs.
  - [ ] Tras 30 min: `df -h /dev/shm` debe mostrar > 30 GB libres.
  - [ ] `find /proc/*/fd -lname "/dev/shm/tcloud-transcription/*" | wc -l`
    debe ser ≤ 12 (uno por worker activo, no acumulado).
  - [ ] `grep "Could not seek" app/storage/logs/worker-batch-*.log | wc -l`
    debe ser 0 en los últimos 30 min.
- [ ] **Confirmar que el pre-flight funciona**:
  - [ ] Llenar `/dev/shm` manualmente: `dd if=/dev/zero of=/dev/shm/big bs=1M count=35000`.
  - [ ] Dispatcar un job. Debe terminar con `state=error, requeue_after_at futuro`.
  - [ ] `dd if=/dev/shm/big of=/dev/null` (limpia).
  - [ ] Esperar 5 min → el tick debe reprocesarlo y completar.
- [ ] **Confirmar que el cleanup defensivo es inocuo**:
  - [ ] Crear un WAV manualmente en `/dev/shm/tcloud-transcription/` con mtime
    viejo, sin fd abierto.
  - [ ] `php artisan transcription:cleanup-orphan-wav --dry-run` debe listarlo.
  - [ ] Sin `--dry-run` debe borrarlo.
  - [ ] Crear otro WAV con `fopen` sostenido desde un proceso auxiliar.
    `cleanup-orphan-wav` debe omitirlo y loggear warning.
- [x] **Tour interactivo** (constraint `interactive_tours_must_include_new_features`):
  - [x] Paso nuevo "API y tmpfs — protección contra /dev/shm lleno" añadido
    en `startApiTranscriptorTour()` entre "Ritmo de envío" y "Cómo leer cada
    control". Selector: `[data-tour="cfg-group-api"]` (dinámico, generado por
    el bucle de grupos).

## 6. Documentación operativa (no en código)

- [x] Crear runbook en `app/docs/runbooks/transcription-stuck.md`:
  - [x] Síntomas: cola no drena, jobs fallan en <1s con "Invalid argument",
    `/dev/shm` 100%.
  - [x] Diagnóstico: 4 comandos de 30 s con valores esperados.
  - [x] Mitigación inmediata: pausa del dispatcher + remount o reinicio de
    workers + verificación.
  - [x] Validación post-fix: 4 checks estáticos + 3 checks runtime.
  - [x] Cuándo escalar: evidencia mínima, workaround temporal con
    `transcription:process-batch --limit=20`.
  - [x] "Cómo NO se reproduce": referencia a los 4 mecanismos de defensa.

## Estimación

| Task | Horas | Bloquea |
|---|---|---|
| 0. Mitigación operativa | 0.5 | — (se hace primero) |
| 1. Fix fd leak | 2 | El resto (sin esto, los demás son parches) |
| 2. Pre-flight espacio | 1.5 + migración | Depende de #1 |
| 3. Cleanup huérfanos | 1.5 | Depende de #1 (para no borrar archivos legítimos) |
| 4. Monitoreo + endpoint | 2 | Depende de #1 y #2 |
| 5. Verificación | 1.5 | Depende de todo |
| 6. Runbook | 0.5 | Depende de #5 |
| **Total** | **~10 h** | |
