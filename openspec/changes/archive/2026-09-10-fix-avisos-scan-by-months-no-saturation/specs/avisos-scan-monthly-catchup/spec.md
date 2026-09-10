## Purpose

Permite al operador disparar un catch-up de TODO el historial de transcripciones sin saturar la base de datos. El sistema descubre los meses con datos válidos y los procesa de forma secuencial, con yielding entre meses para liberar conexiones y progreso visible para el operador.

## ADDED Requirements

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

El planning SHALL capturar `scan_cutoff_at`. Los meses SHALL procesarse como intervalos semiabiertos `[inicio_del_mes, inicio_del_mes_siguiente)`, excepto el mes que contiene `scan_cutoff_at`, cuyo límite superior SHALL ser `scan_cutoff_at`. Las transcripciones creadas después del snapshot no forman parte de ese run.

#### Scenario: Los límites de meses consecutivos no duplican timestamps
- **WHEN** una transcripción termina exactamente al inicio de mayo de 2026
- **THEN** pertenece al intervalo de mayo
- **AND** no pertenece al intervalo de abril

#### Scenario: Datos posteriores al planning quedan fuera del run
- **WHEN** una transcripción se crea después de `scan_cutoff_at`
- **THEN** no se procesa en el plan actual
- **AND** queda disponible para otra corrida o el cron

### Requirement: El worker itera los meses secuencialmente con yielding y tandas internas

El worker SHALL procesar los meses del plan de forma SECUENCIAL (no paralela). Cada mes SHALL drenarse mediante tandas de como máximo 50 candidatos, actualizando progreso y revisando `stop_requested` entre tandas. Entre cada mes SHALL liberar la conexión usada por el worker CLI y ejecutar `sleep(1)`. Si `stop_requested = true` en cache, SHALL detenerse antes de la siguiente tanda o mes.

#### Scenario: Cada mes se procesa como una corrida independiente
- **WHEN** el worker toma el mes "2026-04" del plan
- **THEN** llama `selectCandidates` con `from='2026-04-01 00:00:00'` y `to='2026-05-01 00:00:00'`
- **AND** ejecuta el matching de keywords con el motor actual (KeywordMatcher)
- **AND** marca el mes como `done` en `month_plan` cuando termina
- **AND** acumula `scanned`, `hits_new`, `batches` en el state raíz

#### Scenario: Un mes grande conserva el límite de tanda
- **WHEN** el mes actual contiene más de 50 candidatos
- **THEN** cada consulta de candidatos solicita como máximo 50
- **AND** el worker actualiza el progreso entre tandas
- **AND** puede detenerse sin esperar a completar todo el mes

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

El plan mensual SHALL persistirse en cache (`avisos_scan_bg:{runId}.month_plan`) con el mismo TTL de 2h que el state raíz. Si el worker crashea (OOM, kill -9, PHP fatal), el `month_plan` queda con los meses ya completados marcados como `done` y los pendientes como `pending`. La próxima invocación del mismo runId SHALL reanudar desde el primer mes `pending`, sin reprocesar los `done`.

#### Scenario: Worker crash a mitad del plan preserva progreso
- **WHEN** el worker murió en el mes "2026-08" tras completar los meses 2026-01 a 2026-07
- **THEN** `month_plan` tiene `{"2026-01": "done", ..., "2026-07": "done", "2026-08": "running"|"pending", "2026-09": "pending", ...}`
- **AND** la próxima invocación continúa desde "2026-08" sin tocar los ya hechos

#### Scenario: TTL del cache expirado requiere re-lanzar
- **WHEN** pasaron más de 2h desde el último update del cache
- **THEN** el cache devuelve `null` y el endpoint trata el runId como expirado (404 o estado terminal)
- **AND** el operador debe lanzar un nuevo escaneo (la idempotencia del matching evita duplicados de todas formas)

#### Scenario: Un mes running se recupera como pendiente
- **WHEN** el worker muere después de marcar un mes como `running` pero antes de marcarlo `done`
- **THEN** la recuperación trata ese mes como pendiente
- **AND** no omite el mes por estar en estado `running`
- **AND** conserva los meses previamente marcados como `done`

### Requirement: Las actualizaciones del state son resistentes a carreras

Las actualizaciones del state SHALL impedir que una copia antigua del worker sobrescriba `stop_requested=true`, meses `done` o un estado terminal.

#### Scenario: Stop concurrente no se pierde
- **WHEN** el endpoint de stop escribe `stop_requested=true` mientras el worker actualiza progreso
- **THEN** la siguiente lectura del state conserva `stop_requested=true`
- **AND** el worker detiene el procesamiento en el siguiente punto cooperativo

### Requirement: El preview no ejecuta un conteo pesado de candidatos

El endpoint de preview SHALL ejecutar solamente el planning temporal. No SHALL ejecutar un conteo exacto con los joins completos de candidatos.

#### Scenario: Preview devuelve el plan sin escanear candidatos
- **WHEN** se llama `POST /scan/run-bg/preview` para histórico completo y forzado
- **THEN** devuelve `month_count`, `first_month`, `last_month` y `scan_cutoff_at`
- **AND** no crea un run ni modifica hits, watermarks o el lock activo
- **AND** no ejecuta una consulta de conteo exacto de candidatos

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

Cuando el operador selecciona "Histórico completo" + "Forzar re-escaneo", el frontend SHALL mostrar una confirmación explícita con el # de meses estimado antes de disparar el POST. La estimación se obtiene llamando a un endpoint ligero `POST /ia/avisos-inteligentes/scan/run-bg/preview` que ejecuta el plan sin mutar.

#### Scenario: Confirmación previa con # de meses
- **WHEN** el operador marca "Histórico completo" + "Forzar re-escaneo" en el modal de Escanear
- **THEN** el frontend llama a `/scan/run-bg/preview` con el mismo body
- **AND** el preview devuelve `{month_count: 30, first_month: '2024-03', last_month: '2026-09', candidates_estimate: 300000}`
- **AND** el modal muestra: "Vas a procesar 30 meses (300k transcripciones aprox). ¿Confirmar?"
- **AND** si el operador cancela, no se dispara el scan

#### Scenario: Cancelación del preview
- **WHEN** el operador cancela el preview
- **THEN** no se hace POST al endpoint real
- **AND** el modal vuelve a su estado de configuración inicial
