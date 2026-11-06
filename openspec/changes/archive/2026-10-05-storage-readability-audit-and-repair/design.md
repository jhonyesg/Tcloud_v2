## Contexto

El comando actual `storage:audit-ownership` usa `find -not -user www` corriendo como usuario PHP-FPM. Este enfoque es ciego a los hijos directos del `base_path` que tienen modo restrictivo (`700` ajenos al grupo): `find` recibe `Permission denied` y los salta sin emitirlos. La consecuencia operativa es que el walker recursivo del `chgrp` que se ejecutó el 2026-10-04 tampoco pudo entrar a esos 8 directorios en `Disco_H/RD6/`, y el toast de Mis Archivos siguió mostrando `path_missing` al usuario cada vez que navega a `Casanare > Yopal_Manatial`.

La spec `storage-health-and-reconcile` (vigente, ya mergeada) detecta accesibilidad al nivel del `base_path` completo y dispatcha reconciliación al remontar — esa granularidad (todo el root) no es suficiente para nuestro caso. La accesibilidad puede romperse por hijo individual sin que el root pierda accesibilidad.

Este diseño añade una segunda capa de detección por hijo directo del `base_path`, complementaria de la existente, más una acción de repair explícita y un surface UI en el dashboard admin.

## Diagnóstico del estado actual

```
┌──────────────────────────────────────────────────────────────────────┐
│ Flujo vigente del toast path_missing                                  │
├──────────────────────────────────────────────────────────────────────┤
│ [Usuario navega a Casanare > Yopal_Manatial]                          │
│         │                                                              │
│         ▼                                                              │
│ [FileController::index] ──GET /files?parent_id=...────────────────┐   │
│         │                                                        │   │
│         ▼                                                        │   │
│ [StorageSyncService::syncFolderWithReport]                       │   │
│         │  doSyncFolder():                                        │   │
│         │    $realPath = realpath($scanPath)  ◀── false (EACCES)  │   │
│         │    return report(currentListing, 'path_missing')         │   │
│         ▼                                                        │   │
│ [Frontend reportSync] ──toast: "No se pudo actualizar..."        │   │
│         ▼                                                        │   │
│ [Usuario ve carpetas stale en BD + toast]                        │   │
└──────────────────────────────────────────────────────────────────────┘

Por qué realpath() falla:
  /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_H/RD6/  www:www  755  OK
  └ Casanare/                                               root:root 700  EACCES
       └ Yopal_Manatial/                                     (inaccesible desde PHP-FPM)
```

## Arquitectura objetivo

```
┌──────────────────────────────────────────────────────────────────────┐
│ Flujo post-change                                                     │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  CAPA 1 — Detección por hijo directo (StorageOwnershipAuditCommand) │
│  ───────────────────────────────────────────────────────────────── │
│  foreach $storage in active providers:                              │
│    $entries = scandir($storage->base_path)                          │
│    foreach $entry in $entries:                                      │
│      if @opendir($entryPath) === false:                             │
│        $bdCount = SELECT count(*) FROM files                        │
│                    WHERE storage_provider_id=X                      │
│                      AND path LIKE '<entry>/%'                      │
│        if $bdCount > 0 && !in denylist:                             │
│          untraversable[] += {path, mode, owner, group, bd_count}    │
│    SystemSetting("storage_health:{id}:untraversable_count", N)      │
│                                                                      │
│  CAPA 2 — Repair explícito (StorageRepairOwnershipCommand)          │
│  ───────────────────────────────────────────────────────────────── │
│  if posix_geteuid() !== 0:  abort con mensaje                       │
│  paths = cargar desde último audit                                  │
│  paths = paths.filter(denylist)                                     │
│  if --apply:                                                        │
│    snapshotTable = files_owners_pre_repair_<ts>                      │
│    INSERT INTO snapshotTable SELECT ... WHERE storage_provider_id=│
│                                              AND path LIKE '%/%' │
│    foreach path in paths:                                           │
│      chown -R www:storage-admins path                               │
│      chmod -R u+rwX,g+rwX path                                     │
│  SystemSetting('storage_health:{id}:untraversable_count', 0)       │
│                                                                      │
│  CAPA 3 — Toast diferenciado (StorageSyncService)                   │
│  ───────────────────────────────────────────────────────────────── │
│  $realPath = realpath($scanPath)                                    │
│  if (!$realPath || !is_dir($realPath))  → 'path_missing'           │
│  if (!is_readable($realPath))           → 'path_unreadable'         │
│                                                                      │
│  CAPA 4 — Dashboard partial admin (StorageHealthPartial)            │
│  ───────────────────────────────────────────────────────────────── │
│  DashboardDataProvider::buildAdminStorageHealthData():               │
│    rows = SystemSetting('storage_health:*:untraversable_count')     │
│           filtered by > 0 OR owner_mismatch > 0                      │
│    return {items, audit_finished_at}                                │
│  admin.blade.php:                                                   │
│    @include('dashboard.partials._storage_health',                   │
│            ['context'=>'admin', 'data'=>$storageHealth])           │
│    if data.items.empty: nothing rendered                            │
└──────────────────────────────────────────────────────────────────────┘
```

## Decisiones de diseño

### D1 — Detección PHP-based, no recursive find

`StorageOwnershipAuditCommand` extiende su fase de detección con un walker PHP que NO recursa. Para cada storage activo:

1. `scandir($basePath)` — una sola llamada por storage.
2. Para cada hijo: `@opendir($child)` envuelto en try/catch silencioso.
3. Si falla: cruzar con `files WHERE path LIKE 'hijo/%' AND storage_provider_id=X`.

Por qué no recursar: un walker recursivo correría contra el mismo problema que el `find` original — tropieza con EACCES en el primer nivel y aborta. Solo nos interesa detectar el primer nivel; los nietos son invisibles hasta que el padre sea legible.

Por qué PHP y no `find`: PHP puede capturar `is_readable()` directamente sin emitir a stderr, y el control de errores es trivial (`@opendir` + `$errstr->getCode() === EACCES`). Con `find` habría que parsear stderr.

### D2 — Repair como comando aparte, no flag del audit

Separamos `storage:audit-ownership` (read-only, siempre seguro de correr) de `storage:repair-ownership` (UID 0 requerido, dry-run por defecto). Razones:

- El audit debe poder correr cada hora sin intervención. Si `--apply` está en el mismo comando, un cron mal configurado podría ejecutar reparación sin querer.
- El repair necesita denylist duros, snapshot a BD, confirmación interactiva. Esas responsabilidades no caben en un comando read-only.
- El audit puede ser invocado por FPM en el cron. El repair solo lo invoca el operador manualmente.

### D3 — UID gate, no sudo wrapper

`storage:repair-ownership` aborta con mensaje claro si el UID no es 0. NO intenta usar `sudo` internamente por tres razones:

- `sudo` desde PHP requiere configuración en `/etc/sudoers` específica para `www` (riesgo de seguridad).
- El operador puede tener políticas de sudoers distintas (NOPASSWD, requiere TTY, etc.).
- Un abort explícito es más transparente que un sudo silencioso que el operador no pidió.

Convenio: el operador ejecuta `sudo php artisan storage:repair-ownership --storage=55 --apply --yes`. Si tiene TTY con passphrase, sudo la pide. Sin TTY, sudo falla con error claro.

### D4 — Snapshot pre-cambio a tabla no por storage

Una sola tabla `files_ownership_pre_repair_<YYYYMMDD_HHMMSS>` por corrida (no por path), con todas las filas afectadas. Razones:

- Crear/dropear una tabla por path sería O(N²) en DDL contra BD.
- El operador puede hacer rollback granular vía SQL sobre la tabla completa: `INSERT INTO files SELECT * FROM <snapshot> ON CONFLICT (id) DO NOTHING`.
- Las tablas snapshot NO se dropean automáticamente. El operador decide cuándo limpiar con un comando `--drop-old-snapshots --days=30`.

### D5 — Toast `path_unreadable` ortogonal a `path_missing`

`StorageSyncService::doSyncFolder()` añade el chequeo de `is_readable()` DESPUÉS del chequeo existente de `is_dir()`:

```php
$realPath = realpath($scanPath);
if (!$realPath || !is_dir($realPath)) {
    return $this->report($this->currentListing(...), 'path_missing');
}
if (!is_readable($realPath)) {
    return $this->report($this->currentListing(...), 'path_unreadable');
}
```

Por qué este orden: `realpath()` puede fallar por EACCES en el ancestro antes de poder evaluar el directorio target. Si falla el realpath, el directorio podría existir y no ser legible, o no existir. Por eso, `path_missing` sigue siendo el fallback para "no pudimos confirmar nada"; `path_unreadable` solo aplica cuando confirmamos que la ruta está pero no es legible para PHP-FPM.

### D6 — Dashboard lee SystemSetting directo, no cache Redis

El provider `DashboardDataProvider::buildAdminStorageHealthData()` lee los `SystemSetting('storage_health:*')` directamente, sin pasar por cache de tiers. Justificación:

- El audit escribe los SystemSetting al finalizar la corrida. Si la cache del dashboard tiene TTL 900s, los hallazgos quedan invisibles hasta el próximo refresh.
- Las queries son O(storages con flag activado), no O(storages totales). Son baratas y no justifican cache.
- El audit corre con `->withoutOverlapping(60)`, así que no hay riesgo de leer a mitad de escritura.

### D7 — Denylist invariante, no configurable

La denylist (`System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, paths que empiezan con `.`) es código, no setting. Razones:

- Estos nombres tienen semántica operativa fija (metadata de Windows, papelera). El sistema operativo los crea, no el operador.
- Permitir configuración abre la puerta a que el operador "arregle" un `System Volume Information` y rompa el journaling de Windows.
- Si en el futuro se necesita añadir un nombre a la denylist, es un commit con revisión.

## Goals / Non-Goals

**Goals:**
- Detectar por hijo directo del `base_path`, no solo por root.
- Diferenciar visualmente para el operador "permisos" vs "no existe en disco".
- Permitir al operador correr `sudo php artisan storage:repair-ownership` sin sorpresas.
- Hacer el hallazgo visible en el dashboard admin sin generar ruido cuando todo está saludable.
- Persistir métricas para que el operador vea tendencia histórica (cuándo se introdujo el problema).

**Non-Goals:**
- ❌ Detección recursiva (sigue ciega a nietos cuando el padre está bloqueado).
- ❌ Auto-reparación desde cron (siempre deliberada).
- ❌ Notificación por mail nueva (sistema existente se mantiene).
- ❌ Exposición al cliente.
- ❌ Modificación de la denylist desde CLI.

## Riesgos / Trade-offs

- **[R1] False positive en BD vacía** → mitigated por cross-check con `files` (≥1 fila). Reproducible con fixture que tenga BD vacía bajo path bloqueado.
- **[R2] PHP scandir() lento en NFS remoto** → mitigated por scope limitado a hijos directos, no recursión. Storages con 50+ hijos directos (Disco_A, C) tardan <1s.
- **[R3] Tablas snapshot se acumulan** → mitigated por `--drop-old-snapshots --days=30`. Documentado en design y tasks.
- **[R4] is_readable() false negative si el directorio es ejecutable pero no legible** → PHP `is_readable()` verifica bit de lectura para UID efectivo; no es ambiguo. Si el directorio tiene `drwx------` con owner root, `is_readable()` retorna false para www. Confirmado en PoC del 2026-10-05.
- **[R5] Cambio del mensaje del toast puede confundir usuarios** → mitigated por ADDED status, no MODIFIED. Los mensajes viejos siguen apareciendo cuando aplique).
- **[R6] Auditoría horaria puede coincidir con uso activo** → mitigated por lock de concurrencia existente (`storage:ownership-audit:lock`, TTL 5min). El audit es read-only.
- **[R7] Repair chown -R puede tardar en sub-árboles grandes** → mitigated por `--limit=N` (default 10 paths por corrida). El operador corre el comando varias veces si tiene muchos paths a reparar.
- **[R8] Persistencia de SystemSetting cada hora genera escrituras** → 4 escrituras por storage/hora × 70 storages = 280 escrituras/hora. Cada SystemSetting es un UPDATE contra PostgreSQL. No es carga medible. Documentado para visibilidad.

## Plan de despliegue

1. **Pre-deploy**: dry-run del audit en staging (`php artisan storage:audit-ownership --limit=50`) para confirmar que el reporte detecta correctamente el caso actual (8 untraversable en storage 55).
2. **Deploy código**: merge PR con todo el código (audit enhancement, repair command, sync service cambio, partial dashboard). Sin migrations.
3. **Post-deploy validación manual**:
   - Correr `php artisan storage:audit-ownership --storage=55` → esperar 8 untraversable.
   - Correr `sudo php artisan storage:repair-ownership --storage=55` (dry-run) → confirmar 8 paths listados.
   - Inspeccionar manualmente que NO aparece `System Volume Information` ni `$RECYCLE.BIN`.
   - Correr `sudo php artisan storage:repair-ownership --storage=55 --apply --yes` → confirmar chown + chmod aplicados.
   - Re-correr audit → esperar 0 untraversable.
   - Cargar `/dashboard` como admin → confirmar tarjeta renderizada correctamente.
4. **Activación del flag**: `SystemSetting('storage_health_enabled', '1')` desde CLI.
5. **Schedule**: deploy ya incluye el `Schedule::command('storage:audit-ownership')->hourly()`. Confirmar con `php artisan schedule:list`.

## Plan de rollback

- `git revert <commit-hash>` revierte todo (sin migrations).
- `SystemSetting::set('storage_health_enabled', '0')` oculta el partial sin deploy.
- `DROP TABLE files_ownership_pre_repair_<ts>;` por cada snapshot creado (no hay DROP automático).
- El comando `storage:audit-ownership` original (sin la fase PHP) sigue funcionando como antes — la fase es ADITIVA. Tras el revert, vuelve al comportamiento pre-change.
- El status `path_unreadable` deja de existir en el mapa del frontend tras el revert; cualquier caller que mandase ese status verá el fallback `path_missing` (cambio silencioso, aceptable).

## Open Questions

- **Q1**: ¿Aplicar el fix a los 8 directorios de storage 55 como parte del cambio, o queda como primer run operacional tras el deploy?
  - Mi sugerencia: aplicar como parte del cambio, en la sección "Post-deploy validación manual" arriba. Reduce tiempo de exposición del usuario al toast.
- **Q2**: ¿El comando `storage:repair-ownership` debe ser `rake`-style (`repair-ownership:storage=55`) o flag-style (`repair-ownership --storage=55`)?
  - Mi diseño va con flag-style por consistencia con `storage:audit-ownership`.
- **Q3**: ¿La cadencia horaria del audit coincide con alguna ventana de carga alta? Si sí, mover a `everyTwoHours` o `daily()`.
  - Mi diseño va con hourly por defecto, configurable vía `SystemSetting`.