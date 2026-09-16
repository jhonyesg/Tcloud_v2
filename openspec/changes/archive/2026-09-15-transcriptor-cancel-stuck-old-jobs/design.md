## Context

El operador ve jobs con `created_at` viejo (hasta 19 días) en el transcriptor upstream cuando consulta su cola. La causa raíz no son los crons automáticos — `transcription:tick` y `transcription:poll-results` filtran correctamente por `CarbonImmutable::today()` (queries en `TranscriptionTickCommand.php:147-159` y `PollResultsCommand.php:31-37`). El problema es **acumulación residual**: 3.092 filas locales tienen `job_id` válido en upstream, `state IN (queued, processing)`, `created_at < NOW() - 24 hours` y `started_at IS NULL`. El upstream nunca las terminó y nuestro poll-results no las re-envía (filtra `whereNull('job_id')`), así que quedan zombies en ambos lados.

Este change agrega un comando CLI manual (`transcription:cancel-stuck-old-jobs`) que limpia esa acumulación sin tocar archivos físicos, sin afectar el flujo normal de envío y sin automatizarse — la decisión de cancelar es del operador.

## Goals / Non-Goals

**Goals:**

- Comando CLI reproducible y trazable que cancela en upstream + marca `dead` local.
- Default `--dry-run`; `--apply` explícito para mutar.
- Sin afectar `transcription:tick`, `transcription:poll-results`, `transcription:retry-batch-upstream` ni el scheduler.
- Sin tocar archivos físicos (los 16.582 grabaciones legítimas quedan intactas).
- Bitácora append-only con `actor_user_id`, `job_id`, `tx_id`, `upstream_response_code`, `local_state_after`.
- Stagger + chunk + lock para no saturar un upstream que ya está al 200%.
- Guardarraíl de ratio candidatos/total para evitar borrado masivo accidental (mismo patrón que `transcription:purge-stale-pending`).

**Non-Goals:**

- No se borran archivos. No se mueven a papelera. No se despachan los 16.582 pendientes sin job_id.
- No se reintenta automáticamente la cancelación. El operador la lanza cuando quiere.
- No se programa en `routes/console.php`. Es acción consciente del operador, no auto-firing.
- No se modifica `TranscriptionPollingService::settle()`. La red `aged_out` con `started_at` set sigue su curso.
- No se rediseña el endpoint `/v1/jobs/{id}/cancel` del upstream. Se usa lo que ya expone `TranscriptorApiClient::cancelUpstream()` (línea 526 del archivo).
- No se cambia la UI del operador (`/ia/api-transcriptor`). El comando es 100% CLI.

## Decisions

### D1: Comando nuevo, no extiende `transcription:purge-stale-pending`

**Por qué:** Son responsabilidades distintas. `purge-stale-pending` solo toca filas en `pending` y mueve a `dead` localmente. Aquí necesitamos hablar HTTP con el upstream para cancelar el `job_id` correspondiente antes de marcar `dead`. Mezclar ambos casos en un mismo `--days=N` haría que el flag afecte dos universos con reglas distintas, y haría confuso el dry-run. Comando separado = contrato claro, errores claros, log claro.

**Alternativas consideradas:**

- *Extender `purge-stale-pending` con una bandera `--with-upstream-cancel`* → acopla dos universos, complica el dry-run (¿cuenta candidatos de los dos?), llena el comando de flags mutuamente excluyentes.
- *Hacer el cleanup dentro de `TranscriptionPollingService::settle()`* → agrega latencia HTTP al polling hot path (cada minuto). El polling debe ser rápido; la cancelación puede ser lenta.
- *UI button en `/ia/api-transcriptor`* → una acción destructiva no debería ser un click distraído. CLI con `--apply` explícito es más seguro.

### D2: Filtro por `created_at < cutoff` + margen `min-age-minutes`

**Por qué (revisado 2026-09-15):** validación inicial sobre la BD mostró que TODAS las 3.049 candidatas tienen `started_at NOT NULL`. El polling upstream marca `started_at` aunque el job esté stuck en `queued`/`processing` local. Por eso el filtro `started_at IS NULL` se removió del comando: era un requisito falso que dejaba 0 candidatos.

La antigüedad real de las candidatas viene de `created_at`, no de `started_at`. El filtro operativo queda:
- `state IN ('queued', 'processing') AND job_id IS NOT NULL AND created_at < (now() - interval 'N days') AND created_at < (now() - interval 'M minutes')`
- donde N = `--days` y M = `--min-age-minutes`.

El margen `--min-age-minutes=60` protege contra cancelar jobs recién enviados que aún no llegaron al upstream (latencia del POST inicial). Suficiente porque el tick y poll-results tienen su propia lógica anti-duplicación; este comando solo ataca la cola histórica.

**Alternativas consideradas:**

- *Filtrar por `started_at < cutoff` en vez de `created_at < cutoff`* → 0 candidatos en la realidad actual (todos los `started_at` son recientes). No protege nada.
- *Hacer un GET `/v1/jobs/{id}` previo por cada candidato para confirmar estado* → 3.049 GETs bloqueantes antes de tocar nada; demasiado caro para un comando de cleanup. Además, el upstream ya da la señal útil cuando le pedimos `cancel`: devuelve 404 (no existe), 409 (no queued), 200 (cancelled).
- *Cancelar en bloque vía `/v1/jobs/cancel-batch` (no existe)* → el upstream solo expone cancel unitario. Endpoint `/retry-batch` existe pero es para fallidos, no queued.

### D3: Stagger 750 ms + chunks de 100 — anti-hammer al upstream

**Por qué:** El upstream ya está al 200% de capacidad (6 jobs processing con 3 workers). Cancelar 3.092 jobs en serie sin pausa lo tiraría aún más. El stagger 750 ms (entre chunks de 100) significa ~300 cancelaciones por minuto hacia arriba. Lo justo para limpiar sin provocar tormenta.

**Alternativas consideradas:**

- *Sin stagger, todas en paralelo* → amplifica el problema que estamos atacando.
- *Stagger 2000 ms* → el comando tardaría ~50 min en limpiar todo. El operador probablemente necesita arrancar y ver progreso, no esperar una hora.
- *Stagger por job (750 ms × 3092 = ~40 min)* → innecesariamente conservador; el upstream maneja 100 cancels/segundo en condiciones normales.

### D4: Lock `Cache::lock('transcription:cancel-stuck-old-jobs', 600)`

**Por qué:** Si el operador (o un script) invoca dos veces el comando en paralelo, podrían competir por los mismos job_ids y el upstream recibiría cancelaciones duplicadas. El lock con TTL 600 s sigue el mismo patrón que `purge-stale-pending` (consistente, predecible, auto-expira).

### D5: Audit log en tabla nueva `transcriptor_cancel_audit`

**Por qué:** Cada cancelación deja huella de quién la pidió, cuándo, qué respondió el upstream y qué pasó localmente. El comando es destructivo (afecta estado de jobs visibles desde la UI) y necesita trazabilidad post-mortem. La tabla es append-only (sin UPDATE, sin DELETE por la app) igual que `watermark_audit_log`. Una sola entrada por job cancelado. Sin FK para no acoplar el audit al ciclo de vida de `transcriptions`.

**Alternativas consideradas:**

- *Reusar `audit_logs` (tabla genérica)* → esa tabla la llena `audit.admin.action` desde middleware, usada para acciones admin UI. Mezclar ahí dos universos (UI admin vs CLI manual) haría el log más difícil de filtrar.
- *Loggear solo a `laravel.log`* → difícil de consultar (`grep` por job_id) y se rota. Pierde historia más allá de 30 días.
- *Sin audit* → infactible. El operador necesita poder responder "¿quién canceló el job X?".

### D6: Solo CLI manual, no scheduler

**Por qué:** El operador decidió no auto-ejecutar (decisión 2026-09-15, pregunta "¿Ahora o change proposal?"). Además, ejecutar automáticamente algo destructivo requeriría validación de flags + prompts de confirmación, lo que no tiene sentido en un cron. La acumulación residual es histórica (3.092 filas); en el futuro, si la mantenibilidad depende de auto-cleanup, se programa después de validar que el comando es estable en producción.

### D7: Los archivos físicos NO se tocan en este change

**Por qué:** Los 16.582 archivos en BD son grabaciones de emisoras (14.275 mp4 + 2.307 mpeg, ~80-95/hora por 2 semanas). Borrarlos perdería contenido valioso. Marcar las filas como `dead` sin borrar los archivos los deja en BD como referencia futura (si el operador re-graba o re-envía manualmente), pero NO los borra de disco.

**Para borrarlos en el futuro:** module papelera con 15 días (AGENTS.md `papelera_reciclaje_soft_delete_pattern`). Cambio separado.

## Risks / Trade-offs

- **[Riesgo] Cancelar un job que el upstream realmente está procesando** — el upstream puede tardar minutos en tomar un job, y nuestro `started_at` queda null hasta el webhook/poll. → Mitigación: `--min-age-minutes=60` default. Si el operador quiere ser más agresivo, baja `--min-age-minutes`, pero tiene que entender el riesgo. Documentado en `--help`. Adicionalmente, `--days=7` por defecto protege contra cancelar jobs enviados esta semana.

- **[Riesgo] Saturar más el upstream durante la cancelación** (está al 200%). → Mitigación: stagger 750 ms + chunk 100; lock 600 s; `--dry-run` antes de aplicar.

- **[Riesgo] El upstream cambia la forma de respuesta 409 (no queued)** — hoy `cancelUpstream` lanza `RuntimeException`, lo capturamos y seguimos. → Mitigación: este código está en `TranscriptorApiClient.php:535-540`. Si el upstream evoluciona, ahí es donde se ajusta.

- **[Riesgo] El operador cancela por accidente sin haber visto el dry-run** → Mitigación: `--dry-run` es default. `--apply` requiere flag explícita. La consola imprime conteo + cutoff antes de cancelar.

- **[Riesgo] Comando queda colgado** (NetworkIO uninterrumpible con NFS/caída remota). → Mitigación: el `Http::timeout(getTimeout())` del cliente ya pone tope. Si se cuelga, el proceso php-fpm muere al `max_execution_time` y el lock expira a los 600 s. La siguiente corrida retoma desde donde se quedó.

- **[Trade-off] Hasta 200 ms de latencia por cada `cancelUpstream`** (HTTP al upstream). Con 3.092 jobs y stagger 750 ms sobre chunks de 100, la corrida completa toma ~30 min. Es aceptable para una operación manual; si se vuelve rutinaria, programar en scheduler con `withoutOverlapping`.

- **[Trade-off] No se eliminan los jobs cancelados del upstream** — quedan en estado `cancelled` ocupando espacio. → Documentado: usar `TranscriptorApiClient::deleteUpstream()` (ya existe, línea 576) en una pasada posterior. Decisión consciente: cancelar primero, evaluar, borrar después.

## Migration Plan

1. **Migración nueva**: `2026_09_15_120000_create_transcriptor_cancel_audit_table.php`. Tabla `transcriptor_cancel_audit` con columnas `id (PK)`, `actor_user_id (nullable int)`, `job_id (varchar(64))`, `tx_id (int nullable)`, `upstream_response_code (smallint)`, `local_state_after (varchar(16))`, `error_message (text nullable)`, `created_at (timestamp)`. Índices: `(job_id)`, `(created_at)`. Sin FKs.

2. **Servicio nuevo**: `app/app/Services/Ia/TranscriptorCancelAudit.php`. Métodos:
   - `record(?int $actorUserId, string $jobId, ?int $txId, int $upstreamCode, string $localState, ?string $errorMessage = null): void`
   - `recent(int $limit = 50): Collection` (helper para debugging).

3. **Comando nuevo**: `app/app/Console/Commands/TranscriptionCancelStuckOldJobsCommand.php`. Estructura calcada de `TranscriptionPurgeStalePendingCommand`:
   - `handle()`: lee flags, intenta `Cache::lock`, evalúa guardarraíl, dry-run vs apply.
   - `cancelOne(Transcription $tx, TranscriptorApiClient $client, TranscriptorCancelAudit $audit, ?int $actorUserId): array` — encapsula la lógica HTTP + DB update + audit.
   - `describeCandidates(Carbon $cutoff): array` — para el dry-run.

4. **Sin cambios** en: `transcription:tick`, `transcription:poll-results`, scheduler, `routes/console.php`, model `Transcription`, controllers, UI.

5. **Sin caché nueva**. Sin cambio en `config/transcriptor.php` (los defaults del comando son literales; si en el futuro se quiere hacer configurable, se agrega entonces).

### Verificación post-implementación

```bash
# A) Dry-run cuenta candidatos correctamente
php artisan transcription:cancel-stuck-old-jobs --dry-run --days=7
#   Esperado: ~3.092 candidatos, ratio=0.0074

# B) La tabla audit existe y tiene el shape esperado
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "\d transcriptor_cancel_audit"

# C) Una corrida real de 5 jobs (subset)
php artisan transcription:cancel-stuck-old-jobs --apply --days=7 --batch=5
#   Esperado: 5 cancelaciones + 5 filas nuevas en audit_log
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "SELECT * FROM transcriptor_cancel_audit ORDER BY id DESC LIMIT 5"

# D) El siguiente tick sigue funcionando
php artisan transcription:tick --dry-run
#   Esperado: "Sin pendientes que despachar" (la cola de hoy ya está procesada)

# E) State local refleja cancellations
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "SELECT state, count(*), error_message FROM transcriptions WHERE error_message LIKE 'Cancelado en upstream%' GROUP BY 1, 3"
```

### Rollback

Si algo sale mal después de aplicar:

1. **Abort antes del primer `--apply`**: trivial — no se tocó nada.
2. **Abort a mitad de corrida**: Ctrl-C. El `Cache::lock` expira a los 600 s, así que la próxima corrida puede retomar. Las filas que SÍ se cancelaron tienen `state='dead'` local; las que NO se cancelaron siguen en `queued/processing` con `job_id`. Si la cancelación fue errónea, se re-envían con `POST /bulk-dispatch` con IDs explícitos (la lógica de re-envío respeta `whereNull('job_id')` en el path normal; bulk-dispatch ignora ese filtro por diseño para救援 manual).
3. **Rollback completo (revert + migrate:rollback)**:
   ```bash
   cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
   git revert <commit-hash-de-la-feature>
   cd app && php artisan migrate:rollback --step=1
   # Las filas canceladas localmente siguen en state='dead'. Para re-crear el job:
   # UPDATE transcriptions SET state='pending', job_id=NULL, finished_at=NULL,
   #   dispatched_at=NULL, error_message=NULL WHERE error_message LIKE 'Cancelado en upstream%';
   # ATENCIÓN: esto solo es válido si el operador decide. NO automático.
   ```

## Open Questions

1. **¿El operador quiere también programar este comando en el scheduler** (con `withoutOverlapping` y `--max-ratio` para auto-ejecución semanal) **o mantenerlo manual controlado?** Esto es decisión de operación, no de implementación. Si cambia a "auto", se agrega `Schedule::command('transcription:cancel-stuck-old-jobs --apply --days=14')->weekly()->sundays()->at('03:00')...`. Hoy la respuesta es "manual" (decisión del operador en esta sesión).

2. **¿Vale la pena un segundo comando que haga DELETE upstream** (`deleteUpstream`) **de los jobs ya cancelados para liberar espacio del API?** Hoy `cancelUpstream` deja el job en estado `cancelled`, pero no lo borra. Si el upstream cobra por almacenamiento de jobs, este sería follow-up. No es blocker.
