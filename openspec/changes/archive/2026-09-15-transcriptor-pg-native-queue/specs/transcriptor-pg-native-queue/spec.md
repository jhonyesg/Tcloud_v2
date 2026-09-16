# transcriptor-pg-native-queue Specification

## Purpose

Migrar la cola de despacho de transcripciones del driver Redis al driver PostgreSQL
nativo, donde la propia tabla `transcriptions` ejerce simultáneamente como **registro
histórico** y como **cola activa**, eliminando la doble fuente de verdad que produce
backlog opaco y regulador ciego cuando Redis se desincroniza.

## ADDED Requirements

### Requirement: Worker consume directamente de `transcriptions` con SKIP LOCKED

El sistema SHALL exponer `php artisan transcription:worker` (definido en
`transcriptor-pg-worker-process`) que ejecuta el siguiente loop continuo:

```sql
SELECT *
FROM transcriptions
WHERE state = 'pending'
  AND dispatched_at IS NULL
  AND recorded_at >= (date_trunc('day', NOW() AT TIME ZONE 'America/Bogota'))
ORDER BY recorded_at DESC, discovered_at DESC
LIMIT $batch
FOR UPDATE SKIP LOCKED;
```

Para cada fila retornada, el worker SHALL:

1. `UPDATE transcriptions SET state='processing', dispatched_at=NOW() WHERE id=$id`
2. `COMMIT` (libera el lock de BD).
3. Invocar `TranscriptionSubmitService::submit($row)` (lógica ffmpeg + POST).
4. Según el resultado:
   - `ok=true` → `state='queued'`, `job_id=...`, `submission_committed_at=NOW()`.
   - `requeueable=true` → `state='pending'`, `requeue_after_at=...`,
   `regulator_skip_reason='requeue:<causa>'`.
   - `error` → `state='error'`, `error_message=...`, `finished_at=NOW()`.
   - `dead` (reintentos agotados) → `state='dead'`, `error_message=...`,
   `finished_at=NOW()`.
5. `sleep($idle_sleep_seconds)` si la query no retornó filas.

#### Scenario: Storage habilitado con 50 audios de hoy pendientes

- **WHEN** un worker arranca y existen 50 filas `pending` con `recorded_at >= today`
- **THEN** toma 1 fila por iteración (limit), procesa, y la query retorna la siguiente;
  el ciclo termina cuando `state != 'pending'` o `recorded_at < today`

#### Scenario: Dos workers corren en paralelo sin tomar la misma fila

- **WHEN** worker A y worker B ejecutan la query al mismo tiempo
- **THEN** `FOR UPDATE SKIP LOCKED` hace que cada uno vea un set disjunto de candidatos;
  la verificación por harness `harness_transcriptor_pg_queue.php` cubre este caso

#### Scenario: Query sin filas retorna vacío y duerme

- **WHEN** no hay filas `pending` con `recorded_at >= today`
- **THEN** el worker hace `sleep($idle_sleep_seconds)` (default 2) y reintenta;
  no consume CPU ni conexiones de BD

### Requirement: Scope estricto a HOY (Bogota timezone)

El sistema SHALL calcular el inicio del día actual en `America/Bogota` y filtrar el
worker query por `recorded_at >= hoy_bogota`. Las filas con `recorded_at < hoy_bogota`
que sigan en `state='pending'` NO son candidatas a despacho.

#### Scenario: Audio de ayer sigue en pending pero el worker lo ignora

- **WHEN** existe una fila `state='pending'` con `recorded_at` del día anterior
- **THEN** el worker query la excluye del conjunto de candidatos
- **AND** la fila queda visible para la purga de cutover (ver
  `transcriptor-cutover-backlog-purge`) pero no se procesa por accidente

#### Scenario: Cambio de día natural transiciona scope sin intervención manual

- **WHEN** llega `00:00 America/Bogota` y existe una fila con `recorded_at` del día
  recién terminado
- **THEN** el worker query automáticamente deja de considerarla candidata en el
  siguiente tick; no se requiere intervención manual

### Requirement: Prioridad por recencia (recorded_at DESC, discovered_at DESC)

El worker query SHALL ordenar los candidatos por `recorded_at DESC` (más reciente
primero) y desempate por `discovered_at DESC` (más reciente visto primero si hay dos
audios del mismo `recorded_at`).

#### Scenario: Audio de las 23:00 y audio de las 02:00 del mismo día

- **WHEN** dos filas `pending` con `recorded_at=23:00` y `recorded_at=02:00`
  ambas del día de hoy
- **THEN** el worker toma primero la de las 23:00

#### Scenario: Dos audios del mismo recorded_at

- **WHEN** dos filas `pending` con `recorded_at=15:00` y `discovered_at=15:05` vs
  `discovered_at=15:10`
- **THEN** el worker toma primero la de `discovered_at=15:10`

### Requirement: Watchdog recupera filas en `processing` mayores a N segundos

El sistema SHALL exponer `php artisan transcriptor:watchdog-processing` (cron cada
60 s, registrado en `routes/console.php`) que ejecuta:

```sql
UPDATE transcriptions
   SET state = 'pending',
       dispatched_at = NULL,
       regulator_skip_reason = 'watchdog_recover',
       updated_at = NOW()
 WHERE state = 'processing'
   AND submission_committed_at IS NULL
   AND dispatched_at < NOW() - interval '900 seconds';
```

El timeout SHALL leerse de `SystemSetting('transcriptor_processing_timeout_seconds')`,
default 900.

#### Scenario: Worker murió después de tomar fila pero antes de commit

- **WHEN** una fila tiene `state='processing'`, `dispatched_at` hace 1000 s,
  `submission_committed_at IS NULL`
- **THEN** el watchdog la re-encola a `state='pending'` con
  `regulator_skip_reason='watchdog_recover'`
- **AND** el próximo worker la vuelve a tomar (dedup por `file_id UNIQUE` evita
  doble envío si el original completó entre tanto)

#### Scenario: Fila legítima recién en processing (< 900 s)

- **WHEN** una fila tiene `state='processing'` y `dispatched_at` hace 30 s
- **THEN** el watchdog NO la toca (no cumple `dispatched_at < now() - 900 seconds`)

### Requirement: Filtros preservan el contrato downstream con Correcciones y Avisos

El worker SHALL **NO** tocar filas en estado `done`, `error`, o `dead`. La
transición atómica es exclusivamente:

```
pending → processing → queued (vía submit ok)
                    → pending (vía submit requeueable)
                    → error | dead (vía submit error)
```

El filtro `state='pending' AND dispatched_at IS NULL` garantiza que filas en
estados terminales (donde `TranscriptionProcessor`, `KeywordMatcher`,
`TranscriptionCoherencePass` ya trabajaron o trabajarán) no se ven afectadas.

#### Scenario: Fila en done no se reprocesa accidentalmente

- **WHEN** existe una fila `state='done'` con `srt_content` poblado
- **THEN** el worker query la excluye (el WHERE filtra `state='pending'`)
- **AND** `TranscriptionProcessor` sigue siendo el único path que muta
  `state='done'`

### Requirement: No more `ConvertAndTranscribeJob`, `LimitTranscriptionConcurrency`, ni `dispatch_stagger_ms`

El sistema SHALL retirar la clase `ConvertAndTranscribeJob` (cola Redis via Bus) y
el middleware `LimitTranscriptionConcurrency` (su único consumer). El setting
`transcriptor.dispatch_stagger_ms` queda retirado del schema sin reemplazo: el ritmo
lo controla el regulador (lee `/api/metrics/overview`) y el guardrail de `/dev/shm`
en `TranscriptionSubmitService::markRequeueable` evita saturación de procesos.

#### Scenario: grep devuelve 0 hits post-implementación

- **WHEN** se ejecuta `grep -rn 'ConvertAndTranscribeJob\|LimitTranscriptionConcurrency\|dispatch_stagger_ms' app/`
- **THEN** devuelve 0 hits (gate crítico 1.3 del tasks.md)

#### Scenario: Rollback completo vía git revert

- **WHEN** el nuevo flujo PG falla en producción y el operador decide rollback
- **THEN** `git revert <commit>` + `php artisan config:cache` restaura el path legacy
  Redis sin necesidad de feature flag ni `transcriptor_pg_queue_enabled`