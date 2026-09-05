## Why

El módulo de compartidos muestra como activos enlaces que ya expiraron o cuyo recurso físico desapareció. La vista no permite ordenar, filtrar por rangos de fecha ni depurar resultados masivamente, mientras que la API devuelve hashes de contraseñas y tiene un endpoint de detalle sin autorización por propietario. En la instancia actual hay 223 enlaces sin vencimiento, 11 expirados y 45 que apuntan a rutas físicas ausentes.

## What Changes

- Añadir un listado server-side de shares con paginación, búsqueda, filtros por estado, permiso, storage y rangos de fechas, orden ascendente/descendente y contadores.
- Añadir estados de disponibilidad derivados del catálogo de archivos, diferenciando disponible, ausente y desconocido sin duplicar `file_exists` en `shares`.
- Definir una política explícita de expiración para enlaces nuevos, mantener “Nunca” como opción visible y ofrecer depuración segura de expirados o enlaces no disponibles.
- Reemplazar la eliminación masiva basada en múltiples `DELETE` individuales por una operación autorizada, previsualizable y acotada por filtros; nunca eliminar el `File` desde la limpieza de shares.
- Corregir la exposición de `password_hash`, autorizar `GET /shares/{id}` y reparar el flujo HTML de contraseña pública.
- Añadir índices y restricciones necesarias, además de pruebas de API, sincronización, seguridad y UI.

## Capabilities

### New Capabilities

- `share-management`: listado, filtros, ordenamiento, paginación y depuración masiva server-side.
- `share-link-lifecycle`: expiración, revocación, estados operativos y retención de enlaces.
- `share-file-availability`: estado verificable del recurso físico y manejo seguro de filas stale.
- `share-security`: serialización segura, autorización de propietario y acceso público protegido.

### Modified Capabilities

- `shares-tabs-bulk-delete`: sustituir el borrado cliente-a-cliente por selección y operación bulk server-side, conservando feedback y autorización.

## Impact

Afecta `ShareController`, `PublicShareController`, `Share`, `File`, `StorageSyncService`, migraciones PostgreSQL, rutas públicas y `/shares`, `resources/views/shares/index.blade.php`, el modal de shares en `files/index.blade.php` y las pruebas relacionadas. Requiere migración para índices y metadatos de disponibilidad. No modifica usuarios/sesiones ni elimina archivos físicos automáticamente.

## Non-goals

- No cambiar retroactivamente todos los enlaces existentes sin vencimiento sin una acción explícita del administrador.
- No ejecutar comprobaciones físicas masivas en cada request web.
- No borrar registros `files`, transcripciones ni archivos físicos al depurar enlaces.
