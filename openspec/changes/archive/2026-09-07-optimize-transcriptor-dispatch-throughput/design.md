## Context

El regulador del tick actual (`TranscriptionTickCommand.php:101-131`) usa como única señal de freno `Redis::llen('queues:transcription') >= target_redis_queue (140)`. Esa señal mide cuánto trabajo tiene **nuestra** cola Redis, no cuánto trabajo tiene la API externa (192.168.0.138:9000). En producción, con 2 workers GPU sanos al 30-40% de uso, la cola local puede pasar de 140 igualmente porque el scheduler mete nuevas tareas en ráfagas; el tick frena aunque la GPU esté ociosa, generando drift artificial.

A esto se suma la falta total de telemetría por etapa: los timestamps disponibles en `transcriptions` son solo `created_at`, `started_at` y `finished_at`. `created_at` cubre el descubrimiento del scanner; `started_at` el envío a la API; `finished_at` la respuesta. Pero **no sabemos cuánto tardó la cola Redis entre encolar y worker tomándolo**, ni cuánto llevó el ffmpeg, ni cuánto llevó el POST. Sin esa información, calibrar el regulador es a ciegas.

El diseño añade cuatro marcas temporales, dos endpoints de diagnóstico y reconfigura el regulador para usar señales reales (GPU remota, tmpfs, inflight).

## Goals / Non-Goals

**Goals:**
- Cuatro columnas nuevas en `transcriptions` (`discovered_at`, `dispatched_at`, `submission_committed_at`, `regulator_skip_reason`) sin breaking change sobre columnas existentes.
- Dos endpoints JSON (`/latency`, `/regulator-cause`) que sirvan la telemetría en <200ms.
- Un panel nuevo en la UI `/ia/api-transcriptor` sin reemplazar los existentes.
- Tres modos de regulador (`local_only`, `remote_aware`, `hybrid`) seleccionables en caliente desde `settings`.
- Consulta a `/api/stats` con cache y timeout estricto para no castigar al nodo GPU.

**Non-goals:**
- No se modifica el algoritmo ASR ni `TranscriptorApiClient::submitNoCallback()`; solo se añade un método `getRemoteStats()` con la misma forma que `getHealth()`.
- No se reemplaza la cola Redis por otro broker.
- No se cambia el comportamiento de `markRequeueable()`, `markError()` ni `markDead()` — solo se asegura que las nuevas marcas se conservan en su ciclo de vida.
- No se reescribe `TranscriptionPollingService` ni su cadencia de 1 min.
- No se escala la flota GPU; la pregunta operativa queda en manos del operador con el panel informativo.

## Decisions

### Decision 1: Cuatro columnas nullable sin default no-NULL
Elegimos añadir `discovered_at`, `dispatched_at`, `submission_committed_at` y `regulator_skip_reason` (varchar 96) como nullable sin default `NOT NULL`. Razón: el 2026-08-18 ya había miles de filas en `transcriptions` sin estas marcas; hacerlas `NOT NULL` con `DEFAULT` falsearía el backfill porque el `now() - interval '48 hours'` truncado que propone la migración escribiría timestamps ficticios para filas viejas.

Alternativa considerada: índice parcial sobre filas con `dispatched_at IS NOT NULL AND finished_at IS NULL AND ...` para acelerar el regulador. Descartada porque el regulador actual itera por `state = 'pending' AND job_id IS NULL` que ya tiene `idx_transcriptions_state_pending_jobid` (a verificar).

### Decision 2: Cache de stats remotos con `Cache::remember()` y TTL configurable
Para la consulta `GET /api/stats` remota usamos `Cache::remember('transcriptor:remote_stats', $ttl, fn() => $client->getRemoteStats())`. El TTL `TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS` (default 15s) acota la carga sobre el nodo GPU a 4 req/min cuando el regulador corre cada 2 min; suficiente para mantener frescura sin agregar presión.

Alternativa: polling dedicado cada 15s con cron aparte. Descartada por aumentar el número de cron entries (regla §8 de `transcription-orchestrator-runtime`: una sola entrada OS cron) y por no aportar valor real: el regulador solo necesita saber la saturación al momento del dispatch.

### Decision 3: La decisión del regulador se persiste en cache, no en BD
La estructura `{fired_at, signals_evaluated, values, decision, reason, batch_computed}` se guarda en `Cache::put('transcriptor:tick:last_decision', ..., now()->addHour())`. Es metadata operacional, no dato analítico; rotarla每小时 por TTL es suficiente. Si el proceso Redis se reinicia, simplemente el endpoint devolverá "Sin datos hasta el próximo tick".

Alternativa: una tabla `transcriptor_tick_log` con cada tick. Descartada por crear una tabla muy verbosa para una sola lectura por minuto del endpoint; y porque los logs ya están en `laravel.log` con el mismo detalle.

### Decision 4: `regulator_skip_reason` se persiste en TODAS las filas pendientes del storage afectado
Cuando el tick decide `skipped`, recorre las `Transcription` pendientes del almacenamiento actual y actualiza `regulator_skip_reason` con la razón dominante. Esto da al admin visibilidad histórica de por qué una fila concreta lleva X tiempo sin despachar, sin tener que correlacionar logs.

Alternativa: solo guardar la causa del último tick en cache. Descartada porque la pregunta operativa real es "¿por qué ESTA grabación no se ha enviado?", no "¿por qué el último tick frenó?".

### Decision 5: El panel de diagnóstico es un componente colapsable nuevo, no una pestaña
Insertamos un `<details>` o un bloque Alpine `x-data` con `x-show` dentro de `app/resources/views/ia/api-transcriptor/index.blade.php`, junto a los paneles ya existentes. Esto evita romper los IDs y referencias que ya conoce el frontend (`#jobs-list`, `#scan-modal`, etc.), y mantiene el modal principal sin sobrecarga.

Alternativa: una pestaña "Diagnóstico" separada (`<a data-tab="diagnostico">`). Descartada porque introduce navegación adicional para una vista que necesita estar siempre a un click.

### Decision 6: `transcriptor:backfill-pipeline-stamps` con estimaciones, no exactitud
Para filas con `finished_at IS NOT NULL` pero sin `submission_committed_at`, el backfill aproxima `submission_committed_at = finished_at - AVG(p95_committed_to_finished)` observado en la ventana de las últimas 24h. Marcar la estimación en `laravel.log` con `transcriptor.backfill.estimated` para auditoría.

Alternativa: dejarlo `NULL` y que el panel indique "Estimado: no". Descartada porque rompe la distribución p50/p95 si un porcentaje significativo de filas queda sin dato.

## Risks / Trade-offs

- [Riesgo] Cuatro columnas nullable en una tabla con ~10M filas pueden crecer ~150MB en PG → [Mitigación] las cuatro son `timestamp without time zone` (8B) + `varchar(96)` (variable). Estimación: 16B × 10M = 160MB adicionales. Aceptable dado que la tabla ya está en GB; indexar selectivamente solo si las queries de diagnóstico se vuelven lentas.
- [Riesgo] El modo `remote_aware` depende de la forma JSON de `/api/stats`; si ese endpoint remoto cambia, el regulador se rompe silenciosamente → [Mitigación] `getRemoteStats()` valida la estructura esperada (`processing`, `capacity` o equivalente) y devuelve `unknown` + log `warn` si la respuesta no encaja. El modo `remote_aware` fall-open en ese caso (no dispara freno por GPU).
- [Riesgo] Cache de stats remotos puede quedar stale si la GPU se satura entre TTLs → [Mitigación] el TTL de 15s es corto; el peor caso es encolar 15s extra sobre una API saturada. Con `inflight_max` y `target_redis_queue` como respaldo siempre disponibles, el sistema no se desborda.
- [Riesgo] `Cache::put` puede fallar si Redis se llena o cae → [Mitigación] el tick NO falla por esto; solo loguea warning y sigue. El regulador sigue funcionando, solo que el endpoint no tendrá el `last_decision`.
- [Riesgo] El backfill escribe timestamps estimados que pueden aparecer en queries internas → [Mitigación] documentar en `transcription-pipeline-latency` que el campo `submission_committed_at` estimado se marca con un flag en logs (no en BD, para no añadir otra columna). Las queries de diagnóstico usan `WHERE submission_committed_at IS NOT NULL` que incluye las estimadas.
- [Riesgo] Panel de UI con dos `fetch` simultáneos puede ser lento en la primera carga → [Mitigación] los dos endpoints son agregados SQL sobre índice; presupuesto 200ms cada uno. Si en producción se acerca a 500ms, mover a una vista materializada refrescada cada minuto.
- [Trade-off] Persistir `regulator_skip_reason` por tick puede generar escrituras masivas cuando hay 10k+ pendientes en un storage → [Mitigación] usar `UPDATE ... FROM` con `WHERE storage_provider_id = ? AND state='pending' AND dispatched_at IS NULL LIMIT 1000` y continuar en batches; o aceptar una sola escritura bulk en el mismo ciclo.

## Migration Plan

### Pre-deploy
1. Hacer backup de la BD con el script existente en `/admin/postgres-admin` o `pg_dump -Fc`.
2. Verificar que NO hay un cron manual o systemd que invoque `transcription:tick` (regla §8).
3. Confirmar versión PHP-Laravel y que `Cache::put` soporta claves con TTL arbitrario (soportado desde Laravel 6).

### Deploy
1. **Migración DB**: añadir las cuatro columnas como nullable. Sin backfill automático en la migración; lo hace el comando aparte para no inflar el tiempo de la migración.
2. **Deploy código**:
   - `config/transcriptor.php`: añadir claves `regulator_mode`, `regulator_remote_cache_seconds`, `regulator_remote_timeout_ms`, `regulator_remote_saturation_pct`, `latency_p95_warn_seconds`.
   - `TranscriptorSettings.php`: añadir accessor `str('regulator_mode')` con default `'local_only'`.
   - `Transcription` model: añadir las cuatro columnas a `$fillable` y `$casts`.
   - `TranscriptorApiClient.php`: añadir `getRemoteStats(): ?array` con timeout estricto.
   - `DiskScannerService.php::scanStorage()`: poblar `discovered_at` al crear la fila.
   - `TranscriptionSubmitService.php::submit()`: poblar `submission_committed_at` en el `update` exitoso.
   - `TranscriptionTickCommand.php::handle()`:
     - Persistir `dispatched_at` justo antes de cada `dispatch()`.
     - Persistir `regulator_skip_reason` en todas las pendientes del storage cuando decide `skipped`.
     - Implementar el decision tree por `regulator_mode`.
     - Cachear `transcriptor:tick:last_decision`.
   - `ApiTranscriptorController.php`: añadir `latency()` y `regulatorCause()`.
   - `routes/web.php`: registrar las dos nuevas rutas bajo `['auth','admin']` + `prefix('ia')`.
   - `ia/api-transcriptor/index.blade.php`: añadir el panel colapsable con Alpine.
3. **Backfill**: ejecutar `php artisan transcriptor:backfill-pipeline-stamps` después del deploy. Puede tomar minutos si la tabla es grande; correr en horario de baja carga.
4. **Reiniciar workers**: `systemctl restart 'tcloud-transcription-batch-*'` (regla del orquestador-runtime §6).

### Post-deploy verification
1. `php artisan transcriptor:diagnose-pending` debe mostrar las mismas filas que antes, sin `discovered_at` ni `dispatched_at` poblado para filas pre-existentes.
2. `curl -H "Cookie: tcloud_session=..." http://localhost/ia/api-transcriptor/latency?hours=1` debe devolver JSON con `count_by_state` coherente.
3. `curl ... /regulator-cause` debe mostrar `fired_at` ≈ ahora (recién cacheado por el tick).
4. Verificar log: `grep "regulator_mode" storage/logs/laravel.log` para confirmar que el modo configurado es el que está activo.
5. Crear una grabación de prueba: debería aparecer con `discovered_at` poblado en <60s + 2min de tick.

### Rollback
- `php artisan migrate:rollback --step=N` (donde N es la migración añadida). Las cuatro columnas nullable se eliminan sin pérdida de datos existentes (las otras columnas quedan intactas).
- `git revert` del commit de la feature.
- Las claves de config nuevas caen al default (modo `local_only`) si se borran de `system_settings`; eliminar el `regulator_mode` y reiniciar workers restaura el comportamiento previo.
- `transcriptor:remote_stats` queda como basura en Redis hasta su TTL (15s); aceptable.

## Open Questions

- ¿El endpoint remoto `/api/stats` devuelve exactamente `processing` y `capacity`, o tiene otra forma? Resolver durante la implementación consultando el API real; el método `getRemoteStats()` debe validar y degradar a `null` si no encuentra las claves esperadas.
- ¿`max_batch` debe permanecer independiente de las señales nuevas, o pasar a depender de la capacidad remota estimada? Por ahora mantenemos `max_batch` como techo estático; se reevalúa si en producción se observa batching improductivo cuando `remote_capacity` es muy bajo.
- ¿El backfill debe cubrir también `dispatched_at` para filas que sabemos que pasaron por el tick (logs)? Por ahora la respuesta es no: solo `submission_committed_at` puede estimarse con precisión razonable. `dispatched_at` queda `NULL` para filas pre-existentes; el panel lo indica.
