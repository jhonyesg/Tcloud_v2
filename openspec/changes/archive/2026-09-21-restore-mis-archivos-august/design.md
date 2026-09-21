## Context

El módulo Mis Archivos hoy (commit `7467f74` sobre `main`, 2026-09-19) carga 15 archivos del módulo + 11 columnas nuevas en `files` + 3 tablas auxiliares. La causa inmediata del rollback del operador es la sobre-ingeniería cross-storage de septiembre: 1.455.875 mirror rows, 94% rotas, downloads con HTTP 400 (`openspec/changes/files-mirror-elimination/proposal.md §Why`). La versión de agosto (commit `bb71b9c`, 2026-08-20) tenía 13 columnas en `files`, 0 tablas auxiliares, y un `StorageSyncService` de 529 líneas que el operador describe como "directo, fácil, estable". Los archivos de agosto están extraídos en `/tmp/kilo/mis-archivos-agosto/` (15 archivos, 540 KB) listos para copiar.

Este diseño NO intenta crear un nuevo modelo desde cero — aplica el principio **raíz-sin-parches** (`principio_solucion_raiz_sin_parches_nuevos_registros`): la solución correcta ya existe en el historial del repo; la implementación es volver a ponerla en su lugar, ajustar el esquema al estado de esa época, y actualizar el cron de sincronización para que use la versión simplificada del servicio.

## Goals / Non-Goals

**Goals:**
- Restaurar el modelo de identidad y listado de Mis Archivos al estado de `bb71b9c` (20-ago-2026).
- Mantener las defensas operativas probadas del fix del 27-jul (`a15e5f3`): FileRegistry idempotente, PruneGuard, MountGuard, comando `files:dedupe`, `UNIQUE (storage_provider_id, path)`.
- Ajustar el cron de sincronización (`routes/console.php` + supervisord) para invocar `StorageSyncService::syncFolder()` simplificado, sin métodos de cross-storage.
- Migración de BD sin pérdida de datos: las 78.547 transcripciones y 33 shares apuntando a mirror rows se repuntan al canónico antes del DROP COLUMN.
- Rollback posible: la migration tiene `down()` que restaura las 11 columnas + 3 tablas, y `git revert` del cambio de código restaura los archivos de septiembre.

**Non-Goals:**
- No se reintroduce papelera per-file ni availability tracking como features nuevas (pueden regresar en un change posterior si el operador lo decide).
- No se toca el módulo API Transcriptor: el constraint `transcriptions.source_absolute_path` y la frontera "Mis Archivos escribe, Transcriptor consulta" se mantienen (ver AGENTS.md §"Frontera Mis Archivos ↔ API Transcriptor").
- No se modifican los harnesses de regresión del módulo transcriptor (`harness_transcriptor_physical_identity.php`, etc.); los que se vuelvan obsoletos se archivan.
- No se hace merge con el change WIP `files-mirror-elimination`: este change es más agresivo (revierte también trash y availability) y supersede ese change.

## Decisions

### D1: Reemplazo de código via `git checkout` desde bb71b9c (no cherry-pick manual)

**Decisión**: Para los 15 archivos del módulo Mis Archivos, ejecutar `git checkout bb71b9c -- <path>` sobre cada uno. NO copiar desde `/tmp/kilo/mis-archivos-agosto/` (que es solo un mirror; el git checkout deja el repo en estado conocido).

**Rationale**: El operador ya validó que la versión de agosto es estable. Replicar esa versión exactamente evita que el reemplazo introduzca drift accidental. La copia manual desde `/tmp/kilo/` introduce riesgo de typos en la extracción tar y omite archivos que se descubran después.

**Alternativa considerada**: cherry-pick del commit bb71b9c. Descartada porque bb71b9c tocó archivos de otros módulos (correcciones, IA) que NO queremos revertir.

**Alternativa considerada**: diff manual línea por línea con revisión humana. Descartada porque el delta es +1307 líneas, propenso a errores, y el operador quiere velocidad ("directo, fácil, estable").

### D2: Orden de migración de BD — reparar FKs ANTES de borrar mirror rows

**Decisión**: La migration se divide en 3 pasos con `down()` independiente cada uno:

1. **Pre-check** (verificación, no migración): `SELECT COUNT(*) FROM transcriptions WHERE file_id IN (SELECT id FROM files WHERE canonical_folder_id IS NOT NULL)` debe ser ≤ 78.547; abortar si supera (señal de mirror rows huérfanas que el comando de repunte no contempló).
2. **Repunte de FKs** (comando aparte, NO migration): `files:repair-mirror-targets --apply` repunta `transcriptions.file_id` y `shares.file_id` de cada mirror row a su canónico (resuelto por `physical_path_normalized` antes del DROP).
3. **DROP COLUMN + DROP TABLE** (migration Laravel): ejecuta `ALTER TABLE files DROP COLUMN ...` + `DROP TABLE ...` en una sola transacción.
4. **Backup pre-drop** se hace con `pg_dump --schema-only --table=files --table=file_mirror_audit_log --table=files_owner_canonical_audit` y se guarda en `backups/`.

**Rationale**: El CASCADE de `files_parent_id_fkey ON DELETE CASCADE` borra las transcripciones/shares si no se repuntan antes. Hacerlo en orden garantiza cero pérdida.

**Alternativa considerada**: SET NULL en las FKs en vez de repuntar. Descartada porque pierde el vínculo transcripción↔archivo, que es el dato de valor del módulo Transcriptor.

### D3: Supervisord — ajustar command, no tocar numprocs

**Decisión**: El unit supervisord `tcloud-storage-sync` mantiene `numprocs=1` (default). Lo que cambia es el comando invocado: pasa de la versión septiembre de `StorageSyncService` (con `resolveListingTargets`) a la versión agosto (sin ella). Como la signature de `syncFolder(StorageProvider $storage, ?int $parentId, ?int $userId, bool $forcePrune)` es idéntica en ambas versiones, NO requiere cambio en el unit supervisord.

**Rationale**: El comportamiento observable del comando (mismo nombre, mismos flags, mismo log shape) es idéntico. El cambio es interno al service.

**Alternativa considerada**: Crear un nuevo comando `StorageSyncAugustCommand` que envuelva al service. Descartada porque duplica código sin valor.

### D4: Cache invalidation al deploy — invalidar `folder_listing:*` manualmente

**Decisión**: Antes del primer request post-deploy, ejecutar `redis-cli -n 1 --scan --pattern 'tcloud_tcloud_cache_folder_listing:*' | xargs redis-cli DEL`. El listado cacheado en agosto usa `folder_listing:{storageId}:{parentId}:{gen}:{page}`; el de septiembre usa claves con sufijos distintos por `resolveListingTargets`. Las claves del formato septiembre son huérfanas y se pueden ignorar (expiran en TTL).

**Rationale**: Sin invalidación, los usuarios verían listados cacheados que ya no coinciden con la nueva query shape.

**Alternativa considerada**: Bump de `CacheEpoch` global. Descartada porque no aplica al namespace `folder_listing:` (es de transcriptor).

### D5: AGENTS.md — editar inline las secciones obsoletas

**Decisión**: Las 3 secciones marcadas como `change ...` en AGENTS.md que se vuelven obsoletas por la reversión (Permission checks strict per storage_id, Folder identity canónica para shares, Cross-storage access verification) se ELIMINAN del archivo. NO se dejan como "histórico" — la decisión de la reversión es que ese modelo NO existió.

**Rationale**: Mantener secciones obsoletas genera confusión ("¿está vigente este invariant?"). El operador prefiere archivos limpios.

**Alternativa considerada**: Marcar como `(archived)` con texto tachado. Descartada por la misma razón — el operador quiere ver solo lo vigente.

## Risks / Trade-offs

- **[R1] Reproducción del incidente del 27-jul**: el riesgo del revert es que el modelo simple vuelva a fallar si NFS cae y el `MountGuard` no detecta a tiempo. → Mitigación: el `MountGuard` de agosto YA está en producción y validado. No se reintroduce ningún cambio que pueda romper el guard.
- **[R2] Pérdida de papelera per-file**: el operador no pidió conservarla, pero si en el futuro un usuario reporta "borré sin querer", no hay undo. → Mitigación: el comando `pg_dump` diario sigue vigente; recuperación vía backup de 24h.
- **[R3] Pérdida de availability_state**: si un storage se cae y vuelve, los archivos quedan sin flag de "estuvo caído" (no hay señal histórica). → Mitigación: el `storage_providers.is_accessible` + log siguen siendo la fuente de verdad operacional.
- **[R4] Cross-storage shares quedan vacíos**: el 51% de los folder shares existentes (36 de 70) están sobre mirrors en storage padre cuando los archivos viven en storage hijo. → Mitigación: el comando `shares:repair-mirror-targets --revert` puede revertir la canonicalización previa; también se puede avisar al operador para que recree esos shares en el sub-storage.
- **[R5] Rollback del change es costoso**: revertir este change requiere reaplicar 11 migrations + git revert de ~1300 líneas en 15 archivos. → Mitigación: el `down()` de la migration es completo (recrea columnas + tablas + índices), y el `git revert <commit>` restaura los archivos de septiembre.
- **[R6] Frontend cache stale**: las páginas del módulo Mis Archivos usan cache del navegador (5min típico). → Mitigación: instrucciones en el runbook de deploy pidan hard-reload a los usuarios operativos.
- **[R7] Downtime**: el DROP COLUMN + DROP TABLE requiere lock en `files`. Con 1.5M filas, el lock puede tardar ~30s en PostgreSQL. → Mitigación: hacer la migration durante ventana de mantenimiento (madrugada Bogota).

## Migration Plan

### Pre-deploy (T-30 min)

1. `pg_dump --schema-only tcloudstorage > backups/restore-august-pre-<ts>.sql.gz` (snapshot del schema actual).
2. `pg_dump --data-only --table=files --table=file_mirror_audit_log --table=files_owner_canonical_audit tcloudstorage > backups/restore-august-data-<ts>.sql.gz` (snapshot de las tablas que se eliminan).
3. `git tag -a pre-restore-mis-archivos-august -m "Antes de restore-mis-archivos-august"`.

### Deploy (T+0)

1. Detener supervisord: `systemctl stop tcloud-storage-sync tcloud-storage-sync-2 tcloud-storage-sync-3` (3 workers).
2. `php artisan down --secret=<token>` (maintenance mode).
3. `php artisan files:repair-mirror-targets --apply` (repunte de FKs, ~5 min).
4. Verificar: `SELECT COUNT(*) FROM files WHERE canonical_folder_id IS NOT NULL` debe ser 0; abortar si > 0.
5. Aplicar migration: `php artisan migrate` (DROP COLUMN + DROP TABLE).
6. `git checkout bb71b9c -- app/app/Console/Commands/SyncStorage.php app/app/Console/Commands/DedupeFiles.php app/app/Http/Controllers/FileController.php app/app/Http/Controllers/PublicShareController.php app/app/Http/Controllers/ShareController.php app/app/Models/File.php app/app/Models/Share.php app/app/Models/ShareAccessLog.php app/app/Models/StorageProvider.php app/app/Services/FileRegistry.php app/app/Services/FileScannerService.php app/app/Services/StorageSyncService.php app/resources/views/files/index.blade.php app/resources/views/files/preview.blade.php app/tests/Feature/StorageSyncPruneTest.php`.
7. Editar `routes/console.php` para quitar la invocación a métodos cross-storage (ver D3 — los métodos ya no existen, el service compila igual).
8. `composer dump-autoload`.
9. Invalidar cache (D4): `redis-cli -n 1 --scan --pattern 'tcloud_tcloud_cache_folder_listing:*' | xargs redis-cli -n 1 DEL`.
10. `php artisan optimize:clear` (limpia opcache + view + route cache).
11. Editar AGENTS.md: eliminar las 3 secciones obsoletas (D5).
12. Levantar supervisord: `systemctl start tcloud-storage-sync*`.
13. `php artisan up` (sale de maintenance).
14. Smoke test: navegar `/files?storage_id=5` como admin y como usuario regular; abrir 1 share existente y verificar descarga.

### Rollback (si algo falla en los primeros 30 min)

1. `php artisan down`.
2. `systemctl stop tcloud-storage-sync*`.
3. `git revert <commit-de-restore>` (restaura archivos de septiembre).
4. `php artisan migrate:rollback --step=1` (recrea columnas + tablas).
5. Restaurar datos si la migración borró algo: `psql tcloudstorage < backups/restore-august-data-<ts>.sql`.
6. `composer dump-autoload && php artisan optimize:clear`.
7. `systemctl start tcloud-storage-sync*`.
8. `php artisan up`.

## Open Questions

- **¿El operador quiere conservar `base_path_snapshot` y `physicalPathNormalized()` para usos distintos a mirrors?** Hoy el spec los marca como REMOVED porque su único consumidor era `FilePhysicalIdentity`. Si en el futuro se necesita path físico normalizado para sanitización (evitar `..` traversal), sería un nuevo spec. **Decisión diferida**: REMOVED ahora; reintroducir si surge necesidad concreta.
- **¿El comando `files:repair-mirror-targets --apply` es idempotente?** Sí — re-correrlo sobre rows ya repuntadas es no-op. Pero el código debe garantizarlo; si al implementarlo no se valida la idempotencia, hay que agregar un test de regresión.
