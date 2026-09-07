## Purpose

Define la observabilidad de extremo a extremo del pipeline de transcripción desde que el archivo termina de escribirse en disco hasta que la API externa devuelve el resultado, persistiendo marcas temporales por etapa en la tabla `transcriptions` y exponiendo distribuciones y causas del regulador a través de dos endpoints JSON consumibles desde la UI `/ia/api-transcriptor`.

## Requirements

### Requirement: Pipeline persiste cuatro marcas temporales por Transcription
El sistema SHALL persistir cuatro columnas nuevas en `transcriptions`:
- `discovered_at`: momento en que `DiskScannerService` crea por primera vez la fila `Transcription` para un archivo candidato recién visto en disco.
- `dispatched_at`: momento en que `TranscriptionTickCommand` encola el `ConvertAndTranscribeJob` correspondiente al job pool `transcription` de Redis.
- `submission_committed_at`: momento en que `TranscriptionSubmitService::submit()` recibe respuesta válida del `POST /v1/transcribe` y persiste `job_id` no nulo en la fila.
- `regulator_skip_reason`: texto corto (`≤64` caracteres) con la razón por la que el último tick omitió despacho en este storage cuando aplica a una fila; `NULL` cuando la fila sí fue despachada.

#### Scenario: Discovery en cold start
- **WHEN** el scanner crea una nueva fila `Transcription` para un archivo recién detectado en `base_path/dmY/`
- **THEN** `discovered_at` se setea con el timestamp del momento de creación del registro
- **AND** `dispatched_at`, `submission_committed_at` y `regulator_skip_reason` arrancan en `NULL`

#### Scenario: Fila existente en BD antes del upgrade
- **WHEN** existen filas `Transcriptions` previas a la migración que no tienen `discovered_at` poblado
- **THEN** la columna admite `NULL` sin violar restricciones
- **AND** la fila sigue siendo elegible para `dispatched_at` y `submission_committed_at` en cuanto pase por el pipeline

#### Scenario: Skip por regulador registra la causa
- **WHEN** el tick decide NO encolar por alguna señal del nuevo `regulator_signals`
- **AND** existe al menos una `Transcription` pendiente del almacenamiento actual con `dispatched_at IS NULL`
- **THEN** el tick actualiza `regulator_skip_reason` con la razón dominante (ej. `remote_gpu>=90%`, `shm_free<200MB`, `inflight>=max`)
- **AND** no encola hasta que la señal deje de estar saturada

### Requirement: Endpoint de latencias por etapa con percentiles
El sistema SHALL exponer `GET /ia/api-transcriptor/latency` bajo el grupo `['auth','admin']` + `prefix('ia')`, que devuelva para las últimas N horas configurables (default 24h) y agrupado por storage opcional, las distribuciones p50/p95 en segundos de cuatro etapas:
- `mtime_to_discovered`: `(discovered_at - files.file_modified_at)`
- `discovered_to_dispatched`: `(dispatched_at - discovered_at)`
- `dispatched_to_committed`: `(submission_committed_at - dispatched_at)`
- `committed_to_finished`: `(finished_at - submission_committed_at)`

#### Scenario: Consulta sin parámetros devuelve 24h globales
- **WHEN** un admin llama a `GET /ia/api-transcriptor/latency` sin parámetros
- **THEN** la respuesta incluye percentiles p50 y p95 agregados sobre todas las filas terminadas en las últimas 24h
- **AND** también incluye `count_by_state` (pendientes, queued, processing, done, error, dead) en el mismo periodo

#### Scenario: Filtro por storage y ventana
- **WHEN** el admin pasa `?storage_id=4&hours=6`
- **THEN** las distribuciones y conteos se calculan restringiendo a `storage_provider_id=4` y los últimos 6h
- **AND** la respuesta no excede 200ms de tiempo de cálculo aunque haya 100k filas en la ventana

#### Scenario: Ventana sin datos
- **WHEN** no existen filas en la ventana solicitada
- **THEN** la respuesta devuelve percentiles `null` y `count_by_state` con ceros
- **AND** el frontend muestra explícitamente "Sin datos" en vez de NaN

### Requirement: Endpoint que explica por qué el último tick frenó
El sistema SHALL exponer `GET /ia/api-transcriptor/regulator-cause` que devuelva, para el último tick ejecutado, las señales evaluadas y el resultado del cálculo del regulador:
- `fired_at`: timestamp del tick (`null` si nunca corrió).
- `signals_evaluated`: lista de señales que el modo actual considera (`redis_queue_depth`, `remote_gpu_usage`, `shm_free`, `inflight_active`, `dispatch_paused`).
- `values`: lectura de cada señal (ej. `redis_queue_depth=87`, `remote_gpu_usage=null`, `shm_free=1.4GB`).
- `decision`: `dispatched | skipped` y la razón (`queue_at_target`, `remote_gpu_saturated`, `shm_low`, `inflight_full`, `dispatch_paused`, `none`).
- `batch_computed`: entero con el batch que el regulador habría calculado este ciclo.

#### Scenario: Tick reciente con freno por cola Redis
- **WHEN** el último tick decidió `skipped` por `redis_queue_depth >= target_redis_queue`
- **THEN** el endpoint devuelve `decision=skipped`, `reason=queue_at_target`, `values.remote_gpu_usage=null` (modo `local_only`) y `batch_computed` igual al `min_batch` o 0

#### Scenario: Modo `remote_aware` sin freno
- **WHEN** el modo del regulador es `remote_aware` y la API remota reporta `processing < 80%` del máximo
- **THEN** el endpoint devuelve `decision=dispatched` y `values.remote_gpu_usage=<porcentaje>`
- **AND** `batch_computed` refleja el déficit total

#### Scenario: Causa persistida coincide con el endpoint
- **WHEN** el último tick persistió `regulator_skip_reason='remote_gpu>=90%'` en una fila pendiente
- **THEN** el endpoint expone `decision=skipped` y `reason=remote_gpu_saturated`

### Requirement: Panel de diagnóstico en la UI `/ia/api-transcriptor`
El sistema SHALL añadir un panel colapsable en `app/resources/views/ia/api-transcriptor/index.blade.php` con título "Diagnóstico de pipeline" que muestre, consumiendo los dos endpoints anteriores:
- Cuatro tarjetas con los percentiles p50/p95 por etapa (en segundos y minutos).
- Un semáforo del estado del último tick del regulador con la causa legible.
- Una mini-tabla con `count_by_state` para los administradores ver de un vistazo dónde está el atasco.

#### Scenario: Panel carga al abrir la pestaña
- **WHEN** el admin abre `/ia/api-transcriptor` y el panel está desplegado
- **THEN** el frontend hace dos `fetch` a `/latency?hours=24` y `/regulator-cause` y pinta los datos
- **AND** un botón "Actualizar" repite los dos fetch

#### Scenario: Almacenamiento saturado se ve en rojo
- **WHEN** la causa del último tick es `remote_gpu_saturated` o `shm_low`
- **THEN** el semáforo se pinta rojo y la etiqueta muestra la causa humana (ej. "GPU remota saturada (95%)")
- **AND** se registra recomendación: "Revisar /api/stats en el nodo GPU o liberar `/dev/shm`"

#### Scenario: p95 anormal dispara alerta
- **WHEN** `p95_committed_to_finished > 5 minutos` para una grabación de 15 min (umbral configurable `latency_p95_warn_seconds`, default 300)
- **THEN** la tarjeta de la etapa correspondiente se pinta en ámbar con un tooltip "p95 fuera de rango"
- **AND** se ofrece botón "Ver detalle" que abre el modal existente de jobs

### Requirement: Backfill seguro de las nuevas columnas
El sistema SHALL exponer `php artisan transcriptor:backfill-pipeline-stamps` que para filas sin `discovered_at`/`dispatched_at`/`submission_committed_at` los rellene desde señales externas disponibles (logs del tick, `job_id` y `started_at`, `finished_at`).

#### Scenario: Fila con `finished_at` y `job_id` pero sin `submission_committed_at`
- **WHEN** una fila tiene `finished_at IS NOT NULL` y `job_id IS NOT NULL`
- **THEN** el backfill puede aproximar `submission_committed_at = finished_at - p95_committed_to_finished_promedio_observado_en_ventana`
- **AND** registra advertencia de "valor estimado" en log por fila

#### Scenario: Fila sin ninguna marca rellenable
- **WHEN** la fila no tiene `started_at` ni `job_id` y `state='pending'`
- **THEN** el backfill rellena `discovered_at = created_at` (timestamp de creación de la fila BD) y deja el resto `NULL`
- **AND** no aborta el resto del batch

### Requirement: Compatibilidad con pipeline existente
El sistema SHALL garantizar que las nuevas marcas se rellenan sin tocar la lógica de negocio existente: `markRequeueable()`, `markError()`, `markDead()`, el regulador actual y el polling. Solo se añade escritura de columnas.

#### Scenario: Retry por tmpfs lleno
- **WHEN** el submit rebota una fila por `/dev/shm` sin espacio y la marca `requeue_after_at` futuro
- **THEN** las nuevas marcas no se modifican (siguen en `NULL` hasta que efectivamente se reenvíe)
- **AND** un reenvío posterior sí rellena `dispatched_at` y `submission_committed_at`

#### Scenario: Worker muerto antes de commit
- **WHEN** un worker toma un job y muere antes de persistir `job_id`
- **THEN** la marca `dispatched_at` ya está poblada por el tick pero `submission_committed_at` queda `NULL`
- **AND** un reintento del job rellena `submission_committed_at` (idempotencia por reenvío)
