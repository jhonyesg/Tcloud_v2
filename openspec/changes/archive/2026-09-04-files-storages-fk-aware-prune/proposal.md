## Why

Files y Storages almacenan una imagen del disco que puede divergir del estado real cuando un NFS se cae y remonta. Hoy esa divergencia se manifiesta de dos formas: (1) el listado de `Mis Archivos` muestra filas en `availability_state='unknown'` mientras el usuario navega — sin告诉他 que el disco no responde — y (2) cuando llega el momento de purgar, `PruneGuard` puede borrar filas que tienen transcripciones/shares vinculados, llevándose trabajo terminado en cascada (`transcriptions.file_id ON DELETE CASCADE`).

El incidente del 2026-07-27 ya endureció la purga con cuatro reglas (`storage-sync-overlap-guard`), pero la quinta regla falta: **nunca borrar una fila si está enlazada por FKs aguas abajo**. Esa es la grieta que esta propuesta cierra.

## What Changes

- **Nuevo campo** `storage_providers.kind` (`local` / `external`) con backfill desde `STORAGE_SYNC_EXPECTED_MOUNTS`.
- **Nuevo** valor `availability_state='missing'` en `files` (CHECK ampliado) para marcar filas enlazadas que el scanner confirmó como desaparecidas.
- **`PruneGuard` recibe `linkedCount`** y añade la regla 5 (`orphan_linked`): las candidatas con transcripciones/shares/`media_edit_jobs` se marcan `missing` en vez de borrarse. Función pura, mismo estilo que las cuatro reglas existentes.
- **`StorageSyncService::doSyncFolder()`** respeta la nueva regla y respeta `kind='external'` al remontar (fuerza escaneo completo).
- **Nuevo comando `storage:health`**: cada 5 min prueba `base_path` + `MountGuard` y actualiza `is_accessible` + `last_checked_at`. Idempotente, sin BD pesada, con lock propio.
- **Nuevo comando `storage:reconcile`**: largo, con TTL entre carpetas, lock por storage, dispara `fullSync` con `--force` cuando un storage `external` pasa de `is_accessible=false` a `true`.
- **`FileController::index()`** inyecta `storage_accessible` + `storage_kind` en la respuesta JSON.
- **Banner** en `files/index.blade.php` si el storage activo está caído. Banner en `admin/storages.blade.php` para el estado `kind`.
- **Nuevo `files:prune-unlinked-safe`** con `--dry-run` y `--batch-size` para las tres oleadas de limpieza (1,36 M de huérfanos seguros).

## Capabilities

### New Capabilities

- `storage-health-and-reconcile`: watchdog de accesibilidad + reconciliación al remontar, sin sobrecargar el server (cron 5 min, reconcile por carpeta con TTL).
- `files-storages-banner-ux`: módulo Files lee `is_accessible` + `kind` del storage activo y comunica honestamente el estado al usuario.
- `fk-aware-prune`: `PruneGuard` con regla 5 (`orphan_linked`); `files.availability_state='missing'` + `missing_since_at` para filas enlazadas que no pueden borrarse.

### Modified Capabilities

- `storage-sync-overlap-guard`: añade el requisito "nunca purgar filas enlazadas por FKs aguas abajo"; la guarda `mass_delete_ratio` se reespecifica para contar solo candidatas no-enlazadas.

## Impact

- **Migración (1)**: `2026_09_05_xxxxxx_files_storages_coupling.php` — `storage_providers.kind` + CHECK, ampliar `files.availability_state` CHECK, índice parcial sobre estados terminales.
- **Código modificado (5)**: `PruneGuard.php`, `StorageSyncService.php`, `FileController.php`, `files/index.blade.php`, `admin/storages.blade.php`.
- **Código nuevo (3)**: comandos `storage:health` y `storage:reconcile`, comando `files:prune-unlinked-safe`.
- **Sin cambios**: `ScanResult`, `MountGuard`, `FileRegistry`, índices UNIQUE, FK CASCADE, módulo api-transcriptor, `files:dedupe`.
- **Rutas afectadas**: `GET /files` (payload ampliado), `POST /admin/storages/{id}/test` (dispara reconcile si external), `GET /admin/storages` (muestra `kind`).
- **Schedules**: `storage:health` cada 5 min, `storage:sync --all` cada 15 min (ya existe), `storage:reconcile` por evento (no schedule fijo).
- **Compatibilidad**: cero rupturas hacia atrás; todos los contratos JSON añaden campos sin retirar existentes.

## Non-goals

- No se añade soft-delete a `files` (memoria del proyecto lo prohíbe).
- No se cambia ningún `ON DELETE CASCADE` (la salvaguarda vive en PruneGuard, no en DDL).
- No se hace reconciliation automática intensiva (P2=b: por carpeta, con TTL, sin saturar).
- No se migra la consulta de listado para soportar búsqueda distribuida.
- No se introduce inotify/P1=b (queda como mejora futura opcional).
- No se modifica la UI del módulo api-transcriptor: sus tablas quedan protegidas vía regla 5.
