## Why

El listado de Mis Archivos muestra hoy el toast `path_missing` ("la ruta ya no existe en disco") cuando el `realpath()` del directorio falla. La causa operativa más frecuente NO es que el archivo se haya borrado: es que el directorio es inaccesible para el usuario PHP-FPM (`www`) por permisos restrictivos (`root:root 0700` u otro modo que excluye al grupo). Reproducido 2026-10-05 en storage 55 (`06 Emisoras 05`) con 8 hijos directos de `base_path` en ese modo y 4956 entradas en BD inaccesibles para `www`.

El comando existente `storage:audit-ownership` no detecta este caso: usa `find -not -user www` que, al ejecutarse como `www`, recibe `Permission denied` y salta silenciosamente los directorios con modo `700` ajenos. La consecuencia operativa es doble:

1. El operador no ve el problema en ningún reporte — solo aparece como toast al usuario final.
2. El walker recursivo de `chgrp` que se ejecutó el 2026-10-04 tampoco pudo entrar a esos 8 directorios, así que el fix quedó incompleto sin aviso.

El spec `storage-health-and-reconcile` (ya vigente) cubre accesibilidad a nivel de `base_path` y dispara reconciliación cuando un mount externo remonta. Esa granularidad (todo el root) no es suficiente: la accesibilidad puede romperse por hijo individual sin que el root pierda accesibilidad.

## What Changes

### Detección por hijo inmediato

`storage:audit-ownership` SHALL extender su reporte para incluir la categoría `untraversable`: para cada hijo directo del `base_path` de cada `StorageProvider` activo, SHALL ejecutar `@opendir()` y, ante fallo, cruzar contra `files` para confirmar que la BD tiene contenido real (vs. metadata inerte). El reporte SHALL mantener la categoría `owner_mismatch` existente y SHALL añadir `untraversable` como categoría separada.

### Repair explícito y privilegiado

Nuevo comando `storage:repair-ownership` SHALL ejecutar `chown -R www:storage-admins` y `chmod -R u+rwX,g+rwX` sobre los paths reportados como `untraversable` por el audit, con denylist duro (`System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, paths que empiezan con `.`), snapshot pre-cambio en tabla `files_ownership_pre_repair_<ts>` para rollback, y abort con instrucción clara si el UID no es 0.

### Toast diferenciado al usuario final

`StorageSyncService::doSyncFolder()` SHALL añadir un nuevo status `path_unreadable` cuando `realpath()` resuelve pero `is_readable()` falla. El frontend SHALL diferenciar el mensaje de toast: `path_missing` (la ruta no existe físicamente) sigue mostrando "la ruta ya no existe en disco"; `path_unreadable` SHALL mostrar "el directorio no es legible por el usuario del servidor". Esto evita que el operador confunda un problema de permisos con pérdida real de datos.

### Schedule y observabilidad

`storage:audit-ownership` SHALL correr cada hora (sin `withoutOverlapping` mayor a 60s). El reporte SHALL emitir log estructurado por storage cuando `untraversable > 0`, persistir conteos en `SystemSetting('storage_health:<storage_id>:untraversable_count')` para alimentar el dashboard. NO se añade nuevo sistema de alertas: la emisión por mail mantiene su comportamiento actual.

### Surface UI admin

Nuevo partial Blade `_storage_health` SHALL renderizarse en el dashboard admin (no en cliente), gated por flag `storage_health_enabled` (default false). Muestra, por storage, los conteos `owner_mismatch` y `untraversable` del último audit, fecha del último pass, link a la última corrida, y CTA textual con el comando exacto a ejecutar cuando hay hallazgos. Cero ruido cuando todo está en 0.

### Capabilities nuevas

- `storage-readability-untraversable-children`: detección y reparación de hijos directos del `base_path` que son inaccesibles al usuario PHP-FPM. Cubre audit (read-only, siempre seguro), repair (UID 0, dry-run por defecto), y reportabilidad diferenciada por storage. Complementa — no reemplaza — `storage-health-and-reconcile`.
- `dashboard-storage-health-partial`: partial Blade auto-gated que renderiza en el dashboard admin cuando hay hallazgos de `storage:audit-ownership`. Privacidad: cliente NUNCA ve este partial. Aplica la convención de `dashboard-modular-partials` para gating, shape, y selectores de tour.

### Capabilities modificadas

- `storage-sync-overlap-guard`: `StorageSyncService::doSyncFolder()` añade la rama `path_unreadable` antes del return final por `path_missing`. El contrato de público (`getCategories()`) extiende la rama.
- `dashboard-modular-partials`: el harness `harness_dashboard_partials.php` SHALL incluir aserciones del partial nuevo (gating, shape, privacidad admin-only, presencia de `data-dashboard-partial="storage-health"`).

### No-goals

- ❌ NO se reescribe el `find -not -user www` (sigue funcionando como hasta ahora; el nuevo walker es ADITIVO).
- ❌ NO se cambia el comportamiento de `path_missing` retroactivamente (se conserva "no existe en disco"; el nuevo estado es ADITIVO).
- ❌ NO se añade sistema de alertas nuevo: el existente (laravel.log + métricas + mail opcional) se mantiene. El partial del dashboard es la única superficie nueva para hallazgos.
- ❌ NO se repara automáticamente desde el cron: el repair siempre es deliberado, con `sudo` o root explícito.
- ❌ NO se exponen health metrics al cliente: solo admin, igual que el resto de storage admin UI.
- ❌ NO se tocan `System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, ni paths que empiecen con `.`.

## Impact

**Código afectado**:

- `app/app/Services/StorageSyncService.php` (modificado ~15 líneas: nueva rama `path_unreadable` antes de `path_missing`).
- `app/app/Console/Commands/StorageOwnershipAuditCommand.php` (modificado ~80 líneas: nueva fase PHP-based probe, denylist, output multi-categoría, log estructurado, persistencia en SystemSetting).
- `app/app/Console/Commands/StorageRepairOwnershipCommand.php` (nuevo, ~150 líneas: UID check, denylist, snapshot pre-cambio, chown/chmod recursivo, logging).
- `app/resources/views/files/index.blade.php` (modificado ~5 líneas: nueva entrada en mapa `noEscaneado`).
- `app/app/Services/Dashboard/DashboardDataProvider.php` (modificado ~40 líneas: `buildAdminStorageHealthData()` con queries acotadas y tier frío).
- `app/resources/views/dashboard/partials/_storage_health.blade.php` (nuevo, ~80 líneas: header documentando shape, gating por flag, `data-dashboard-partial="storage-health"`, render condicional por hallazgo).
- `app/resources/views/dashboard/admin.blade.php` (modificado ~2 líneas: `@include` del nuevo partial).
- `app/routes/console.php` (modificado ~3 líneas: schedule `storage:audit-ownership` hourly).
- `app/database/migrations/2026_10_05_120000_create_files_ownership_pre_repair_tables.php` (nuevo: tabla snapshot para rollback; solo se materializa cuando corre el primer `--apply`).
- `app/tests/harness_storage_readability_audit.php` (nuevo, ~30 aserciones: untraversable detectado, owner-mismatch detectado, denylist respetado, snapshot pre-repair, UID gate, no-mutación en dry-run).
- 2 specs OpenSpec nuevas (`storage-readability-untraversable-children`, `dashboard-storage-health-partial`), 3 modificadas (las listadas arriba).

**Schema**:

- Tabla nueva `files_ownership_pre_repair_<ts>` creada on-demand por el comando `--apply`. No es migración fija — el comando crea y dropea la tabla snapshot en su lifecycle (creada antes del DELETE, retenida hasta que el operador decida dropear con `--drop-old-migrations` o manualmente).
- `SystemSetting('storage_health:<storage_id>:untraversable_count')` (int) y `SystemSetting('storage_health:<storage_id>:owner_mismatch_count')` (int): escritas por el audit, leídas por el dashboard. No requieren migration.

**Riesgos**:
- (R1) Falsos positivos: un directorio vacío en BD podría marcarse como untraversable sin contenido real. Mitigación: el cross-check con `files WHERE storage_provider_id=X AND path LIKE 'Y/%'` exige ≥1 fila para emitir el hallazgo.
- (R2) El walker PHP es O(hijos directos de base_path), no O(árbol entero). Para storages con miles de hijos directos (Disco_A, Disco_C) sigue siendo eficiente (<1s por storage). No hace falta recursion completa porque solo nos interesan los inaccesibles, que son los que el walker NO puede ver.
- (R3) El snapshot pre-cambio es una tabla por corrida. Sin `--drop-old-migrations` puede acumular tablas. Mitigación: `--drop-old-migrations` lista y dropea snapshots > 30 días (configurable).
- (R4) `is_readable()` puede ser caro en NFS remoto. Mitigación: el probe solo corre sobre hijos directos, no recursión; cache Redis 5 min por `(storage_id, scope)` opcional vía SystemSetting.
- (R5) Cambiar el toast message puede confundir a usuarios acostumbrados al texto actual. Mitigación: el nuevo status `path_unreadable` es ortogonal a `path_missing`; los mensajes viejos siguen apareciendo cuando aplique (path que realmente no existe).

**Rollback**:

- `git revert <commit-hash>` revierte código y partial.
- `SystemSetting::set('storage_health_enabled', '0')` oculta el partial sin deploy.
- Para limpiar SystemSettings y snapshots de la BD manualmente: `redis-cli FLUSHDB` (cache) y `DROP TABLE files_ownership_pre_repair_<ts>;` (BD).
- El comando `storage:audit-ownership` original (sin la fase nueva) sigue funcionando como antes — la fase es ADITIVA.

**APIs externas**: ninguna breaking change. Los mensajes de toast cambian solo para el caso nuevo `path_unreadable`; `path_missing` mantiene su texto.

**Capacidades nuevas**: 2. **Modificadas**: 3. **Removidas**: 0.