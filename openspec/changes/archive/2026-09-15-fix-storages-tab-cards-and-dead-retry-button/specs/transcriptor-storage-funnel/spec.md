## MODIFIED Requirements

### Requirement: Pestaña Storages muestra conteos Pendientes, En curso y Listos por storage

El sistema SHALL renderizar, en la tabla de la pestaña Storages del módulo API Transcriptor (`/ia/api-transcriptor`), columnas de conteo por fila para los storages con `transcription_enabled = true`:

- **Pendientes (hoy)** = filas `transcriptions` creadas hoy con `state ≠ 'done'` (incluye `pending`, `queued`, `processing`, `error`, `dead`).
- **Listos (hoy)** = filas `transcriptions` creadas hoy con `state = 'done'`.

Los conteos SHALL agruparse por `files.storage_provider_id` mediante una sola query agregada por scope heredado (`transcriptor-storage-funnel` cache 60s). Los conteos aparecen ÚNICAMENTE como columnas de la tabla de storages: el módulo NO SHALL renderizar tarjetas resumen globales con estos funnel (las tarjetas "Pendientes hoy" y "Listos hoy" del header quedan retiradas; el header solo conserva las tarjetas de volumen "Cantidad Total" y "Cantidad Real (ponderado)").

#### Scenario: Storage con 8 pendientes, 2 en curso, 430 listos
- **WHEN** el operador abre `/ia/api-transcriptor`, activa la pestaña Storages y existe un storage con `id = 42` que tiene 8 `transcriptions` en estado `pending` sin `dispatched_at`, 2 en `queued`, y 430 en `done`
- **THEN** la fila correspondiente muestra `Pendientes: 8`, `Listos: 430` en sus columnas

#### Scenario: Storage sin actividad muestra ceros sin texto adicional
- **WHEN** un storage con `transcription_enabled = true` no tiene ninguna fila en `transcriptions`
- **THEN** la fila muestra `0` en las dos columnas, alineado como los demás números, sin texto "Sin trabajos" ni placeholders

#### Scenario: Conteos erróneos o muertos cuentan como Pendientes
- **WHEN** un storage tiene 4 filas en `state = 'error'` creadas hoy con `dispatched_at IS NULL`
- **THEN** esas 4 filas se contabilizan en la columna Pendientes (hoy) (junto con las `pending`), porque aún no han sido despachadas o reintentadas

#### Scenario: No existen tarjetas resumen de funnel diario
- **WHEN** el operador carga la pestaña Storages
- **THEN** el bloque de tarjetas del header muestra solo "Cantidad Total" y "Cantidad Real (ponderado)"; no hay ninguna tarjeta etiquetada "Pendientes hoy" ni "Listos hoy"

## ADDED Requirements

### Requirement: Botón de reintento batch retirado del tab Storages

El tab Storages NO SHALL renderizar ningún botón que dispare `POST /ia/api-transcriptor/retry-batch` (ruta eliminada en `simplify-api-transcriptor-to-storage-and-config`). La recuperación masiva de fallidos SHALL seguir disponible únicamente vía:

- El cron semanal `transcription:retry-batch-upstream --max-age-hours=168` (lunes 04:00).
- Ejecución CLI manual: `php artisan transcription:retry-batch-upstream`.

#### Scenario: El header de la tabla no contiene el botón muerto
- **WHEN** el operador carga la pestaña Storages
- **THEN** el header de la tabla no contiene ningún formulario con action `/ia/api-transcriptor/retry-batch`; un `POST` a esa ruta sigue respondiendo 404 pero ya no es alcanzable desde la UI

#### Scenario: El cron de recuperación masiva sigue activo
- **WHEN** llega el lunes 04:00 hora local
- **THEN** el schedule `transcription:retry-batch-upstream` corre y su output se anexa a `storage/logs/transcription-retry-batch.log` (comportamiento preexistente, sin cambio)

## ADDED Requirements

### Requirement: Columna Errores por storage desde snapshots

El sistema SHALL renderizar una columna "Errores" en la tabla de storages, alimentada por el campo `error_count` del último `transcription_storage_snapshots` del storage (el mismo payload que ya devuelve `GET /ia/api-transcriptor/storages/{id}/snapshot`, campo `current`). La columna SHALL:

- Mostrar el conteo de `error_count` del día para storages con `transcription_enabled = true`; `N/A` para los inhabilitados.
- Mostrar `0` (o el último valor conocido) con estilo neutral cuando no hay errores; resaltarse en rojo/ámbar cuando `error_count > 0`.
- NO disparar queries ni endpoints adicionales: reutiliza el mismo fetch que la columna "Snapshot transcriptor" ya realiza por fila.

#### Scenario: Storage con 3 errores en su último snapshot
- **WHEN** el último snapshot de Caracol TV (id=2) tiene `error_count = 3`
- **THEN** la fila muestra `3` en la columna Errores resaltado con color de alerta

#### Scenario: Storage sin errores
- **WHEN** el último snapshot de un storage tiene `error_count = 0`
- **THEN** la columna muestra `0` en estilo neutral, sin badge

#### Scenario: Storage recién habilitado sin snapshots
- **WHEN** un storage con `transcription_enabled = true` aún no tiene ninguna fila en `transcription_storage_snapshots` (snapshot corre cada 15 min)
- **THEN** la columna muestra un placeholder neutral (`—`) hasta el primer snapshot, sin errores de JS

## ADDED Requirements

### Requirement: Contador global de Errores hoy en el header de la tabla

El sistema SHALL mostrar en el header del bloque de la tabla Storages un contador "Errores hoy" que sume los `error_count` de los snapshots de los storages habilitados visibles en la página actual de la tabla. El contador SHALL ser textual (cero → neutral; > 0 → color de alerta) y NO SHALL requerir interacción.

#### Scenario: Tres storages con errores suman en el contador
- **WHEN** los snapshots de tres storages habilitados visibles tienen `error_count` 2, 0 y 5
- **THEN** el contador del header muestra `7`

#### Scenario: Sin errores en ninguna fila visible
- **WHEN** todos los storages de la página actual tienen `error_count = 0` o no tienen snapshot
- **THEN** el contador muestra `0` en estilo neutral

#### Scenario: El contador solo suma la página visible
- **WHEN** la tabla está paginada (25/pág.) y los storages con errores viven en otra página
- **THEN** el contador refleja únicamente los errores de la página visible, sin paginar ni fetchear las demás (el dato por storage se carga por fila con el snapshot que ya existe)