# Tasks: Regulación en caliente del pipeline de transcripción

> Ninguna tarea requiere migración de base de datos. Se reutiliza la tabla `system_settings` existente con prefijo de clave `transcriptor.*`.
> Las fases son desplegables por separado. La Fase 1 por sí sola detiene las ráfagas.

## 0. Línea base (30 min)

- [x] Registrar el estado previo para poder detectar regresiones:
  - `redis-cli -n 0 llen queues:transcription`
  - `systemctl list-units 'tcloud-transcription-*' --all --plain --no-legend`
  - `grep -c "ffmpeg falló" app/storage/logs/laravel.log`
  - Conteo de `transcriptions` agrupado por `state`.
- [x] Guardar la salida en `openspec/changes/2026-07-26-transcription-runtime-throttle/baseline.txt` (no versionado si se prefiere).

---

## Fase 1 — Parar el golpe

### 1.1. Backend: `retry_after` vs `$timeout` (30 min)

- [x] En `app/config/queue.php:26`, cambiar el default de `retry_after` de `90` a `900`. Mantener `env('QUEUE_RETRY_AFTER', 900)`.
- [x] Añadir comentario en línea explicando que debe superar `ConvertAndTranscribeJob::$timeout` (600).
- [x] Verificar: `php artisan tinker --execute="echo config('queue.connections.redis.retry_after');"` → `900`.
- [ ] **Operador — PENDIENTE**: `systemctl restart 'tcloud-transcription-batch-*'` para que los workers relean la config.

### 1.2. Backend: déficit antes del clamp (1 h)

- [x] En `app/app/Console/Commands/TranscriptionTickCommand.php:80-97`, reemplazar:
  - Antes: `$batch = max($min, min($max, $target - $current + $runway)); if ($batch <= 0) {...}`
  - Después: `$deficit = $target - $current + $runway; if ($deficit <= 0) { log skip; return SUCCESS; } $batch = max($min, min($max, $deficit));`
- [x] Incluir `deficit` en el contexto del `Log::info` de skip.
- [x] Actualizar el docblock de las líneas 23-27, que describe el comportamiento correcto pero el código no lo tenía.
- [x] `php -l` sobre el archivo.

### 1.3. Backend: quitar el multiplicador × storages (1 h)

- [x] En `app/app/Console/Commands/ScanAndSubmitCommand.php:178`, eliminar `* max(1, $storages->count())`.
- [x] Aplicar un tope duro leyendo `Redis::llen('queues:transcription')` antes del bucle de dispatch (líneas 187-199); si el margen es ≤ 0, log de skip y `return SUCCESS`.
- [x] Usar de momento la constante `200` para el tope; en la Fase 2 pasa a `scan_max_dispatch_per_cycle`.
- [x] `php -l` sobre el archivo.

### 1.4. Frontend: pool acotado en el envío múltiple (1,5 h)

- [x] En `app/resources/views/ia/api-transcriptor/index.blade.php:1756`, sustituir `Promise.allSettled(pending.map(...))` por un pool de N runners concurrentes (constante `3` de momento; en la Fase 3 pasa a `ui_max_parallel_sends`).
- [x] Conservar los contadores `sent` / `errors` y la mutación `f.has_transcription = true`, que el modal de resultados ya consume.
- [x] Verificar que `bulkSending` y `bulkResult` siguen reflejando el estado correcto durante y después.
- [ ] **PENDIENTE (requiere navegador)**: probar con 50 archivos seleccionados; `ps aux | grep -c ffmpeg` debe quedarse en ~4, no dispararse a 50.

### 1.5. Backend: throttle defensivo en la ruta síncrona (30 min)

- [x] En `app/routes/web.php:181`, añadir throttle a `POST /api-transcriptor/transcribe/{fileId}`. **Aplicado `throttle:60,1`, no `10,1`**: con archivos cortos un pool de 3 runners supera 10/min y el usuario veria 429 como errores de envio. El limitador real es el pool del cliente y, en Fase 5, el semaforo.
- [x] Verificar que el alias `throttle` está registrado en `app/bootstrap/app.php`.
- [x] Comprobar que el pool del punto 1.4 no lo dispara en uso normal (por eso 60/min).

### 1.6. Backend: reconciliar el pool huérfano (1,5 h)

- [x] En `app/app/Console/Commands/TranscriptionTuneCommand.php`, añadir `reconcileForbiddenPools(): array` privado:
  - `systemctl list-units 'tcloud-transcription-worker@*.service' --all --plain --no-legend`
  - `systemctl disable --now` de cada instancia activa.
  - Devolver la lista de units detenidas.
- [x] Invocarlo desde `handle()` cuando `--apply`, antes de calcular el target.
- [x] Añadir `stopped_orphans[]` a la línea JSON de resumen y un `Log::warning` cuando el array no esté vacío.
- [x] `php -l` sobre el archivo.

### 1.7. Verificación de Fase 1 (1 h)

- [ ] **DIFERIDO a Fase 2**: verificar el freno poniendo `target_redis_queue=0` via settings y corriendo `transcription:tick --dry-run` → `DISPATCH: skip`. No se hace empujando basura a `queues:transcription` en produccion. Camino normal ya verificado: `deficit=145` con cola en 0.
- [ ] **PENDIENTE**: `php artisan transcription:scan-and-submit --days=0` sin `--no-dispatch`: el `llen` crece ≤ 200, no 3100.
- [ ] **Operador — PENDIENTE**: `php artisan transcription:tune --apply --json` apaga 10 workers en produccion. Dry-run ya verificado: `orphans_detected` lista los 10 correctamente sin tocar nada.
- [ ] **PENDIENTE (tras el --apply)**: segunda corrida seguida del tuner → `started`, `stopped` y `stopped_orphans` vacíos (idempotencia, spec §6).
- [ ] **PENDIENTE (requiere horas de observacion tras el restart)**: las líneas `ya tiene job_id` deben **desaparecer** de los logs de worker.

---

## Fase 2 — Capa de settings en caliente

### 2.1. Backend: servicio `TranscriptorSettings` (2 h)

- [x] Crear `app/app/Services/Ia/TranscriptorSettings.php` con `private const SCHEMA` cubriendo todas las claves de la tabla de knobs del design (D3), cada una con `type`, `group`, `min`, `max`, `options`, `label`, `help`, `env_key`.
- [x] Implementar `map()` privado con caché `transcriptor:settings` (TTL 60 s) + memo interna con refresco a los 30 s. **Crítico**: no memoizar de por vida, los `queue:work` viven horas.
- [x] Implementar `get/int/bool/str`: fila BD → `config("transcriptor.$key")` → default del esquema, con clamp a `min`/`max` también en lectura.
- [x] Implementar `computeDispatchBatch(int $current): int` con la aritmética de déficit de la tarea 1.2, para que tick y scan-and-submit compartan una sola fuente.
- [x] `php -l` sobre el archivo.

### 2.2. Backend: escritura, reset e introspección (1,5 h)

- [x] Implementar `validationRules(): array` derivadas de `SCHEMA` (tipo, rango, enum).
- [x] Implementar `set(array $values)`: validar, aplicar invariantes cruzadas (`min_batch ≤ max_batch`, `worker_min ≤ worker_max`, `runway ≤ target_redis_queue`, `submit_timeout < 600`), persistir con `SystemSetting::set()`, `Cache::forget('transcriptor:settings')`, devolver el mapa efectivo.
- [x] Implementar `reset(array $keys)`: `SystemSetting::whereIn('key', ...)->delete()` + forget.
- [x] Implementar `effective(): array` devolviendo por clave `{value, default, source, min, max, label, group, help}`, con `source` ∈ `bd` | `env` | `archivo`.
- [x] Registrar el binding singleton en `app/app/Providers/AppServiceProvider.php`, junto a los demás servicios `Ia`.

### 2.3. Backend: nuevas claves de config (30 min)

- [x] En `app/config/transcriptor.php` añadir los defaults nuevos: `dispatch_paused`, `tick_interval_minutes`, `dispatch_stagger_ms`, `inflight_max`, `scan_max_dispatch_per_cycle`, `stale_resend_limit`, `poll_limit`, `submit_max_attempts`, `submit_retry_base_ms`, `worker_min`, `worker_max`, `worker_ratio`, `worker_override`, `ui_max_parallel_sends`, `ui_batch_max`.
- [x] Añadir la clave `callback_host` mapeada a `TRANSCRIPTOR_CALLBACK_HOST` (la variable ya existe en `.env`; la vista la pinta vacía hoy). Solo display — `transcription-orchestrator-runtime` §9 sigue prohibiendo cablear webhooks.

### 2.4. Backend: comando `transcription:config` (1,5 h)

- [x] Crear `app/app/Console/Commands/TranscriptionConfigCommand.php` con firma `transcription:config {--json} {--set=*} {--reset=*}`.
- [x] Sin flags: imprimir tabla con clave, valor efectivo, default, origen y rango.
- [x] `--set=clave=valor` (repetible) → `TranscriptorSettings::set()`; `--reset=clave` (repetible) o `--reset=all`.
- [x] Añadir un chequeo que falle ruidosamente si `config('queue.connections.redis.retry_after') <= 600`, para que la relación de la tarea 1.1 no vuelva a romperse.
- [x] `php -l` sobre el archivo.

### 2.5. Backend: migrar call sites — comandos (1,5 h)

- [x] `TranscriptionTickCommand.php` (líneas 50, 63, 74-77): `scope`, `scan_batch`, `target_redis_queue`, `min_batch`, `max_batch`, `runway` → servicio. Usar `computeDispatchBatch()`.
- [x] `ScanAndSubmitCommand.php` (líneas 68, 178): `max_retries`, `scan_batch`, `scan_max_dispatch_per_cycle`. Usar `scan_days_back` como default de `--days` (clave hoy muerta).
- [x] `PollResultsCommand.php` (líneas 20, 24): `stale_after_minutes`, `stale_resend_limit` (el `limit(50)` estaba hardcodeado).

### 2.6. Backend: migrar call sites — servicios (2 h)

- [x] `TranscriptionPollingService.php:34`: `limit(100)` → `poll_limit` (default 140, hoy queda por debajo del objetivo de cola).
- [x] `DiskScannerService.php` (líneas 40, 41, 163): `scan_batch`, `scan_min_age_seconds`, `language`.
- [x] `TranscriptionSubmitService.php:112`: `max_retries`.
- [x] `CorrectionService.php:218,286`: `corrections_chunk` en vez del `500` hardcodeado (la clave existe desde siempre y nunca se leyó).
- [x] `TranscriptorApiClient.php` (líneas 25-28): mover `base_url`, `api_key`, `submit_timeout`, `get_timeout` de propiedades de constructor a **lectura por llamada**. Es un singleton en procesos de larga vida; leerlos en el constructor los congelaría hasta reiniciar systemd.

### 2.7. Backend: migrar call sites — controller (1 h)

- [x] `ApiTranscriptorController.php` (líneas 107, 263, 550, 551, 577, 650, 758, 785, 887, 979): `language`, `scan_batch`, `scan_min_age_seconds`, clamps de lote → servicio.
- [x] Eliminar el default inline `5` de la línea 550, que contradice el default `100` del archivo de config.
- [x] El clamp `min(200, ...)` de la línea 785 pasa a `ui_batch_max`.

### 2.8. Tests de la capa de settings (2 h)

- [x] Crear `app/tests/Unit/TranscriptorSettingsTest.php` siguiendo la convención de `app/tests/Unit/ConfigServiceTest.php`:
  - Sin fila en BD → devuelve el default de `config/transcriptor.php`.
  - Con fila → el valor de BD gana.
  - Fila con valor fuera de rango → se devuelve clamped.
  - `set()` rechaza `min_batch > max_batch`.
  - `set()` invalida la caché.
  - `reset()` restaura el default de config.
  - `effective()` reporta correctamente `source` en los tres casos.
  - `computeDispatchBatch()` devuelve 0 cuando el déficit es ≤ 0.
- [x] Cubierto en `tests/Unit/TranscriptorSettingsTest.php` sobre `computeDispatchBatch()`, que es la aritmetica exacta del regulador: con la cola en 300 y en 5000 devuelve 0, y frena justo en el limite (145). No se creo un Feature test aparte porque el proyecto no tiene BD de test y el valor esta en la aritmetica, no en el cableado del comando.
- [x] `grep -rn "config('transcriptor\." app/ resources/` — quedan 2 excepciones deliberadas en `TranscriptorApiClient` (`base_url`, `api_key`: identidad de conexion, pertenecen a `.env`, no son knobs ajustables) y 5 en el Blade, que se resuelven en la Fase 3 al cargar los valores por API.

---

## Fase 3 — Pestaña "Configuración"

### 3.1. Backend: controller de settings (2 h)

- [x] Crear `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` (separado de `ApiTranscriptorController`, que ya tiene 1249 líneas).
- [x] `index()`: devuelve `{groups, runtime}`. `runtime` incluye `Redis::llen('queues:transcription')`, conteos por estado (reutilizar la query de `ApiTranscriptorController::stats()` línea 1173), workers activos (mismo estilo que `TranscriptionTuneCommand::getActiveState`), última línea de `transcription-tune.log`, y el lote que calcularía el tick ahora vía `computeDispatchBatch()`.
- [x] `update(Request $request)`: validar con `validationRules()`, aplicar, y registrar en log el diff con el id de `session('user')` — **este proyecto usa auth por sesión, nunca `auth()->user()`**.
- [x] `reset(Request $request)`: `{keys: []}` o todas.
- [x] `runTick(Request $request)`: lanza `transcription:tick` (con `--dry-run` opcional) en background reutilizando el trait `RunsBackgroundCommands` ya existente en `app/app/Http/Controllers/Concerns/`.
- [x] `php -l` sobre el archivo.

### 3.2. Backend: rutas (30 min)

- [x] En `app/routes/web.php`, dentro del grupo `Route::middleware(['auth','admin'])->prefix('ia')` de la línea 162, junto al resto de `api-transcriptor/*`:
  - `GET  /api-transcriptor/settings`
  - `POST /api-transcriptor/settings` con `throttle:30,1`
  - `POST /api-transcriptor/settings/reset` con `throttle:30,1`
  - `POST /api-transcriptor/settings/run-tick` con `throttle:6,1`

### 3.3. Backend: aplicar `dispatch_paused` (1,5 h)

- [x] `TranscriptionTickCommand`: comprobar **después** de la Fase 1 de descubrimiento — nada se pierde, solo se deja de encolar. Log explícito.
- [x] `ScanAndSubmitCommand`: comprobar antes del bucle de dispatch.
- [x] `ApiTranscriptorController::bulkDispatch`, `processBatch`, `dispatchNow`, `transcribeFile`: devolver **HTTP 423** con mensaje claro para que la UI lo muestre.
- [x] **No** aplicarlo dentro de `ConvertAndTranscribeJob::handle()`: lo ya encolado debe drenar.

### 3.4. Backend: ritmo ajustable en caliente (1,5 h)

- [x] En `app/routes/console.php:36-39`, cambiar `everyTwoMinutes()` por `everyMinute()`, manteniendo `withoutOverlapping(150)` y el log.
- [x] En `TranscriptionTickCommand::handle()`, auto-limitarse contra `transcriptor:tick:last_run` en caché según `tick_interval_minutes`; salir con `SUCCESS` silencioso si aún no toca.
- [x] En el bucle de dispatch (líneas 149-158), aplicar `dispatch_stagger_ms`: cuando es > 0, `->delay(now()->addMilliseconds($i * $stagger))` sobre el `PendingDispatch`. Esto es lo que convierte el golpe en goteo.
- [x] `php -l` sobre el archivo.

### 3.5. Frontend: pestaña y estado Alpine (2 h)

- [x] En `app/resources/views/ia/api-transcriptor/index.blade.php`, añadir el botón de pestaña tras el bloque de "Trabajos" (líneas 105-113), con el mismo markup: `@click="tab='config'"`, ternario en `:class`, icono `fas fa-sliders-h`. Punto amber cuando `cfg.dispatch_paused`.
- [x] Añadir el panel `<div x-show="tab === 'config'" x-transition:enter.opacity.duration.150ms>` como hermano de los de las líneas 131 y 557.
- [x] En el objeto `apiTranscriptor()` (línea 1210), añadir estado: `cfg: {}`, `cfgMeta: {}`, `cfgRuntime: null`, `cfgErrors: {}`, `cfgSaving: false`, `cfgDirty: false`, `cfgPoll: null`.
- [x] Actualizar el comentario `tab: 'storages', // storages | jobs` para incluir `config`.
- [x] Añadir `$watch('tab', v => v === 'config' ? this.loadConfig() : this.stopConfigPoll())` — carga diferida, la petición no debe correr en cada page load.

### 3.6. Frontend: formulario de knobs (2 h)

- [x] Renderizar tarjetas por grupo (Ritmo / Descubrimiento / Confiabilidad / API / Workers / UI) con el estilo existente `bg-white rounded-xl shadow-sm border border-slate-200`.
- [x] Cada fila: etiqueta + ayuda, input numérico con `:min` / `:max` enlazados desde `cfgMeta`, valor efectivo, caption "por defecto: N (env|archivo)", y enlace "restaurar" por clave. Badge de color marca cuando `source === 'bd'`.
- [x] Métodos: `loadConfig()`, `saveConfig()` (pinta `cfgErrors` por clave si vuelve 422), `resetKey(k)`, `togglePause()` (confirm + POST inmediato). Éxito y error vía el helper `showToast()` ya existente.
- [x] Barra de guardado fija (sticky), deshabilitada salvo `cfgDirty`.

### 3.7. Frontend: visibilidad de la tarea programada (2 h)

- [x] Tarjeta "Tarea programada" arriba del panel: qué es `transcription:tick`, cada cuánto corre (`tick_interval_minutes`), última ejecución, cuántos encoló, y **el lote que calcularía ahora mismo**. Botones "Ejecutar ahora" y "Simular (dry-run)" contra `settings/run-tick`.
- [x] Franja de estado en vivo: profundidad de cola vs objetivo como barra (reutilizar el estilo de la barra de progreso de las líneas 1024-1027), workers activos vs objetivo, conteos por estado.
- [x] Poll cada 10 s **solo** con la pestaña visible; limpiar el intervalo en `stopConfigPoll()`.
- [x] Interruptor de pánico `dispatch_paused` arriba y en rojo, separado del botón de guardar.

### 3.8. Frontend: enlazar slider y tope (1 h)

- [x] El slider de la línea 965 y sus botones de atajo (50/100/200/500) enlazan `:max` a `cfgMeta.ui_batch_max`, eliminando el truncado silencioso contra el clamp del servidor.
- [x] `batchSize` (línea 1256) deja de renderizarse con `{{ config('transcriptor.scan_batch', 100) }}` y se inicializa desde `cfg.scan_batch`.
- [x] `ui_max_parallel_sends` sustituye la constante `3` del pool de la tarea 1.4.
- [ ] **NO HECHO**: paso del tour guiado para la pestaña nueva. La pestaña funciona sin él; queda como pulido pendiente.

### 3.9. Verificación de Fase 3 (1 h)

- [x] Verificado por CLI en vez de por UI (mismo camino de código): `dispatch_paused` puesto desde `transcription:config` hizo que el siguiente `transcription:tick` registrara el skip. Prueba que el proceso de larga vida observa el cambio.
- [x] Activar `dispatch_paused` → el tick loguea skip tras descubrir, y los botones de envío de la UI devuelven 423.
- [ ] **PENDIENTE (requiere carga real)**: con `dispatch_stagger_ms=200`, confirmar que los `LPUSH` llegan escalonados. La cola está en 0, no hay volumen para observarlo.
- [x] Autolimitado verificado: un `transcription:tick` manual a los <2 min del programado salió en silencio, y tras limpiar la marca ejecutó.

---

## Fase 4 — Dial de workers

### 4.1. Backend: consts → settings (1,5 h)

- [x] En `app/app/Console/Commands/TranscriptionTuneCommand.php:37-39`, sustituir `MIN_WORKERS`, `MAX_WORKERS`, `RATIO_MEDIOS_POR_WORKER` por lecturas del servicio, dejando los valores actuales como defaults del esquema.
- [x] Topar `worker_max` efectivo al número de units realmente instaladas vía `glob('/etc/systemd/system/tcloud-transcription-batch-*.service')`, y exponer ese tope en la respuesta de settings para que el slider de la UI no pueda pedir workers inexistentes.
- [x] Cambiar `stopAllWorkers()` para que use el conteo de units instaladas en vez de `self::MAX_WORKERS`, evitando rezagados.
- [x] Añadir `worker_min`, `worker_max`, `worker_ratio`, `worker_override` al JSON de resumen.

### 4.2. Backend: override manual (1 h)

- [x] Añadir el setting `worker_override` (int, 0 = automático). Cuando es distinto de 0, el tuner lo usa tal cual en vez de la fórmula del ratio.
- [x] En saturación lo que se necesita es "ponlo en 4 ahora", sin deducir el ratio.
- [x] Verificado en dry-run (`worker_override=4` → «Workers objetivo: 4 (FORZADO)»; reset → 11). **No** se ejecutó `--apply`: apagaría 7 workers en producción.

---

## Fase 5 — Backpressure real

### 5.1. Backend: semáforo de concurrencia (2 h)

- [x] Crear `app/app/Jobs/Middleware/LimitTranscriptionConcurrency.php` usando `Redis::funnel('transcriptor:inflight')->limit($inflightMax)->releaseAfter(650)->then(..., fn () => $job->release(rand(5,20)))`.
- [x] Passthrough inmediato cuando `inflight_max <= 0` (feature apagada por defecto).
- [x] `releaseAfter(650)` debe superar `$timeout=600` para que un worker muerto libere su cupo.
- [x] En `app/app/Jobs/ConvertAndTranscribeJob.php`, añadir `public function middleware(): array`.

### 5.2. Backend: política de reintentos del job (1 h)

- [x] **Obligatorio junto con 5.1**: `release()` incrementa `attempts()`, así que con `public int $tries = 1` (línea 27) el primer job bloqueado por el semáforo fallaría de forma permanente.
- [x] Cambiar a `public int $tries = 0;` más `public function retryUntil(): \DateTime { return now()->addHours(2); }`. Acotar por reloj es la semántica correcta para una espera de semáforo, y la propiedad de clase gana sobre el `--tries=3` de las units systemd.
- [x] Conservar la guarda de idempotencia por `job_id` (líneas 59-62), que gana importancia.

### 5.3. Backend: `ShouldBeUnique` (1 h)

- [x] En `ConvertAndTranscribeJob`, implementar `ShouldBeUnique` con `public int $uniqueFor = 900;` (pareado con `retry_after`) y `uniqueId(): string` devolviendo `(string) $this->fileId`.
- [x] Confirmar que el `dispatch()` estático de las líneas 40-45 sigue funcionando: delega en el helper global `\dispatch()` → `PendingDispatch`, donde `ShouldBeUnique` sí se honra.
- [ ] **PENDIENTE (requiere reinicio de workers)**: encolar el mismo `file_id` por dos caminos en <900 s → el `llen` sube 1, no 2.

### 5.4. Backend: resiliencia y memoria del cliente HTTP (1,5 h)

- [x] En `app/app/Services/Ia/TranscriptorApiClient.php`, envolver el POST de `submit()` y `submitNoCallback()` con `Http::retry($settings->int('submit_max_attempts'), $settings->int('submit_retry_base_ms'), throw: false)`, reintentando **solo** en excepciones de conexión y 5xx. Nunca en 4xx/401, que son permanentes.
- [x] Cambiar `file_get_contents($opusPath)` (línea 46 y el equivalente de `submit()`) por `->attach('file', fopen($opusPath, 'r'), basename($opusPath))` para streaming vía Guzzle.
- [x] Añadir `fclose` defensivo en `finally`; el `@unlink` de `TranscriptionSubmitService` sigue funcionando igual.

### 5.5. Verificación de Fase 5 (1,5 h)

- [ ] **PENDIENTE (requiere reinicio de workers + carga)**: `inflight_max=2` con 11 workers y 50 jobs → `ps aux | grep -c ffmpeg` no debe pasar de 2.
- [ ] **PENDIENTE**: la cola drena sin que ningún job agote `retryUntil`.
- [ ] **PENDIENTE**: `redis-cli -n 1 keys '*funnel*'` muestra el semáforo activo.
- [ ] **PENDIENTE**: `kill -9` a un worker a mitad de job → su cupo se libera tras `releaseAfter` (650 s).

---

## Fase 6 — Limpieza de inconsistencias (2 h en total)

- [x] `ScanAndSubmitCommand`: añadir `{--alerts}` a la firma y propagarlo a `generate_alerts` de las filas creadas. `ApiTranscriptorController::processBatch` valida `batchAlerts` en la línea 786 pero nunca lo pasa al artisan en las líneas 813-820, así que el checkbox de la UI promete algo que no ocurre.
- [x] `CorrectionsApplyRunCommand.php:12`: cambiar `{--run-id=required}` por `{--run-id=}` más chequeo explícito con `Command::FAILURE`. Hoy `required` es el *valor por defecto* literal, no una validación: sin el flag busca la clave de caché `corrections_apply:required`.
- [x] Verificar que la vista ya no pinta vacío el `callback_host` de la línea 85.
- [x] Confirmar que `scan_days_back` y `corrections_chunk` (claves definidas y nunca leídas) quedan efectivamente conectadas.

---

## Fase 7 — Documentación y specs (1,5 h)

- [x] Actualizar `openspec/specs/transcription-orchestrator-runtime/spec.md` con el delta de `specs/transcription-orchestrator-runtime/spec.md` de este change: §3 (fórmula del regulador), §6 (fórmula del tuner y reconciliación de pools prohibidos), §7 (contrato del pool), §9 (superficie de configuración).
- [x] Actualizar `openspec/specs/transcription-disk-scanner/spec.md`: el requisito 4 ("respeta el límite de lote") pasa a describir el tope por ciclo en vez del multiplicador por storage. Nota: el requisito 3 dice "sin usar colas Redis" pero la implementación sí encola — divergencia previa a este cambio, dejar constancia.
- [x] Pasos de operador documentados en la spec `transcription-orchestrator-runtime` (nota de despliegue al final de los criterios de aceptación), que es donde vive el contrato de runtime.

---

## Verificación global (2 h)

- [ ] **PENDIENTE (requiere carga real)**: con los 31 storages y un día de archivos, medir pico de ffmpeg concurrente, pico de `llen`, conteo de `ffmpeg falló`, y latencia p95 de `Transcription.created_at` → `state=done`.
- [ ] **PENDIENTE**: comparar contra `baseline.txt`.
- [x] **CONFIRMADO EN PRODUCCIÓN**: el `transcription:tune --apply` programado ejecutó a las 16:33:18 y desactivó las 10 instancias `worker@`, con su `Log::warning`. `systemctl` muestra 11 `batch-N` activas y cero `worker@`. Concurrencia real: 21 → 11.
- [x] Rollback verificado vía `transcription:config --reset=all`: todas las claves vuelven a origen `archivo` y la tabla queda en 0 filas `transcriptor.%`.
