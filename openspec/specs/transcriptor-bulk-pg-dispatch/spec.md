# transcriptor-bulk-pg-dispatch Specification

## Purpose

El endpoint de despacho masivo (`POST /ia/api-transcriptor/jobs/bulk-dispatch`)
 marca filas `Transcription` con `dispatched_at` y `state='processing'` directamente
en PostgreSQL, sin pasar por la cola Redis. El worker PG (`transcription:worker`)
las recoge en su siguiente iteración por el filtro `state='pending'`, por lo que
el bulk dispatch las procesa sincrónicamente al momento y el endpoint garantiza
< 1 s para lotes de hasta 2000 ids.

## Requirements

### Requirement: Endpoint bulk-dispatch marca filas en PostgreSQL en menos de 1 segundo

El sistema SHALL exponer `POST /ia/api-transcriptor/jobs/bulk-dispatch` que recibe
opcionalmente `{ ids: int[] }` y para cada `Transcription` válida ejecuta:

```sql
UPDATE transcriptions
   SET state = 'processing',
       dispatched_at = NOW(),
       updated_at = NOW()
 WHERE id = ANY($ids)
   AND state IN ('pending', 'queued', 'processing')
   AND job_id IS NULL
   AND recorded_at >= today_bogota;
```

La respuesta SHALL ser 200 JSON en menos de 1 segundo para lotes de hasta 2000
elementos, con la forma `{ enqueued: int, skipped_queued: int, errors: int }`.

El backend SHALL delegar en `TranscriptionBulkDispatchService::dispatch(array $ids)`
(ver `app/app/Services/Ia/TranscriptionBulkDispatchService.php`).

#### Scenario: Admin encola 50 ids específicas

- **WHEN** el admin hace POST con `{ ids: [4711, 4708, 4715, ...] }` y 48 de ellos
  son `state IN ('pending','queued','processing')` con `job_id IS NULL`
- **THEN** el endpoint responde 200 con `{ enqueued: 48, skipped_queued: 2, errors: 0 }`
  y los 48 jobs son tomados por el worker PG en la siguiente iteración

#### Scenario: Admin no envía ids y se auto-seleccionan

- **WHEN** el admin hace POST con body `{}` o `{ ids: null }`
- **THEN** el backend auto-selecciona hasta 2000 `Transcription` con
  `state IN ('pending','queued','processing')`, `job_id IS NULL` y
  `recorded_at >= today_bogota`, ordenadas por `recorded_at DESC`, y procede
  idéntico al caso con ids explícitos

#### Scenario: Validación rechaza ids inválidas

- **WHEN** el admin envía `{ ids: ["foo", -1, 0] }`
- **THEN** el endpoint responde 422 con `{ message: "ids.* must be integer",
  errors: { ... } }` y NO marca ninguna fila

#### Scenario: Más de 2000 ids se truncan

- **WHEN** el admin envía `{ ids: [1, 2, ..., 3000] }` (3000 ids)
- **THEN** el endpoint responde 422 con error de validación
  `ids must not have more than 2000 items` (no se procesa parcialmente)

#### Scenario: Fila con job_id se omite sin error

- **WHEN** entre los ids hay uno con `state IN ('queued','processing')` PERO con
  `job_id NOT NULL` (caso: ya enviada a la API externa)
- **THEN** esa fila se cuenta en `skipped_queued` (no en `errors`) y el
  `UPDATE` no la afecta — el admin debe usar `refresh-status` individualmente

#### Scenario: Fila con state terminal se omite

- **WHEN** entre los ids hay uno con `state IN ('done','error','dead')`
- **THEN** esa fila se cuenta en `skipped_queued` y no se marca (un job `done`
  no debe re-enviarse por bulk)

### Requirement: Bulk dispatch actualiza PostgreSQL, no Redis

El sistema SHALL actualizar `transcriptions` directamente, sin encolar nada a
Redis. El worker PG (`transcription:worker`) detecta las filas marcadas
(`state='processing'`) y las procesa en su siguiente iteración.

Si el setting `transcriptor_pg_queue_enabled` existiera, en `false` el bulk
dispatch cae al path legacy de `ConvertAndTranscribeJob::dispatch`. (En esta
versión el setting no existe; el rollback es vía git revert.)

#### Scenario: Bulk dispatch en cold path

- **WHEN** el admin hace POST con 2000 ids
- **THEN** el endpoint ejecuta `UPDATE … WHERE id = ANY($ids)` con índice sobre
  `id` (PK) y termina en ~150 ms (cold)

### Requirement: Errores parciales no abortan el batch

El sistema SHALL envolver cada llamada individual al `UPDATE` en try/catch. Si una
falla, incrementará `errors` y continuará con la siguiente sin abortar el batch
entero. Cada error individual se loguea como `warning` con `Log::warning()`.

#### Scenario: Error en fila 137 de 200 no aborta las restantes

- **WHEN** el UPDATE de la fila 137 lanza `\Throwable` (cualquier excepción),
  pero las filas 1-136 y 138-200 sí se actualizan
- **THEN** el endpoint responde 200 con `{ enqueued: 199, skipped_queued: 0,
  errors: 1 }` y el log contiene una línea `bulk_dispatch_pg tx=137: <message>`

### Requirement: Frontend usa un único fetch para bulk dispatch

El sistema SHALL reemplazar, en el módulo API Transcriptor
(`app/resources/views/ia/api-transcriptor/index.blade.php`), el método
`bulkDispatchPending()` para que en lugar de hacer `Promise.allSettled(targets.map(fetch))`
con un POST por cada job, haga un único POST a `/ia/api-transcriptor/jobs/bulk-dispatch`
con `{ ids: [...] }` en el body.

#### Scenario: Admin hace clic en Procesar 50 pendientes ahora

- **WHEN** el admin hace clic en el botón "Procesar 50 pendientes ahora"
  (bulk sin selección explícita)
- **THEN** el frontend hace UN
  `fetch('/ia/api-transcriptor/jobs/bulk-dispatch', { method: 'POST',
  body: JSON.stringify({ ids: [...] }) })` y recibe respuesta en <1s

#### Scenario: Admin selecciona 10 con checkboxes y hace clic

- **WHEN** el admin activa el modo selección (checkbox "Seleccionar"),
  marca 10 jobs y hace clic en "Procesar 10 seleccionados ahora"
- **THEN** el frontend envía `{ ids: [id1, id2, ..., id10] }` al mismo endpoint
  y recibe respuesta con la misma forma

#### Scenario: Botón deshabilitado mientras hay request en vuelo

- **WHEN** el admin hace clic en "Procesar N..." y el request aún no ha retornado
- **THEN** el botón se mantiene deshabilitado (`:disabled="bulkDispatching"`) y
  muestra "Encolando..." con icono `fa-spin`, previniendo doble-click y
  multi-Submit accidental

### Requirement: UI muestra progreso via polling de stats

El sistema SHALL mantener el `bulkDispatchResult` con la forma
`{ enqueued, skipped_queued, errors }` y mostrar al admin una barra o mensaje
indicando "Encolados para procesamiento en background — N jobs". El progreso real
(cuántos faltan) lo da el polling ya existente de `/ia/api-transcriptor/stats`
que refresca contadores `queued`/`processing`/`done` cada 2 segundos.

#### Scenario: Admin ve encolado exitoso

- **WHEN** el endpoint responde 200 con `{ enqueued: 1356, skipped_queued: 0,
  errors: 0 }`
- **THEN** la UI muestra: "✓ 1356 trabajos encolados. Los workers los procesarán
  en background. El progreso se refleja en el panel 'Estado por BD' arriba."

#### Scenario: Errores parciales se reportan

- **WHEN** el endpoint responde 200 con `{ enqueued: 1300, skipped_queued: 50,
  errors: 6 }`
- **THEN** la UI muestra: "✓ 1300 encolados · 50 omitidos (ya enviados) ·
  ⚠ 6 con error. Revisa `php artisan transcriptor:diagnose-pending` para los
  pendientes restantes."

#### Scenario: Panel collapsable refleja avance

- **WHEN** pasan 30 segundos desde el clic y los workers procesaron 200 jobs
- **THEN** el panel "Estado por BD" muestra `queued: 1156, processing: 50,
  done: 150`, reflejando el avance real sin que el admin necesite recargar
  la página