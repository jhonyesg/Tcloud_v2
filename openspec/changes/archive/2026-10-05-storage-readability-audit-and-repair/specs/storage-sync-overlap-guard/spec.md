## MODIFIED Requirements

### Requirement: El sincronizado informa de lo que hizo

`syncFolder()` devuelve el listado, no lo ocurrido. Cinco de las salidas de `doSyncFolder()` devuelven el listado de BD **sin haber escaneado** —lock tomado, montaje caído, escaneo no fiable, sync desactivado, ruta desaparecida— y eran indistinguibles de un sync limpio para el llamador.

- `StorageSyncService` SHALL exponer `syncFolderWithReport()` devolviendo `{files, stats}`, con `syncFolder()` delegando en él para no romper a los llamadores existentes.
- `stats.status` SHALL nombrar la salida tomada: `synced`, `locked`, `mount_detached`, `scan_untrusted`, `sync_disabled`, `path_missing`, `path_unreadable`, `unknown_folder`.
- `stats` SHALL incluir `created`, `updated`, `deleted`, `disk_count`, `orphans` y `pruned`.
- `deleted` SHALL contar el **subárbol completo**: borrar una carpeta-día se lleva cientos de filas por cascada, y reportar la fila padre sola engaña.
- La UI SHALL distinguir visualmente «sincronizado» de «no se pudo escanear», y NO SHALL reportar éxito cuando no hubo escaneo.

#### Scenario: El lock está tomado durante un refresco manual

- **WHEN** el cron o un `silentSync` tienen `sync:folder:{storage}:{parent}` y el usuario pulsa «Actualizar»
- **THEN** el camino forzado SHALL esperar hasta 3 segundos por el lock
- **AND** si vence SHALL devolver `status: 'locked'` y decírselo al usuario, no fingir éxito

#### Scenario: Una ruta física no existe pero un ancestro es inaccesible al usuario del servidor

- **WHEN** `realpath()` resuelve a un directorio pero `is_readable()` falla porque el modo excluye al usuario PHP-FPM
- **THEN** SHALL devolver `status: 'path_unreadable'`
- **AND** SHALL devolver el último listado de BD sin escanear disco
- **AND** SHALL NO confundir este caso con `path_missing` (la ruta sí existe en disco, solo no es legible)

> Diferencia operativa: `path_missing` indica que el operador o el sistema borraron el directorio (pérdida real, emergente). `path_unreadable` indica que los permisos del filesystem cambiaron (problema operativo, no destructivo). La UI SHALL mostrar mensajes distintos para que el operador sepa si restaurar un backup o correr un comando de reparación.