# Design — `mis-archivos-depurar-registros-fantasma`

## Context

El cambio responde a la propuesta: el revert de código del baseline de agosto (task 5.6 de `restore-mis-archivos-august`) ya está aplicado en `bb71b9c`, pero dos clases de datos corruptos persisten en BD:

1. **Carpetas fantasma en `files`**: 29 filas con `parent_id IS NULL` y `file_modified_at IS NULL` en `storage_provider_id=5` (00 Discos), creadas en ráfagas los días 2026-09-17 09:30, 14:18 y 15:26-15:37. Disparan el listado de Mis Archivos con nombres que no existen en disco y disparan exploraciones que muestran basura cuando se entra al directorio raíz.
2. **`user_storages` con `permissions='full'` entre usuarios no relacionados y un storage personal ajeno** (caso storage 179: aparecen `Stakeholders` y `jsuarez`; ausente `StakeholdersPrensa` que es el dueño del segmento de path). El endpoint `GET /user/storages` solo respeta `userStorages()` del usuario y no filtra por `is_personal` ni por dueño canónico.

El sync ya está sano tras el revert (`bb71b9c` confirmado por el operador en su último mensaje), por lo que la intervención es data-only + un filtro de visibilidad.

## Goals / Non-Goals

**Goals:**
- Purga segura e idempotente de filas corruptas con respaldo automático en tabla snapshot.
- Visibilidad correcta de `is_personal` en Mis Archivos sin tocar el sync ni mover archivos.
- Reversibilidad total vía la tabla snapshot y un `--dry-run` por defecto.
- 0 migraciones: data-only + un handler de visibilidad.

**Non-Goals:**
- Reescribir `StorageSyncService::createFileFromScan` (línea 395, patrón prohibido). El revert lo dejó funcional y los fantasmas son residuales.
- Reasignar archivos por dueño canónico (`owner_id`).
- Auditar storages no personales.

## Decisions

### D1 — Detección de carpeta fantasma por `file_modified_at IS NULL` + inexistencia en disco

El criterio se apoya en dos propiedades simultáneas de las filas residuales:
- `file_modified_at` siempre lo escribe `StorageSyncService::createFileFromScan` y `FileController::store` como Carbon del mtime del directorio; las filas que carecen de él son de un origen que no leyó disco.
- Cruzar contra `storage_providers.base_path` con `is_dir()` rechaza falsos positivos: una carpeta operativa pudo tener `file_modified_at` legítimo pero su path desapareció (montaje caído). Esas NO se borran en este pase — son candidatos al `PruneGuard` del sync, que ya tiene su guardarraíl de ratio.

**Alternativas consideradas:**
- Borrar por solo `file_modified_at IS NULL` sin cruzar con disco → capturaría filas legítimas creadas vía API sin `mtime` (no aplica en este repo, pero deja flanco abierto si mañana se agrega un endpoint que crea carpetas sin tocar disco).
- Borrar por solo `is_dir(...) === false` → capturaría carpetas legítimas de syncs antiguos cuyo path fue renombrado en disco por el operador, borrando metadata todavía útil.

### D2 — Snapshot pre-DELETE en `files_ghost_pre_purge_<ts>`

Sigue el patrón de `DedupeFiles::buildMap` (tabla `unlogged` para velocidad) pero **conservando** la tabla post-comando. Razón: este snapshot es una tabla de auditoría más que un mapa de trabajo, y debe sobrevivir para que el operador pueda restaurar a mano si el cambio fallara a futuro.

### D3 — FixPersonalVisibility resuelve el dueño canónico desde `base_path`

Para `base_path` = `/home/www/Usuarios_tcloud/<username>/`, el segmento final se extrae con `basename(rtrim($base_path, '/'))` y se busca en `users.username` (case-sensitive, índice único). Esta lógica es la misma que documenta `StorageProvider::canonicalOwnerId` en AGENTS.md, pero como ese helper quedó RETIRADO tras el revert (task 6 del change archivado), el comando la reimplementa localmente y la inyecta también al `StorageProvider::personalCanonicalUsername()` estático para reuso desde `FileController::storages()`.

**Alternativas consideradas:**
- Usar el campo `files.owner_id` para deducir el dueño del storage → no determinístico en storages con varios `user_storages` (mismo motivo que el patrón prohibido). Se descarta.
- Sacar el dueño de `users.personal_quota_bytes` (que sí está atado al dueño real) → no está en `user_storages`, por lo que un usuario `full`权 sobre un personal ajeno tendría la cuota en otro lado. Más indirección sin ganancia.

### D4 — Filtro de visibilidad en `FileController::storages()` con helper de modelo

Cambio mínimo: añadir un método `StorageProvider::personalCanonicalUsername(): ?string` que devuelva el segmento final de `base_path` cuando `is_personal=true` (o `null` en otro caso). En `storages()` se hace:

```php
$storages = $userStorages->filter(function ($us) use ($user) {
    $sp = $us->storageProvider;
    if (!$sp->is_personal || $user->isAdmin()) return true;
    return $sp->personalCanonicalUsername() === $user->username;
})->map(...);
```

Lo positivo: 8 líneas, sin migraciones, sin índices nuevos.
**Alternativas consideradas:**
- Crear vista SQL `user_visible_storages_v1` con un JOIN a `users.username` → más caro (vista materializada no se justifica con 30 storages) y opaca la causa en logs.
- Forzar el filtro en `hasStoragePermission()` → rompe el check de share/download que sí debe respetar `full`权 sobre personales ajenos.

### D5 — Sin tocar `StorageSyncService::createFileFromScan`

El operador dijo "actualmente funciona bien". El cambio está scoped a data-only + visibilidad, lo que evita que el revert (ya complicado: 5.4 + tasks 6) quede incompleto.

## Risks / Trade-offs

- **[R1] Una carpeta legítima creada a mano por el operador y nunca sincronizada se borra** → mitigación: la tabla snapshot `files_ghost_pre_purge_<ts>` queda persistente y se ofrece el comando complementario `files:restore-from-snapshot` (no implementado en este change; documentado en runbook del operador).
- **[R2] `user-storages:fix-personal-visibility` borra accesos legítimos** → mitigación: `--dry-run` por defecto imprime diff antes de aplicar; si el operador corrigió manualmente la asignación vía `users:assign-storage` con `--reason`, el cambio debe documentarlo en el changelog antes de correr `--apply`.
- **[R3] El `is_personal` de un storage antiguo quedó en `false` por error** → el filtro lo ignora y el personal ajeno sigue siendo visible. Mitigación: si reaparece, comando `storages:fix-personal-flag` (fuera de scope de este change).
- **[R4] Cache de listado caliente en el cliente** → el filtro es server-side en `GET /user/storages`, no cachea datos sensibles; no requiere invalidación. La caché de carpetas dentro de cada storage sí queda invalidada por `invalidateFolderCache()` tras `files:purge-ghost-folders`.
- **[R5] Snapshot crece mucho** → con ~29 carpetas por storage es despreciable. Si el cambio se reusara para `cleanup` masivos, agregar `--keep-snapshot=false`.

## Migration Plan

**Sin migration Laravel.** Todo el flujo es data-only:

1. **Pre-fix** (read-only):
   ```bash
   php artisan files:purge-ghost-folders --dry-run
   php artisan user-storages:fix-personal-visibility --dry-run
   ```
   Operador revisa diffs.

2. **Apply-fix**:
   ```bash
   php artisan user-storages:fix-personal-visibility --apply --yes
   php artisan files:purge-ghost-folders --apply --yes
   ```
   Por orden: primero `user_storages` (porque la visibilidad del UI depende de eso) y después `files` (porque las carpetas fantasma pueden colgar de un `parent_id` que el fix de visibilidad NO toca).

3. **Verificación**: tabla que imprime cada comando + recarga manual de Mis Archivos en Chrome.

4. **Rollback**:
   - `files`: `INSERT INTO files SELECT * FROM files_ghost_pre_purge_<ts>` (asumiendo que ningún otro flujo tocó BD en el ínterin; `file_id` UUID no aplica aquí porque son `bigserial`).
   - `user_storages`: no se borra en cascada nada, pero el operador puede reasignar a mano si eliminó una fila legítima.

5. **Post-fix**:
   - `STORAGE_SYNC_ENABLED=true; php artisan cache:clear` (invalidar `folder_gen:*` Redis keys por si quedaron con stats de los snapshots).

## Open Questions

Ninguna que cambie el approach. El campo `files.canonical_folder_id` (citado en el change archivado) ya no existe post-revert; el `parent_id IS NULL` raíz se interpreta directamente sin canonicalización.
