## 1. Refactor del trait de lanzamiento

- [x] 1.1 En `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php`, cambiar la firma de `execBackground()` a `protected function execBackground(string $cmd, string $logTag = 'unknown', ?string $logFile = null, ?string $cacheKey = null): bool`
- [x] 1.2 Implementar normalización de `$cmd`: `preg_replace('/\s*&\s*$/', '', $cmd)`; loguear `runs_background.stripped_trailing_ampersand tag=X` si se recortó algo
- [x] 1.3 Implementar validación con `bash -n`: ejecutar un probe `setsid bash -n <wrapped> < /dev/null`; si trim() no es vacío, loguear `runs_background.invalid_syntax tag=X: <error>` y si `$cacheKey` no es null, escribir el cache con `status: error` y mensaje accionable; retornar `false`
- [x] 1.4 Construir el wrapper final: `setsid bash -c <escapeshellarg("echo ...start...; $cmd >> $logFile 2>&1; echo ...end... >> /tmp/kilo_artisan_bg.log 2>&1")> &`; ejecutar con `exec($shellCmd)`; retornar `true`
- [x] 1.5 Asegurar imports correctos: `use Illuminate\Support\Facades\Cache;` y `use Illuminate\Support\Facades\Log;` (recordatorio AGENTS.md)

## 2. Migración del controller de transcripción

- [x] 2.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`, en `processBatch()` (~líneas 1098-1111), eliminar la línea `$cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';`
- [x] 2.2 Llamar `$this->execBackground($cmd, 'transcriptor:scan', $logFile, $cacheKey)` con el logFile y cacheKey
- [x] 2.3 Capturar el retorno booleano: si es `false`, devolver `response()->json(['error' => 'Lanzador produjo bash inválido, revisá logs.'], 500)` y salir
- [x] 2.4 Confirmar que el logTag en `/tmp/kilo_artisan_bg.log` queda como `[transcriptor:scan]` (sin cambios, ya estaba así)

## 3. Migración de los otros dos callers (consistencia)

- [x] 3.1 En `app/app/Http/Controllers/Ia/CorreccionesController.php` (~línea 881), reemplazar `$this->execBackground($cmd, 'corrections:apply')` por `$this->execBackground($cmd, 'corrections:apply')` (firma idéntica, sin nuevos args — el caller ya respeta el contrato)
- [x] 3.2 En `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (~línea 414), mismo cambio cosmético (firma idéntica)
- [x] 3.3 Verificar que el `liveness ping` de ambos (espera 2s + check cache) sigue siendo defensa en profundidad y no se rompe

## 4. Tests

- [x] 4.1 Crear `app/tests/Feature/TranscriptorBatchLauncherTest.php` que ejercite `processBatch()` directamente con un batch pequeño (e.g. 5) y verifique: (a) el cache tiene `status: starting` justo después de la respuesta 200; (b) dentro de 5 segundos el cache transiciona a `running` o `queued`; (c) existe el archivo `storage/logs/transcription-batch-{runId}.log` con al menos una línea
- [x] 4.2 Crear `app/tests/Feature/RunsBackgroundCommandsValidationTest.php` que invoque `execBackground()` con un `$cmd` malformado (e.g. `$cmd = 'echo "a" ; bad &; echo b'`) y verifique que retorna `false`, loguea `runs_background.invalid_syntax`, y (si se le pasa cacheKey) escribe el cache con `status: error`
- [x] 4.3 Crear `app/tests/Feature/RunsBackgroundCommandsTrailingAmpersandTest.php` que pase un `$cmd` con `&` al final y verifique que el trait lo recorta y loguea `runs_background.stripped_trailing_ampersand`

## 5. Validación manual post-cambio

- [x] 5.1 Click en "Escanear storages" desde la UI con un storage habilitado → confirmar que el modal avanza de "starting" a "Procesando lote..." en ≤ 5s y termina con resultados reales (no `starting` colgado)
- [x] 5.2 `ls -la app/storage/logs/transcription-batch-{runId}.log` confirma que el log nuevo existe
- [x] 5.3 `tail /tmp/kilo_artisan_bg.log | grep transcriptor:scan` muestra los marcadores `start`/`end` para el run
- [x] 5.4 Verificar que `corrections:apply` y `avisos:scan-run` siguen funcionando (sus endpoints siguen retornando resultados, no se quedan colgados)

## 6. Deploy

- [x] 6.1 NO requiere migración de BD (verificar que `php artisan migrate:status` muestra todo aplicado)
- [x] 6.2 NO requiere reinicio de workers supervisord `tcloud-transcription-batch-*` (verificar con `systemctl status tcloud-transcription-batch-1.service`)
- [x] 6.3 Merge → reload de PHP-FPM (e.g. `nginx -s reload && systemctl reload php84-php-fpm` o equivalente) para que el trait actualizado se cargue en workers PHP-FPM
- [x] 6.4 Documentar el rollback en `AGENTS.md` (sección "Operaciones de fondo") con los 3 pasos: git revert + reload PHP-FPM (sin tocar workers supervisord)

## 7. Fix del watchdog race en `runBatch()` (encontrado durante validación)

El fix del bg-launcher (grupos 1-6) solucionó que el artisan NUNCA arrancara.
Pero apareció un SEGUNDO bug, detectado al iterar con Playwright: el endpoint
`/process-batch` responde consistentemente en ~5s, y el frontend tiene un
`Promise.race` con timeout de 5s que rechaza el fetch ANTES de que llegue la
respuesta real. El catch actual crea un `runId` sintético `timeout_xxx` y
empieza a pollear contra un cache key inexistente → 404 → modal pegado en
"starting" durante 2h. El bg-launcher funciona al nivel HTTP (curl prueba
200 + cache `queued` + log file), pero la UI no muestra progreso.

- [x] 7.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, en `runBatch()` (~líneas 2860-2919), reemplazar el bloque catch del watchdog-timeout: en vez de crear un `runId` sintético y hacer polling ciego, hacer `await fetchPromise` para esperar la respuesta real del server (con un fallback de timeout total más generoso, e.g. 20s) y usar el `run_id` real. Solo si el fetchPromise también falla después del fallback, mostrar el error original "sin respuesta del servidor"
- [x] 7.2 Eliminar el `setTimeout(..., 1500)` que dispara `startPolling(this.batchRunId)` con el ID sintético
- [x] 7.3 Asegurar que el modal NO muestre "Iniciando proceso en background..." durante más de 1 poll (2s) — si el server está lento, el texto debe cambiar a "Esperando respuesta del servidor..." o similar para distinguir "el server arrancó" de "todavía ni siquiera respondió"
- [x] 7.4 Mantener `batchRunning = true` mientras `fetchPromise` no haya resuelto, para que el modal siga mostrando la fase "running" en vez de cerrarse

## 8. Re-validación con Playwright

- [x] 8.1 Click "Escanear storages" + "Iniciar procesamiento" → verificar que `batchRunId` termina siendo un `batch_xxx` real (no `timeout_xxx`)
- [x] 8.2 Verificar que `batchProgress.status` transiciona `starting` → `running` o `queued` dentro del primer poll (2s después de que el fetch resuelva)
- [x] 8.3 Verificar que el modal termina mostrando resultados (processed/errors/total_candidates) cuando el batch finaliza, no queda colgado en "Procesando lote..."
- [x] 8.4 Screenshot del estado final del modal con los resultados reales
