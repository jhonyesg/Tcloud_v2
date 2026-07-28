# Tasks: Impedir y limpiar la duplicación de filas en `files`

> Implementado y verificado en producción el 2026-07-27. El orden no era negociable: parar la
> hemorragia antes de limpiar, o la limpieza se habría vuelto a duplicar.

## Fase 0 — Freno inmediato

- [x] Crear `app/config/storage_sync.php` con el interruptor `enabled`, los umbrales de purga, los TTL de lock y los montajes esperados.
- [x] Borrar `app/debug_sync55b.php`: script residual en la raíz que llamaba al privado `createFileFromScan()` por reflexión e insertaba sin ninguna guarda.
- [x] `routes/console.php`: `withoutOverlapping()` → `withoutOverlapping(30)`. El TTL por defecto es de **1.440 minutos**; un `SIGKILL` durante un cuelgue de NFS dejaba el lock muerto y paraba todos los syncs 24 h en silencio.

## Fase 1 — Escrituras idempotentes y serializadas

- [x] Crear `app/app/Services/FileRegistry.php` con `ensure($storage, $path, $attrs)`: busca por la clave canónica `(storage_provider_id, path)`, sana el `parent_id` si difiere, y ante `SQLSTATE 23505` relee al ganador en vez de duplicar.
- [x] Enrutar por él `StorageSyncService::createFileFromScan()` y los dos `File::create` de `DiskScannerService` (`:134` y `resolveParentId():493`, este último el escritor más frecuente: cada 2 min desde `transcription:tick`).
- [x] Lock `Cache::lock()` **no bloqueante** por carpeta dentro de `syncFolder()`, no en los llamadores, para que los cinco puntos de entrada lo hereden.
- [x] Lock por storage en `fullSync()`. Jerarquía estricta storage → carpeta: sin deadlock.
- [x] Matar el amplificador: `groupBy('path')` en vez de `keyBy('path')` (que colapsaba los duplicados y los ocultaba a la purga), `$seenPaths` y `chunkById(500)` en `fullSync()`.
- [x] `ApiTranscriptorController::syncStorage()` devuelve **409** si ya hay un sync en curso, y **423** si el sincronizado está desactivado.
- [x] Freno del auto-escaneo web con `Cache::add()` (`autoscan_backoff`, 60 s), mismo patrón que ya usaba `PublicShareController`.

## Fase 2 — Blindaje NFS

- [x] Crear `app/app/Services/ScanResult.php`: `ok(entries)` / `failed(reason)` con seis motivos. Elegido sobre una excepción porque la señal de "no fiable" debe viajar junto a las entradas para alimentar `PruneGuard`.
- [x] `FileScannerService::scanDirectory()` devuelve `ScanResult` en vez de `array`, con `clearstatcache()` al entrar (NFS cachea atributos).
- [x] Crear `PruneGuard` + `PruneDecision`: cuatro reglas, con `scan_untrusted` inmune a `--force-prune`.
- [x] `StorageSyncService` no purga nada si el escaneo no es fiable; registra `prune_refused` con contexto.
- [x] Crear `MountGuard`: parseo de `/proc/self/mounts` y detección por comparación de dispositivo con el padre. Marca `storage_providers.is_accessible = false` al detectar caída.
- [x] `SyncStorage::syncOne()` consulta a `MountGuard`: `is_dir` + `is_readable` **pasan** con un montaje caído.
- [x] Añadir `--force-prune` a `storage:sync` como escape del operador.
- [x] **Operador**: declarar los 5 montajes NFS reales (`Disco_D` … `Disco_H`) en `STORAGE_SYNC_EXPECTED_MOUNTS`. Verificado: los 5 se detectan activos.

## Fase 3 — De-duplicado

- [x] Extraer el algoritmo a `Services/Dedupe/DedupePlanner.php` + `DedupePlan.php` sobre arrays planos, para poder probarlo sin BD.
- [x] Crear `Console/Commands/DedupeFiles.php` (`files:dedupe`) con `--dry-run`, `--storage`, `--chunk`, `--keep-map`, `--yes`.
- [x] `SET max_parallel_workers_per_gather = 0`: el contenedor de Postgres tiene `/dev/shm` de 64 MB y las agregaciones sobre 1 M de filas morían con *"could not resize shared memory segment"*.
- [x] Reafirmar el invariante `path = parent.path || '/' || name` y abortar si no se cumple.
- [x] Superviviente = menor id **prefiriendo el que ya tenga transcripción**, para no descartar trabajo de GPU.
- [x] Transcripciones: **fusionar, no repuntar** — `transcriptions_file_id_unique` es UNIQUE y un `UPDATE ... SET file_id` fallaría con 23505.
- [x] Re-parentar **antes** de borrar, y aserción de seguridad de que ningún superviviente cuelga de un padre condenado.
- [x] Invalidar `folder_gen` de las carpetas afectadas, recogidas **antes** del borrado.
- [x] `pg_dump` de `files`, `transcriptions`, `transcription_segments`, `shares`, `media_edit_jobs` (742 MB, integridad verificada contra el recuento de la BD).
- [x] Ventana de mantenimiento: `STORAGE_SYNC_ENABLED=false` y `scope=unbounded` para parar también el descubrimiento de `transcription:tick`.
- [x] Dry-run revisado y ejecución: **70.804 filas borradas, 31.499 re-parentadas**, 0 errores.

## Fase 4 — Índice único

- [x] Migración `2026_07_27_150000_restore_files_storage_path_unique_index.php` con `$withinTransaction = false` (`CONCURRENTLY` no admite transacción), verificación de `indisvalid` y aborto si quedan duplicados.
- [x] Manejo central del `23505` en el `withExceptions()` de `bootstrap/app.php` → **409** en vez de 500.

## Fase 5 — Tests

- [x] `FileScannerServiceTest` — el más valioso: fija que **vacío ≠ ilegible**, que es exactamente el bug.
- [x] `PruneGuardTest` — las cuatro reglas, el bypass, y una reconstrucción del incidente del 27-jul.
- [x] `DedupePlannerTest` — elección de superviviente, la propiedad de seguridad frente al CASCADE, fusión de subárboles en una pasada, e idempotencia.
- [x] `MountGuardTest` — parseo de `/proc/mounts` contra texto de ejemplo, detección por dispositivo.
- [x] `ScanResultTest` — `isTrustworthyEmpty()` solo con `ok=true` y sin entradas.

## Verificación

- [x] V1 duplicados: **70.804 → 0**
- [x] V2 sin duplicados nuevos: 3 syncs concurrentes sobre el mismo storage → 1 hizo trabajo (0,55 s), 2 salieron en 0,01 s por el lock, **delta 0 filas**
- [x] V3 contra disco: storage 134 nivel raíz **51 → 17** = las 17 carpetas reales
- [x] V4 integridad del árbol: rutas incoherentes 0, huérfanos 0, raíces con `/` 0
- [x] V5 referencias colgando: transcripciones 0, comparticiones 0, `media_edit_jobs` 0
- [x] V6 índice: `indisvalid = true`, `indisunique = true`
- [x] V7 la restricción muerde: `INSERT` duplicado dentro de `BEGIN`/`ROLLBACK` → falla con 23505
- [x] Vigilancia de 1 hora con sync y tick reactivados: **cero alertas**, duplicados en 0
- [ ] **Soak pendiente**: repetir la consulta de duplicados a diario durante una semana y vigilar `prune_refused`, `scan_untrusted` y `mount_detached` en el log
