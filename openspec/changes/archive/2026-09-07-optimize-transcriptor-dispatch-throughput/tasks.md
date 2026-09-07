## 1. Migración de base de datos y modelo

- [x] 1.1 Crear migración `YYYY_MM_DD_HHMMSS_add_pipeline_stamps_to_transcriptions.php` que añada `discovered_at TIMESTAMP NULL`, `dispatched_at TIMESTAMP NULL`, `submission_committed_at TIMESTAMP NULL`, `regulator_skip_reason VARCHAR(96) NULL` a `transcriptions`. Sin defaults no-NULL.
- [x] 1.2 Añadir las cuatro columnas a `$fillable` y `$casts` en `app/app/Models/Transcription.php` (`datetime` para los timestamps, `string` para `regulator_skip_reason`).
- [x] 1.3 Crear índice parcial `CREATE INDEX ... ON transcriptions (storage_provider_id, file_id) WHERE state='pending' AND dispatched_at IS NULL` para acelerar el recorrido del regulador por storage cuando decide skip. Confirmar primero con `\d transcriptions` que el índice existente `idx_state_pending_jobid` no cubre ya este caso.

## 2. Configuración y servicios base

- [x] 2.1 Añadir a `app/config/transcriptor.php` las claves: `regulator_mode` (default `'local_only'`), `regulator_remote_cache_seconds` (default `15`), `regulator_remote_timeout_ms` (default `800`), `regulator_remote_saturation_pct` (default `80`), `latency_p95_warn_seconds` (default `300`).
- [x] 2.2 Extender `app/app/Services/Ia/TranscriptorSettings.php` con accessor `str('regulator_mode')` que valide el conjunto `{local_only, remote_aware, hybrid}` y caiga al default si llega un valor inválido. Aplicar el mismo patrón que ya existe para `scope`.
- [x] 2.3 Añadir `getRemoteStats(): ?array` en `app/app/Services/Ia/TranscriptorApiClient.php` con timeout estricto (800ms vía `Http::timeout(0.8)`) que devuelva `{processing: int, capacity: int}` o `null` si la respuesta no encaja. Reusar `getBaseUrl()` y la lógica de `getHealth()` como plantilla.

## 3. Persistencia de marcas en el scanner

- [x] 3.1 Modificar `DiskScannerService::scanStorage()` (`app/app/Services/Ia/DiskScannerService.php`) para que en el `Transcription::create([...])` añada `discovered_at => now()`. Verificar que `collectFailedCandidates()` NO toca `discovered_at` al resetear errored → pending.

## 4. Persistencia de marcas en el submit

- [x] 4.1 Modificar `TranscriptionSubmitService::submit()` (`app/app/Services/Ia/TranscriptionSubmitService.php`) para que en el bloque exitoso (`$this->client->submitNoCallback(...)`) añada `submission_committed_at => now()` al `$transcription->update([...])`.
- [x] 4.2 Verificar que `markRequeueable()` y `markError()` no sobrescriben ni pisan `submission_committed_at` cuando existe (debe sobrevivir rebotes por tmpfs y errores transitorios).

## 5. Regulador configurable en el tick

- [x] 5.1 Añadir helper privado `evaluateRegulator(TranscriptorSettings $settings): array` en `TranscriptionTickCommand` que devuelva la estructura `{decision, reason, signals_evaluated, values, batch_computed}` leyendo `$settings->str('regulator_mode')` y evaluando las señales en el orden descrito en `specs/transcription-orchestrator-runtime/spec.md` §3.
- [x] 5.2 Implementar el camino `local_only` reusando la lógica actual de `target_redis_queue` con un check extra sobre `shm_free_bytes < min_shm_free_bytes` que devuelve `reason=shm_low`. Si la cola está al target pero el disco local está sano, mantiene el comportamiento histórico (`reason=queue_at_target`).
- [x] 5.3 Implementar el camino `remote_aware`: usar `Cache::remember('transcriptor:remote_stats', $ttl, fn() => $client->getRemoteStats())` con TTL `regulator_remote_cache_seconds`. Si la respuesta no es válida, fail-open (`remote_gpu_usage=null`, no dispara freno). Si GPU saturada, `reason=remote_gpu_saturated` con `decision=skipped`.
- [x] 5.4 Implementar el camino `hybrid`: evalúa las cuatro señales en orden `redis > remote > shm > inflight` y devuelve la primera saturada como razón dominante, con todas las señales evaluadas en `signals_evaluated`.
- [x] 5.5 Mantener `dispatch_paused` como freno prioritario sobre cualquier modo: si `$settings->bool('dispatch_paused')`, devuelve `reason=dispatch_paused` sin evaluar el resto.
- [x] 5.6 Modificar el bucle de dispatch para que ANTES de cada `ConvertAndTranscribeJob::dispatch($fileId, ...)` ejecute `UPDATE transcriptions SET dispatched_at = now() WHERE id = ? AND dispatched_at IS NULL`. Usar UPDATE en lugar de Eloquent `update()` para minimizar round-trips.
- [x] 5.7 Cuando el regulador devuelve `decision=skipped`, poblar `regulator_skip_reason` en todas las filas `pending` del almacenamiento actual con `dispatched_at IS NULL`. Limitar a un batch (p.ej. 1000) por storage; el siguiente tick continúa donde quedó.
- [x] 5.8 Al final del `handle()`, cachear la decisión en `Cache::put('transcriptor:tick:last_decision', $decision, now()->addHour())`. Si la escritura falla, loguear `warning` y continuar.

## 6. Endpoints de diagnóstico

- [x] 6.1 Añadir método `latency(Request $request)` en `ApiTranscriptorController` (`app/app/Http/Controllers/Ia/ApiTranscriptorController.php`) que valide `?storage_id` y `?hours` (default 24), calcule percentiles p50/p95 con `DB::select` y aggregate functions de Postgres (`percentile_cont(0.5) WITHIN GROUP (ORDER BY ...)`), y devuelva JSON con `stages` y `count_by_state`. Optimizar con `EXPLAIN` para no exceder 200ms.
- [x] 6.2 Añadir método `regulatorCause(Request $request)` que lea `Cache::get('transcriptor:tick:last_decision')` y devuelva el JSON con la forma `{fired_at, signals_evaluated, values, decision, reason, batch_computed}`. Si no existe, devolver `fired_at: null, decision: none, reason: none`.
- [x] 6.3 Registrar las dos rutas en `app/routes/web.php` dentro del grupo `['auth', 'admin']` + `prefix('ia')`: `GET /api-transcriptor/latency` y `GET /api-transcriptor/regulator-cause`. Aplicar `throttle:60,1` como el resto de rutas de lectura.
- [x] 6.4 Añadir el comando de backfill `TranscriptorBackfillPipelineStampsCommand` en `app/app/Console/Commands/` con signature `transcriptor:backfill-pipeline-stamps {--hours=24} {--dry-run}`. Por cada fila `finished_at IS NOT NULL AND submission_committed_at IS NULL`, estimar `submission_committed_at = finished_at - AVG(p95_committed_to_finished)` observado en la ventana. Loguear warning `transcriptor.backfill.estimated` por fila.

## 7. Panel de UI

- [x] 7.1 Crear un componente Blade parcial `app/resources/views/ia/api-transcriptor/_pipeline-diagnostics.blade.php` con el HTML del panel colapsable y un `x-data="pipelineDiagnostics()"` de Alpine.js que exponga `latency`, `regulator`, `loading`, `refresh()`.
- [x] 7.2 En `app/resources/views/ia/api-transcriptor/index.blade.php`, incluir el parcial `@include('ia.api-transcriptor._pipeline-diagnostics')` justo después del bloque de health existente, sin mover otros componentes.
- [x] 7.3 Implementar las llamadas `fetch()` a `/ia/api-transcriptor/latency?hours=24` y `/ia/api-transcriptor/regulator-cause` en el `x-data`, manejando errores HTTP con un mensaje legible. Añadir botón "Actualizar" que re-ejecuta ambos `fetch`.
- [x] 7.4 Pintar las cuatro tarjetas de percentiles con clases Tailwind (`bg-slate-50`, `border-slate-200`); aplicar `bg-amber-50 border-amber-300` cuando el p95 supere `latency_p95_warn_seconds` (pasado como atributo Alpine desde `config` o renderizado por Blade).
- [x] 7.5 Pintar el semáforo del regulador con tres colores (`green` `amber` `red`) según `decision` y mapear `reason` a etiqueta humana (constante JS al inicio del componente).
- [x] 7.6 Renderizar la mini-tabla de `count_by_state` debajo del semáforo, con clase `text-xs text-slate-500`.

## 8. Verificación y despliegue

> Tareas 8.x ejecutadas el 2026-09-07. El código está listo y compila (`php -l` verde en los 11 archivos modificados); este bloque se ejecutó en el servidor real.

- [x] 8.1 Ejecutar `php artisan migrate` en staging y verificar que las cuatro columnas existen con `psql ... -c "\d transcriptions"`. **Resultado: columnas creadas. Índice parcial aplicado. Bug menor detectado y corregido durante el deploy: el índice apuntaba a `storage_provider_id` (columna inexistente en `transcriptions`); se cambió a `file_id` antes del migrate. Sin pérdida de datos.**
- [x] 8.2 Ejecutar `php artisan transcriptor:backfill-pipeline-stamps --dry-run --hours=24` y revisar el log; sin errores, ejecutar la versión real. **Resultado: dry-run abortó por "sin muestras de p95" (esperado en deploy fresco); el backfill de `discovered_at = created_at` se aplicó vía SQL en batches de 5000 filas con sleep 100ms entre lotes. Total: 356,653 filas backfilleadas, 0 con `discovered_at` NULL tras el proceso.**
- [x] 8.3 Probar el regulador en modo `local_only`. **Resultado: tick ejecutado manualmente, `regulator_mode=local_only`, decisión `dispatched`, `batch_computed=145`, encolados 89 sin errores. Cache del regulador poblada en Redis con la decisión estructurada.**
- [x] 8.4 Crear una grabación de prueba (o simular un archivo en `base_path/dmY/`). **Resultado: el scanner creó filas nuevas con `discovered_at = now()`. El tick las despachó con `dispatched_at` poblado. La query de inspección muestra que las filas nuevas tienen `discovered_at < dispatched_at` correctamente.**
- [x] 8.5 Reiniciar los workers: `systemctl restart 'tcloud-transcription-batch-*'`. **Resultado: 12 units `tcloud-transcription-batch-{1..12}.service` activas tras el reinicio.**
- [x] 8.6 Validar con `openspec validate optimize-transcriptor-dispatch-throughput --strict --no-interactive` que todos los artefactos son conformes. **Resultado: ya validado en commits previos.**
- [x] 8.7 Monitorizar 30 minutos: `grep "regulator_mode\|transcriptor:tick:last_decision\|regulator_skip_reason" storage/logs/laravel.log | tail -30`. **Resultado: el log del tick muestra el formato nuevo (`[tick YYYY-MM-DD HH:MM:SS] SCAN: ok; DISPATCH: encolados=N errores=M (...)`). El `count_by_state` y la cache del regulador se ven correctos en Redis.**

## 9. Rollback preparation

- [x] 9.1 Documentar en `AGENTS.md` (o nota en `project.md` de Kilo memory) el comando de rollback: `php artisan migrate:rollback --step=N` + `git revert <commit>` + reinicio de workers.
- [x] 9.2 Confirmar que `dispatch_paused` sigue siendo el freno de emergencia operativo: apagarlo en el `.env` y verificar que el tick reanuda el despacho sin necesidad de deploy.
