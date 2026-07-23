# transcriptor-state-visibility Specification

## Purpose
TBD - created by archiving change transcriptor-pending-state-visibility. Update Purpose after archive.
## Requirements
### Requirement: Sub-tab Pendientes incluye filas en estado pending de BD
El sistema SHALL mostrar, en la sub-tab "Pendientes" del módulo API Transcriptor (`/ia/api-transcriptor`), todas las filas de `Transcription` cuyo `state` sea exactamente `'pending'` (BD), junto con las ya visibles en `queued` y `processing`. El contador `jobsPendingCount` SHALL incluir la suma de los tres estados: `pending + queued + processing`.

#### Scenario: Fila en state='pending' aparece en sub-tab Pendientes
- **WHEN** la respuesta de `GET /ia/api-transcriptor` incluye una `Transcription` con `state = "pending"`
- **THEN** el badge numérico de la sub-tab "Pendientes" refleja esa fila, y al activar `jobsSubTab === 'pending'` la fila es visible en la tabla de trabajos con badge de estado gris distinguible del de `queued`

#### Scenario: Fila en state='pending' no aparece en sub-tab Completados
- **WHEN** una `Transcription` tiene `state = "pending"`
- **THEN** `jobsCompletedCount` no la cuenta y la fila no aparece al activar `jobsSubTab === 'completed'`

#### Scenario: Cero filas coinciden con cualquier estado
- **WHEN** no hay filas en `pending|queued|processing` ni en `done|error|dead`
- **THEN** ambos contadores muestran `0` y la sub-tab activa muestra el placeholder "Sin trabajos pendientes" o "Sin trabajos completados" según corresponda

### Requirement: Fila en state='pending' ofrece acción "Enviar ahora"
El sistema SHALL permitir, sobre filas `Transcription` con `state = "pending"` (sin `job_id` upstream), invocar la acción "Enviar ahora" que llama al endpoint `POST /ia/api-transcriptor/jobs/{id}/dispatch-now`. El controlador SHALL permitir el envío cuando el estado es exactamente `pending`, `queued` o `processing`, llamando directamente a `TranscriptionSubmitService::submit()` sin borrar ni recrear la fila en el caso `pending`.

#### Scenario: Enviar ahora sobre fila pending ejecuta submit
- **WHEN** el usuario hace clic en "Enviar ahora" sobre una fila con `state = "pending"`
- **THEN** el frontend abre el modal de progreso y hace POST a `/ia/api-transcriptor/jobs/{id}/dispatch-now`, el backend llama `TranscriptionSubmitService::submit()` sobre la fila existente (sin `delete` + `firstOrCreate`), y al terminar la fila queda en `queued` o `processing` con `job_id` poblado

#### Scenario: Enviar ahora sobre fila pending falla con error claro
- **WHEN** el endpoint responde 500 porque `TranscriptionSubmitService::submit()` falló (ej. 401 de la API externa)
- **THEN** el modal de progreso muestra el `error_message` devuelto, la fila sigue en `state = "pending"`, y se actualiza `error_message` en BD

### Requirement: Fila en state='pending' ofrece acción "Borrar" sin pasar por upstream
El sistema SHALL permitir, sobre filas `Transcription` con `state = "pending"` (sin `job_id`), invocar la acción "Borrar" que llama al endpoint `POST /ia/api-transcriptor/jobs/{id}/cancel`. El controlador SHALL borrar la fila directamente (sin llamar a la API externa) cuando el estado es `pending`, y devolver mensaje diferenciado al de cancelación normal de `queued|processing`.

#### Scenario: Borrar sobre fila pending ejecuta DELETE local
- **WHEN** el usuario hace clic en "Borrar" sobre una fila con `state = "pending"` y confirma
- **THEN** el frontend hace POST a `/ia/api-transcriptor/jobs/{id}/cancel`, el controlador verifica `state === "pending"`, NO intenta cancelar upstream, ejecuta `$job->delete()`, devuelve 200 `{ message: "Fila pendiente borrada (no fue enviada)" }`, y `this.jobs` se refresca sin la fila

#### Scenario: Cancelar sigue funcionando sobre queued/processing
- **WHEN** el usuario cancela una fila con `state = "queued"` o `state = "processing"`
- **THEN** el comportamiento existente se preserva: intentar cancelar upstream si hay `job_id`, marcar `state = "error"` con `error_message` describiendo la cancelación

### Requirement: Header de Trabajos muestra desglose completo por estado de BD
El sistema SHALL renderizar, en la cabecera de la sub-tab Trabajos de `/ia/api-transcriptor`, un panel colapsable que lista los seis estados de BD (`pending`, `queued`, `processing`, `done`, `error`, `dead`) con su contador actual, leído de `GET /ia/api-transcriptor/stats` (`stats.local[state]`).

#### Scenario: Panel muestra conteos correctos al cargar
- **WHEN** el admin abre `/ia/api-transcriptor` y la respuesta de `stats` devuelve `local = { pending: 47, queued: 12, processing: 23, done: 318, error: 4, dead: 1 }`
- **THEN** el panel colapsable muestra cada estado con su color correspondiente (gris para pending, slate para queued, azul para processing, verde para done, rojo claro para error, rojo oscuro para dead) y sus conteos exactos

#### Scenario: Panel se actualiza tras polling
- **WHEN** el polling refresca `stats` cada N segundos (ver `loadStats()` línea 1176 de `index.blade.php`)
- **THEN** los conteos se actualizan visualmente sin recarga de página

#### Scenario: Panel está colapsado por defecto
- **WHEN** el admin abre la página por primera vez
- **THEN** el panel está en estado colapsado mostrando solo un botón "Estado por BD (resumen)" sin saturar la cabecera

### Requirement: stateClass y stateDot incluyen estilo para state='pending'
El sistema SHALL devolver estilos distintivos para `state = "pending"` tanto en el helper PHP `ApiTranscriptorController::stateClass()` / `stateDot()` como en sus equivalentes Alpine en `index.blade.php` (líneas 2010 y 2019), de forma que una fila `pending` no se confunda visualmente con `queued`.

#### Scenario: Fila pending muestra badge gris con texto "pending"
- **WHEN** Alpine.render invoca `stateClass('pending')` para una fila
- **THEN** se devuelve `bg-slate-200 text-slate-700` y `stateDot('pending')` devuelve `bg-slate-500`

#### Scenario: Otros estados mantienen sus colores actuales
- **WHEN** Alpine.render invoca `stateClass('done')` o `stateClass('error')`
- **THEN** se devuelven los mismos valores que antes del cambio (`bg-green-100 text-green-700` y `bg-red-100 text-red-700` respectivamente)

### Requirement: Filtro `state` incluye opción `pending`
El sistema SHALL ofrecer, en el `<select x-model="stateFilter">` de la sub-tab Trabajos (líneas 564-570 de `index.blade.php`), una opción `<option value="pending">pending</option>` para que el admin pueda filtrar la lista unificada explícitamente por ese estado.

#### Scenario: Filtro pending muestra solo filas pending
- **WHEN** el admin selecciona la opción "pending" en el filtro de estado
- **THEN** la URL resultante de `load()` agrega `?state=pending`, el backend filtra `Transcription::where('state', 'pending')`, y la tabla muestra únicamente esas filas en ambas sub-tabs

### Requirement: Comando artisan `transcriptor:diagnose-pending` lista filas pending
El sistema SHALL proveer un comando artisan `php artisan transcriptor:diagnose-pending` que lista todas las `Transcription` en `state = "pending"` con `id`, `file_id`, `original_name`, `created_at`, `started_at`, `storage_provider_id`, `transcription_priority` y `job_id`, en formato tabla CLI con colores y opción `--json` para piping.

#### Scenario: Ejecutar comando sin filas pendientes
- **WHEN** el admin ejecuta `php artisan transcriptor:diagnose-pending` y no hay filas en `state = "pending"`
- **THEN** imprime "✓ No hay filas pendientes" y sale con código 0

#### Scenario: Ejecutar comando con filas pendientes
- **WHEN** el admin ejecuta `php artisan transcriptor:diagnose-pending` y existen 47 filas pendientes
- **THEN** imprime una tabla con columnas `ID | File | Storage | Prioridad | Antigüedad (min) | Job ID`, ordenada por `created_at` ascendente (la más antigua primero)

#### Scenario: Opción --json produce salida JSON
- **WHEN** el admin ejecuta `php artisan transcriptor:diagnose-pending --json`
- **THEN** imprime un array JSON con todas las filas (sin colores ANSI), suitable para `jq` o piping a scripts

#### Scenario: Fila con job_id y state='pending' se marca como anómala
- **WHEN** una fila tiene `job_id` no nulo pero `state = "pending"`
- **THEN** el comando marca esa fila con `⚠ ANOMALÍA` en la columna Job ID (señal de bug grave en el flujo de estados) y ordena esas filas al final de la tabla

