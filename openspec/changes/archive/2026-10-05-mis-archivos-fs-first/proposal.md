## Why

El listado de carpetas en Mis Archivos consulta hoy la tabla `files`, que se llena vía `StorageSyncService` escaneando el filesystem con cadencia de cron (5-15 min). El cliente percibe latencia perceptible al navegar y, peor, ve estado stale: si un sync no ha corrido todavía, los archivos recién creados no aparecen; si el sync escribió filas malas, persisten hasta el próximo pase. El operador también reporta constantes falsos reportes cuando el breadcrumb muestra carpetas duplicadas por races en el sync (mitigado por el commit baseline `39961b3` pero la causa raíz — la BD como cache vivo del filesystem — sigue).

El modelo AA Panel (File Manager de hostings) demuestra un patrón alternativo válido: el filesystem ES la fuente de verdad para "qué hay en esta carpeta", y la BD solo guarda metadata con valor operativo (shares, permisos, transcripciones, auditoría). Esto elimina:
- Latencia perceptible (scandir es O(n) sobre entradas visibles, ~1ms NFS local, ~20ms NFS remoto).
- Estado stale: lo que ves es lo que está en disco ahora.
- Complejidad del sync pasivo (`StorageSyncService::syncFolder`, `PruneGuard`, `FileRegistry`, mirror logic). El sync se reduce a un matcher de metadata que reconcilia BD↔filesystem por nombre/ruta.
- Duplicación cross-storage (storage 5 vs sub-storages 134/6/7/8): el filesystem es uno solo, no hay forma de "duplicar" una ruta en disco.

Alineado con las decisiones de proyecto vigentes:
- `mis_archivos_soberania_sobre_archivos` (Mis Archivos soberano, API Transcriptor solo lee de BD).
- `modulos_soberanos_archivos_persiste_transcriptor_consulta` (Mis Archivos persiste, no al revés).
- `mis_archivos_no_cache_requisito` (cachear es contraproducente cuando el origen cambia rápido).
- `archivos_infra_no_duplicidad_intencion` (anti-duplicidad por diseño).

## What Changes

### Lectura primaria desde filesystem (carpeta, breadcrumb, búsqueda por nombre)

`Mis Archivos` SHALL listar el contenido de una carpeta invocando `scandir()` + `stat()` sobre el `base_path` del storage + la sub-ruta del breadcrumb, en vez de `SELECT … FROM files`. Los datos devueltos incluyen nombre, tamaño, mtime, `is_folder` y la ruta absoluta en disco. El `id` (file_id) se incluye solo cuando existe fila en BD (resuelta por `(storage_provider_id, path)`); cuando NO existe, `id=null` y se reporta `source: 'filesystem'`.

### Metadata en segundo plano

Un job periódico `MatchFilesystemToDatabaseJob` SHALL reconciliar por storage: para cada ruta en disco, busca fila por `(storage_provider_id, path)`; si no existe, la crea con `owner_id` desde `user_storages` y `mime_type` desde extensión; si existe, actualiza `size` y `file_modified_at` si difieren; las filas en BD sin contraparte en disco se marcan `pending_deletion=true` y se purgan tras una ventana de gracia (default 7 días) configurable vía `SystemSetting('mis_archivos.missing_grace_days')`.

### Switch gradual con feature flag

Toda la ruta nueva SHALL estar detrás de `SystemSetting('mis_archivos.fs_primary_enabled')` (default `false`). Cuando está en `false`, el comportamiento actual (BD-primero) sigue intacto. Cuando está en `true`, el listado usa `FilesystemListingService`. El matcher corre siempre, manteniendo la BD caliente para cuando se apague el flag.

### Capabilities nuevas

- `mis-archivos-filesystem-listing`: lectura directa desde filesystem para listado, breadcrumb y búsqueda por nombre. Mantiene shape compatible con la respuesta AJAX actual más `source: 'filesystem' | 'database'` y `file_id: int|null`.
- `mis-archivos-fs-db-matcher`: job periódico que reconcilia filesystem↔BD por nombre y ruta. Idempotente y rate-limited por `SystemSetting('mis_archivos.matcher_rows_per_minute')`.
- `mis-archivos-permission-aware-listing`: el listado SHALL filtrar por permisos de `user_storages` antes de renderizar (no exponer nombres de archivos en storages a los que el usuario no tiene acceso). Hoy se filtra a nivel BD query; mañana se filtra contra la lista de `storage_provider_id` permitidos.

### Capabilities modificadas

- `file-upload-ux`: el upload sigue creando la fila en BD vía `FileRegistry`, pero el listado inmediato después del upload usa filesystem (no requiere re-sync).
- `share-file-download`: el share se resuelve por `(storage_provider_id, path)` antes de servir. Si el path tiene file_id, se usa; si no, se resuelve y se cachea 60 s.
- `storage-health-and-reconcile`: el comando `storages:health-check` ahora también verifica que `FilesystemListingService::list()` devuelve la misma cantidad de carpetas raíz que el listado BD para diagnosticar drift.

### Removed Capabilities

- `files-folder-tree-integrity-guard` (la columna `parent_id` se vuelve opcional, los ciclos parent_id ya no se materializan porque el breadcrumb se arma por dirname del path, no por CTE sobre `parent_id`). Queda el servicio `FileBreadcrumbIntegrityService` por si el modo BD-only sigue activo durante la transición.
- `storage-sync-parent-id-validator` (sin ciclos cuando la lectura es del filesystem; el validator queda en modo pasivo).
- `files-search`-BD-mode: el cuadro de búsqueda SHALL buscar primero en filesystem (`shell glob` o `find -name`), y solo si el usuario lo pide, extender a BD.

## Impact

**Código afectado**:
- `app/app/Services/MisArchivos/FilesystemListingService.php` (nuevo, ~250 líneas).
- `app/app/Services/MisArchivos/FilesystemPermissionGuard.php` (nuevo, ~80 líneas).
- `app/app/Services/MisArchivos/FilesystemDbMatcher.php` (nuevo, ~200 líneas).
- `app/app/Console/Commands/MatchFilesystemToDatabaseCommand.php` (nuevo, ~150 líneas).
- `app/app/Console/Commands/MisArchivosBenchmarkCommand.php` (nuevo, ~120 líneas).
- `app/app/Jobs/MatchFilesystemToDatabaseJob.php` (nuevo, ~80 líneas).
- `app/app/Http/Controllers/FileController.php` (modificado ~120 líneas: index, search, breadcrumbs).
- `app/app/Http/Controllers/ShareController.php` (modificado ~30 líneas: resolve por path si file_id es null).
- `app/resources/views/files/index.blade.php` (modificado ~15 líneas: mostrar badge "FS" cuando `source === 'filesystem'`).
- `app/config/mis_archivos.php` (nuevo).
- 2 specs OpenSpec modificadas, 3 nuevas.

**Schema**:
- Nueva columna `files.pending_deletion_at TIMESTAMPTZ NULL` (nullable, default NULL). Migration reversible.
- Índice `(storage_provider_id, path)` ya existe y se reusa (era `files_storage_provider_id_path_unique`).
- NO se elimina `parent_id` ni `is_folder` ni nada del schema actual. El rollback es completo.

**Riesgos**:
- (R1) Latencia NFS remota en carpetas grandes: scandir de 5000 archivos en NFS WAN puede tomar 1-3 s. Mitigación: paginación client-side con `limit=500` server-side por defecto, configurable vía `SystemSetting('mis_archivos.listing_limit')`.
- (R2) El matcher se atrasa: archivos recién creados tardan minutos en tener `file_id`, así que los shares sobre ellos no resuelven inmediato. Mitigación: el resolver de shares cae a filesystem directo si `file_id IS NULL`, manteniendo la UX.
- (R3) Búsqueda por nombre: `shell glob` no escala para 1M+ archivos. Mitigación: el matcher mantiene un índice trigram en BD que se consulta en paralelo (parallel query: filesystem para resultados recientes, BD para full-text).
- (R4) Cambio masivo: afecta todas las rutas. Mitigación: feature flag + canary (activar solo en storage 134 primero, monitor 48h, expandir).
- (R5) Permisos: si el listado filesystem no respeta `user_storages`, expone archivos que el usuario no debería ver. Mitigación: `FilesystemPermissionGuard::filter()` se invoca antes de devolver el listado.

**Rollback**: `SystemSetting::set('mis_archivos.fs_primary_enabled', '0')` revierte al modo BD-primero. Sin deploy. La BD sigue sincronizada por el matcher, así que la UX del modo viejo es idéntica a la de antes del commit baseline.

**APIs externas**: ninguna breaking change en JSON response (campos nuevos opcionales).

**Capacidades removidas**: 1 (`files-folder-tree-integrity-guard` queda en modo pasivo, no se borra el código por 1 ciclo). **Capabilities nuevas**: 3.
