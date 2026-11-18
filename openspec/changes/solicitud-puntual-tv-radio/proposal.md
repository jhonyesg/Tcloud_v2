## Why

El cliente no tiene vía para pedir material de un medio que **no** tiene asignado: `user_storages` es hoy el único control de acceso a medios, no existe entrega de material no asignado y tampoco obtener un enlace público de una **franja horaria** concreta. En paralelo, el control de "cuánto pidió cada cliente" se hace a mano, sin registro de qué archivos se entregaron.

## What Changes

- **Catálogo explícito de medios** (tabla `medio`): un medio es el par `(storage_id, subpath)` con nombre, tipo (TV/Radio) y región. Convierte en direccionables los ~84 sub-canales que viven dentro de Emisoras 01/02/03/04 (`RD1/Antioquia/BLU`, `RD6/Casanare/Yopal_Manatial`…), hoy inalcanzables porque no son storages.
- **Botón "Remapear" en administración** (`/admin/medios`): previsualiza y da de alta los sub-canales detectados; comando `medios:remapear` con las mismas validaciones. No escribe a ciegas ni mueve filas de `files`.
- **Módulo cliente "Solicitud Puntual de TV y Radio"** (`/solicitud-puntual`, bajo "Compartidos"): lista **solo** los medios no asignados.
- **Dos modos de entrega**: *franja* (N archivos exactos, N enlaces) y *día completo* (1 carpeta, 1 enlace). Regla de recomendación configurable: por encima de 2 h se sugiere el día.
- **Validación de cobertura** contra la grilla de slots del medio, con paso derivado de los datos (15 min TV, 21 min radio nacional, ~14 min radio regional, 20 min en el árbol NFS).
- **Registro contable**: tablas `solicitud_puntual` y `solicitud_puntual_item` (snapshot de nombre, ruta y bytes) → minutos, bytes y archivos por cliente.
- **Historial**: más reciente → más antiguo, paginado, con buscador y encabezados ordenables.
- **Guard de compartidos generalizado**: `isBackupManaged()` pasa a `isRequestManaged()` y cubre también las solicitudes puntuales en edición, borrado y depuración masiva.
- **Migración de BD**: sí — `medio`, `solicitud_puntual`, `solicitud_puntual_item`.

## Capabilities

### New Capabilities

- `media-catalog`: catálogo explícito de medios, detección de sub-canales por layout, remapeo administrativo y comando de consola.
- `puntual-request-workflow`: listado del complemento no asignado, selección de fecha y franja, validación de cobertura, generación de enlaces, registro contable, historial y revocación.

### Modified Capabilities

- `share-management`: los enlaces gestionados por un flujo de solicitud quedan excluidos de edición, borrado y depuración masiva.
- `share-link-lifecycle`: los enlaces de solicitud puntual tienen vigencia propia (3 días desde la solicitud) y no admiten `expires_at: null`.

## Non-goals

- **No se tocan `files` ni el sync**: el medio es un índice sobre `(storage_id, subpath)`; no se mueven, copian ni duplican filas. Se descarta crear storages por sub-canal (patrón ya rechazado en `solicitud-backup-mis-archivos` por duplicar `files`).
- **No hay tarifas ni facturación automática**: el módulo registra; el cobro es manual.
- **No hay aprobación ni cola**: el enlace se crea al instante.
- **No aparece lo que el usuario ya tiene asignado**: el módulo es solo el complemento.
- **No se extiende la retención**: sigue la ventana del árbol de origen (48–50 días).

## Impact

- **Migraciones**: tablas `medio`, `solicitud_puntual`, `solicitud_puntual_item` (FK `SET NULL` a `files` y `shares`).
- **Modelos**: nuevos `Medio`, `SolicitudPuntual`, `SolicitudPuntualItem`; relaciones inversas en `Share` y `StorageProvider`.
- **Servicios**: `MedioCatalogService`, `PuntualResolver`, `PuntualRequestService`.
- **Rutas**: `Admin\MedioController` (`/admin/medios`, `auth`+`admin`) y `SolicitudPuntualController` (`/solicitud-puntual`, `auth`).
- **Vistas**: `resources/views/solicitud-puntual/index.blade.php` y `resources/views/admin/medios.blade.php`; sidebar en `layouts/app.blade.php`; `config/puntual.php`.
- **Harness nuevo**: `tests/harness_solicitud_puntual.php`; se extienden `harness_route_contract.php` y `harness_shares_module.php`.
- **Riesgo alto**: el cliente accede por enlace público a material de medios que no tiene asignados → exige verificador independiente antes de cerrar.
- **Cierra un hueco preexistente**: `MediaPreviewController::preview()` servía `/media/{id}/preview` sin comprobar permiso. El módulo se apoyaba en que el material no se sirve fuera de sus enlaces, así que se cierra con el mismo permiso que usa `FileController::preview()`.
