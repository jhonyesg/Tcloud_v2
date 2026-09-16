# transcript-queue-old-jobs Specification

## Purpose
Capacidad del sistema para cancelar jobs viejos en el transcriptor upstream y marcarlos como `dead` en la BD local, manteniendo la disciplina de "no tocar archivos físicos" y dejando trazabilidad bitácora append-only.

## Requirements

### Requirement: Comando CLI `transcription:cancel-stuck-old-jobs`

El sistema SHALL exponer un comando artisan `transcription:cancel-stuck-old-jobs` que cancela jobs stuck en el upstream y marca las filas locales correspondientes como `dead`.

#### Scenario: Dry-run default

- **GIVEN** el operador ejecuta `php artisan transcription:cancel-stuck-old-jobs`
- **WHEN** el comando arranca
- **THEN** SHALL entrar en modo dry-run por defecto (sin `--apply`)
- **AND** SHALL imprimir conteo de candidatos con `state IN ('queued','processing') AND job_id IS NOT NULL AND created_at < (now() - 7 days) AND created_at < (now() - 60 minutes)`
- **AND** SHALL desglosar por estado (`queued` vs `processing`)
- **AND** SHALL imprimir el candidato más viejo
- **AND** SHALL imprimir la ratio `candidates/total` y el cutoff
- **AND** SHALL abortar si la ratio supera `--max-ratio` (default 0.5)
- **AND** SHALL exit 0 sin mutar nada

#### Scenario: Cancelación efectiva con `--apply`

- **GIVEN** el operador ejecuta `php artisan transcription:cancel-stuck-old-jobs --apply --days=7`
- **WHEN** el comando identifica N candidatos
- **THEN** SHALL iterarlos en chunks de `--batch` (default 100)
- **AND** SHALL aplicar `usleep(stagger_ms * 1000)` entre cada cancelación individual (no entre chunks); default 750 ms
- **AND** SHALL llamar `TranscriptorApiClient::cancelUpstream($tx->job_id)` por cada candidato
- **AND** SHALL respetar `Cache::lock('transcription:cancel-stuck-old-jobs', 600)` para impedir carreras

#### Scenario: Respuesta 200 del upstream

- **WHEN** `cancelUpstream($jobId)` devuelve HTTP 200 con `{state: "cancelled"}`
- **THEN** SHALL actualizar la fila local: `state='dead'`, `error_message="Cancelado en upstream por antiguedad: created_at < {cutoff}. transcription:cancel-stuck-old-jobs."`, `finished_at=now()`
- **AND** SHALL registrar en `transcriptor_cancel_audit`: `actor_user_id`, `job_id`, `tx_id`, `upstream_response_code=200`, `local_state_after='dead'`

#### Scenario: Respuesta 409 (no queued)

- **WHEN** `cancelUpstream($jobId)` devuelve HTTP 409 (job no está en estado queued)
- **THEN** SHALL dejar la fila local sin cambios (`state` y `error_message` intactos)
- **AND** SHALL registrar en `transcriptor_cancel_audit`: `local_state_after=unchanged`, `upstream_response_code=409`
- **AND** SHALL loguear `INFO transcriptor.cancel.skipped_non_queued`

#### Scenario: Respuesta 404 (job ya no existe en upstream)

- **WHEN** `cancelUpstream($jobId)` devuelve HTTP 404
- **THEN** SHALL actualizar la fila local a `state='dead'` con `error_message` indicando que el job fue eliminado en upstream
- **AND** SHALL registrar en `transcriptor_cancel_audit`: `local_state_after='dead'`, `upstream_response_code=404`

#### Scenario: Respuesta 5xx / timeout

- **WHEN** `cancelUpstream($jobId)` devuelve HTTP 5xx o la conexión excede `getTimeout()` segundos
- **THEN** SHALL abortar el lote actual (sin continuar con la siguiente cancelación)
- **AND** SHALL liberar el cache lock antes de salir
- **AND** SHALL registrar en `transcriptor_cancel_audit` la última cancelación intentada con `upstream_response_code=503`
- **AND** SHALL loguear `WARNING transcriptor.cancel.upstream_unavailable`
- **AND** el operador podrá re-ejecutar el comando; los jobs ya cancelados aparecen como `dead` y los pendientes persisten

### Requirement: Filtro estricto de candidatos

El sistema SHALL filtrar las filas candidatas con TODAS estas condiciones simultáneamente:

- `state IN ('queued', 'processing')`
- `job_id IS NOT NULL`
- `created_at < (now() - interval 'N days') AND created_at < (now() - interval 'M minutes')` donde N es `--days` y M es `--min-age-minutes`

#### Scenario: Margen mínimo por `--min-age-minutes`

- **GIVEN** una fila recién creada (hace 5 min) con `state='queued'`, `job_id='abc'`
- **WHEN** el operador ejecuta `--days=0 --min-age-minutes=60`
- **THEN** esta fila SHALL NOT ser candidata (no han pasado los 60 min de margen)
- **AND** SHALL protegerse contra el caso "upstream aún no la registró pero ya tenemos job_id"

#### Scenario: Antigüedad medida por `created_at` (no `started_at`)

- **GIVEN** una fila con `created_at` hace 19 días y `started_at` hace 1 día (el polling upstream lo actualiza aunque el job siga atascado en `queued`/`processing` local)
- **WHEN** el operador ejecuta `--days=7`
- **THEN** esta fila SHALL ser candidata (cumple `created_at < now() - 7 days`)
- **AND** el upstream responderá con 404 (job ya no existe), 409 (no está en queued, probablemente procesado en algún momento) o 200 (cancelled)
- **AND** solo el 200 y el 404 cambian el estado local a `dead`; el 409 deja el estado intacto (`unchanged`)

### Requirement: Guardarraíl de ratio

El sistema SHALL abortar la corrida con `WARNING transcriptor.cancel.aborted_mass_delete` si la ratio `candidatos/total` supera `--max-ratio` (default 0.5).

#### Scenario: Ratio baja (normal)

- **GIVEN** 3.092 candidatos de 418.385 totales (ratio 0.0074)
- **WHEN** el comando arranca con `--max-ratio=0.5`
- **THEN** SHALL proceder normalmente (ratio 0.0074 < 0.5)

#### Scenario: Ratio crítica (protección)

- **GIVEN** 200.000 candidatos de 418.385 totales (ratio 0.48) por un bug nuevo que disparó la acumulación
- **WHEN** el operador ejecuta con `--max-ratio=0.5`
- **THEN** SHALL abortar con exit 0 y mensaje de error explicando que probablemente hay otro bug detrás
- **AND** SHALL loguear `WARNING transcriptor.cancel.aborted_mass_delete {candidates:200000, ratio:0.4782, max_ratio:0.5}`

### Requirement: Audit log append-only

El sistema SHALL persistir cada intento de cancelación en la tabla `transcriptor_cancel_audit`.

#### Scenario: Estructura de la tabla

- **WHEN** el operador ejecuta `psql -c "\d transcriptor_cancel_audit"`
- **THEN** SHALL tener columnas: `id (PK, bigIncrements)`, `actor_user_id (unsignedInteger nullable)`, `job_id (varchar(64))`, `tx_id (unsignedInteger nullable)`, `upstream_response_code (smallInteger unsigned)`, `local_state_after (varchar(16))`, `error_message (text nullable)`, `created_at (timestamp)`.
- **AND** SHALL tener índices en `job_id` y `created_at`
- **AND** SHALL NOT tener foreign keys (es bitácora, independencia de ciclo de vida de `transcriptions`)

#### Scenario: Append-only

- **GIVEN** una fila en `transcriptor_cancel_audit`
- **WHEN** cualquier código intenta `UPDATE` o `DELETE` desde la app
- **THEN** SHALL NOT existir método público en `TranscriptorCancelAudit` que permita UPDATE/DELETE sobre filas existentes

### Requirement: Comando no programado automáticamente

El sistema SHALL NOT agendar `transcription:cancel-stuck-old-jobs` en `routes/console.php`. La cancelación SHALL ser siempre operación manual consciente del operador.

#### Scenario: Ausencia en scheduler

- **WHEN** el operador inspecciona `php artisan schedule:list`
- **THEN** SHALL NO aparecer ninguna entrada `transcription:cancel-stuck-old-jobs`

### Requirement: Archivos físicos intactos

El sistema SHALL NO tocar archivos físicos en disco como consecuencia de este comando. Solo SHALL mutar la fila de `transcriptions` y opcionalmente la fila en el upstream (vía API).

#### Scenario: Conteo de archivos sin cambios

- **GIVEN** un snapshot de archivos antes de la corrida (T1.4 de tasks.md)
- **WHEN** el operador ejecuta `--apply` en subset pequeño (5 jobs)
- **THEN** SHALL NO haber archivos borrados del filesystem como efecto del comando
