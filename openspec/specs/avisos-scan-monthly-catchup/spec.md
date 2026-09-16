## Purpose

Capability `avisos-scan-monthly-catchup`: el modo de escaneo iterativo por mes que permite al operador disparar catch-ups de TODO el historial de transcripciones sin saturar la base de datos. Descubre los meses con datos válidos y los procesa de forma secuencial, con yielding entre meses para liberar conexiones y progreso visible para el operador.

## Requirements

### Requirement: El worker genera un plan mensual antes de iterar

Cuando el operador lanza un escaneo con la combinación `noWindow=true && force=true && !from && !to && !preset`, el worker SHALL primero descubrir el rango temporal de los datos consultando `min(finished_at)` y `max(finished_at)` de `transcriptions WHERE state = 'done'`. Con esos dos extremos, SHALL derivar la lista de meses `YYYY-MM` entre el primero y el último (inclusive). El plan SHALL persistirse en `avisos_scan_bg:{runId}` bajo la clave `month_plan` como un mapa `YYYY-MM => 'pending'`.

#### Scenario: 3 meses de datos generan un plan de 3 entradas
- **WHEN** la BD tiene transcripciones con `state='done'` y `finished_at` entre 2026-01-15 y 2026-03-22
- **THEN** el plan contiene exactamente `{"2026-01": "pending", "2026-02": "pending", "2026-03": "pending"}`
- **AND** `months_total = 3`
- **AND** `current_month = null` antes de empezar

#### Scenario: Sin datos el plan está vacío y el scan termina inmediato
- **WHEN** no hay transcripciones con `state='done'`
- **THEN** `months_total = 0`
- **AND** `status` se marca como `done` con `scanned: 0` y `hits_new: 0`
- **AND** el modal frontend muestra "Sin candidatos para procesar"

#### Scenario: Mes parcial actual usa `now()` como `to`
- **WHEN** el mes actual (e.g., septiembre 2026) tiene transcripciones y la fecha actual es 2026-09-15
- **THEN** ese mes se procesa con `from = 2026-09-01` y `to = 2026-09-15` (no fin de mes)
- **AND** los demás meses del plan usan fin de mes como `to`

### Requirement: El plan usa un snapshot temporal y límites sin solapamiento

El plan SHALL capturar `scan_cutoff_at = now()` al momento del lanzamiento para excluir transcripciones creadas durante la corrida del worker. Cada mes SHALL procesarse con un rango semi-abierto: `t.finished_at >= inicio_del_mes` AND `t.finished_at < inicio_del_mes_siguiente`. Esto evita problemas de segundos, microsegundos y límites duplicados entre meses consecutivos.

#### Scenario: Transcripciones creadas durante la corrida no se incluyen
- **WHEN** el worker arranca a las 14:00:00 con `scan_cutoff_at = 2026-09-09T14:00:00-05:00`
- **AND** una nueva transcripción se crea a las 14:05:00 con `finished_at = 2026-09-09T14:05:00`
- **THEN** esa nueva transcripción NO aparece en ninguna tanda del plan actual (queda para un próximo scan)
- **AND** las transcripciones con `finished_at < 14:00:00` sí se procesan normalmente

#### Scenario: Bordes de mes son semi-abiertos
- **WHEN** el plan tiene meses 2026-03 y 2026-04
- **THEN** el mes 2026-03 se procesa con `from = 2026-03-01 00:00:00` y `to = 2026-04-01 00:00:00`
- **AND** el mes 2026-04 se procesa con `from = 2026-04-01 00:00:00` y `to = 2026-05-01 00:00:00`
- **AND** ninguna transcripción con `finished_at = 2026-04-01 00:00:00` se procesa dos veces (cae en abril)

### Requirement: El worker itera los meses secuencialmente con yielding y tandas internas

El worker SHALL procesar los meses del plan de forma SECUENCIAL (no paralela). Cada mes SHALL conservar el drenaje interno por tandas de ≤50 candidatos (matching de keywords con UNIQUE triple). Entre cada mes SHALL ejecutar `DB::disconnect()` para liberar la conexión de PostgreSQL y `sleep(1)` para darle espacio a PHP-FPM. Si `stop_requested = true` en cache, SHALL detenerse antes de empezar el siguiente mes (cancelación cooperativa entre meses, no entre candidatos).

#### Scenario: Cada mes se procesa como una corrida independiente
- **WHEN** el worker toma el mes "2026-04" del plan
- **THEN** llama `selectCandidates` con `from='2026-04-01 00:00:00'` y `to='2026-05-01 00:00:00'`
- **AND** ejecuta el matching de keywords con el motor actual (KeywordMatcher)
- **AND** marca el mes como `done` en `month_plan` cuando termina
- **AND** acumula `scanned`, `hits_new`, `batches` en el state raíz

#### Scenario: Yielding libera la conexión entre meses
- **WHEN** el worker termina de procesar el mes "2026-04"
- **THEN** ejecuta `DB::disconnect()` (cierra la conexión PDO)
- **AND** ejecuta `sleep(1)` antes de empezar el siguiente mes
- **AND** el siguiente mes abre una nueva conexión limpia

#### Scenario: Cancelación entre meses es cooperativa
- **WHEN** el operador hace click en "Detener" durante el procesamiento del mes "2026-07"
- **THEN** ese mes termina de procesarse (no se aborta a mitad de matching)
- **AND** el worker revisa `stop_requested` ANTES de empezar el mes "2026-08"
- **AND** si está marcado, sale con `status = 'stopped'` sin procesar meses pendientes

### Requirement: El plan es resumible tras crash del worker

El plan mensual SHALL persistirse en cache (`avisos_scan_bg:{runId}.month_plan`) con el mismo TTL de 2h que el state raíz. Si el worker crashea (OOM, kill -9, PHP fatal), el `month_plan` queda con los meses ya completados marcados como `done` y los pendientes como `pending`. Un mes `running` que no completó SHALL tratarse como `pending` al recuperar (no se salta). La próxima invocación del mismo runId SHALL reanudar desde el primer mes `pending`, sin reprocesar los `done`.

#### Scenario: Worker crash a mitad del plan preserva progreso
- **WHEN** el worker murió en el mes "2026-08" tras completar los meses 2026-01 a 2026-07
- **THEN** `month_plan` tiene `{"2026-01": "done", ..., "2026-07": "done", "2026-08": "running"|"pending", "2026-09": "pending", ...}`
- **AND** la próxima invocación continúa desde "2026-08" sin tocar los ya hechos

#### Scenario: Mes running sin completar se trata como pending
- **WHEN** el cache tiene un mes con status `running` (porque el worker murió en plena tanda)
- **THEN** la próxima invocación lo trata como `pending` y lo reprocesa
- **AND** el matching es idempotente (UNIQUE triple), así que no genera duplicados

#### Scenario: TTL del cache expirado requiere re-lanzar
- **WHEN** pasaron más de 2h desde el último update del cache
- **THEN** el cache devuelve `null` y el endpoint trata el runId como expirado (404 o estado terminal)
- **AND** el operador debe lanzar un nuevo escaneo (la idempotencia del matching evita duplicados de todas formas)

### Requirement: Las actualizaciones del state son resistentes a carreras

Cuando el worker escribe el `month_plan` y el contador `months_done` en cache, SHALL protegerse contra carreras con el endpoint de stop. Si el state actual tiene `stop_requested=true` y el worker intenta escribir un state sin esa marca, SHALL preservar `stop_requested=true` en el state escrito. El endpoint de stop SHALL poder consultar y mutar el cache sin que el worker lo pise silenciosamente.

#### Scenario: Worker escribe state sin saber que stop_requested fue marcado
- **WHEN** el endpoint `scanRunStop` marca `stop_requested=true` en cache
- **AND** el worker está en medio de una iteración mensual y va a escribir su state
- **THEN** el helper `writeStateSafe` re-añade `stop_requested=true` al state del worker antes de Cache::put
- **AND** la próxima revisión de stop_requested en el worker sale del loop

#### Scenario: Stop_requested no puede volver a false una vez activo
- **WHEN** en algún momento el state tiene `stop_requested=true`
- **THEN** ninguna escritura posterior del worker puede quitar esa marca
- **AND** el operador puede confiar en que una vez detenido, el scan no sigue

### Requirement: El preview no ejecuta un conteo pesado de candidatos

El endpoint `POST /ia/avisos-inteligentes/scan/run-bg/preview` SHALL ejecutar SOLO el planning (query barata `min/max(finished_at)`). NO SHALL reproducir los joins pesados de `selectCandidates` ni calcular un `candidates_estimate` exacto. El preview devuelve `{mode, month_count, first_month, last_month, message}` para que el frontend muestre la confirmación antes del launch.

#### Scenario: Preview con 3 meses retorna plan completo
- **WHEN** el operador POST al preview con `noWindow+force` y la BD tiene 3 meses de datos
- **THEN** la respuesta es `{mode: 'monthly', month_count: 3, first_month: '2024-03', last_month: '2024-05', message: 'Vas a procesar 3 mes(es) (2024-03 → 2024-05).'}`
- **AND** el endpoint retornó en <100ms (query barata de min/max)

#### Scenario: Preview sin datos retorna mensaje explícito
- **WHEN** la BD no tiene transcripciones con `state='done'`
- **THEN** el preview devuelve `{mode: 'monthly', month_count: 0, message: 'Sin candidatos en el historial. El escaneo terminaría inmediato.'}`

### Requirement: El endpoint expone el plan en la respuesta 202

Cuando `runScanBackground` activa el modo mensual, la respuesta 202 SHALL incluir además de `runId`:
- `mode: 'monthly'`
- `month_count: N`
- `first_month: 'YYYY-MM'` (el más antiguo con datos)
- `last_month: 'YYYY-MM'` (el más reciente con datos)

El frontend SHALL usar estos campos para mostrar el plan antes de iterar.

#### Scenario: Respuesta 202 con shape mensual
- **WHEN** el endpoint detecta `noWindow=true && force=true && !from && !to && !preset`
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'monthly', month_count: 30, first_month: '2024-03', last_month: '2026-09'}`
- **AND** el modal frontend muestra: "Escaneo histórico: 30 meses detectados (marzo 2024 → septiembre 2026)"

#### Scenario: Otros modos devuelven `mode: 'classic'`
- **WHEN** el endpoint recibe `preset='24h'` o `from=X, to=Y` o `force=true && noWindow=false`
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'classic', window_label: 'Último día (24 h)'}`
- **AND** el comportamiento existente no cambia

### Requirement: El endpoint de status expone el progreso mensual

El endpoint `GET /ia/avisos-inteligentes/scan/run-bg/{runId}` SHALL incluir en su respuesta:
- `mode: 'monthly' | 'classic'`
- `months_total`, `months_done`, `current_month` (cuando `mode = 'monthly'`)
- El polling del frontend SHALL usar `current_month` y `months_done` para actualizar el modal cuando `mode = 'monthly'`.

#### Scenario: Polling muestra el mes actual y el avance
- **WHEN** el modal está abierto y el worker está en el mes "2026-07" de un plan de 30
- **THEN** cada poll de status devuelve `{..., mode: 'monthly', current_month: '2026-07', months_done: 22, months_total: 30}`
- **AND** el modal muestra "Procesando mes 23/30 (julio 2026)" en lugar de contadores de candidatos

### Requirement: El frontend confirma antes de lanzar un catch-up histórico

Cuando el operador selecciona "Histórico completo" + "Forzar re-escaneo", el frontend SHALL mostrar una confirmación explícita con el # de meses estimado antes de disparar el POST. La estimación se obtiene llamando al endpoint `/scan/run-bg/preview`.

#### Scenario: Confirmación previa con # de meses
- **WHEN** el operador marca "Histórico completo" + "Forzar re-escaneo" en el modal de Escanear
- **THEN** el frontend llama a `/scan/run-bg/preview` con el mismo body
- **AND** el preview devuelve `{month_count: 30, first_month: '2024-03', last_month: '2026-09'}`
- **AND** el modal muestra: "Vas a procesar 30 meses (marzo 2024 → septiembre 2026). ¿Confirmar?"
- **AND** si el operador cancela, no se dispara el scan

#### Scenario: Cancelación del preview
- **WHEN** el operador cancela el preview
- **THEN** no se hace POST al endpoint real
- **AND** el modal vuelve a su estado de configuración inicial

### Requirement: El modo mensual tiene un interruptor operativo

El modo mensual SHALL estar detrás de un feature flag `avisos_scan_monthly_mode.enabled` en `system_settings` (o `.env`) para poder desactivarlo sin revertir el despliegue. Si el flag está desactivado, el path `noWindow+force` sigue usando el código clásico (un solo scan monolítico). Esto permite al operador del servidor "frenar" el modo mensual si la query plan mensual o la iteración presentan problemas en producción sin hacer rollback del cambio.

#### Scenario: Flag activado usa el path mensual
- **WHEN** `avisos_scan_monthly_mode.enabled = true` (default)
- **THEN** el endpoint detecta `noWindow+force+sin rango` y activa el modo mensual
- **AND** el worker itera por meses con yielding

#### Scenario: Flag desactivado vuelve al path clásico
- **WHEN** el admin del sistema cambia `avisos_scan_monthly_mode.enabled = false`
- **AND** el operador intenta lanzar un scan histórico
- **THEN** el endpoint NO activa el modo mensual (devuelve `mode: 'classic'`)
- **AND** el worker corre el scan monolítico (estado anterior al fix)
- **AND** el comportamiento conocido de saturación de BD puede volver (aceptable como rollback operativo)
