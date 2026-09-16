## 1. Preparación y validación de baseline

- [ ] 1.1 Confirmar que el upstream no esté en medio de un despacho: `SELECT state, COUNT(*) FROM transcriptions GROUP BY state;` (snapshot pre-cambio).
- [ ] 1.2 `git status` limpio en branch dedicado `simplify-api-transcriptor-to-storage-and-config`.
- [ ] 1.3 **Gate crítico — Cross-module contract**: confirmar que ningún código fuera del módulo importa los métodos a eliminar del controller. Ejecutar `grep -rE "ApiTranscriptorController::(scanStorage|processFolder|processDay|processBatch|storageFiles|batchStatus|transcribeFile|dispatchNow|refreshStatus|reprocess|cancelJob|destroy|retry|bulkDispatch|emptyFolders|stats|health|latency|regulatorCause|liveConsumption|transcript|transcribeProgress|jobStatus|estimateScan|syncStorage|show|unstick)" app/` y `grep -rE "use App\\\\Http\\\\Controllers\\\\Ia\\\\ApiTranscriptorController" app/ tests/` — ambos deben devolver **0 hits** fuera del propio controller. Si sale algo, BLOQUEAR hasta determinar si es cosmético o sustancial.
- [ ] 1.4 Confirmar que `_pipeline-diagnostics.blade.php` no se incluye desde otra vista: `grep -r "_pipeline-diagnostics" app/resources/views/` — debe devolver 1 hit en `index.blade.php`.
- [ ] 1.5 Confirmar que `TranscriptionConsumptionSnapshot` solo lo llama el schedule: `grep -rE "TranscriptionConsumptionSnapshot|transcription:consumption-snapshot" app/` — debe devolver 2 hits (clase + schedule).
- [ ] 1.6 Confirmar que `transcriptor:tick:last_decision` solo lo lee `regulatorCause`: `grep -rE "transcriptor:tick:last_decision" app/` — debe devolver solo referencias internas al controller eliminado.
- [ ] 1.7 **Gate crítico — Servicios preservados**: confirmar que los servicios que usan Correcciones y Avisos siguen siendo referenciados: `grep -rE "TranscriptionProcessor|TranscriptionCoherencePass|TranscriptionPollingService|TranscriptionSubmitService|TranscriptionReviewService|TranscriptorApiClient|TranscriptorSettings" app/app/Services/Ia/` — debe listar los 7 servicios. Cualquier cambio aquí BLOQUEA el change.
- [ ] 1.8 **Gate crítico — Modelos preservados**: confirmar que `Transcription`, `TranscriptionSegment`, `TranscriptionReview` siguen siendo importados desde otros módulos: `grep -rE "use App\\\\Models\\\\Transcription(Segment|Review)?" app/app/Http/Controllers/Ia/Correcciones* app/app/Http/Controllers/Ia/AvisosInteligentesController.php app/app/Services/Ia/` — debe listar imports en cada archivo.
- [ ] 1.9 Re-correr `harness_mis_avisos_viewer.php` ANTES del cambio como baseline: `cd app && php tests/harness_mis_avisos_viewer.php` → exit 0. Guardar el output.
- [ ] 1.10 Re-correr `verify_mis_avisos_flow.php` y `harness_avisos_scan_time_presets.php` como baseline. Outputs guardados.

## 2. Borrado de endpoints y rutas

- [ ] 2.1 En `app/routes/web.php` líneas 187-227: borrar las 24 rutas listadas en `proposal.md` (preservar `GET /ia/api-transcriptor`, `POST /ia/api-transcriptor/storages/{id}/toggle`, y los 4 endpoints de settings en líneas 230-233).
- [ ] 2.2 Verificar que `php artisan route:list | grep api-transcriptor` solo muestra las 6 rutas que sobreviven.

## 3. Reducción del controller

- [ ] 3.1 Borrar constantes `JOBS_PER_PAGE_DEFAULT`, `JOBS_PER_PAGE_MAX`, `JOBS_WINDOW_MAX`, `JOB_SCOPES` del controller.
- [ ] 3.2 Borrar el método `pausedResponse()` (sin consumidores tras quitar los endpoints de envío).
- [ ] 3.3 Borrar el método `parseFlexibleDate()` (era solo de `processFolder`, `processDay`, `estimateScan`).
- [ ] 3.4 Reescribir `indexData()`: solo construir `$storages` + `$funnelByStorage` + scope heredado; eliminar los bloques de jobs, filtros, paginación, ui_limits. Target ~120 líneas.
- [ ] 3.5 Borrar métodos: `show`, `retry`, `bulkDispatch`, `dispatchNow`, `refreshStatus`, `reprocess`, `cancelJob`, `destroy`, `storageFiles`, `scanStorage`, `processFolder`, `processDay`, `estimateScan`, `processBatch`, `batchStatus`, `transcribeFile`, `jobStatus`, `transcript`, `transcribeProgress`, `syncStorage`, `latency`, `regulatorCause`, `liveConsumption`, `emptyFolders`, `stats`, `health`, `shmStatus`.
- [ ] 3.6 En `toggleStorage()`: eliminar los `Cache::forget('transcriptor:stats:combined')`, `transcriptor:health:combined')`, `transcriptor:empty_folders:...` que ya no aplican (deja solo `forgetInheritedTranscriptionScope`).
- [ ] 3.7 Verificar `grep -nE "function (show|retry|bulkDispatch|dispatchNow|...)" ApiTranscriptorController.php` — solo debe quedar `__construct`, `index`, `indexData`, `toggleStorage`.

## 4. Reescritura de la vista

- [ ] 4.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`: borrar el banner de "carpetas sin archivos" (líneas 320-389) y los `template x-if` que lo envuelven.
- [ ] 4.2 Borrar las cards "Trabajos N" sobre el tab Storages (líneas 392-410) y el bloque `@include('ia.api-transcriptor._pipeline-diagnostics')` (línea 416).
- [ ] 4.3 Borrar el botón "Escanear storages" (líneas 301-308), "Salud API" (líneas 309-314 y línea 55), "Guía" (líneas 51-54) y el `data-tour` attributes huérfanos.
- [ ] 4.4 En la cabecera de tabs (líneas 268-315): dejar solo " los botones "Storages" y "Configuración" — borrar "Trabajos" y "Consumo".
- [ ] 4.5 Borrar el tab Trabajos completo (líneas 1065-1443) y todo el tab Consumo (líneas 1444-1556).
- [ ] 4.6 En el Alpine `x-data="apiTranscriptor(...)"`: borrar state y métodos `loadHealth`, `loadStats`, `loadEmptyFolders`, `loadJobs`, `openBatchModal`, `openProcessFolder`, `openProcessDay`, `openTranscribe`, `bulkDispatch`, `scanStorage`, `dispatchNow`, `runDispatch`, `bulkDispatchResult`, `bulkDispatching`, `selectJobMode`, `selectedJobs`, `jobsSubTab`, `jobsPendingCount`, `jobsCompletedCount`, `jobsFailedCount`, `jobsPagination`, `transcribeProgress`, `progressStatus`, `progressStep`, etc.
- [ ] 4.7 Borrar la función JS `startApiTranscriptorTour()` y todas las definiciones `apiTranscriptorTour` (líneas 4249+).
- [ ] 4.8 Verificar tamaño final del archivo: target ~600 líneas.

## 5. Borrado de archivos completos

- [ ] 5.1 `rm app/resources/views/ia/api-transcriptor/job-detail.blade.php`.
- [ ] 5.2 `rm app/resources/views/ia/api-transcriptor/_pipeline-diagnostics.blade.php`.
- [ ] 5.3 `rm app/app/Console/Commands/TranscriptionConsumptionSnapshot.php`.
- [ ] 5.4 Verificar que `StorageProvider::resolveInheritedTranscriptionScope` y los demás servicios siguen sin referencias huérfanas (`grep -r TranscriptionConsumptionSnapshot app/`).

## 6. Schedule y consola

- [ ] 6.1 En `app/routes/console.php` línea 156: borrar `Schedule::command('transcription:consumption-snapshot')->everyMinute()->withoutOverlapping(5);` y el comentario asociado.
- [ ] 6.2 Verificar que `php artisan schedule:list` ya no lista `transcription:consumption-snapshot`.

## 7. Test harnesses

- [ ] 7.1 Borrar `app/tests/harness_api_transcriptor_pending_perf.php` (cubría `/stats`, `/health`, `/empty-folders` que ya no existen).
- [ ] 7.2 Borrar `app/tests/harness_api_transcriptor_modal_scope.php` (cubría `openBatchModal` y `live-consumption`).
- [ ] 7.3 Mantener `app/tests/harness_api_transcriptor_index_perf.php` — verificar que sigue pasando (la cache `transcriptor.scope.inherited.*` no se ve afectada).
- [ ] 7.4 Re-correr el harness `harness_api_transcriptor_index_perf.php` desde `app/`: `cd app && php tests/harness_api_transcriptor_index_perf.php`. Exit 0 esperado.

## 8. Cache Redis cleanup

- [ ] 8.1 (Opcional) `redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor:*' | xargs redis-cli DEL` para limpiar las 5 cache keys huérfanas. NO obligatorio porque todas expiran en <2h.

## 9. Verificación final

- [ ] 9.1 `composer dump-autoload` desde `app/`.
- [ ] 9.2 `cd app && php artisan route:cache`.
- [ ] 9.3 `cd app && php artisan view:cache`.
- [ ] 9.4 `cd app && php artisan optimize:clear`.
- [ ] 9.5 Verificar en navegador que `GET /ia/api-transcriptor` carga solo con tabs Storages y Configuración, sin errores JS en consola.
- [ ] 9.6 Verificar que `POST /ia/api-transcriptor/storages/{id}/toggle` sigue funcionando (probar toggle ON→OFF→ON sobre un storage de prueba).
- [ ] 9.7 Verificar que `GET /ia/api-transcriptor/settings` renderiza con todos los settings actuales (`cfgMeta` poblado, `cfgRuntime` con queue_depth y next_batch).
- [ ] 9.8 Confirmar que `transcription:tick` sigue ejecutándose vía `php artisan schedule:run` y registra filas en log.
- [ ] 9.9 Confirmar que `transcription:poll-results` sigue ejecutándose y actualiza `last_polled_at` en filas `queued`.
- [ ] 9.10 Confirmar que ningún `ps aux | grep -E "TranscriptionConsumptionSnapshot"` queda corriendo.
- [ ] 9.11 Re-correr `tests/harness_dashboard_partials.php` para confirmar que la UI del dashboard sigue renderizando los partials auto-gated sin requerir consumo de `/stats` o `/live-consumption`.
- [ ] 9.12 **Regresión Mis Avisos (crítico)**: `cd app && php tests/harness_mis_avisos_viewer.php` → exit 0. Diff contra baseline guardado en 1.9 (debe coincidir).
- [ ] 9.13 **Regresión Mis Avisos flow**: `cd app && php tests/verify_mis_avisos_flow.php` → exit 0.
- [ ] 9.14 **Regresión Avisos Scan**: `cd app && php tests/harness_avisos_scan_time_presets.php` → exit 0.
- [ ] 9.15 **Smoke /ia/correcciones**: con sesión admin en el navegador, abrir `/ia/correcciones` → confirmar que las pestañas (Pendientes, Aprobadas, etc.) renderizan datos `Transcription` y `TranscriptionSegment` reales. Si el endpoint responde 5xx o la UI muestra "Sin datos" cuando hay transcripciones done, BLOQUEAR.
- [ ] 9.16 **Smoke /ia/avisos-inteligentes**: con sesión admin, abrir `/ia/avisos-inteligentes` → confirmar que muestra hits de keywords de transcripciones done. Si no, BLOQUEAR.
- [ ] 9.17 **Diff snapshot de filas modelo**: `SELECT COUNT(*) FROM transcriptions WHERE state='done'` y `SELECT COUNT(*) FROM transcription_segments` antes y después del deploy — los conteos deben ser idénticos o mayores (no menores; no se borra nada).

## 10. Cación del change

- [ ] 10.1 Commit con mensaje conventional: `feat(api-transcriptor): simplify to Storages + Config tabs only`.
- [ ] 10.2 Push al branch y abrir PR.
- [ ] 10.3 Tras merge: `openspec archive simplify-api-transcriptor-to-storage-and-config --yes`.
- [ ] 10.4 Verificar que el directorio `openspec/changes/simplify-api-transcriptor-to-storage-and-config/` desaparece de `openspec list`.
- [ ] 10.5 Update AGENTS.md: añadir nota corta "El módulo `/ia/api-transcriptor` solo expone Storages (toggle) y Configuración desde 2026-09-15; reintentos manuales de jobs vía `transcription:retry-batch-upstream` (CLI semanal) o `transcription:scan-and-submit` para forzar barridos."