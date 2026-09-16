# Change: Impedir y limpiar la duplicación de filas en `files`

## Why

Un servidor NFS se cayó y se reinició. Al volver, la UI mostraba cada carpeta repetida tres veces. La tabla `files` tenía **70.804 filas duplicadas** por `(storage_provider_id, path)` — hasta **36 copias** del mismo `.mp4` — y seguía creciendo. El disco tenía 17 carpetas donde la BD tenía 51.

Cadena causal, cada eslabón verificado en código y en datos:

1. **Se eliminó la única protección.** `2026_05_21_000002_cleanup_and_add_indexes.php:14` hace `DROP INDEX files_path_storage_provider_id_unique` con el comentario *"167MB unused index: 0 scans recorded"*. El razonamiento fue erróneo: ninguna consulta lo usaba para leer, pero era lo único que garantizaba la corrección. El `down()` lo recrea, el `up()` no.

2. **Un NFS caído es indistinguible de una carpeta vacía.** `FileScannerService::scanDirectory()` devolvía `[]` en cuatro modos de fallo y también para un directorio realmente vacío. Cuando un montaje cae, el punto de montaje sigue siendo un directorio local vacío y legible: `is_dir()` e `is_readable()` pasan.

3. **El escaneo vacío se leía como "borraron todo".** `StorageSyncService:87-94` recorría las filas no vistas en disco y llamaba a `deleteRecursively()`. Sin *soft deletes* y con `files_parent_id_fkey ON DELETE CASCADE`, un parpadeo de NFS **borraba el árbol entero**.

4. **El borrado disparaba una estampida.** `FileController:159-170` lanza `syncFolder()` **síncrono dentro de la petición web** cuando la carpeta está vacía en BD. Tras el borrado, cada carga de página de cada usuario disparaba un escaneo concurrente. Prueba: las 3 carpetas duplicadas del storage 134 se crearon **en el mismo segundo**.

5. **`fullSync()` multiplicaba.** Iteraba todas las filas de carpeta, duplicados incluidos; cada copia resolvía al mismo directorio físico y recreaba el subárbol bajo cada una. De ahí las 36 copias, creadas en una sola ejecución.

Tres identidades distintas convivían sin respaldo en BD: sync usaba `(storage, parent_id, path)`, `DiskScannerService` `(storage, path)`, `FileController::store()` `(parent_id, name, storage)`.

## What Changes

- **Escrituras idempotentes**: nuevo `App\Services\FileRegistry` con `ensure()`, identidad canónica `(storage_provider_id, path)`. Una carrera pierde con elegancia (captura `SQLSTATE 23505` y relee al ganador) en vez de duplicar. Convergen a él `StorageSyncService`, `DiskScannerService` y `FileController`.
- **Serialización**: `Cache::lock()` por carpeta dentro de `syncFolder()` y por storage en `fullSync()` — así lo heredan los cinco puntos de entrada. Jerarquía estricta storage → carpeta, sin deadlock.
- **Blindaje NFS**: nuevo `ScanResult` (`ok` / `failed(reason)`), `PruneGuard` (nunca purgar con escaneo no fiable, ni con disco vacío y filas en BD, ni si se borraría >34%), `MountGuard` (detecta montaje caído comparando dispositivo con el del padre).
- **Fin del amplificador**: `groupBy('path')` en vez de `keyBy('path')`, que colapsaba los duplicados y los ocultaba a la purga; y `$seenPaths` en `fullSync()`.
- **Limpieza**: nuevo comando `files:dedupe` que re-parenta antes de borrar y aborta si algún superviviente cuelga de un padre condenado.
- **Índice único restaurado** con `CREATE UNIQUE INDEX CONCURRENTLY` y manejo central del 23505 en `bootstrap/app.php`.
- **Interruptor** `storage_sync.enabled` y `withoutOverlapping(30)` en el schedule.

## Non-goals

- Reescribir el descubrimiento en disco: solo se le inyectan las guardas.
- Soft deletes en `File`: fuera de alcance; las guardas de purga cubren el riesgo real.
- Recuperar los subárboles borrados durante el incidente: el sync los vuelve a descubrir.
- Cambiar el layout de storages ni su solapamiento físico.

## Impact

- **Specs**: `storage-sync-overlap-guard` (modificada — su escenario 3 afirmaba algo falso sobre el TTL del lock).
- **Migrations**: una, `2026_07_27_150000_restore_files_storage_path_unique_index.php`, con `$withinTransaction = false` porque `CONCURRENTLY` no admite transacción. Aborta si quedan duplicados.
- **Código**: `StorageSyncService`, `FileScannerService`, `FileController`, `DiskScannerService`, `ApiTranscriptorController`, `SyncStorage`, `routes/console.php`, `bootstrap/app.php`. Nuevos: `FileRegistry`, `ScanResult`, `PruneGuard`, `PruneDecision`, `MountGuard`, `Dedupe\DedupePlanner`, `Dedupe\DedupePlan`, `DedupeFiles`, `config/storage_sync.php`.
- **Borrado**: `app/debug_sync55b.php`, script residual que insertaba filas por reflexión sin guardas.
- **Datos**: 70.804 filas eliminadas, 31.499 re-parentadas, 8 transcripciones redundantes descartadas (el superviviente ya tenía la suya, mismos segmentos).
- **Rollback**: el índice tiene `down()` limpio. El de-duplicado **no es reversible**; el `pg_dump` previo es la única vuelta atrás.
