# Why

Tras `restore-mis-archivos-august` (código llevado a `bb71b9c`, migration DROP aplicada) el operador reporta dos síntomas que el revert de código **no** resolvió porque son **datos corruptos persistidos** que solo se purgan con comandos de limpieza:

1. **Carpetas fantasma** en la raíz de `storage_provider_id=5` ("00 Discos"): 29 carpetas con `file_modified_at IS NULL` (jamás pasaron por `scandir`) + nombres DDMMYYYY (`01092026`, `02092026`...) duplicados con sus versiones legítimas profundas, + nombres-recorte (`bo-lite`, `nspec`, `hanges`, `anges`, `les`, `owser_profiles`, `atic`, `l_conf`, `gin-and-access-management`, `e-backend-to-flask-docker`). El `ls` del disco real (`/www/wwwroot/data.mediaserver.com.co/Tcloud`) solo contiene `Aplicaciones, Disco_A..Disco_I, dockers` — el resto NO existe en disco pero aparece en la UI.
2. **Visibilidad cruzada de personales**: `user_storages` tiene a `jsuarez` (user 1) con `full` en `Personal - StakeholdersPrensa` (storage 179), que pertenece a `StakeholdersPrensa` (user 10, ausente). El flag `is_personal` se calcula en `FileController::storages()` pero no se filtra, así que un admin con `full`权 ve TODOS los personales ajenos.

El operador quiere "limpiar esos registros antiguos y que Chrome + botón Actualizar funcionen correctamente" sin más cambios de código.

# What Changes

- **Comando `files:purge-ghost-folders`** (`--dry-run` por defecto, `--apply`, `--storage=`, `--yes`): borra de `files` las carpetas cuyo `path` no exista en el disco de su `StorageProvider` Y tengan `file_modified_at IS NULL`. Con checkpoint `CREATE TABLE files_ghost_pre_purge_<ts> AS SELECT ...` antes del DELETE. NO toca archivos (`is_folder=false`). NO toca carpetas con `file_modified_at` (vienen del sync legítimo).
- **Comando `user-storages:fix-personal-visibility`** (`--dry-run` por defecto, `--apply`, `--user=`, `--yes`): para cada `storage_provider` con `is_personal=true`, conserva **solo** la fila del usuario canónico (último segmento de `base_path` `/home/www/Usuarios_tcloud/<username>/`) y elimina las demás. Reporta diff antes de aplicar.
- **Filtro de visibilidad** en `FileController::storages()`: si `storage.is_personal=true`, el endpoint `GET /user/storages` solo devuelve ese storage cuando el usuario solicitante es el dueño canónico (o admin). Cambio de 8 líneas, sin migración.

**BREAKING**: para el admin `jsuarez` (user 1), el storage 179 deja de aparecer en su listado Mis Archivos. Si lo necesita legítimo, se le asigna por `users:assign-storage` con `--reason` documentado.

# Non-goals

- NO se modifica `StorageSyncService::createFileFromScan` línea 395 (el patrón `?? 1`). El operador confirma "actualmente funciona bien" tras el restore; los fantasmas son residuales, no se siguen generando. Si reaparecen, propuesta futura.
- NO se hace cleanup de archivos `.mp4` huérfanos ya linkeados (con `file_modified_at`).
- NO se mueven archivos entre storages; la jerarquía de carpetas legítimas queda intacta.

# Capabilities

### New Capabilities

- `mis-archivos-data-purge`: comandos de limpieza de datos corruptos post-restore (carpetas fantasma en `files`, accesos personales cruzados en `user_storages`). Cubre flujo de auditoría (`--dry-run`), aplicación con checkpoint de respaldo, y rollback desde la tabla snapshot.

### Modified Capabilities

- `mis-archivos-storage-visibility`: el listado del usuario en Mis Archivos filtra los `is_personal` para que cada usuario solo vea su propio personal (admin ve todos). Antes se mostraban todos los `user_storages` del usuario, incluidos personales ajenos con `full`.

# Impact

- 2 comandos nuevos: `app/app/Console/Commands/PurgeGhostFoldersCommand.php`, `app/app/Console/Commands/FixPersonalVisibilityCommand.php`.
- 1 cambio en `FileController::storages()` (`is_personal` filter, 8 líneas).
- 0 migraciones (data-only: las filas se respaldan vía `CREATE TABLE AS SELECT` antes del DELETE).
- 0 cambios a `StorageSyncService`, `FileRegistry`, `FileScannerService` (código de `bb71b9c` intacto).
- Reversibilidad total: la tabla `files_ghost_pre_purge_<ts>` permite re-insertar; `user_storages` borrados se restauran con `INSERT ... SELECT` desde la misma.
- Rutas afectadas: `GET /user/storages` (1 endpoint).
- Riesgos: (R1) si una carpeta legítima fue creada por el operador a mano y olvidó refrescar el `file_modified_at`, será borrada → mitigación: el `is_personal=false` de `00 Discos` (storage 5) y el log de filas borradas permiten auditoría post-fix. (R2) si `base_path` de un personal cambió y el dueño canónico calculado difiere del dueño real, el comando borraría al equivocado → mitigación: `--dry-run` muestra diff antes de aplicar.

# Affected areas

- `app/app/Console/Commands/` (2 archivos nuevos)
- `app/app/Http/Controllers/FileController.php` (1 método, `storages()`)
- `app/app/Models/StorageProvider.php` (helper para calcular dueño canónico — usado por `FixPersonalVisibilityCommand` y por el filtro del controller)
