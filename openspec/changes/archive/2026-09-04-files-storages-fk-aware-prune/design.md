## Context

Véase `proposal.md` para la motivación. Resumen técnico del estado actual:

- `files` almacena una imagen del disco con `availability_state ∈ {'available','unknown'}`. Hoy `last_verified_at` y `missing_since_at` se escriben pero el frontend no los usa para filtrar el listado.
- `PruneGuard` (`app/app/Services/PruneGuard.php`) es una función pura con 4 reglas. Su contrato `decide(dbCount, diskCount, scanOk, forced): PruneDecision` no conoce cuántas candidatas están enlazadas por FKs.
- `StorageSyncService::doSyncFolder()` (línea 264-294) borra huérfanos sin verificar FKs, apoyándose en `CASCADE` para llevarse el trabajo terminado aguas abajo.
- `StorageProviderController::test()` y `SyncStorage::syncOne()` ya ejecutan la comprobación de accesibilidad (path + `MountGuard::detachedAncestor()`) — la lógica existe, falta el loop y el trigger.
- `STORAGE_SYNC_EXPECTED_MOUNTS` enumera 9 rutas declaradas como montajes externos esperados.

## Goals / Non-Goals

**Goals (nivel diseño):**
- Una sola migración toca DDL; cero cambios a FKs o al índice UNIQUE.
- `PruneGuard` sigue siendo función pura, testeable sin BD ni filesystem.
- El watchdog es idempotente y barato: `storage:health` no debe tocar `files`.
- La reconciliación se serializa por storage (lock) y se paced por carpetas (TTL) para no saturar el server.

**Non-Goals (nivel diseño):**
- No se introduce `inotify` ni cambios de kernel.
- No se añade `deleted_at` a `files` ni se cambia ningún `ON DELETE CASCADE`.
- No se reescribe el listado para paginación cursor-based.
- No se hace reconciliation en cron fijo; vive del evento `is_accessible: 0→1` con lock + TTL.

## Decisions

### D1. `storage_providers.kind` como enum CHECK, no como type

`type` ya existe (`local` / `s3`). Reusar `type` para significar "disco local vs red" mezcló dos conceptos: el backend del storage y la naturaleza del mount. **Decisión**: campo nuevo `kind` (`local` / `external`) con CHECK.

**Alternativas consideradas:**
- (a) `boolean is_network: bool` — confuso cuando hay USB, SSD, etc.
- (b) Ampliar `type` con `local-block` / `local-network` — rompe `StorageProvider::where('type','local')` en cuatro lugares (controllers, commands, blade).
- (c) `kind` (elegida) — aditiva, sin tocar lógica existente.

### D2. `availability_state='missing'` en lugar de nuevo campo `purged_at`

`missing` describe mejor la realidad: la fila existe en BD con su transcripción preservada, pero el archivo físico no se pudo confirmar. Usar un campo nuevo (`purged_at`) duplicaba el sentido de `missing_since_at`.

**Migración:** `ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check` + nuevo CHECK incluyendo `'gone'` (estado terminal para huérfanos confirmados por escaneo fiable, sin FK).

### D3. Regla 5 de PruneGuard: `orphan_linked`, evaluada DESPUÉS de `mass_delete_ratio`

La regla de ratio cuenta todas las candidatas. Si filtra por linkedCount primero, perdemos la señal de "purga masiva sospechosa" (que sigue siendo la regla 3). Por eso la regla 4 (renumerada 5) compara la cuenta `linkedCount` y bifurca el comportamiento: las linked se marcan `missing`, las unlinked se borraban igual.

Firma nueva:
```php
public function decide(
    int $dbCount, int $diskCount, bool $scanOk,
    bool $linkedCount, bool $forced = false
): PruneDecision
```

### D4. Watchdog sin inotify: cron de 5 min + evento explícito

P1=(a): el comando `storage:health` corre cada 5 min vía `Console\Kernel::schedule()`. Hace `is_dir + is_readable + MountGuard::detachedAncestor` por storage. Si detecta `kind='external'` y `is_accessible: 0→1`, despacha `storage:reconcile` como proceso `Process::start()` (no espera) y registra el evento en `Cache` con TTL 1 h para evitar re-dispatch en el siguiente tick.

**Por qué no inotify:** requiere `inotify` PHP extension + permisos, y la detección de mount vs unmount no es trivial en PHP. La latencia de 5 min es aceptable para un caso de uso donde el operador restaura el NFS manualmente.

### D5. Reconciliación paced por carpeta con TTL

P2=(b): `storage:reconcile` itera las carpetas del storage usando `chunkById(50)` (no `get()`) con un `sleep(N)` entre chunks configurable (default 2s). Lock por storage (`sync:storage:{id}`) reusando el patrón existente en `StorageSyncService::fullSync()`. Lock por carpeta (`sync:folder:{id}:{pid}`) dentro del `fullSync()` que ya existe.

**Por qué paced:** 720k filas en Disco_F pueden saturar el `chunkById` si se procesan en 1 s. 2 s entre chunks limita la ráfaga a ~30 carpetas/min y deja aire para el tráfico web.

### D6. Banner reactivo en `files/index.blade.php`

`FileController::index()` añade dos campos al payload:
```php
'storage_accessible' => $storage->is_accessible,
'storage_kind'       => $storage->kind,
```
El frontend los consume en `Alpine.data('fileManager')` y muestra un banner amarillo `bg-amber-50` con el nombre del storage y un mensaje contextual.

**Sin cambio de filtro SQL:** el listado sigue trayendo todas las filas; el banner avisa al usuario. Filtrar requiere tocar la query y el contract JSON.

### D7. `files:prune-unlinked-safe` como comando separado de `files:dedupe`

`files:dedupe` ya cumple su rol (eliminar duplicados). Mezclar la purga de huérfanos vinculados obligaría a revisar su invariante y su monitor. **Decisión**: comando nuevo con `--dry-run`, `--batch-size`, `--max-ratio` (defensa secundaria).

## Risks / Trade-offs

- **[R1] Stale rows de 1,69M colman la BD indefinidamente** → oleada 3 los reduce solo en remontajes reales; oleada 2 los elimina en una pasada. Documentado en tasks.md.
- **[R2] Reconciliación paced alarga hasta horas en storage de 720k** → aceptable: pasa en background, lock no bloquea la UI. Si el operador necesita inmediato, existe `--force-no-pacing` (uso raro).
- **[R3] `is_accessible` puede falsificar positivo si el cron se cae 10 min** → guardado por `MountGuard::detachedAncestor()` (FS-level) + TTL del cache de listado (60s root, 300s hoy). Si `is_accessible=true` pero el scanner recibe `partial` o `untrusted`, `PruneGuard` lo rechaza.
- **[R4] Regla 5 cambia contrato de PruneGuard** → cambio aditivo, default de `linkedCount` en tests viejos = 0 mantiene comportamiento histórico.
- **[R5] Banner cambia UX sin filtro** → documentado en `files-storages-banner-ux` spec; el usuario puede ver filas `unknown` pero con aviso explícito.

## Migration Plan

### Deploy (un solo paso, sin downtime)

```bash
cd /var/www && \
php artisan config:clear && \
php artisan migrate --force && \
php artisan storage:health --once
```

Migración única `2026_09_05_xxxxxx_files_storages_coupling.php`:
1. ADD COLUMN `storage_providers.kind` VARCHAR(16) NOT NULL DEFAULT 'local' CHECK (...);
2. UPDATE `storage_providers` SET kind='external' WHERE base_path IN (STORAGE_SYNC_EXPECTED_MOUNTS);
3. DROP/ADD CHECK de `files.availability_state` para incluir `'missing'` y `'gone'`;
4. CREATE INDEX parcial `idx_files_missing_reconcile` (storage_provider_id, availability_state) WHERE state IN ('unknown','missing','gone').

### Schedules (en `app/app/Console/Kernel.php`)

```php
$schedule->command('storage:health')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);   // TTL explícito, memoria del 2026-07-27

$schedule->command('storage:sync --all')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14);
```

`storage:reconcile` no se programa: se dispara on-demand desde `StorageProviderController::test()` cuando detecta transición 0→1 en `kind='external'`.

### Rollback

```bash
php artisan migrate:rollback --step=1   # única migración
```

Reversibilidad: CHECKs caen a su versión previa (unknown + available), columna `kind` se borra, índice se borra. Ninguna fila de `files` se ve afectada.

## Open Questions

- Si el operador quiere forzar reconciliación inmediata sin esperar 5 min: ¿botón "Re-verificar" en admin/storages llama directo a `storage:reconcile --storage=X --force-no-pacing`? Sí (decidido en tasks.md).
- ¿Backfill de 1,36 M huérfanos en ventana de mantenimiento o por storage durante el día? Por storage, en horario valle, con `--batch-size=500` y monitor de `storage_sync.orphan_linked`.
