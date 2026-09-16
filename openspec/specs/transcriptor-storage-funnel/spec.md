# transcriptor-storage-funnel Specification

## Purpose

Muestra al operador del módulo API Transcriptor conteos en vivo (Pendientes, En curso, Listos) por cada `StorageProvider` con `transcription_enabled = true`, agregados correctamente para padres heredados vía prefijo de `base_path`, para que sea posible detectar omisiones del scanner y del dispatcher sin abrir cada storage individualmente.

## Requirements

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

### Requirement: Storage padre muestra suma agregada de sus descendientes habilitados

El sistema SHALL computar, para cada `StorageProvider` con `transcription_enabled = true`, un conteo que cubra tanto sus archivos directos como los de los `StorageProvider` cuyo `base_path` esté dentro del prefijo del padre y que también tengan `transcription_enabled = true` (alcance heredado por `StorageProvider::resolveInheritedTranscriptionScope`). La fila del padre SHALL mostrar la suma agregada.

Cuando dos o más storages independientes comparten el mismo `base_path` o uno contiene al otro pero el padre tiene `allow_parent_overlap = true`, el sistema SHALL mostrar la suma cruda sin deduplicar, y SHALL añadir un badge `⚠ solapamiento` en la fila del padre para advertir del posible doble conteo.

#### Scenario: Emisoras 1 con tres hijos habilitados muestra suma correcta
- **WHEN** el storage padre `Emisoras 1` (id=1, `base_path=/mnt/emisoras`, `transcription_enabled=true`) tiene tres descendientes habilitados: Caracol TV (id=2, `base_path=/mnt/emisoras/caracol`), RCN TV (id=3, `base_path=/mnt/emisoras/rcn`), Canal 1 (id=4, `base_path=/mnt/emisoras/canal1`), y los conteos por hijo son 8/2/430, 5/0/175, 2/1/20 respectivamente
- **THEN** la fila de Emisoras 1 muestra `Pendientes: 15`, `En curso: 3`, `Listos: 625`

#### Scenario: Padre con allow_parent_overlap true muestra badge de solapamiento
- **WHEN** Emisoras 1 tiene `allow_parent_overlap = true` y existe un storage independiente Blu Radio (id=5, `base_path=/mnt/emisoras/blu`) con `transcription_enabled = true`
- **THEN** la fila de Emisoras 1 muestra la suma cruda incluyendo los conteos de Blu Radio más un badge visible `⚠ solapamiento` junto al nombre

#### Scenario: Hijo aparece dentro del alcance del padre aunque tenga transcription_enabled false
- **WHEN** un descendiente por prefijo de `base_path` tiene `transcription_enabled = false`
- **THEN** ese descendiente NO entra en el conteo agregado del padre (solo entran los habilitados), según `resolveInheritedTranscriptionScope`

### Requirement: Vista jerárquica plegable de padres e hijos

El sistema SHALL renderizar las filas de los storages hijos indentadas debajo de su fila padre cuando se expande el padre. La fila padre SHALL mostrar un control (chevron o triángulo) que el operador puede pulsar para alternar entre expandido y colapsado. El estado (expandido/colapsado) SHALL persistir en `localStorage` del navegador bajo una clave por scope (ej. `transcriptor-storages-expanded:{userId}`) para que se recuerde entre recargas.

#### Scenario: Click en chevron del padre expande las filas hijas
- **WHEN** el operador hace clic en el control de expansión de la fila Emisoras 1 (que está colapsada)
- **THEN** aparecen debajo, con sangría visual (~24px o equivalente Tailwind `pl-6`), las filas de Caracol TV, RCN TV y Canal 1 con sus conteos individuales, y el chevron rota 90° indicando estado expandido

#### Scenario: Estado expandido persiste tras recarga
- **WHEN** el operador expandió Emisoras 1 y luego recarga la página
- **THEN** la fila sigue expandida al renderizar, leyendo el flag de `localStorage`

### Requirement: Columna transcription_priority visible en modo solo-lectura

El sistema SHALL mostrar, en la tabla Storages, una columna adicional con el valor entero actual de `storage_providers.transcription_priority` para cada fila. La columna SHALL renderizarse como un número dentro de un badge neutral (sin color semáforo). SHALL ser solo-lectura: ningún elemento de la celda SHALL ser interactivo ni disparar peticiones.

#### Scenario: Prioridad 10 se muestra como "10" en badge neutro
- **WHEN** el storage Caracol TV tiene `transcription_priority = 10`
- **THEN** la fila muestra el número `10` en la columna Prioridad con estilo de badge gris, sin botones, sin hover effects de edición

#### Scenario: Prioridad default 0 se muestra
- **WHEN** un storage nunca ha sido editado y conserva `transcription_priority = 0` (default de la migración)
- **THEN** la celda muestra `0` con el mismo estilo de badge

### Requirement: Badge de alerta en columna Pendientes cuando hay acumulación o errores

El sistema SHALL añadir un badge `⚠` junto al número de Pendientes cuando se cumpla cualquiera de estas condiciones:

1. El conteo de Pendientes es estrictamente mayor que el umbral configurado en `system_settings.transcriptor_pending_alert_threshold` (default 5).
2. Existen 1 o más filas `transcriptions` con `state = 'error'` o `state = 'dead'` para ese storage con `updated_at` dentro de las últimas 24 horas.

El badge SHALL ser un elemento visual discreto (color ámbar o similar) y SHALL incluir un `title` HTML con la razón textual de la alerta al pasar el cursor.

#### Scenario: 6 pendientes dispara badge porque supera el umbral default
- **WHEN** un storage tiene `Pendientes = 6` y `transcriptor_pending_alert_threshold = 5` (default)
- **THEN** la celda muestra `6 ⚠` y el badge tiene `title="6 pendientes supera el umbral de 5"`

#### Scenario: 0 errores recientes no disparan badge aunque haya muchos pendientes
- **WHEN** un storage tiene `Pendientes = 100` pero cero filas `error` o `dead` con `updated_at >= now() - 24h`
- **THEN** el badge ⚠ aparece ÚNICAMENTE por la condición 1 (umbral), con `title="100 pendientes supera el umbral de 5"`

#### Scenario: Errores recientes disparan badge aunque pendientes sea bajo
- **WHEN** un storage tiene `Pendientes = 2` (bajo el umbral) pero 3 filas con `state = 'error'` y `updated_at` en las últimas 24h
- **THEN** la celda muestra `2 ⚠` con `title="3 errores en las últimas 24h"`

### Requirement: Conteos se cachean con TTL de 60 segundos por scope

El sistema SHALL cachear el resultado de la query agregada de conteos por scope (raíz del árbol heredado) usando `Cache::remember("transcriptor.funnel.scope.{rootId}", 60, ...)`. La clave SHALL incluir el `rootId` del storage para que cada padre con descendientes tenga su propia entrada. Cuando el operador ejecute el toggle de `transcription_enabled` sobre cualquier storage, el sistema SHALL invalidar la entrada de cache del scope afectado usando `Cache::forget`.

#### Scenario: Segunda carga dentro de 60 s sirve conteos cacheados
- **WHEN** el operador recarga `/ia/api-transcriptor` dos veces en menos de 60 segundos
- **THEN** la segunda carga NO ejecuta la query agregada contra PostgreSQL (los logs de Laravel no muestran `select count(*) ... group by`), y los conteos mostrados son idénticos a la primera carga

#### Scenario: Toggle de transcription_enabled invalida cache del scope
- **WHEN** el operador desactiva `transcription_enabled` en Caracol TV (id=2, hijo de Emisoras 1 id=1)
- **THEN** la entrada de cache con clave `transcriptor.funnel.scope.1` se elimina, y la próxima carga de la página ejecuta la query de nuevo

#### Scenario: Cache miss ejecuta exactamente una query agregada
- **WHEN** la página carga por primera vez después de invalidación
- **THEN** se ejecuta una sola query `SELECT files.storage_provider_id, transcriptions.state, count(*) FROM files JOIN transcriptions ON ... GROUP BY files.storage_provider_id, transcriptions.state` (no N+1), y los conteos se reconstruyen para todos los storages del scope en una pasada

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
