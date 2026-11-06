# AGENTS.md

Convenciones operativas y de código para agentes (Kilo u otros) que trabajen en TCloud.

## Regla crítica: sesiones en Redis

Toda consulta o eliminación de claves de sesión DEBE pasar por:
- `SessionService::sessionExistsInRedis($sid)`
- `SessionService::sessionRedisKey($sid)` (privado, pero las pruebas deben
  validar la lógica vía el método público)
- `SessionService::killSession($record)`

**Nunca** usar `Redis::connection('cache')` ni `Redis::connection('default')`
directamente para sesiones. Las sesiones reales viven en `Redis::connection('session')`
(DB lógica configurable vía `REDIS_SESSION_DB`, default `2`) bajo la clave
`{database.redis.options.prefix}{cache.prefix}{session_id}` (ej.
`tcloud_tcloud_cache_abc123`).

**Cuidado con `config/session.php`**: `session.store` (que apunta a un
`Cache::store()` registrado en `config/cache.php::stores`) y `session.connection`
(que se pasa a `Redis::setConnection()` internamente) son cosas DISTINTAS.
Solo se modifica `session.connection` para apuntar a la nueva conexión
`session`. `session.store` se deja en null (default = `redis`). Setear
`session.store = 'session'` rompe la app con HTTP 500 porque no existe un
cache store con ese nombre (verificado en deploy 2026-09-05).

Razón histórica: el bug original (2026-09-05) hacía que `cleanOrphans()`
borrara el 100% de las filas de `user_sessions` cada 30 minutos, causando
logout silencioso a todos los usuarios — incluyendo admins con
`session_lifetime_minutes=0` (nunca expira). El bug estaba en
`sessionExistsInRedis()` y `killSession()` apuntando a la conexión `cache`
(DB 1) cuando las sesiones viven en DB 0 (vía override de
`SessionManager::createRedisDriver()`).

## Helpers disponibles

| Helper | Uso |
|--------|-----|
| `SessionService::sessionExistsInRedis($sid): bool` | ¿La sesión existe en Redis? |
| `SessionService::killSession(UserSession $s): void` | Borrar sesión (DB + Redis) |
| `SessionService::killAllUserSessions(User $u, ?string $exceptSid = null): int` | Borrar todas las sesiones de un usuario |
| `SessionService::cleanOrphans(bool $dryRun = false): int` | Borrar user_sessions huérfanas (con guardarraíl de ratio) |
| `SessionService::cleanExpired(): int` | Borrar user_sessions expiradas |
| `SessionService::getEffectiveLifetimeMinutes(User $u): int` | Lifetime efectivo (per-user override o global) |
| `SessionService::getEffectiveMaxSessions(User $u): int` | Max sesiones simultáneas |

## Monitoreo operativo

Detectar anomalías en la limpieza de sesiones:

```bash
# ¿Cuántas sesiones se escanearon vs cuántas se borraron en las últimas 24h?
grep "sessions.cleanup" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log | tail -50

# ¿Hubo abortes por purga masiva?
grep "sessions.cleanup.aborted_mass_delete" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log

# ¿Cuántas sesiones activas tenemos ahora mismo?
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage \
  -c "SELECT COUNT(*) FROM user_sessions WHERE expires_at IS NULL OR expires_at > now();"

# ¿Cuántas sesiones hay realmente en Redis (DB de sesiones)?
redis-cli -a 'Clouding2026!Redis' -n 2 KEYS 'tcloud_tcloud_cache_*' | wc -l
```

Síntomas de problemas:
- `sessions.cleanup.aborted_mass_delete` con ratio alto: cleanOrphans detectó
  que estaba a punto de borrar casi todas las sesiones. Investigar antes de
  aprobar un nuevo deploy.
- `user_sessions` cayendo a 0 cíclicamente: el bug original — significa que
  `sessionExistsInRedis` apunta a la conexión incorrecta de nuevo. NO
  desplegar más cambios hasta arreglar.
- `intervals_too_aggressive` repetido: alguien bajó el intervalo de
  `sessions_cleanup_interval_minutes` por debajo de 5 min.

## Operaciones Redis

### Migración de la DB de sesiones (DB 0 → DB 2)

Si necesitas migrar sesiones legacy desde DB 0 (donde vivían antes del fix)
hacia DB 2 (la nueva conexión dedicada):

```bash
# 1. Verificar cuántas claves hay en DB 0 (sessions antiguas)
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | wc -l

# 2. Copiar una a una (RENAME preserva el TTL)
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -I{} redis-cli -a 'Clouding2026!Redis' -n 2 RENAME "{}" "{}"

# 3. Validar que ahora DB 2 tiene las sesiones
redis-cli -a 'Clouding2026!Redis' -n 2 KEYS 'tcloud_tcloud_cache_*' | wc -l

# 4. Esperar 24h para confirmar estabilidad, luego purgar DB 0
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -r redis-cli -a 'Clouding2026!Redis' -n 0 DEL
```

⚠️ **Crítico**: NO borrar las claves de DB 0 antes del paso 2, o todos los
usuarios quedan deslogueados.

### Rollback (si el deploy tumba sesiones)

```bash
# 1. Revertir el merge en git
git revert <commit-hash>

# 2. Mover sesiones de vuelta a DB 0
redis-cli -a 'Clouding2026!Redis' -n 2 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -I{} redis-cli -a 'Clouding2026!Redis' -n 0 RENAME "{}" "{}"
```

## Convenciones de código

- **PHP**: ver `phpcs.xml` / `pint.json` (si existen). PSR-12 por defecto.
- **Tests**: ver `phpunit.xml`. Tests de integración usan el harness
  `tests/harness_*.php` que ejecuta contra PostgreSQL y Redis reales.

## Schema clarity policy

Dos tablas distintas usando el mismo nombre de columna para conceptos
diferentes es **clarity debt**. Se resuelve vía rename, no vía documentación.

Contrato formal: ver `openspec/specs/schema-clarity-rename-policy/spec.md`.

**Reglas operativas** (resumen):

- **Mismo nombre en tablas distintas SOLO permitido cuando el concepto es el
  mismo** (`id`, `created_at`, `updated_at`, `name`, `path`). Si dos tablas
  tienen FKs distintas con el mismo nombre de columna, renombrar.
- **Audit-log `action` values son inmunes al rename**: `link_mirror`,
  `unlink_mirror`, `repoint_share`, etc. describen la operación conceptual,
  no la columna. No se renombran aunque cambie el nombre de la columna.
- **Deprecated helpers conservan nombre viejo por 1 ciclo de release**:
  aplica solo a helpers que SIGUEN existiendo. La familia mirror/canónico de
  `files` fue RETIRADA por el restore de agosto 2026
  (`2026_09_20_120000_drop_mirror_columns_and_restore_baseline`): **NO**
  existen `files.canonical_folder_id`, `files.merged_into_id`,
  `files.merged_at`, `files.merged_reason`, `files.base_path_snapshot`, ni las
  tablas `file_mirror_audit_log`, `files_owner_canonical_audit` y
  `files_prune_batches`. **NO** existen `File::isMirror()`,
  `File::canonical()`, `File::isFolderMirror()` ni `File::canonicalFolder()`.
  El modelo vigente de identidad física es
  `storage_providers.parent_storage_id` (DERIVADA por
  `StorageHierarchyService::recomputeParent()`, no mass-assignable) más la
  equivalencia por ruta normalizada
  (`storage_providers.physical_path_normalized`). Sobrevive
  `storage_providers.duplicate_of_storage_id` con su FK e índice.
  Guardarraíl: `tests/harness_merged_into_rename_compat.php` verifica que la
  familia retirada NO reaparezca en el código de aplicación.
- **Cualquier propuesta de columna nueva debe incluir grep de blast radius**
  (archivos, migrations, harnesses) en el `proposal.md`. Sin audit previo
  no se aprueba el PR.
- **Cada rename entrega**: una migration reversible atómica (BEGIN/COMMIT,
  renombra columna + FK constraint + índice), `down()` que restaura los
  nombres viejos, y todos los call sites actualizados en el mismo commit.
  No hay ventana de convivencia de nombres.

**Reglas operativas para columnas ambiguas**: `storage_providers.type` (legacy)
se conserva deprecado mientras exista `kind` (canónico) — la eliminación total
queda en un PR posterior una vez confirmado que `type='s3' | kind='local'` (1 fila
divergente) se resuelve manualmente. Migración no-breaking.

## Invariante de `files.owner_id` (change `files-canonical-owner-by-storage`, 2026-09-18)

**Contrato**: cada `(storage_provider_id, path)` tiene UN `owner_id` canónico,
resuelto por `StorageProvider::canonicalOwnerId($storageId)`. **NUNCA** usar
`$storage->userStorages()->first()?->user_id ?? 1` sin `orderBy` en código
nuevo (es no-determinístico en PostgreSQL).

**Resolución del canónico**:
1. Si `is_personal = true`: extraer `username` del último segmento de `base_path`
   (formato `/home/www/Usuarios_tcloud/{username}/`). Si el user existe, ese es
   el canónico. Esto previene que admin global robe ownership de storages
   personales ajenos donde incidentalmente tiene `full`.
2. Sino, `user_id` con `permissions='full'` y **menor `user_storages.id`**
   (insertion order) sobre el storage.
3. Si no hay `full` propio, subir por `parent_storage_id` aplicando la misma
   regla 2.
4. Si la cadena completa no tiene `full`, retornar `NULL`. Las vías de creación
   de archivos DEBEN lanzar excepción en ese caso.

**Visibilidad per-user NO depende de `files.owner_id`**. Se deriva de
`user_storages.permissions` para el `storage_provider_id` del file. El check
canónico es `FileController::checkFilePermission()` o `User::hasStoragePermission()`.
Cualquier callsite que autorice contra `owner_id` per-fila reintroduce el bug
original y debe refactorizarse a `storage_provider_id + user_storages`.

**Papelera cambió de UX**: post-fix, cada usuario ve la papelera de los storages
donde tiene acceso (`whereIn('storage_provider_id', $user->userStorages)`), no
"papelera de lo que es mío". Admin sigue viendo TODO. La invalidación del cache
del sidebar se hace por actor (quien operó), no por `owner_id`.

## Harnesses de regresión (`tests/harness_*.php`)

Suite de scripts PHP ejecutables directamente contra PostgreSQL/Redis
reales. Cada uno valida un contrato operacional o una regresión específica
sin levantar el stack completo. Patrón común:

- Tag único por corrida (ej. `hmv_<8-hex>`, `harness_<8-hex>`, `hmcl_<8-hex>`)
  para que todo INSERT/UPDATE sea trazable y limpiable.
- Helpers `h_ok` / `h_fail` / `h_section` / `h_check(cond, ok, fail?)` con
  contador global `$failures`; exit code `0` (todos OK) o `1` (algún fail).
- Bloque `try { ... } finally { cleanup por tag }` que borra en orden
  inverso. Cleanup defensivo al inicio elimina residuos de corridas
  previas con `LIKE 'hmcl_%'` (mismo prefijo) para no acumular basura.

### Inventario de harnesses actuales

| Harness | Change | Contrato verificado |
|---|---|---|
| `harness_mis_avisos_viewer.php` | `2026-09-05-mis-avisos-mentions-viewer` | Visor: `visibleTranscription`, `pageVisibleSegments`, `todayHits`, capabilities por fila; `MediaClipController::canAccessFile`. |
| `harness_storage_sync_is_file_linked.php` | `fix-storage-sync-missing-db-facade-import` | Regresión: facade `DB` resuelve a `Illuminate\Support\Facades\DB` (no `App\Services\DB`); `syncFolderWithReport()` end-to-end sin 500. |
| `harness_mis_avisos_clip_limit.php` | `verify-mis-avisos-clip-counts-toward-editor-limit` | **Cupo mensual del editor de medios**: clips desde Mis Avisos cuentan en `mediaEditorClipsThisMonth()`; al alcanzar `media_editor_clip_limit` el endpoint responde **HTTP 403** ("Límite mensual alcanzado..."). Previews, jobs `status='failed'`, admin y `limit=0` NO cuentan. |
| `harness_canales_cupo.php` | `medios-puntuales-cupos-y-adopcion-remota` | **Cupo de canales por par (grabador × usuario)**: límite leído de `grabador_usuario` (no del modelo `Grabador`), `uso >= limite` bloquea, límite subido se aplica, pivote inexistente y `limite=0` deniegan explícitamente (no "ilimitado"), admin bypassa, bajar el límite **no** borra canales. |
| `harness_canales_adopcion.php` | `medios-puntuales-cupos-y-adopcion-remota` | **Adopción de canales remotos huérfanos**: detección por `api_canal_id`/`slot_nombre` normalizado, clasificación `adoptable` (hijo directo del `ruta_base` + familia compartida) vs `sugerido` vs `fuera_de_alcance`, adopción = INSERT espejo sin escrituras al grabador, idempotencia (`ya_ok`), `formato_salida` nulo resuelto por `grabadores.tipo`, y adopción no bloqueada por cupo. |
| `harness_dashboard_partials.php` | `dashboard-modular-partials` | **Contrato de partials auto-gated del dashboard**: 22 aserciones sobre gating (no renderiza cuando flag=false), shape por contexto (admin/client), privacidad (cliente NO incluye `hits_`/`audit_`/`drift_*`), separación de contexto (`bg-jobs-active` solo admin), y presencia de selectores `data-dashboard-partial="..."` para tours. |
| `harness_merged_into_rename_compat.php` | `schema-clarity-rename-merged-into` / `fix-shares-module-regression` | **Guardarraíl de la retirada mirror**: verifica que `files.canonical_folder_id`/`merged_into_id`/`merged_at`/`merged_reason`/`base_path_snapshot` y las tablas `file_mirror_audit_log`/`files_owner_canonical_audit`/`files_prune_batches` NO existen, que `storage_providers.duplicate_of_storage_id` sí existe con su FK e índice, y que **ningún archivo de aplicación** consulta la familia retirada (una query contra una columna inexistente es un 500 en producción). |
| `harness_merged_into_create_edit_delete.php` | `schema-clarity-rename-merged-into` / `fix-shares-module-regression` | **E2E del modelo vigente de storages**: creación (`kind` canónico + `type` legacy + `physical_path_normalized` derivado), jerarquía `parent_storage_id` como campo DERIVADO y no mass-assignable (`StorageHierarchyService::recomputeParent()`), detección de duplicados por ruta normalizada (`findByNormalizedPath`/`canonicalFor`), merge/un-merge por `duplicate_of_storage_id`, y el guardarraíl de retirada mirror. |
| `harness_route_contract.php` | `fix-shares-module-regression` | **Contrato de rutas**: toda ruta registrada debe resolver a un método de controller existente. Es el guardarraíl que faltaba: el 2026-09-21 dos commits eliminaron métodos sin retirar rutas ni UI, y el síntoma fue HTTP 500 en producción sin que ningún harness lo detectara. Incluye una lista de **deuda conocida** (rutas ya huérfanas de módulos modalizados, fuera de alcance) que NO debe crecer: cualquier ruta huérfana nueva falla el harness. |
| `harness_shares_module.php` | `fix-shares-module-regression` | **Contrato del módulo de compartidos** (60 aserciones): la navegación de navegador devuelve la VISTA y la API devuelve JSON, previsualización que no muta, depuración bulk que respeta el alcance del usuario y reporta omitidos/fallidos, verificación por cursor que cubre cada enlace exactamente una vez, filtros de estado que particionan el conjunto, orden por NIVEL de permiso y por GRUPO de estado, `422` en sort/filtro inválido, autorización por propietario en el detalle, `password_hash` ausente de toda respuesta, y la depuración que nunca borra filas de `files`. |
| `harness_modules_rename_audit.php` | `schema-clarity-rename-merged-into` | **Auditoría módulo-por-módulo** (10 módulos, 36 aserciones): Mis Archivos, Shares, Admin Storages, Mis Avisos, API Transcriptor, Media Editor, Dashboard, Papelera, Canal/Grabador, Cross-Module (observers + casts). Cada módulo valida su operación crítica con los nombres nuevos y reporta PASS/FAIL por sección. |

### Runbook: `harness_mis_avisos_clip_limit.php`

```bash
# Desde la raíz del repo
cd app && php tests/harness_mis_avisos_clip_limit.php
# exit 0 = OK, exit 1 = alguna aserción falló
```

**Cuándo correrlo:**
- Tras cualquier cambio en `MediaClipController.php` (lógica de `clip()`,
  `processLegacySegments`, guard de la línea 126).
- Tras cualquier cambio en `User::hasReachedClipLimit()` /
  `User::mediaEditorClipsThisMonth()` / `User::canUseMediaEditor()`.
- Tras cualquier cambio que toque la capability `can_clip` en
  `MentionsSearchService` o el deep-link Mis Avisos → editor.
- Tras migraciones que agreguen/modifiquen columnas en `media_edit_jobs`
  (`status`, `user_id`, `created_at`).

**Qué verifica (8 aserciones):**
1. **(a)** 1 clip confirmado → `MediaEditJob` con `status='done'` → `count=1`,
   `hasReachedClipLimit()=false`.
2. **(b)** `preview=true` con `count=limit` → response `!= 403` (guard NO
   dispara; resto puede fallar 5xx en fixture fake — eso prueba el bypass).
3. **(c)** 2 clips → `count=2=limit`, `hasReachedClipLimit()=true`.
4. **(d)** 3er intento sin preview → **HTTP 403** con body conteniendo
   "Límite" (o su forma unicode-escaped `\u00ed`).
5. **Admin**: 3 jobs done con `limit=1` → `hasReachedClipLimit()=false`
   (admin bypass en `User.php:126`).
6. **Failed**: `status='failed'` no incrementa el conteo.
7. **`limit=0`**: ilimitado (`hasReachedClipLimit()=false` aunque `count=5`).
8. **Sin editor**: `media_editor_enabled=false` → **HTTP 403** con body
   "Editor de medios no habilitado" (guard previo al del cupo).

**Caveats documentados:**
- La aserción (b) NO verifica que la pipeline ffmpeg/ffprobe del preview
  funcione — solo verifica que el guard del 403 NO dispara. Esto es
  intencional (decisión de diseño 4: "sin ejecutar ffmpeg en el harness").
- El fixture mp4 (`/tmp/hmcl_<tag>/emision.mp4`) contiene bytes random;
  suficiente para pasar el check `file_exists()` pero no para que ffmpeg
  produzca un output válido. Esa parte la cubren los tests de integración
  del transcriptor.
- El harness hace queries ligeras y acotadas a filas con prefijo
  `hmcl_*`; no satura la BD.
- **Migrations**: prefijo de fecha, ej. `2026_05_13_100002_add_session_fields_to_users_table.php`.
- **OpenSpec**: specs en `openspec/specs/`, cambios activos en `openspec/changes/`.
- **Acceso a `$validated`/`$request` para claves opcionales**: SIEMPRE
  extraer a variable local con `?? null` antes de usarla, o usar `data_get(...)`.
  PHP 8.4 lanza `Undefined array key` como `ErrorException` para claves
  faltantes, lo que produce un 500 opaco `{"message":"Server Error"}` para
  el operador. Referencia: regresión del 2026-09-09 documentada en
  `openspec/changes/archive/2026-09-09-fix-avisos-scanlaunch-from-undefined-key/`
  (línea 416 de `AvisosInteligentesController`). Patrón seguro:
  ```php
  $preset = $validated['preset'] ?? null;
  $from   = $validated['from']   ?? null;
  $to     = $validated['to']     ?? null;
  'window_label' => $preset ?: (($from || $to) ? 'custom' : 'global'),
  ```
- **Auth**: SIEMPRE `session('user_id')`, NUNCA `auth()->user()`.
- **Servidor**: NO Vercel ni Supabase. nginx + PHP-FPM sobre cloud.mediaserver.com.co.
- **Facades**: todo facade (`DB`, `Cache`, `Log`, `Storage`, `Mail`, etc.)
  usado dentro de `app/app/Services/*.php` debe tener su
  `use Illuminate\Support\Facades\X;` correspondiente. Sin el import, PHP
  resuelve `X` dentro del namespace actual (`App\Services\X`) y lanza
  `Class "App\Services\X" not found` → HTTP 500. Referencia: regresión del
  2026-09-06 documentada en
  `openspec/changes/fix-storage-sync-missing-db-facade-import/` (caso
  `StorageSyncService::isFileLinked()` con `DB`). Harness de regresión:
  `tests/harness_storage_sync_is_file_linked.php`.
**Watermarks del escaneo de avisos** (change `avisos-keyword-storage-watermark` +
  `avisos-scan-coverage-reconciler-and-partition` +
  `avisos-scan-coverage-observability-and-ux`):
  `(keyword_id, storage_provider_id)` en la tabla `keyword_scan_watermarks`
  con PK compuesta y avance monotónico vía UPSERT `GREATEST`. El cursor global
  `SystemSetting('avisos_scan_cursor')` está RETIRADO.

  Cualquier mutación sobre `keyword_scan_watermarks` DEBE pasar por el servicio
  `App\Services\Ia\WatermarkReconciler` (`ensureForUser`, `ensureForKeyword`,
  `ensureForStorage`, `rewindPair`). NUNCA SQL inline en modelos.

  Toda mutación queda registrada en `watermark_audit_log` (append-only):
  `actor_user_id` (NULL para hooks automáticos), `action` (enum:
  `rewind_pair`, `full_scan`, `hook_auto`, `reconcile`), `keyword_id`,
  `storage_id`, `before_value`, `after_value`, `metadata` (JSONB).

  Endpoints sensibles (`POST /scan/rewind`, `POST /scan/full`) usan el
  middleware `audit.admin.action` para inyectar el actor y registrar la
  traza automáticamente.

  **Cache epoch (invalidación inmediata)**: `system_settings.coverage_cache_epoch`
  se incrementa vía `CacheEpoch::bump()` en cada mutación del Reconciler. La
  cache key de `coveragePaginated` lo incluye, así el rewind se ve en la UI
  sin esperar al TTL.

  **Retención de audit log**: `avisos:archive-audit-log --days=90 --dry-run`
  cuenta; sin `--dry-run` mueve filas de `watermark_audit_log` a
  `watermark_audit_log_archive` en chunks de 1000 (sin pérdida de auditoría).

  **Full scan en background**: `POST /scan/full-bg` retorna runId + 202.
  El worker `avisos:full-scan-run` actualiza la cache key cada iteración.
  La UI hace polling cada 2s al endpoint de status.

  Runbook operativo:
  - `php artisan avisos:reconcile-watermarks [--dry-run] [--user=ID]`:
    detecta drift en cobertura y repara pares faltantes.
  - `php artisan avisos:reset-watermark-counters`: reinicia los contadores.
  - `php artisan avisos:ensure-month-partition --month=YYYY-MM`:
    scaffolding para particionamiento de `segment_keyword_hits` (NO activa
    la partición hoy; ver `Change 'partition-segment-keyword-hits'` cuando
    se supere el trigger de 10M filas).
  - `php artisan avisos:archive-audit-log --days=90`: archiva log viejo.
  - `php artisan avisos:rescan-keyword {keyword|id} [--dry-run] [--user=ID]`:
    re-indexa una keyword con la regla de matching vigente (borra sus hits
    en chunks, rebobina watermarks vía `WatermarkReconciler::rewindPair`
    con auditoría, re-escanea por pares). Usado por el change
    `avisos-keyword-word-boundary-matching` para limpiar los falsos
    positivos de "petro" (petróleo/Petromil/...). Siempre correr `--dry-run`
    primero.

## Regla de matching de menciones: frontera de palabra

El matching de keywords (motor universal `KeywordMatcher`, backfill
`MentionBackfillService`, fallback `LegacyKeywordMatcher` y el resaltado JS
del visor) exige FRONTERA DE PALABRA: una keyword solo matchea cuando el
carácter adyacente a ambos lados del match no es letra ni dígito Unicode.
La verificación es por CARÁCTER UTF-8 completo (no por byte) — mismo enfoque
que `CorrectionService::isWordCharAt`. Helper central:
`App\Services\Ia\KeywordBoundaryMatcher` (`countOccurrences`,
`firstPosition`, `matchesWord`). El `str_contains`/LIKE por subcadena se
conserva SOLO como pre-filtro rápido (es superset de la frontera).
Regresión: `tests/Unit/KeywordBoundaryMatcherTest.php`.

Guardrail anti-abuso en creación de keywords (cliente y admin): la forma
normalizada debe medir >= 3 caracteres (`MisAvisosController::passesMinLength`).
No invalida keywords cortas preexistentes.

  Referencia: `app/app/Services/Ia/WatermarkReconciler.php`,
  `app/app/Services/Ia/AuditLogArchiver.php`,
  `app/app/Services/Ia/CacheEpoch.php`,
  `app/app/Services/Ia/AvisosScanService.php` (métodos `selectCandidates`,
  `bumpWatermarks`, `runFullScan`, `coveragePaginated`).

## Caveats del módulo de cobertura de watermarks

Tres situaciones conocidas con comportamiento aceptable-no-óptimo, documentadas
para futuros mantenedores:

### (a) Mutaciones directas en BD bypassan la cache

Si un admin o script hace `INSERT` / `UPDATE` / `DELETE` directo en `keyword_scan_watermarks`
sin pasar por `WatermarkReconciler`, el contador `CacheEpoch` NO se incrementa y la UI
sigue mostrando el estado cacheado hasta que el TTL de 60s expire o se ejecute
`avisos:reset-cache-coverage`.

**Workaround**: ejecutar `php artisan avisos:reset-cache-coverage` después de mutaciones directas.

### (b) Race condition teórica aceptada entre CacheEpoch y lecturas concurrentes

`CacheEpoch::bump()` ejecuta `UPDATE system_settings SET value = value + 1` que es atómico
en PG, pero DOS mutaciones concurrentes generan DOS bumps distintos. Una lectura
concurrentada puede ver el primer bump sin el segundo, lo que daría un cache miss
"temprano" pero no incorrecto (siempre refleja un epoch ya committed).

**Severidad**: baja. El peor caso es una lectura extra a BD, nunca datos stale.

### (c) `AvisosScanService::coverage()` deprecado pero existente

El método legacy (no paginado) sigue existiendo por compatibilidad. Emite
`E_USER_DEPRECATED` solo en `APP_DEBUG=true`. En producción es silencioso.
Un caller externo que use el método seguirá funcionando idénticamente al
comportamiento pre-deprecation.

**Workaround**: si necesitas paginación, usa `coveragePaginated()` directamente.

## Rollback del change `optimize-transcriptor-dispatch-throughput`

Tres pasos para volver al estado anterior si la feature rompe en producción:

```bash
# 1. Revertir la migración (las cuatro columnas son nullable, drop limpio).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan migrate:rollback --step=1

# 2. Revertir el merge (mantiene la migracion revertida en sync con el codigo).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash-de-la-feature>

# 3. Reiniciar los workers para que vuelvan a la clase anterior de
#    TranscriptionTickCommand (cachean la definicion en memoria).
systemctl restart 'tcloud-transcription-batch-*'
```

**Freno de emergencia alternativo** (sin deploy): `dispatch_paused=true` en
`system_settings` o `TRANSCRIPTOR_DISPATCH_PAUSED=true` en `.env` deja el
descubrimiento corriendo pero corta el envio. El siguiente tick NO encola,
los endpoints de envio devuelven HTTP 423, y el resto del sistema sigue
funcionando. Es el interruptor de seguridad antes de decidirse por el
rollback completo.

## Freno de emergencia del botón "Ver transcripción" en Mis Archivos

**Change:** `mis-archivos-transcript-viewer` (2026-09-10). El botón nuevo en
la columna Acciones de Mis Archivos puede ocultarse globalmente sin deploy
poniendo `FEATURE_MIS_ARCHIVOS_TRANSCRIPT_VIEWER=false` en `.env` (y luego
`php artisan config:cache`). El visor en sí (Mis Avisos y Mis Archivos)
sigue funcionando: solo se oculta el botón que lo abre desde Mis Archivos.
El visor compartido (`Alpine.store('transcriptViewer')` en
`layouts/app.blade.php`) lee el flag desde `window.tcloudFeatures` en cada
render del botón, así que basta con refrescar la página para que aplique.
Comportamiento equivalente al rollback completo: el cliente ve sus archivos
como antes del change.

Para volver al estado normal: quitar la línea del `.env` y correr

## Reprocesar completados (`--include-done` / checkbox "Incluir completados")

**Change:** `transcriptor-rescan-completed`. El botón "Escanear storages" tiene
un tercer checkbox, "Incluir completados", que reprocesa transcripciones en
`state='done'` (mismo trato que el path de fallidos, distinto `state`).

**Comportamiento:**
- Conserva el archivo en disco y la fila; solo sobreescribe `srt_content` al
  confirmar el nuevo resultado del upstream.
- Bumpea `retries++` (sirve como "veces reprocesado").
- Si el archivo se borró del disco, promueve la fila a `state='dead'` con
  `error_message` mencionando "Archivo no accesible".
- Si el reenvío falla upstream, la fila queda en `state='error'` con el
  `srt_content` viejo intacto como fallback. En el siguiente batch con
  `--include-failed`, la fila es elegible para un nuevo reintento.
- El filtro de fecha es por `finished_at` (no `created_at`): el operador piensa
  en "lo que terminó hoy", no en "lo que se creó hoy".

**Lock `ShouldBeUnique` (R1):** `ConvertAndTranscribeJob` es `ShouldBeUnique`
con `uniqueFor=900s` keyed por `file_id`. Antes del dispatch, `collectDoneCandidates`
libera el lock vía `Cache::lock('laravel_unique_job:' . ConvertAndTranscribeJob::class . ':' . $fileId)->forceRelease()`.
El patrón del cache key viene de `Illuminate\Bus\UniqueLock::getKey()`
(vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php).

**Verificación operacional:**
- Log por corrida: `grep "rescan-done\|collectDoneCandidates" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log`
- Conteo en estimación previa: campo `done_rescan` en `/ia/api-transcriptor/scan/estimate`.
- Comando CLI directo: `php artisan transcription:scan-and-submit --include-done --days=0 --batch=200 --from=12092026 --to=12092026`

**Rollback (sin deploy de emergencia):** ignorar la flag en cualquiera de los
tres puntos — UI checkbox, controller propaga al comando, comando invoca el
service. Cero migración que revertir. Si se quisiera reversión completa:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash>
systemctl reload php84-php-fpm   # liberar opcode cache
```

No hay workers que reiniciar (cambio NO toca `ConvertAndTranscribeJob` ni
`TranscriptorTickCommand`). El regulador de cola Redis y el cron automático
siguen funcionando idéntico.

`php artisan config:cache`.

## Rollback del change `fix-transcriptor-batch-bg-launcher`

Tres pasos para volver al estado anterior si el botón "Escanear storages"
vuelve a fallar en producción. El cambio es NO disruptivo: solo toca el
trait `RunsBackgroundCommands` y un controller. No hay migración de BD
involucrada y los workers supervisord (`tcloud-transcription-batch-*`)
no necesitan reinicio — el bug original era 100% en el wrapper de
lanzamiento del controller, no en los workers de Redis.

```bash
# 1. Revertir el merge (un solo archivo crítico: el trait).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash-de-la-feature>

# 2. Recargar PHP-FPM para que el trait revieja quede en memoria de
#    los workers. Sin esto, PHP-FPM sigue corriendo la versión cacheada
#    en opcode del trait viejo.
#    Ajustar al sistema de gestión de PHP-FPM propio del servidor:
systemctl reload php84-php-fpm    # o el equivalente según el entorno
# (alternativa equivalente): `nginx -s reload` + reload del pool FPM

# 3. Verificación rápida post-rollback (sanity, no obligatoria):
#    Click en "Escanear storages" manualmente en la UI. Si el bug
#    ORIGINAL (modal colgado en "Iniciando proceso en background...")
#    vuelve a aparecer, el rollback no se aplicó completo — revisar
#    que `git revert` haya tocado
#    app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php.
```

**Por qué este rollback es seguro**:
- Los otros dos callers del trait (`CorreccionesController::apply`,
  `AvisosInteligentesController::scan`) ya respetaban el contrato
  antes del cambio (pasaban `$cmd` sin `&` ni redirección). El
  revert NO rompe esas rutas — siguen funcionando porque la firma
  del trait con `?string $logFile = null` y `?string $cacheKey = null`
  es backward-compatible (Laravel/IDE ignoran los args extra en
  callers que no los pasan).
- Si el operador quiere volver a usar "Escanear storages" con el
  revert aplicado, el bug original reaparece (modal colgado). Eso
  ES la señal de que el revert funcionó: significa que el trait
  viejo está activo y el controller viejo (que le pasaba `&` al
  `$cmd`) está corriendo. En ese caso, **no seguir usando el botón
  manual** y aplicar el fix nuevamente cuando se diagnostique la
  regresión.

**Freno de emergencia alternativo** (sin deploy): mientras se
diagnostica, el operador puede ejecutar el escaneo desde CLI
mientras el botón UI está roto:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
/usr/bin/php84 artisan transcription:scan-and-submit --days=0 --batch=200
```

El cron `TranscriptionTickCommand` también corre el mismo comando
in-process (no vía `execBackground`), por lo que el escaneo
automático sigue funcionando aunque el botón manual esté roto.

## Cómo agregar un nuevo módulo al dashboard (`dashboard-modular-partials`)

El dashboard (`/dashboard`, `dashboard.admin` + `dashboard.user`) usa el patrón
**partials auto-gated**: cada módulo inteligente expone una tarjeta como partial
Blade independiente en `app/resources/views/dashboard/partials/` que decide
internamente si renderiza según el flag del modelo. El `DashboardController`
siempre hace `@include` sin condicionar, así agregar un módulo nuevo cuesta:

1. Crear `app/resources/views/dashboard/partials/_<modulo>.blade.php` con
   header documentando el shape de `$data` (admin) y/o `$user` (cliente).
   Cada partial declara su flag de gating en el primer `@if`.
2. Agregar el helper correspondiente en `App\Services\Dashboard\DashboardDataProvider`
   (`buildAdminXxxData()` y/o `buildClientXxxData()`) que retorne el slice
   de datos ya calculado (queries acotadas, sin N+1). El provider decide el
   tier de cache (frío/tibio/caliente) de cada bloque.
3. Registrar el bloque en `buildAdmin()` / `buildClient()` y agregar el
   `@include('dashboard.partials._<modulo>', ['context' => 'admin', 'data' => $dashboardData['<modulo>']])`
   (o `'context' => 'client', 'user' => $user`) en `admin.blade.php` /
   `user.blade.php`. El controller desembolsa `['data']` de cada sobre antes
   de pasar a la vista.

Contrato del partial (header del archivo):
```
Recibe $context ('admin' | 'client') + $user? + $data (shape tipado).
Reglas:
  1. Decide solo si renderiza según $context + flag del modelo.
  2. Si no aplica → vacío (nunca rompe el dashboard).
  3. data-dashboard-partial="<id>" en el contenedor para tours estables.
  4. Icono FontAwesome consistente + shell `bg-white rounded-xl shadow-sm
     border border-slate-200 p-5`.
```

**Privacidad**: los partials en contexto cliente NO deben incluir datos
derivados de `watermark_audit_log`, `keyword_matches`, `alert_logs`,
`drift_missing`/`drift_orphan`. Solo estado del módulo (config + cuotas).

**Validación**: cualquier cambio en partials debe pasar
`php tests/harness_dashboard_partials.php` (22 aserciones sobre gating,
shape, privacidad y separación de contexto).

## Cupo de canales por par y adopción remota (`medios-puntuales-cupos-y-adopcion-remota`, 2026-10-22)

**Bug corregido**: `CanalController::store()` leía `$acceso->limite_canales` sobre un
modelo `Grabador` (columna inexistente → `null`), y `usados >= null` evalúa **siempre
true**. Ningún usuario no-admin podía crear canales con cualquier límite; el valor
editado en Grabadores (4→5) se guardaba pero no se aplicaba. El rechazo además era
invisible porque ninguna vista del módulo renderizaba `session('error')`.

### Fuente única del cupo

```php
App\Services\GrabacionesPuntuales\CupoCanalesService
  ->limite($grabadorId, $userId): ?int      // null = sin asignación (NO ilimitado)
  ->uso($grabadorId, $userId): int          // count(canales) del par
  ->resumen($grabadorId, $userId): ?array   // limite, usados, disponibles, ruta_base
  ->resumenBloqueado(...)                   // idem + lockForUpdate (dentro de transacción)
  ->puedeCrear($grabadorId, $userId, $isAdmin): bool
```

Reglas invariantes:
- **NUNCA** leer el límite como atributo del modelo `Grabador`. Vive en
  `grabador_usuario.limite_canales`.
- `limite = 0` se trata como **sin asignación** (null), no como ilimitado.
- Sin pivote → denegar con mensaje explícito, nunca comparar contra `null`.
- El admin bypassa el cupo.
- `store()` evalúa el cupo y hace el INSERT en la misma transacción con
  `lockForUpdate` del pivote (dos POST concurrentes no exceden el límite).

### Bajar el límite NO borra canales

`GrabadorController::actualizarAsignacion` escribe el pivote y, si
`usados > limite`, responde con `warning` + `excedentes[]`. La poda requiere
`prune=true` explícito; si el `DELETE` remoto falla, la transacción aborta
completa y no se borra ninguna fila local.

`GrabadorController::asignarUsuario` persiste el `limite_canales` recibido
(default 10) y provisiona exactamente esa cantidad de slots. Antes hardcodeaba 10.

### Detección y adopción de canales remotos

`App\Services\GrabacionesPuntuales\AdopcionRemotaService` clasifica los canales
del grabador sin fila local:

| Confianza | Regla | Acción |
|---|---|---|
| `adoptable` | hijo **directo** del `ruta_base` del par **y** prefijo compartido con algún slot local | se puede integrar con un clic |
| `sugerido` | vive bajo el `ruta_base` pero sin coincidir familia, o en subdirectorio anidado | solo selección manual explícita |
| `fuera_de_alcance` | no cuelga de ningún `ruta_base` | no se ofrece |

**Adoptar = INSERT local espejo. NUNCA emite POST/PUT/DELETE al grabador.**
Idempotente por `(grabador_id, api_canal_id)` y `(grabador_id, slot_nombre)`.
Re-verifica el `api_canal_id` contra la API antes de insertar. Resuelve
`formato_salida` nulo desde `grabadores.tipo`. **No se bloquea por cupo**: el
canal ya graba; la respuesta reporta `par.excedido` para que el admin suba el
límite.

### Endpoints y comando

- `POST /grabaciones-puntuales/canales/sincronizar` — vinculación + detección
  (devuelve `actualizados`, `ya_ok`, `sin_match`, `remotos_detectados`,
  `adoptables`, `sugeridos`, `fuera_de_alcance`).
- `POST /grabaciones-puntuales/canales/adoptar` — admin only, body
  `{grabador_id, user_id, api_canal_ids[]}`.
- `GET /grabaciones-puntuales/canales/detectar/{grabador}` — solo lectura, para
  el panel de cupos del modal de usuarios en Grabadores.
- `php artisan canales:detectar-remotos [--grabador=] [--user=] [--json] [--adoptar]`
  — dry-run por defecto; `--adoptar` solo integra los `adoptable`.

### Feedback visible

`resources/views/partials/flash-toast.blade.php` renderiza
`session('success'|'warning'|'error')` como toast con auto-dismiss. Está incluido
en `canales/{index,create,edit}` y `grabadores/{index,show}`. Los formularios
preservan `old()`. Sin este partial, cualquier `back()->with('error', ...)` del
módulo era invisible para el usuario.

### Regresión

```bash
cd app
php tests/harness_canales_cupo.php        # cupo: 24 aserciones
php tests/harness_canales_adopcion.php    # adopción: 30 aserciones
php tests/Unit/CupoCanalesServiceTest.php # unitario: 10 tests
```

### Rollback

`git revert` del commit. Sin migración. La adopción solo INSERTa filas con
`api_canal_id`; revertir el código las deja válidas y visibles.

## Cache por tiers del dashboard (`dashboard-tiered-cache`)

El payload de `/dashboard` se cachea por tiers de volatilidad en
`App\Services\Dashboard\DashboardDataProvider`. Cada bloque se expone en un
sobre uniforme `{data, generated_at, stale}`; el controller desembolsa `data`
y la vista muestra `Datos actualizados hace X` a partir de `generated_at`.

| Tier | Bloque | Key | Fresh / Stale | Mecanismo |
|---|---|---|---|---|
| ❄ frío | stats globales (`total_*`, `storage_used`) | `dashboard:cold:stats` | 900 / 1800 s | `Cache::flexible` |
| ❄ frío | agregados admin media editor | `dashboard:cold:media-editor` | 900 / 1800 s | `Cache::flexible` |
| ~ tibio | resumen Mis Avisos (4 KPIs) | `dashboard:warm:mis-avisos:e{epoch}` | 120 / 600 s | `Cache::flexible` |
| ☀ caliente | RAM/SHM, sesiones, bg_jobs | sin cache | — | por request |

- **TTLs configurables por env**: `DASHBOARD_COLD_TTL`,
  `DASHBOARD_COLD_STALE_TOTAL`, `DASHBOARD_WARM_TTL`,
  `DASHBOARD_WARM_STALE_TOTAL` (ver `app/config/dashboard.php`). Subir el TTL
  no requiere deploy de código, solo `php artisan config:cache`.
- **Invalidación del tibio**: la key incluye `CacheEpoch::get()`; un
  rewind/reconcile de watermarks se ve en la siguiente carga sin esperar TTL.
- **El resumen tibio es acotado**: `DashboardService::coverageSummary()` solo
  calcula `pairs_*` + `drift_negative`. NO paga `auditRecent`, `scansRecent`,
  `readiness` ni `driftReport` completo.
- **Cliente sin cache**: los bloques de `buildClient()` son por-usuario y
  baratos; se sirven en vivo para no filtrar datos entre usuarios.

### Invalidación manual

```bash
cd app && php artisan dashboard:clear-cache
```

Olvida `dashboard:cold:*` y `dashboard:warm:mis-avisos:e{epoch-5..epoch+1}`.
NO toca `coverage:dashboard` ni las keys del `WatermarkReconciler` (esas se
invalidan solas con el bump del epoch).

### Verificación

```bash
cd app && php tests/harness_dashboard_tiered_cache.php
```

Mide frío vs caliente (referencia: ~1.5 s → ~9 ms, ratio ~170x), valida el
sobre uniforme, la preservación de shapes, la frescura y la key por epoch.

### Rollback

1. `git revert <commit-hash-de-la-feature>` (el change NO tiene migración).
2. `php artisan config:cache` si se tocó `.env`.
3. No hay estado persistente que limpiar: las claves Redis expiran solas.
   Para forzar limpieza inmediata tras el revert: `redis-cli -a '...' -n 1
   --scan --pattern 'tcloud_cache_dashboard:*' | xargs -r redis-cli ... DEL`
   (usar la DB del cache, no la de sesiones).

**Freno de emergencia sin deploy**: subir `DASHBOARD_COLD_TTL` (y
`..._STALE_TOTAL`) en `.env` + `php artisan config:cache` hace el cache más
conservador; el comportamiento funcional no depende del cache.

## Cómo agregar un nuevo job al indicador global (`bg-job-indicator-widget`)

El widget flotante global (`app/resources/views/components/bg-job-indicator.blade.php`,
incluido desde el layout) muestra cards con los jobs en background activos en
cualquier módulo. Cada módulo expone su job a través de un "scanner".

Para agregar un módulo nuevo:

1. Crear `app/app/Services/BgJobs/XxxJobScanner.php` con un método estático
   `scan(): array` que devuelva `[]` si no hay jobs activos, o un array de
   jobs normalizados al shape:
   ```php
   [
       'kind' => 'kebab-case-id',
       'runId' => '<identificador único de la corrida>',
       'module' => '<nombre legible>',
       'label' => '<etiqueta humana>',
       'startedAt' => '<ISO8601>',
       'progress' => [ ... ],   // campos arbitrarios; el widget los renderiza
       'url' => '/path?focus=bg-{kind}-{runId}',
   ]
   ```
2. Si el job se lanza en background (vía `RunsBackgroundCommands::execBackground`
   o similar), asegurarse de que el módulo registre su runId en una cache key
   que el scanner pueda consultar (ej: `transcription_batch:active_runs` para
   el transcriptor). Al terminar el job, remover el runId de esa lista.
3. Registrar el scanner en `app/app/Services/BgJobRegistry.php`:
   ```php
   private array $scanners = [
       'kebab-case-id' => [App\Services\BgJobs\XxxJobScanner::class, 'scan'],
   ];
   ```
4. Si el módulo tiene una página con modal que se beneficia del deep-link
   `?focus=bg-{kind}-{runId}` desde el widget, leer el parámetro en su `init()`
   de Alpine y abrir el modal manualmente. Sin ese focus, NO auto-abrir nada
   (dejar que el operador decida cuándo).

Convención de deep-link: `?focus=bg-{kind}-{runId}`. El módulo debe
parsearlo y abrir su modal solo si coincide con un runId activo.

## Cambios de UX documentados

- **Módulo de Avisos Inteligentes**: recargar la página con un escaneo activo
  ya NO fuerza el cambio a la pestaña "Escaneo" ni abre el modal de progreso
  automáticamente. El operador ve el widget global en la esquina inferior
  derecha y decide cuándo abrir el detalle haciendo click en "Ver detalles".
  Esto era un bug reportado el 2026-09-09.
- **Módulo del API Transcriptor**: mismo principio. El modal del batch solo
  se abre con click explícito en "Escanear storages" o vía deep-link
  `?focus=bg-transcriptor-batch-{runId}` desde el widget.

## Modo mensual del escaneo de avisos (fix-avisos-scan-by-months-no-saturation)

Cuando el operador elige **"Histórico completo"** + **"Forzar re-escaneo"**
en `/ia/avisos-inteligentes`, el worker itera por meses en vez de hacer un
scan monolítico. La unidad de trabajo acotada evita saturar las conexiones
de PostgreSQL (cada mes es una query barata por índice).

**Reglas operativas:**
- Se activa solo con la combinación `noWindow=true && force=true && !from && !to && !preset`.
- El planning usa `min/max(finished_at) WHERE state='done'` (query barata).
- Cada mes usa rango semi-abierto `[inicio_del_mes, inicio_del_mes_siguiente)`.
- Entre meses el worker hace `DB::disconnect()` + `sleep(1)` para liberar
  conexiones de BD y darle espacio a PHP-FPM.
- El plan persiste en `avisos_scan_bg:{runId}.month_plan` con el mismo
  TTL (2h). Si el worker crashea, retoma desde el último mes `pending`.
- Los `runId` que llegan con `scan_cutoff_at` viejo siguen funcionando
  porque el plan es un snapshot.

**Endpoints:**
- `POST /ia/avisos-inteligentes/scan/run-bg` — devuelve `{runId, mode: 'monthly'|'classic', month_count, first_month, last_month}` cuando es mensual.
- `POST /ia/avisos-inteligentes/scan/run-bg/preview` — solo planning, no muta nada. Usado para mostrar el `# de meses` al operador antes del launch.

**Cache shape (modo mensual):**
```php
'avisos_scan_bg:{runId}' => [
    'mode' => 'monthly',
    'month_plan' => [['2024-03', 'done'], ['2024-04', 'running'], ...],
    'months_total' => 30,
    'months_done' => 1,
    'current_month' => '2024-04',
    'scan_cutoff_at' => '2026-09-09T14:25:42-05:00',
    // ... campos legacy (scanned, hits_new, etc.) se mantienen
]
```

**Migración opcional:**
- `transcriptions_state_finished_at_idx` — `CREATE INDEX CONCURRENTLY`
  sobre `(state, finished_at) WHERE state='done'`. Acelera el query por mes.
  Si no se aplica, el plan mensual sigue acotando el trabajo aunque las
  queries tarden más.

**Deduplicación del bulk INSERT de watermarks (fix-avisos-watermarks-cardinality-violation):**
cuando el scan procesa varias transcripciones del mismo storage que
comparten keywords activas, el bulk INSERT a `keyword_scan_watermarks`
podía disparar `SQLSTATE[21000]: Cardinality violation` (filas
duplicadas dentro del mismo batch). El service expone
`AvisosScanService::dedupeBumpSet()` que consolida por
`(keyword_id, storage_provider_id)` antes del INSERT: MAX de
`scanned_until`, SUM de `candidates_total`, SUM de `hits_total`. Helper
estático testeable en `tests/Unit/AvisosScanServiceBumpDedupeTest.php`.
Se invoca automáticamente al inicio de `bumpWatermarks()`.

Reproducido y resuelto el 2026-09-10: el modo mensual procesó 3/3 meses
con `failed: 0` (150 transcripciones escaneadas, sin el error).

## Cache del scope heredado de `StorageProvider`

**Change:** `2026-09-12-api-transcriptor-index-perf-cache`.

`StorageProvider::resolveInheritedTranscriptionScope(int $rootId)` está cacheado
en Redis con TTL configurable (default **300 s**). La cache del propio TTL
(`transcriptor.scope.ttl`) vive 60 s.

### Contrato de invalidación

**Quién debe invalidar** — toda mutación de `storage_providers.base_path` o
`transcription_enabled` DEBE llamar `StorageProvider::forgetInheritedTranscriptionScope($rootId)`
(o el helper que ya lo hace, ej. `ApiTranscriptorController::toggleStorage`).

Único mutador hoy: `ApiTranscriptorController::toggleStorage` (línea 738+ del
controller). Si se agrega un endpoint UI que edite `base_path`, ese endpoint
debe invalidar también. Mismo criterio para `StorageFunnelService::resolveRootIdFor`
(key `transcriptor.root_id_for.{storageId}`, TTL 600 s).

### Helpers públicos

```php
StorageProvider::resolveInheritedTranscriptionScope(int $rootId): array  // cacheado
StorageProvider::forgetInheritedTranscriptionScope(int $rootId): void     // invalidar
StorageProvider::inheritedTranscriptionScopeInfo(int $rootId): array      // hereda cache
```

### Settings relacionados

| Setting | Default | Efecto |
|---------|---------|--------|
| `SystemSetting('transcriptor_scope_cache_ttl')` | `300` | TTL de la cache del scope. `0` = bypass (freno de emergencia). Rango válido `[0, 3600]`. Cambiar el setting tarda hasta 60 s en aplicarse (la key `transcriptor.scope.ttl` cachea el valor leído). |

### Performance observada

- Cold (compute): 4-24 ms / scope
- Warm (cache hit): 1-2 ms / scope
- Reducción de queries en `indexData()` warm: 544 → 24 (cache de SystemSetting TTL + resolveRootIdFor incluida)
- Latencia warm `indexData()`: ~950 ms → ~470 ms

### Diagnóstico

```bash
# Cuantas keys de scope hay cacheadas:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor.scope.inherited.*' | wc -l

# Forzar bypass:
php -r 'require "vendor/autoload.php"; ... App\Models\SystemSetting::set("transcriptor_scope_cache_ttl","0");'

# Reset completo:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor.scope.*' | xargs -r redis-cli -a 'Clouding2026!Redis' -n 1 DEL
```

### Harness de regresión

`tests/harness_api_transcriptor_index_perf.php` — 22 aserciones sobre el cache,
invalidación, bypass, e índice `storage_providers_base_path_pattern_idx`.

## Cache de `/stats`, `/health` y `/empty-folders` (transcriptor)

**Change:** `2026-09-12-api-transcriptor-pending-perf`.

Tres endpoints AJAX del módulo API Transcriptor disparados durante el Alpine
init tienen caches Redis para evitar HTTP calls al upstream transcriptor y
rescaneos de filesystem.

### Endpoints cacheados

| Endpoint | Cache key | TTL default | Override |
|----------|-----------|-------------|----------|
| `GET /ia/api-transcriptor/stats` | `transcriptor:stats:combined` | **300 s** (5 min) | `SystemSetting('transcriptor_stats_cache_ttl')` |
| `GET /ia/api-transcriptor/health` | `transcriptor:health:combined` | **120 s** (2 min) | `SystemSetting('transcriptor_health_cache_ttl')` |
| `GET /ia/api-transcriptor/empty-folders` | `transcriptor:empty_folders:{storage_id}:max{maxDirs}` | 600 s (10 min) | (hardcoded; revisar si necesita override) |

**Justificación del TTL de `/stats` y `/health`**: el operador recarga `/ia/api-transcriptor` después de navegar 1-3 minutos por otros módulos. Con TTL 60s/30s originales, esos caches expiraban antes de la vuelta y la página se sentía lenta al regresar. Con 300s/120s, el reload tras navegación prolongada encuentra los caches calientes y los AJAX pesan <100 ms en lugar de 200-500 ms. Si el operador necesita tiempo real, `SystemSetting::set('transcriptor_stats_cache_ttl', '0')` (bypass).

Ambos `/stats` y `/health` emiten `Cache-Control: max-age=30, private` para
que el navegador no revalide en cada navegación entre pestañas.

### Contrato de invalidación

**Quién debe invalidar** — `POST /api-transcriptor/storages/{id}/toggle`
(=`ApiTranscriptorController::toggleStorage`) ya invalida las tres caches
relevantes en una sola pasada:

```php
foreach ([200, 300, 400, 500] as $cap) {
    Cache::forget("transcriptor:empty_folders:{$storage->id}:max{$cap}");
}
Cache::forget('transcriptor:stats:combined');
Cache::forget('transcriptor:health:combined');
```

Las mutaciones individuales de `transcriptions` (crear / completar / fallar)
**NO invalidan `/stats`**. El TTL de 60 s es aceptable para contadores
informativos; si el operador necesita tiempo real, esperar 60 s.

### Race-safe scan en `/empty-folders`

El scan inicial de cada storage usa `Cache::lock("transcriptor:empty_folders:lock:{storage_id}", 30)`
para serializar: dos requests concurrentes que llegan en cold path solo
ejecutan UN `scandir()` por storage. El segundo espera el resultado del primero.

Sentinel `__none__`: si un storage no tiene carpetas vacías, se cachea ese
sentinel para no re-escanear en cada hit.

### Performance observada (medición live, 2026-09-12)

| Endpoint | Cold | Warm |
|----------|------|------|
| `/stats` | 288 ms | **1.9 ms** |
| `/health` | 8 ms | **1.3 ms** |
| `/empty-folders` (todos los 70 storages) | 3.6 s | **18 ms** |

Page load warm del módulo API Transcriptor (browser total): **~3.5 s → ~1.5 s**.

### Diagnóstico

```bash
# Cuantas stats cacheadas hay:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor:stats:*'

# Forzar bypass global de stats:
php -r 'require "vendor/autoload.php"; ... App\Models\SystemSetting::set("transcriptor_stats_cache_ttl","0");'

# Reset completo:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor:*' \
  | xargs -r redis-cli -a 'Clouding2026!Redis' -n 1 DEL
```

## Cache de endpoints pesados de Avisos Inteligentes, Correcciones y Admin Storages

**Change:** `2026-09-13-perf-audit-and-improve`.

Audit Playwright (2026-09-13) identificó 4 endpoints con latencias inaceptables (>500 ms warm).
Todos ahora cacheados en Redis con TTL configurable.

### Endpoints cacheados

| Endpoint | Cache key | TTL default | Override |
|----------|-----------|-------------|----------|
| `GET /ia/avisos-inteligentes/scan` | `avisos:scan_status` | 60 s | `SystemSetting('avisos_scan_status_cache_ttl')` |
| `GET /ia/correcciones/mining-status` | `correcciones:mining_status` | 30 s | (hardcoded) |
| `GET /ia/correcciones/ai-suggest-status` | `correcciones:ai_suggest_status` | 30 s | (hardcoded) |
| `GET /ia/correcciones/ai-suggest-settings` | `correcciones:ai_suggest_settings` | 60 s | (hardcoded) |
| `GET /admin/storages` (AJAX) | `admin:storages:index` | 60 s | `SystemSetting('admin_storages_cache_ttl')` |

### Contrato de invalidación

**`avisos:scan_status`** — invalidada en:
- `WatermarkReconciler::ensureForUser/Keyword/Storage/rewindPair` (cualquier `bump`).
- `AvisosInteligentesController::saveScanSettings`.

**`correcciones:mining_status` / `correcciones:ai_suggest_status`** — TTL-only. Cambian solo cuando se crea/resuelve una correccion; 30 s de staleness es aceptable.

**`correcciones:ai_suggest_settings`** — invalidada en:
- `aiSuggestSettingsUpdate` (POST).
- `aiSuggestSettingsReset` (DELETE).
- `aiSuggestSettingsRefreshModels` (POST /refresh-models).
- `aiSuggestSettingsApiKey` (POST /api-key).

**`admin:storages:index`** — invalidada en:
- `StorageProviderController::store/update/destroy`.
- `ApiTranscriptorController::toggleStorage()` (afecta `transcription_enabled`).

### Performance observada (medición live, 2026-09-13)

| Endpoint | Cold | Warm |
|----------|------|------|
| `/ia/avisos-inteligentes/scan` | 4939 ms | **2.6 ms** |
| `/ia/correcciones/mining-status` | 78 ms | **0.6 ms** |
| `/ia/correcciones/ai-suggest-status` | 6 ms | **0.4 ms** |
| `/admin/storages` (AJAX) | 1254 ms | **4.0 ms** |

### Runbook de diagnóstico

```bash
# Ver todas las caches del modulo:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_avisos:scan_status*'
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_correcciones:*'
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_admin:storages:*'

# Forzar bypass:
php -r 'require "vendor/autoload.php"; ... App\Models\SystemSetting::set("avisos_scan_status_cache_ttl","0");'

# Reset completo de las caches nuevas:
redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_avisos:*' \
  | xargs -r redis-cli ... DEL
```

## Procesamiento personalizado / "Procesar históricos" (2026-09-16)

Botón + modal en `/ia/api-transcriptor` (cabecera del módulo y tarjeta "Tarea
programada") para **descubrir y encolar manualmente** trabajo por alcance:

- **Alcance**: Hoy / Rango de fechas / Histórico.
- **Tipo de trabajo**: sin transcripción (`--include-failed` ausente, descubrimiento),
  con error (`--include-failed`), completados (`--include-done`).

### Endpoints

| Endpoint | Rol |
|---|---|
| `POST /ia/api-transcriptor/scan/estimate` | **Solo lectura**: cuenta trabajo disponible (sin fila, error, done). No muta. |
| `POST /ia/api-transcriptor/scan/run` | Lanza `transcription:scan-and-submit` en background; devuelve `run_id` (HTTP 202). |
| `GET /ia/api-transcriptor/scan/status/{runId}` | Polling del progreso (cada 2 s desde la UI). |

### Piezas

- `App\Services\Ia\TranscriptorWorkEstimator` — estimación acotada
  (`COUNT_CAP = 50000`); devuelve `capped: true` cuando un conteo toca el techo.
  Mide latencia: hoy ≈ 0.5 s, histórico ≈ 1.4 s.
- `ApiTranscriptorController::estimateScan/runScan/scanStatus` + helpers
  `resolveScanScope` y `toDmY` (ISO `YYYY-MM-DD` → carpeta `DDMMYYYY`).
- Estado Alpine: `pz*` en `index.blade.php`; markup del modal en
  `_settings-tab.blade.php`.

### Reglas de diseño

- **El modal DESCUBRE y ENCOLA, no salta el regulador.** El envío sigue
  gobernado por el stager, el worker PG y la histéresis de la cola remota.
- **Candado de concurrencia** `transcriptor:scan_run:lock`: un segundo lanzamiento
  simultáneo devuelve HTTP 409. Lo libera el comando `ScanAndSubmitCommand` en un
  `finally`, así no depende de que el navegador siga haciendo polling.
- **Estimación barata**: los conteos de `transcriptions` usan `recorded_at`; el
  "sin fila" se resuelve contra la tabla `files` (no recorre el disco).

### Regla histórica que este botón habilita

El worker PG y el stager filtran `recorded_at >= hoy`, así que las filas de
**fechas pasadas** no las toma el pipeline automático. Para procesar históricos
hay que usar este modal con alcance Rango/Histórico; el descubrimiento las crea
y el `bulkDispatch` las encola explícitamente.

## Filtro de tamaño en el escaneo (`min_file_size_bytes`, 2026-09-16)

Una grabación con el stream caído existe en disco pero pesa **0 bytes**. El
tick la encolaba igual, el stage la rechazaba por `min_file_size_bytes`, la
reencolaba cada 5 min y consumía los reintentos (~40 min) antes de morir: la
fila aparecía como "en cola" esperando algo que nunca podría convertirse, con
CPU de ffprobe pagada en cada intento.

**Ahora el filtro se aplica en el ORIGEN**: `DiskScannerService::scanStorage()`
salta los archivos por debajo de `min_file_size_bytes`, así la fila **ni se
crea**. El filtro se reevalúa en cada escaneo, así que si el grabador estaba
escribiendo y el archivo crece después, el siguiente tick lo levanta normal.

| Setting | Default | Notas |
|---|---|---|
| `min_file_size_bytes` | **512000** (500 KB) | `0` = desactivar. Antes 1024. |

También se aplica en `TranscriptionSubmitService::stage()` (para filas ya
creadas) y en `TranscriptionBackfillLostCommand`.

**Ámbito**: solo el pipeline del transcriptor (`DiskScannerService`). El
explorador de archivos usa `StorageSyncService`, que NO filtra por tamaño: un
archivo pequeño sigue siendo visible y descargable.

**Referencia de tamaño**: un programa de 21 min pesa ~20 MB en MP3 y ~12 MB en
M4A; 500 KB equivale a ~30 s de audio. Los archivos por debajo de ese umbral
medidos en producción tenían `word_count = 0` (grabaciones vacías).

**Reversión**: `SystemSetting::set('transcriptor.min_file_size_bytes', '1024')`
(o el valor que se quiera). No hay migración.

## Incidente 2026-09-16: bucle de stagers por `dispatched_at` no limpiado

**Síntoma**: CPU del host al 70-80% sin conversiones visibles en el panel,
25-30 procesos `transcription:stage` concurrentes, load average 42 en 32 cores.

**Causa raíz (dos bugs propios, corregidos en `TranscriptionStageCommand`):**

1. `candidates()` seleccionaba `pending` + `staged_path IS NULL` pero **no
   filtraba `dispatched_at IS NULL`**, mientras que el claim condicional de
   `TranscriptionSubmitService::stage()` sí lo exige. Una fila `pending` con
   `dispatched_at` seteado era candidata pero el claim la rechazaba: ffmpeg se
   pagaba, devolvía `lost_claim` y **el inventario nunca crecía**, así que el
   loop de lotes elegía las mismas filas para siempre.
2. El resultado `lost_claim` se contaba como `staged`, así que el loop no
   detectaba que no había progreso.

Se sumó el `withoutOverlapping(5)` del scheduler: a los 5 min vencía el lock y
arrancaba **otro** stager en paralelo mientras el primero seguía girando.

**Correcciones aplicadas:**

- `candidates()` ahora exige `whereNull('dispatched_at')` (misma condición que
  el claim).
- `lost_claim` es un bucket propio; se propaga por `absorbParallelPayload` /
  `absorbStageResult` / `mergeBatchOut`.
- **Tope duro `MAX_BATCHES_PER_RUN = 20`** por corrida.
- Si un lote entero pierde el claim sin progreso (`lost_claim > 0` y
  `staged === 0` y `requeued === 0`), la corrida corta con warning.
- Fix de datos: `UPDATE transcriptions SET dispatched_at = NULL WHERE
  state='pending' AND dispatched_at IS NOT NULL` (109 filas el 2026-09-16).

**Regla para cualquier reset manual de filas a `pending`**: limpiar SIEMPRE
`dispatched_at` (y `staged_path` si se quiere reconvertir). El worker PG
selecciona por `dispatched_at IS NULL`, y el stager por la misma condición; una
fila `pending` con el sello puesto es invisible para ambos caminos.

```sql
-- Reset correcto de una fila a pending:
UPDATE transcriptions
SET state='pending', job_id=NULL, dispatched_at=NULL, requeue_after_at=NULL,
    error_message=NULL, staged_path=NULL, staged_bytes=NULL, staged_at=NULL
WHERE id = :id;
```

## El paso 1 del poller competía con los workers por CPU

`transcription:poll-results` tiene dos fases: **(1)** reenviar hasta
`stale_resend_limit` pendientes atascados ejecutando **ffmpeg + POST
síncronos**, y **(2)** sondear resultados.

Cada reenvío medido: **~4 s** (ffmpeg). Con el default de 50, la fase 1 consume
**~3.5 min del ciclo** y la fase 2 no llega a correr: los jobs que ya están
`done` en el nodo remoto **nunca se recogen** y el backlog crece.

Medición 2026-09-16: `sondeadas_10min = 0` con ~1.800 filas en `queued`.

**Fix operativo**: `transcriptor.stale_resend_limit = 0`. El reenvío de
atascados lo hace el worker PG con staging paralelo, que es su diseño correcto;
duplicarlo dentro del poller solo destruye el ciclo de sondeo.

| Métrica | Antes | Después |
|---|---|---|
| `queued` | 1.796 | 9 |
| `done` en 10 min | ~0 | 1.059 |
| Ritmo | estancado | -54 queued / +100 done cada 150 s |

**Regla**: el poller sondea, el worker PG convierte y envía. No mezclar ffmpeg
dentro del ciclo de polling.

## Coherencia IA del transcriptor: manual-only (2026-09-16)

`transcriptor.ai_coherence_enabled` arranca en **`false`** (default en código:
`config/transcriptor.php` y `TranscriptorSettings`). El pase LLM de coherencia
NO se ejecuta solo.

**Por qué**: `TranscriptionCoherencePass::apply()` corre dentro de
`TranscriptionProcessor::processDone()`, o sea en el **poller**, en CADA
transcripción ingestada. Con el toggle encendido se gastan tokens en todo el
volumen y el poller cae a ~9.5 s/job (con el gateway LLM caído el tiempo se
paga igual sin obtener corrección). Medición 2026-09-16: **9.5 s/job → 0.26
s/job** (37x) al apagarlo.

Esto completa el change archivado
`2026-08-25-llm-coherence-manual-only-defaults-off`, que ordenaba el default en
`false` pero quedó a medias (solo se aplicó a `llm-correction.enabled`).

### Cómo correrlo a propósito

```bash
# 1. Encender el toggle (AI Settings o directo):
#    SystemSetting::set('transcriptor.ai_coherence_enabled', '1');
#    TranscriptorSettings->flush()
# 2. Disparar el pase sobre el rango deseado:
cd app && php artisan transcription:backfill-coherence --days=7 --batch=5 --sleep=2
# 3. Apagarlo otra vez al terminar.
```

El comando `transcription:backfill-coherence` aborta con WARNING si el toggle
está apagado (no gasta tokens ni toca BD).

### Circuit breaker

`TranscriptionCoherencePass::callWithRetry()` excluye un proveedor LLM tras 5
fallos consecutivos en 10 min (`coherence_breaker:{provider}` en cache). Si
todos quedan excluidos, el pase cae al texto del diccionario sin corrección.

## Zona horaria de PostgreSQL (`fix-pg-session-timezone-bogota`, 2026-09-16)

La conexión `pgsql` fija `timezone => America/Bogota` (override:
`DB_TIMEZONE` en `.env` + `php artisan config:cache`). **No quitar esa clave.**

**Por qué**: Laravel formatea los bindings `DateTimeInterface` con
`format('Y-m-d H:i:s')` en la zona del Carbon (`America/Bogota`), y PostgreSQL
interpreta ese texto naive en la zona de la **sesión**. Con la sesión en UTC
(default de PostgreSQL), cada `timestamptz` escrito desde la app quedaba
corrido **-5 h**: la hora local se guardaba como si fuera UTC.

Columnas `timestamptz` afectadas (las únicas interpretadas):

| Tabla | Columna |
|---|---|
| `transcriptions` | `recorded_at`, `staged_at` |
| `files` | `file_modified_at` |
| `transcription_storage_snapshots` | `captured_at` |

Las columnas naive (`created_at`, `dispatched_at`, `started_at`,
`finished_at`, `last_polled_at`, `requeue_after_at`) NO se ven afectadas:
se guardan como hora de pared y se comparan contra `now()` en el mismo marco.
Ese mismo marco es el que necesitan el watchdog
(`dispatched_at < now()-900s`) y el filtro "hoy" del worker — con la sesión en
UTC, el watchdog se retrasaba 5 h y dejaba filas `processing` varadas.

### Regla para escribir fechas

Todo `Carbon::createFromTimestamp($ts)` debe pasar la zona de la app:

```php
Carbon::createFromTimestamp($ts, config('app.timezone'))  // correcto
Carbon::createFromTimestamp($ts)                          // UTC -> desfase +5h
```

`DiskScannerService` ya usaba `config('app.timezone')`; el fix alineó
`StorageSyncService` (3 sitios) y `FileController` (3 sitios).

### Backfill de datos ya desfasados

```bash
cd app
php artisan transcription:fix-recorded-at-timezone --days=N          # dry-run
php artisan transcription:fix-recorded-at-timezone --days=N --apply
```

Corrige **solo** las filas donde el desfase es demostrable: `recorded_at` ==
hora de pared del nombre tratada como UTC (derivadas de `RecordedAt`). Es
idempotente y no toca las que provinieron de `file_modified_at`/`finished_at`.
Aplicado el 2026-09-16: 35.670 filas de los últimos 2 días.

## Freno con histéresis de la cola remota (`transcriptor-remote-queue-hysteresis-brake`)

El envío al nodo remoto ya **NO** compara `queue_queued >= target_remote_queue`
en crudo. Esa comparación producía ping-pong alrededor del techo: frena en 180,
el nodo drena 1 job, la cola queda en 179 y se reanuda — goteo de 1-2 jobs por
ciclo y el nodo nunca baja de verdad.

Ahora el freno vive en `App\Services\Ia\RemoteQueueBrake` con dos umbrales:

| Setting | Default | Efecto |
|---|---|---|
| `target_remote_queue` | 180 | FRENO inmediato al alcanzarlo. |
| `resume_remote_queue` | 120 | REANUDO solo cuando la cola baja a este valor. |
| `remote_queue_recheck_seconds` | 30 | Con el freno activo, cada cuánto se revalida la cola. |
| `remote_queue_requeue_seconds` | 30 | Aplazamiento del sender al rebotar por este freno (reemplaza los 5 min genéricos solo para este motivo). |

**Comportamiento:**
- Freno **inmediato** en el techo, sin esperar la ventana de revalidación.
- Entre `resume` y `target` el envío permanece frenado (colchón de respiro).
- Reanuda solo si la cola `<= resume` **y** venció la ventana de revalidación.
- **Fail-open**: sin telemetría (`remoteInfo === null`) el freno no aplica.
- Invariante garantizada en código: `resume` se clampea siempre a `< target`
  (aunque el setting sea absurdo), para que el freno no se levante con la
  misma cola que lo activó.

**Pestillo compartido**: `RemoteQueueBrake::CACHE_KEY`
(`transcriptor:remote_queue_brake`) guarda `{braked, queue, checked_at}`. Lo
usan el **planner** (`TranscriptionTickCommand::computeEffectiveBatch` devuelve
0 si el pestillo está activo) y el **sender**
(`TranscriptionSubmitService::send` reencola conservando el staged). Ambos
toman la misma decisión, así que no hay carrera entre tick y worker.

**Invalidación**: `TranscriptorSettingsController::update()` y `reset()` llaman
`RemoteQueueBrake::forget()` cuando cambia la configuración, para que un
pestillo viejo no sobreviva a un cambio de umbrales.

**Observabilidad**: el panel muestra `cfgRuntime.remote_brake`
(`braked`, `queue`, `target`, `resume`, `recheck_seconds`, `requeue_seconds`).
La barra de la cola remota se pinta ámbar cuando el pestillo está activo, y el
pie dice "Frenada (baja a 120 para reanudar) · revalida cada 30s".

**Harness**: `tests/harness_remote_queue_brake.php` (18 aserciones: freno en el
techo, ausencia de ping-pong en 179/150, reanudo con ventana vencida, ventana
vigente, fail-open, coherencia con el planner, aplazamiento corto, invariante
`resume < target`).

**Rollback sin deploy**: poner `resume_remote_queue` igual a
`target_remote_queue - 1` (o cualquier valor cercano al techo) reduce la
histéresis al mínimo; con `resume = target - 1` el comportamiento vuelve a ser
casi el original. No hay migración ni estado persistente que limpiar: el
pestillo expira solo.

## Staging paralelo (`transcriptor-stage-parallel-lotes`, 2026-09-16)

El comando `transcription:stage` (fase 1 del pipeline) ya **NO** opera en
goteo de 1 ffmpeg + sleep. Opera en **lotes paralelos** vía `pcntl_fork`:

- `staging_parallel` (default **4**, rango 1..8): cuántos ffmpeg corren a la
  vez. Cada hijo es un proceso independiente que ejecuta
  `TranscriptionSubmitService::stage()` y reporta el resultado al padre por
  un `stream_socket_pair`. El hijo hace `DB::disconnect()` al arrancar y
  termina con `posix_kill(getmypid(), SIGKILL)` para NO ejecutar destructores
  de Laravel sobre sockets heredados.
- `staging_pace_seconds` (default **0**): pausa al final de cada lote. Con
  0 el stager arranca el lote siguiente apenas termina el actual.
- `staging_budget_bytes` (default 8GB): freno duro por bytes. El stager no
  arranca un lote si el total staged excedería el presupuesto.
- `staging_target_inventory` (default 60): freno por conteo. El tope real es
  `min(inventario, presupuesto)`.

**Razón operativa**: el goteo anterior desperdiciaba el host. Entre
conversiones el CPU caía a ~3% mientras la cola seguía creciendo, y el sender
quedaba sin inventario durante esos huecos. Con 4 paralelos y sin pausa, el
stager mantiene el RAM disk cargado (limitado por `staging_budget_bytes`) y
el sender tiene siempre munición lista. La saturación del host se gobierna
por bytes de tmpfs y por `staging_parallel`, no por un sleep fijo.

**Watchdog por hijo**: 900 s (`CHILD_TIMEOUT_SECONDS`). Si un ffmpeg se
cuelga, el padre lo mata con SIGKILL y cuenta la fila como failed; el
siguiente ciclo del cron la vuelve a candidatar (no tiene staged_path).

**Fallback sin pcntl**: si `pcntl_fork` no está disponible, el comando cae a
secuencial (1 conversión a la vez, sin pausa) y loguea warning. Es señal de
entorno mal configurado, no un modo soportado.

**Rollback sin deploy**: poner `staging_parallel=1` (vía UI o `php artisan
tinker`) vuelve al comportamiento secuencial. Para suspender todo el staging
sin tocar workers: `staging_enabled=false`.

**Comando de diagnóstico**:

```bash
cd app && php artisan transcription:stage --dry-run
# muestra: inventario actual, presupuesto libre, paralelos, candidatos
```

Logs por corrida: `storage/logs/transcription-stage.log` (cada lote) y
`laravel.log` (warnings por hijo sin payload o timeout).

## Cola nativa PG del transcriptor (change `transcriptor-pg-native-queue`)

Post-migracion (cutover 2026-09-15) el modulo API Transcriptor ya **NO usa
Redis como cola de despacho**. La cola ES la tabla PostgreSQL `transcriptions`
misma: las filas `state='pending'` con `dispatched_at IS NULL` y
`recorded_at >= today` son candidatas; el worker las toma con `FOR UPDATE
SKIP LOCKED`.

### Componentes nuevos

| Archivo | Rol |
|---------|-----|
| `app/app/Console/Commands/TranscriptionWorkerCommand.php` | Poleador PG. Una instancia por unit supervisord; corre `SELECT FOR UPDATE SKIP LOCKED LIMIT N` en loop. Signal handlers SIGTERM/SIGINT para salida limpia. |
| `app/app/Console/Commands/TranscriptionWatchdogCommand.php` | Recupera filas en `processing` con `dispatched_at < now() - processing_timeout_seconds` y `submission_committed_at IS NULL` → las pone en `pending` con `regulator_skip_reason='watchdog_recover'`. |
| `app/app/Console/Commands/TranscriptionStorageSnapshotCommand.php` | Cada 15 min: agrega `pending/inflight/sent/error` por storage en `transcription_storage_snapshots`. Alimenta la tarjeta del tab Storages. |
| `app/app/Console/Commands/TranscriptionPruneSnapshotsCommand.php` | Purga diaria (03:00 Bogota) snapshots > 7 dias. |
| `app/app/Console/Commands/TranscriptorPurgeBacklogCommand.php` | One-shot de cutover: migra `target_redis_queue` → `target_pg_queue` y borra backlog pre-migracion. |
| `app/app/Services/Ia/TranscriptionBulkDispatchService.php` | Reemplaza `ConvertAndTranscribeJob::dispatch`. Usado por `POST /ia/api-transcriptor/jobs/bulk-dispatch`, `ScanAndSubmitCommand` y `TranscriptionBackfillLostCommand`. |
| `app/database/migrations/2026_09_15_130000_add_pending_today_dispatch_index_to_transcriptions.php` | Indice parcial `(recorded_at DESC, discovered_at DESC) WHERE state='pending' AND dispatched_at IS NULL`. Acelera el worker query a <5 ms. |
| `app/database/migrations/2026_09_15_130100_create_transcription_storage_snapshots_table.php` | Tabla de snapshots por storage. PK compuesta `(storage_provider_id, captured_at)`. |
| `app/config/supervisor/tcloud-transcription-worker.conf` | Unit supervisord del worker. numprocs=3, stopwaitsecs=3600. |

### Schedules (registrados en routes/console.php)

- `transcriptor:watchdog-processing` cada minuto (withoutOverlapping 60)
- `transcriptor:storage-snapshot` cada 15 minutos (withoutOverlapping 60)
- `transcriptor:prune-storage-snapshots` diario 03:00 Bogota (withoutOverlapping 120)

### Settings renombrados / retirados

- `transcriptor.target_redis_queue` → `transcriptor.target_pg_queue` (mismo valor, mismo semantica)
- `transcriptor.dispatch_stagger_ms` **retirado**. Sin dispatch step como fase distinta; el ritmo lo controla el regulador (lee `/api/metrics/overview`) y el guardrail de `/dev/shm` en `TranscriptionSubmitService::markRequeueable`.

### Decisiones operativas

- **Sin kill switch**: el rollback completo via `git revert <commit>` cubre los escenarios. `dispatch_paused` (existente) + `TRANSCRIPTOR_DISPATCH_PAUSED` (env) siguen siendo los frenos de emergencia.
- **Concurrencia**: N units supervisord. Sin funnel Redis (`LimitTranscriptionConcurrency` eliminado).
- **Privacidad cliente**: `GET /ia/api-transcriptor/storages/{id}/snapshot` filtra campos para rol `cliente`.

### Cutover (ya ejecutado)

El cutover fue un procedimiento operativo documentado en `design.md §1.7` del change. Pasos clave: backup pre-cutover (`pg_dump` + `COPY transcriptions TO ...`), drenado del pipeline legacy, `transcriptor:purge-backlog --execute`, migracion de las dos tablas nuevas, deploy del codigo, arranque de `tcloud-transcription-worker-*`.


## Helper central `BogotaTime` (change `bogota-end-to-end-timezone`, 2026-09-16)

Todas las queries que preguntan "¿qué es hoy?" en el módulo transcriptor pasan
por `App\Services\Ia\BogotaTime::todayStart()`. Métodos disponibles:

- `BogotaTime::todayStart(): CarbonImmutable` — inicio del día Bogota actual
- `BogotaTime::now(): CarbonImmutable` — instante actual en Bogota
- `BogotaTime::todayAsDateString(): string` — `YYYY-MM-DD` del día Bogota
- `BogotaTime::TIMEZONE` — constant `'America/Bogota'`

**Regla**: cualquier código nuevo que pregunte por "hoy" usa este helper. Nunca
`CarbonImmutable::today()` sin argumento, nunca `Carbon::today('UTC')`. Si se
necesita otra zona en el futuro, el cambio es refactor del helper, no de cada
call site.

## Backfill de `recorded_at` con desfase Bogota

El comando `transcription:fix-recorded-at-timezone` corrige filas donde
`recorded_at` quedó desfasado por sesiones PG que interpretaron la hora como UTC
en vez de Bogota. **No se ejecuta automáticamente** — queda como tarea manual
del operador:

```bash
# Auditoría primero (no muta):
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan transcription:fix-recorded-at-timezone --days=30 --dry-run

# Si el dry-run muestra filas afectadas que parecen razonables, aplicar:
php artisan transcription:fix-recorded-at-timezone --days=30 --apply
```

El comando es idempotente: detecta si la fila ya está en Bogota y la salta.
Ver `design.md` del change para el algoritmo exacto.

## Diagnóstico operacional en Bogota (psqlrc del operador)

`/root/.psqlrc` aplica en cada conexión psql interactiva del usuario root:

- `SET timezone = 'America/Bogota';` — toda query devuelve timestamps Bogota
- `\set today_bogota \`date +%Y-%m-%d\`` — variable psql con la fecha Bogota
- `\pset null '[NULL]'` y `\encoding UTF8` — defaults menores

Para escalar a otros usuarios del sistema operativo, mover a `/etc/psqlrc`
o crear `/etc/profile.d/psql.sh` que lo copie al home de cada usuario.

## Frontera Mis Archivos ↔ API Transcriptor (`transcriptor-physical-file-identity`, 2026-09-16)

**Regla de oro**: Mis Archivos **escanea y escribe** `files`; API Transcriptor
**consulta y no escribe** `files`. Toda la independencia de los dos módulos se
sostiene sobre esta frontera.

### Qué puede y qué no puede hacer cada módulo

| | Mis Archivos | API Transcriptor |
|---|---|---|
| Escanea el filesystem | **sí** (`storage:sync`, botón Actualizar) | **no** |
| Escribe `files` | **sí** (único escritor) | **no, jamás** |
| Crea carpetas en `files` | sí, al indexar | **no** |
| Escribe `transcriptions` | no | **sí** (único escritor) |
| Lee `transcription_enabled` para pertenencia | **no** | sí (elegibilidad) |

### Las dos preguntas que NO se deben mezclar

```php
// ¿DE QUIÉN ES la fila?  -> geometría pura, sin mirar tx
$owner = $hierarchy->resolveGeometricOwner($absolutePath);   // Mis Archivos

// ¿QUIÉN PUEDE PROCESARLA? -> cadena de ancestros + tx
$owner = $hierarchy->ownerOf($absolutePath);                 // Transcriptor
```

Mezclarlas fue el origen del acoplamiento: `StorageSyncService` filtraba por
`transcriptionEnabled()` para decidir dueño, así que **apagar la transcripción
de un storage hijo cambiaba dónde Mis Archivos asignaba archivos** y producía
cientos de miles de filas duplicadas.

### Elegibilidad por CADENA, no por el storage de la fila

El panel "Pendientes de hoy", el estimador y el descubrimiento determinan si un
archivo es target de transcripción por la **cadena de ancestros de su ruta**
(`EXISTS ancestro con tx=true`), **nunca** por `files.storage_provider_id`.
Medición 2026-09-16: filtrar por fila perdía **9.074 archivos de hoy** (28.530
elegibles por cadena vs 19.456 por fila, casi todos de `00 Discos`, tx=false,
bajo ancestros habilitados).

### Identidad física del archivo

- `transcriptions.source_absolute_path` (UNIQUE) es el identificador del
  pipeline. Un archivo físico → **una** transcripción, aunque `files` tenga N
  filas (una por gestor).
- `transcriptions.file_id` es nullable con FK `ON DELETE SET NULL`: borrar en
  Mis Archivos **no** destruye el resultado transcrito.
- Los reintentos (`--include-failed`, `--include-done`) **reusan** la fila; no
  crean otra.

### Jerarquía persistida

`storage_providers.parent_storage_id` es la fuente única del parentesco (ya no
se deduce por `LIKE` de `base_path`). Rutas normalizadas **idénticas** son
**nodos equivalentes** (mismo nodo físico, dos gestores): no se enlazan
padre/hijo ni se fusionan.

**Invalidación obligatoria**: quien cambie `transcription_enabled` o
`base_path` DEBE llamar `StorageHierarchyService::forgetAll()` (toggle) o
`forget($id)` + `recomputeParent($storage)` (base_path). Sin eso, la cache de
60 s deja el dueño efectivo stale.

**Un solo escritor de la bandera**: `transcription_enabled` se escribe
únicamente en `ApiTranscriptorController::toggleStorage`. Ningún otro módulo
la modifica (Avisos y Correcciones la leen).

### Comandos

```bash
# Reconciliar identidades físicas (auditable, dry-run por defecto)
cd app && php artisan transcription:reconcile-physical-identities           # dry-run
cd app && php artisan transcription:reconcile-physical-identities --apply   # aplicar

# Verificar invariantes
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "
SELECT count(*) FILTER (WHERE source_absolute_path IS NULL) AS sin_ruta,
  (SELECT count(*) FROM (SELECT source_absolute_path FROM transcriptions
     WHERE source_absolute_path IS NOT NULL GROUP BY 1 HAVING count(*)>1) x) AS duplicados
FROM transcriptions;"
# Ambas deben ser 0.
```

**Harness**: `cd app && php tests/harness_transcriptor_physical_identity.php`
(27 aserciones sobre jerarquía, nodos equivalentes, dueño efectivo vs
geométrico, elegibilidad por cadena, frontera de escritura, identidad física y
CASCADE roto).

### Rollback

```bash
git revert <commit-hash>
php artisan migrate:rollback --step=3   # drop parent_storage_id, source_absolute_path, FK
systemctl restart 'tcloud-transcription-*'
```

`parent_storage_id` y `source_absolute_path` son nullable y aditivas. El
`down()` de la FK `SET NULL` **aborta** si quedaron transcripciones con
`file_id IS NULL` (archivos borrados): hay que resolverlas antes.


## Runbook: limpieza de datos corruptos en Mis Archivos (`mis-archivos-depurar-registros-fantasma`, 2026-09-21)

Tras el restore de agosto (`restore-mis-archivos-august`) el código quedó sano pero quedaron **datos corruptos** que solo se purgan con comandos:

1. **Carpetas fantasma en `files`**: carpetas con `file_modified_at IS NULL` y `parent_id IS NULL` cuyo path no existe en disco. Creadas en masa por el sync pre-restore. Detectadas originalmente en storage 5 (`00 Discos`) pero pueden aparecer en cualquier storage (storage 59 = 00 Aplicaciones reportado con 54 candidatas adicionales).
2. **`user_storages` con permisos cruzados en personales**: un storage personal con `is_personal=true` puede tener filas de usuarios no-canonicos (ej. `Personal - StakeholdersPrensa` con jsuarez asignado). El filtro de visibilidad los oculta pero la fila persiste.

### Comandos

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app

# 1) Limpiar user_storages de personales con permisos cruzados
php artisan user-storages:fix-personal-visibility          # dry-run (default)
php artisan user-storages:fix-personal-visibility --apply --yes [--user=ID]

# 2) Limpiar carpetas fantasma (file_modified_at IS NULL + path no en disco)
php artisan files:purge-ghost-folders                       # dry-run (default), todos los storages
php artisan files:purge-ghost-folders --storage=5          # dry-run, un storage
php artisan files:purge-ghost-folders --apply --yes --storage=5

# 3) Limpiar archivos (no carpetas) con parent_id IS NULL en el root de un storage.
#    Para cada uno: si tiene duplicado en OTRO storage -> drop. Si duplicado
#    en el mismo storage con path distinto -> drop. Si el path encaja con un
#    sub-storage -> reasignar storage_provider_id. Resto -> keep_root.
php artisan files:purge-orphan-roots                        # dry-run
php artisan files:purge-orphan-roots --apply --yes --storage=5

# 4) Limpiar archivos cuyo path completo en BD NO existe en disco. Cubre
#    archivos movidos/borrados sin re-sync, carpetas padre desmontadas, etc.
php artisan files:cleanup-disappeared                       # dry-run
php artisan files:cleanup-disappeared --apply --yes --storage=5
```

### Patrón de snapshot

Los cuatro comandos `--apply` crean ANTES del DELETE una tabla snapshot persistente:

- `files:purge-ghost-folders` → `files_ghost_pre_purge_<YYYYMMDD_HHMMSS>`
- `files:purge-orphan-roots` → `files_orphan_roots_pre_purge_<YYYYMMDD_HHMMSS>`
- `files:cleanup-disappeared` → `files_disappeared_pre_purge_<YYYYMMDD_HHMMSS>`

Cada una contiene copia exacta de las filas a borrar. Para restaurar (rollback granular de una pasada):

```bash
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "
INSERT INTO files SELECT * FROM files_<comando>_pre_purge_<ts>
ON CONFLICT (id) DO NOTHING;
"
```

Las tablas persisten (no se borran automáticamente). Backups completos de referencia:
- `backups/mis-archivos-depura-pre-20260921_104436.sql.gz` (44MB, files + user_storages).
- `backups/mis-archivos-depura-files-only-20260921_111623.sql.gz` (44MB, files).

### Helpers de StorageProvider

```php
StorageProvider::personalCanonicalUsername(): ?string  // 'jsuarez' para personal, null en otro caso
StorageProvider::isOwnedBy(User $user): bool           // true si user es dueno canonico del personal
```

El segundo es consumido por `FileController::storages()` para excluir personales ajenos del listado de Mis Archivos (admin bypass).

### Verificación

```bash
cd app
php tests/harness_mis_archivos_personal_visibility.php   # 18+ aserciones
php tests/harness_purge_ghost_folders.php                # 12+ aserciones
```

Ambos harnesses crean fixtures con tag unico (`hmpv_<hex>` / `hmfg_<hex>`) y limpian en `finally`.

## Contrato del breadcrumb de Mis Archivos (change `fix-mis-archivos-breadcrumb-current-folder-double-render`, 2026-09-30)

El array `breadcrumbs` que devuelve `/files` (AJAX) en respuesta a la navegación de una carpeta SHALL contener **únicamente los ancestros** del folder solicitado, ordenados desde el storage root hasta el padre inmediato. La carpeta actual (el folder solicitado) SHALL **NO** aparecer en el array. La UI renderiza la carpeta actual por separado desde `currentFolderName` / `currentFolder` (bloque `x-if` separado en `index.blade.php:2368`).

Esto aplica en ambos modos:

- **BD-first** (`FileController::index` líneas 117-129): el CTE recursivo arranca con la fila del `parent_id`; esa primera fila se descarta (`array_shift`) antes de invertir el array.
- **FS-first** (`FilesystemListingService::parentChain`): el último segmento (último elemento de `subPath`) se descarta (`array_pop`) tras construir la cadena.

### Cache de listado afectado

Las claves `folder_listing:{storageId}:{pid}:{gen}:{page}` en Redis cachean el payload completo (incluido `breadcrumbs`). Al cambiar el shape de `breadcrumbs`, las claves cacheadas siguen mostrando el breadcrumb viejo hasta que el TTL venza (60s para root, 300s para folders de hoy, 86400s para folders antiguos).

**Operación obligatoria al deploy de cualquier cambio que afecte la forma de `breadcrumbs`**:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php -r '
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
foreach (DB::select("SELECT DISTINCT storage_provider_id FROM files WHERE is_folder=true") as $r) {
    Cache::increment("folder_gen:{$r->storage_provider_id}:null");
    echo "bumped folder_gen:{$r->storage_provider_id}:null\n";
}
'
```

Esto incrementa la generación (`gen`) en una sola pasada, dejando inalcanzables las claves cacheadas con el shape viejo. Sin este paso, los usuarios ven el breadcrumb con el bug hasta que el TTL expire naturalmente (hasta 24h para los folders más cacheados).

### Verificación manual

Navegar a una carpeta profunda (ej. `Disco_I > television > Telemedellin > 30092026` en storage 5 `00 Discos`). El breadcrumb debe mostrar **una sola vez** cada nombre: `Home > 00 Discos > Disco_I > television > Telemedellin > 30092026`.

### Diagnóstico

```bash
# Inspeccionar el shape actual del breadcrumb (debe NO incluir la carpeta actual):
curl -sk -b /tmp/cookies.txt -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
  "https://cloud.mediaserver.com.co/files?parent_id=8499102&storage_id=5&nb=0" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); print('len:', len(d['breadcrumbs']), 'names:', [b['name'] for b in d['breadcrumbs']])"
# Esperado: len: 3, names: ['Disco_I', 'television', 'Telemedellin']
```

## Runbook: permisos de storage y creación de carpetas (change `fix-mis-archivos-new-folder-500-toast`, 2026-10-01)

**Síntoma**: el operador crea una carpeta desde el modal y el usuario ve un toast rojo con "El directorio no es escribible por el usuario del servidor" (post-fix). En `app/storage/logs/laravel.log` aparecen líneas `folder.create_failed` o `share.folder_create_failed` con `reason=parent_not_writable`.

**Diagnóstico** (ejecutar como root):

```bash
# 1. Confirmar el UID/GID del FPM (esperado: www / 1000)
id www

# 2. Listar la raíz del storage problemático (reemplazar <base_path>)
ls -la /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/

# 3. Confirmar el último error en logs
grep "folder.create_failed\|share.folder_create_failed" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log | tail -5
```

Si el `ls -la` muestra propietario `root` y grupo `root` (o cualquiera ≠ `www`) y el log tiene `parent_not_writable`, el problema es de filesystem ownership — PHP-FPM no puede escribir ahí.

### Fix A — chown completo (más simple, menos seguro para auditoría)

```bash
chown -R www:www /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
systemctl reload php84-php-fpm  # opcional, refresca caches de stat() en FPM
```

**Trade-off**: cualquier herramienta administrativa que asuma `root:root` (backups con rsync, scripts de mantenimiento) puede romperse.

### Fix B — grupo escribible (recomendado, preserva ownership)

```bash
# Crear grupo si no existe
groupadd storage-admins 2>/dev/null || true

# Agregar www al grupo
usermod -aG storage-admins www

# Hacer las raíces de storage grupo-escribibles (sin cambiar owner)
chmod -R g+w /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/

# Asegurar que el grupo del directorio sea storage-admins
chgrp -R storage-admins /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/

systemctl reload php84-php-fpm
```

**Trade-off**: pasos adicionales, pero `root:root` se conserva para auditoría y las nuevas carpetas creadas por `www` heredan el grupo `storage-admins` (g+s se aplica por separado si se quiere que los hijos hereden automáticamente).

### Verificación post-fix

```bash
# Desde la UI: ir a la carpeta raíz del storage afectado, abrir modal "Nueva Carpeta",
# escribir nombre de prueba (ej. test_20261001_fix), Crear. Esperado: toast verde
# "Carpeta creada correctamente" y la fila aparece en el listado.
# Borrar la carpeta de prueba tras confirmar.

# Vía curl (alternativa):
curl -sk -b /tmp/cookies.txt -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -X POST -d '{"name":"test_20261001_fix","parent_id":<PID>,"storage_id":<SID>,"is_folder":true}' \
  "https://cloud.mediaserver.com.co/files"
# Esperado: HTTP 201 con {"id":...,"name":"test_20261001_fix",...}
```

### Rollback del chown

```bash
# Fix A:
chown -R root:root /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/

# Fix B:
chmod -R g-w /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
chgrp -R root /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
gpasswd -d www storage-admins
```

**Defensa en código**: aunque el chown no se aplique, el operador ve ahora un toast rojo con el motivo exacto (`El directorio no es escribible por el usuario del servidor` o `No se pudo crear el directorio en el almacenamiento: <mensaje de PHP>`) en vez del genérico `{"message":"Server Error"}` con `HTTP 500` opaco. El log `laravel.log` siempre tiene el contexto completo (`storage_id`, `physicalPath`, `uid`, `message`).

## Runbook: detección y reparación de directorios no traversables (change `storage-readability-audit-and-repair`, 2026-10-05)

**Síntoma**: en Mis Archivos, navegar a una carpeta profunda muestra el toast rojo `el directorio no es legible por el usuario del servidor` (post-cambio) / `la ruta ya no existe en disco` (pre-cambio, indistinguible). En el dashboard admin aparece una tarjeta "Permisos de almacenamiento" con `N untraversable` para el storage afectado. En BD hay filas registradas bajo esos paths (cruzados con `bd_count > 0`).

**Causa operativa**: el directorio existe físicamente pero PHP-FPM (corriendo como `www`) no puede entrar porque su modo (ej. `700`) y/o owner (ej. `root:root`) lo excluyen. El `find` del comando existente `storage:audit-ownership` también es ciego a este caso: corriendo como `www`, tropieza con `EACCES` al descender y salta el directorio sin reportarlo.

**Diagnóstico**:

```bash
# 1. Verificar qué storagees tienen untraversable ahora mismo:
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan storage:audit-ownership --scope=untraversable --limit=50

# 2. Ver el detalle por storage (incluye mode, owner, group, bd_count):
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -t -c \
  "SELECT key, value FROM system_settings WHERE key LIKE 'storage_health:%:untraversable_count';"

# 3. Confirmar visualmente que un hijo directo del base_path tiene modo excluyente:
stat -c '%A %a %U:%G %n' /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_H/RD6/*
# (buscar entradas tipo drwx------ root:root que NO estén en denylist)
```

### Categorías de hallazgo

| Categoría | Significado | Acción |
|---|---|---|
| `untraversable` (categoría nueva) | hijo directo del base_path con modo que excluye a `www` y BD con contenido | reparar |
| `metadata_skip` | hijo cuyo nombre cae en denylist (System Volume Information, $RECYCLE.BIN, dotfiles) | ignorar |
| `owner_mismatch` (categoría legacy) | archivo/dir bajo base_path con owner != `www` (puede ser descendiente de hijo traversable) | reparar con `--apply` |

**Denylist invariante** (no modificable por flag): `System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, `.Trash*`, `lost+found`, y todo lo que arranque con `.`.

### Repair (SOLO local disqueras ext4 — NFS requiere acción server-side)

```bash
# Dry-run primero (recomendado). Imprime path/mode/owner:group/bd_count sin mutar:
sudo php artisan storage:repair-ownership --storage=55

# Aplicar (UID 0 requerido):
sudo php artisan storage:repair-ownership --storage=55 --apply --yes
#   - Crea tabla snapshot files_owners_pre_repair_<ts> con filas afectadas (37048 max snapshot observados).
#   - Ejecuta chown -R www:storage-admins + chmod -R u+rwX,g+rwX sobre cada path.
#   - Resetea storage_health:<id>:untraversable_count a 0.
#   - Requiere `--yes` o confirmación interactiva cuando >5 paths.

# Limpiar tablas snapshot viejas (>30 días):
sudo php artisan storage:repair-ownership --drop-old-snapshots=30

# Limitar cantidad por corrida (default 10):
sudo php artisan storage:repair-ownership --storage=55 --apply --yes --limit=100
```

**Limitación crítica en NFS con `root_squash`**: storage `Disco_H` es NFS4 (`192.168.0.112:/mnt/grabador/radios2`) con `root_squash` por defecto. El cliente root queda mapeado a `nobody` en el server, así que `chown` y `chmod` devuelven "Permission denied" / "Operation not permitted" para archivos ajenos. La solución es server-side:

```bash
# Opción 1: SSH al NFS server (192.168.0.112) y aplicar el mismo chown/chmod allí:
ssh root@192.168.0.112 "chown -R www:storage-admins /mnt/grabador/radios2/Casanare && chmod -R u+rwX,g+rwX /mnt/grabador/radios2/Casanare"

# Opción 2: cambiar el export NFS a `no_root_squash` (afecta TODOS los clientes, decisión operativa).
```

**Después del repair server-side, re-correr audit local**:

```bash
php artisan storage:audit-ownership --storage=55 --scope=untraversable
# Esperado: untraversable: 0
```

### Surface admin

El partial `dashboard.partials._storage_health` se renderiza automáticamente en `/dashboard` cuando:
1. `SystemSetting('storage_health_enabled') === '1'`, y
2. hay al menos un storage con `untraversable_count > 0` o `owner_mismatch_count > 0`.

La tarjeta muestra el comando CLI exacto para inspeccionar (`php artisan storage:audit-ownership --storage=N`) y reparar (`sudo php artisan storage:repair-ownership --storage=N --apply --yes`). El partial **NO se renderiza para rol cliente**.

### Activación del flag del dashboard

```bash
# Activar la tarjeta del dashboard (default off):
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c \
  "INSERT INTO system_settings (key, value, updated_at) VALUES ('storage_health_enabled','1', now()) ON CONFLICT (key) DO UPDATE SET value = '1', updated_at = now();"

# Desactivar:
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c \
  "DELETE FROM system_settings WHERE key = 'storage_health_enabled';"
```

### Rollback del change

```bash
# 1. Revertir el merge:
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash>

# 2. El flag se puede dejar activo sin deploy:
SystemSetting::set('storage_health_enabled', '0');

# 3. Droppear snapshots manualmente:
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c \
  "DROP TABLE IF EXISTS files_owners_pre_repair_<ts>;"

# 4. Limpiar SystemSettings de storage_health:* (opcional):
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c \
  "DELETE FROM system_settings WHERE key LIKE 'storage_health:%';"
```

**Sin migration**: el change es NO-breaking a nivel schema (SystemSetting es JSONB genérico). Rollback completo vía `git revert`.

## Xdebug cargado en PHP-FPM de producción (hallazgo 2026-10-05)

**Hallazgo**: PHP-FPM 8.4 corre con **Xdebug cargado en `xdebug.mode=develop`**
en producción. Verificado:
- `/www/server/php/84/etc/php.ini:1862` declara
  `zend_extension=.../xdebug.so` (php.ini compartido por CLI y FPM).
- El SAPI de FPM (`cgi-fcgi`, vía `/www/server/php/84/bin/php-cgi -i`) reporta
  `xdebug.mode=develop`.
- `/proc/<fpm-worker>/maps` muestra `xdebug.so` mapeado en los workers.

**Impacto medido**: Xdebug añade overhead enorme a código con muchas llamadas a
función (serialización Eloquent `toArray()`, hidratación de modelos). Medición
del listado `/admin/storages` (196 filas):

| | Con Xdebug (CLI) | Sin Xdebug |
|---|---|---|
| `get()->toArray()` (nuevo) | ~640 ms | **~80 ms** |
| `withCount('files')->get()->toArray()` (viejo) | ~1.2-2.5 s | ~550-843 ms |

El SQL crudo de las 196 filas tarda ~6 ms; el resto es CPU de serialización
que Xdebug amplifica ~8x. **Los benchmarks de PHP en CLI de este host están
contaminados por Xdebug** — para medir performance real usar
`php -d xdebug.mode=off ...`.

**Nota**: no se desactivó en este change (es configuración global del servidor,
fuera de scope y con riesgo operativo). Queda como candidato de optimización
server-wide de alto impacto si el operador lo aprueba:
`xdebug.mode=off` o `xdebug.start_with_request=no` en `php.ini` + reload FPM.

## El listado de Admin Storages NO computa `files_count` (change `admin-storages-list-drop-files-count`, 2026-10-05)

`GET /admin/storages` (AJAX) devuelve los `storage_providers` **sin** el
atributo derivado `files_count`. La columna "Archivos" fue eliminada del
listado porque pagar 196 `count(*)` contra `files` (1.36M filas / 2.5 GB)
costaba 625 ms-1.8 s para un dato informativo que no aporta en esa pantalla.

- **Nunca** volver a agregar `withCount('files')` a `computeAdminStorages()`
  (`StorageProviderController.php`). El listado debe tocar solo
  `storage_providers` (~6 ms SQL, ~80 ms con serialización sin Xdebug).
- El conteo exacto se expone **bajo demanda** en `show()`
  (`GET /admin/storages/{id}`), que sí conserva `withCount('files')`. El único
  consumidor es el modal de confirmación de borrado (`openDeleteModal()` en
  `admin/storages.blade.php`), que lo pide para UN storage (~40-105 ms). NO
  usar `show()` en bucles.
- La caché `admin:storages:index` (TTL 60 s) se conserva, pero ya no es
  requisito de rendimiento. Se puede retirar como follow-up.
- El listado ahora tiene estado de carga explícito (`loadingStorages`) y toast
  de error: nunca más queda en blanco en silencio mostrando "No hay storages
  configurados" mientras el fetch está en vuelo.

## Borrado de carpetas en Mis Archivos (change `fix-mis-archivos-folder-delete`, 2026-10-05)

**Síntoma**: al eliminar una carpeta desde la UI, los archivos individuales se
borraban bien pero las carpetas NO: la fila desaparecía un momento y la carpeta
reaparecía al recargar. No había error visible (toast de éxito) y en consola
solo aparecía un `Failed to load resource: 500` genérico.

**Causa raíz (dos bugs combinados):**

1. **Ownership**: el cron `qhb_medellin.sh` (`/www/server/cron/`, ejecutado por
   crontab de **root**) corre `procesar_qhb_medellin.py`, que crea
   `20261003/`, `20261004/`, `20261005/imagenes`, `pdf_paginas_ocr/` con owner
   `root:root`. PHP-FPM corre como `www`, así que `unlink`/`rmdir` devuelven
   `Permission denied`. El listado fs-first seguía viendo la carpeta en disco.
2. **Borrado BD-only**: `FileController::deleteRecursive()` recorría **solo las
   filas de `files`** (recursión por `parent_id`). Las carpetas de QHB tienen
   ~64 archivos físicos por 1 fila de BD, así que el disco nunca quedaba vacío y
   `rmdir` fallaba en silencio; aun así se borraban las filas y se respondía
   `200 Deleted` (falso éxito).

**Contrato nuevo** (disco-primero, sin falso positivo):

- `deleteRecursive()` purga el **árbol físico completo** con
  `purgeDirectoryTree()` (`RecursiveDirectoryIterator` CHILD_FIRST), que
  incluye contenido NO rastreado en BD. Solo si el disco queda limpio se borran
  las filas (`parent_id` CASCADE) y los clip-thumbs de los descendientes.
- Si algún `unlink`/`rmdir` falla, devuelve el path del fallo y **NO borra
  ninguna fila**; el endpoint responde **HTTP 500** con `permission_denied=true`
  y la UI muestra toast rojo. La carpeta queda visible para reintentar.
- **Guards de seguridad** en `deleteRecursive()`:
  - Nunca borra un path que sea `base_path` de otro StorageProvider (los
    storages pueden anidarse, ej. storage 5 `00 Discos` contiene canales).
  - `purgeDirectoryTree()` no desciende en mountpoints anidados (device distinto).
- El borrado de **archivo individual** sigue igual: `deleteFile()` devuelve
  `bool`, y en fallo de disco responde 500 (la fila sí se borra — el archivo
  huérfano no bloquea el listado).

**Normalización de ownership (recurrencia):** el wrapper
`/www/server/cron/qhb_medellin.sh` ahora normaliza tras cada corrida:

```bash
find "$OUTPUT_ROOT" -maxdepth 1 -xdev -not -user www -exec chown -R www:storage-admins {} +
find "$OUTPUT_ROOT" -maxdepth 1 -xdev -not -user www -exec chmod -R u+rwX,g+rwX {} +
```

`www` está en el grupo `storage-admins` (uid 1000), así que el grupo escribible
basta para `rmdir`/`unlink`.

**Reparación de lo ya creado** (storage 1104, 2026-10-05):

```bash
cd "/www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/Impresos 02/QHB Medellin"
chown -R www:storage-admins ./20261003 ./20261004 ./20261005 QHB_Medellin_2026100*.pdf
chmod -R u+rwX,g+rwX  ./20261003 ./20261004 ./20261005 QHB_Medellin_2026100*.pdf
# Verificar: php artisan storage:audit-ownership --storage=1104  -> owner_mismatch: 0
```

**Verificación**: `cd app && sudo -u www php tests/harness_folder_delete_contract.php`
(16 aserciones: purga física con archivos no rastreados, archivo individual,
y fallo por permisos conservando BD). Correr como `www` (uid != 0), no root.

**Rollback**: `git revert <commit>` del change. No hay migración. Las filas de
BD nunca se tocan en el path de fallo, así que no queda estado inconsistente.


## Contrato del módulo de compartidos (change `fix-shares-module-regression`, 2026-10-27)

**Origen de la regresión**: el 2026-09-21 dos commits (`b07e434` y `13645ca`)
eliminaron métodos de controller y servicios SIN retirar las rutas ni la UI
que los invocaban. En producción eso fue **HTTP 500** en cada depuración
masiva de compartidos y en **todo enlace público con contraseña** (el
formulario de `shares/public-password.blade.php` posteaba a un endpoint
inexistente). Además, los filtros que la UI enviaba (`availability`, rangos
de fecha, `status=never`) eran **inertes** y el ordenamiento por
`permission`/`status` caía en silencio a `created_at`.

Specs que gobiernan el módulo (son el contrato, no la documentación):

- `openspec/specs/share-management/spec.md`
- `openspec/specs/share-security/spec.md`
- `openspec/specs/share-file-availability/spec.md`
- `openspec/specs/share-link-lifecycle/spec.md`
- `openspec/specs/shares-tabs-bulk-delete/spec.md`
- `openspec/specs/share-folder-identity-canonical/spec.md` (re-escrita contra
  el modelo vigente)

### Contrato de API

| Endpoint | Método | Notas |
|---|---|---|
| `GET /shares` | `GET` | Filtros: `q`, `permission`, `status` (`active`/`expired`/`never`), `availability` (`available`/`missing`/`unknown`), `storage_id`, `created_from/to`, `expires_from/to`, `accessed_from/to`. Sort: `name`, `created_at`, `expires_at`, `accesses`, `size`, `permission`, `status`. **Un valor fuera del vocabulario se rechaza con `422`**, no se ignora. |
| `POST /shares` | `POST` | Sin `expires_at` y sin `never_expires` aplica `SHARE_DEFAULT_EXPIRY_DAYS` (30). |
| `GET /shares/{id}` | `GET` | Exige propietario o admin. |
| `PUT /shares/{id}` | `PUT` | `expires_at: null` = quitar vencimiento. |
| `POST /shares/bulk-preview` | `POST` | `{count, summary}`. No muta. |
| `POST /shares/bulk-delete` | `POST` | `{deleted_count, omitted_count, failed_count, omitted_ids}`. `409` si el conjunto cambió desde la previsualización. **Nunca borra `File`, archivo físico ni transcripciones.** |
| `POST /shares/availability/verify` | `POST` | Recorrido por cursor. `{checked, available, missing, unknown, has_more, next_cursor}`. `checked` cuenta **enlaces**, no archivos. |
| `POST /s/{token}/authenticate` | `POST` | Formulario HTML de contraseña. `401` si falla, `302` si acierta. |

**Vocabulario cerrado de expiración**: `never` (sin fecha) · `active` (fecha
futura) · `expired` (fecha pasada). Los tres **particionan** el conjunto: el
filtro `active` NO incluye los enlaces sin vencimiento. El alias legacy
`permanent` se rechaza con `422`.

### Estado de disponibilidad del recurso

Tres columnas en `files`, aditivas y nullables:

| Columna | Significado |
|---|---|
| `availability_state` | `unknown` (sin verificación confiable) · `available` (el filesystem confirma que existe) · `missing` (confirmado ausente) |
| `last_verified_at` | Instante de la última verificación |
| `missing_since_at` | Desde cuándo se detectó la ausencia |

**Frontera de escritura**: el estado es un dato DERIVADO de una sonda de
filesystem, **no catálogo**. `ShareAvailabilityService` lo escribe con un
`UPDATE` acotado sobre esas tres columnas y **no toca ningún otro atributo
de la fila ni `updated_at`**. Mis Archivos sigue siendo el único módulo que
crea y actualiza el catálogo de `files`.

Una fila **nunca verificada** tiene `availability_state IS NULL` y se
comporta como `unknown` en el filtro, pero **no** como `missing`: un NULL
nunca afirma que el archivo se borró.

### Listing de carpeta pública (cross-storage)

`PublicShareController::folderContents()` reúne los hijos directos de la
fila de la carpeta **más** las filas raíz (`parent_id IS NULL`) de los
storages cuyo `base_path` cae dentro de su ruta física. Sin eso, el
contenido de un sub-storage nunca aparecería en el enlace.

- Deduplica por **nombre**; ante el mismo nombre gana la fila del storage
  más específico (`base_path` más largo).
- `isDescendantOf()` tiene fallback por **ruta física**: sin él, las
  acciones sobre archivos de un sub-storage respondían `403`.
- El listing se limita a `SHARE_FOLDER_LISTING_MAX_ITEMS` (2000): una
  carpeta que sea la raíz física de un sub-storage puede tener decenas de
  miles de entradas y la vista las serializa todas en el DOM. Al superar el
  tope, la vista **lo dice** en vez de truncar en silencio.

### Verificación

```bash
cd app
php tests/harness_route_contract.php            # contrato de rutas
php tests/harness_shares_module.php              # contrato de compartidos
php tests/harness_merged_into_rename_compat.php  # retirada mirror
php tests/harness_merged_into_create_edit_delete.php
```

### Rollback

`git revert` del change + `php artisan migrate:rollback --step=2` (las tres
columnas de disponibilidad son aditivas; la tabla
`share_notification_log` se recrea con su schema). Sin estado persistente en
Redis más allá de la caché pública, que expira sola.
`systemctl reload php84-php-fpm` para liberar el opcode cache.

### Verificación en producción (2026-10-27)

| Comprobación | Resultado |
|---|---|
| Previsualización de expirados (no muta) | `count=72`, resumen `{expired:72, missing:5, unknown:12, permissions:{read:50, upload:8, full:7, write:7}}` |
| Verificación por cursor (2 lotes) | `checked=72` · `available=55` · `missing=5` · `unknown=12` |
| Partición de estados | 1 `active` + 72 `expired` + 244 `never` = 317 |
| Filtro de disponibilidad | 165 `available` + 37 `missing` + 115 `unknown` = 317 |
| Sort inválido | `422` |
| `password_hash` en listado/detalle | ausente |
| Detalle de share ajeno | `403`, sin filtrar el token |
| Enlace con contraseña por HTTP | `GET /s/{token}` → 200 con el formulario (antes 500) · `POST /s/{token}/authenticate` con contraseña incorrecta → **401** (antes 500) |
| Listing de carpeta con sub-storage | 22.508 elementos físicos → se listan 2.000 con aviso de truncado (payload JSON 353 KB) |
| Migrations | `2026_10_27_140000_add_share_availability_to_files` (1.240.274 filas intactas) · `2026_10_27_141000_drop_share_notification_log_table` (histórico en `backups/share_notification_log_20261027_093303.csv`) |
| Guardarraíles | `harness_route_contract` 12 ✓ · `harness_shares_module` 60 ✓ · `harness_merged_into_rename_compat` 23 ✓ · `harness_merged_into_create_edit_delete` 28 ✓ |

**La depuración real NO se ejecutó contra los 72 shares expirados de producción**:
el endpoint quedó verificado con fixtures propias (el harness borra lo que crea) y
con la previsualización real. Borrar enlaces vigentes es decisión del operador.

## Zona horaria de `files.file_modified_at` (`fix-fechas-mis-archivos-shares`, 2026-11-06)

**Regla**: todo timestamp de filesystem (`filemtime`, `stat.mtime`) que se escriba
o serialice en Mis Archivos DEBE pasar por `App\Support\FilesystemTime`
(`::fromTimestamp()`, `::fromPath()`). **Nunca** llamar
`Carbon::createFromTimestamp($ts)` sin segundo argumento en ese camino.

**Por qué**: en Carbon 3, `createFromTimestamp($ts)` sin zona devuelve el instante
en **UTC**. Eloquent serializa el binding con la hora de pared UTC y PostgreSQL la
interpreta en la zona de la sesión (`America/Bogota`, ver
`database.connections.pgsql.timezone`): la hora local real se guarda **5 h
adelantada**. La regresión la introdujo `b07e434` (2026-09-21) al quitar el
segundo argumento de tres llamadas en `StorageSyncService`.

Síntoma operativo: un archivo con `mtime` `2026-11-05 20:42:24 -05` aparecía en
la UI como `6 nov 2026, 01:42`. También afectaba a Prensa (`Disco_C/Prensa/*`).

### Helpers

```php
App\Support\FilesystemTime::fromTimestamp(int|float|string|null $ts): ?CarbonImmutable
App\Support\FilesystemTime::fromPath(?string $path): ?CarbonImmutable
App\Support\FilesystemTime::zone(): string   // 'America/Bogota'
```

Call sites migrados (antes sin zona): `StorageSyncService:231,295,410`,
`FilesystemDbMatcher:166,327,392,407`, `FilesystemListingService:259`,
`FileController:549,1353,1488`.

### Runbook: reparar filas ya desfasadas

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app

# 1. Auditoría (dry-run por defecto, no escribe):
php artisan files:fix-modified-at-timezone --since=2026-09-21

# 2. Reparar la firma exacta del bug (+5 h) en los storages legibles:
php artisan files:fix-modified-at-timezone --since=2026-09-21 --apply --yes

# 3. Valores imposibles restantes (file_modified_at > now()):
php artisan files:fix-modified-at-timezone --since=2026-09-21 --mode=all --future-only --apply --yes

# 4. Limitar a un storage:
php artisan files:fix-modified-at-timezone --storage=34 --since=2026-09-21 --apply --yes
```

| Flag | Efecto |
|---|---|
| *(ninguno)* | Dry-run: reporta y no escribe |
| `--apply` | Ejecuta. **Crea snapshot `files_mtime_pre_fix_<ts>` ANTES** de escribir |
| `--yes` | Omite la confirmación interactiva |
| `--storage=N` | Limita a un storage |
| `--since=YYYY-MM-DD` | Solo filas con `updated_at >= fecha` (default `2026-09-21`, inicio de la regresión) |
| `--mode=shift` (default) | Solo drift +5 h: la firma exacta del bug |
| `--mode=all` | Cualquier drift contra el mtime del disco |
| `--future-only` | Solo `file_modified_at > now()` |

**Idempotente**: una fila ya correcta no vuelve a matchear. Verificado en
producción: 140.115 filas corregidas en 113 storages; segunda corrida reporta
`desfasadas (+5h): 0` y `Nada que corregir`.

**Filas que quedan sin tocar (a propósito)**:
- `ilegibles` (~41k): el archivo/carpeta ya no existe en disco o su storage está
  omitido. Sin `mtime` no hay forma de probar el instante correcto.
- `otro drift`: el mtime del directorio cambió después del sync (drift ≠ +5 h).
  No es evidencia de la regresión; se reconcilia con `--mode=all` si el operador
  lo decide.
- Storages con `base_path_no_existe` o mount de red caído: se reportan como
  omitidos. **Nunca** se hace `stat()` sobre un mount NFS caído (modo `hard`
  bloquea PHP-FPM; usa `MountGuard`).

### Rollback

```sql
-- Restauración granular de una pasada (usa el snapshot que el comando reportó):
INSERT INTO files SELECT * FROM files_mtime_pre_fix_<ts>
ON CONFLICT (id) DO UPDATE SET file_modified_at = EXCLUDED.file_modified_at;
```

`git revert` del change restaura el comportamiento anterior (con el bug). Las
tablas `files_mtime_pre_fix_*` persisten hasta que el operador las dropee.

### Verificación

```bash
cd app
php vendor/bin/phpunit tests/Unit/FilesystemTimeTest.php --no-coverage   # 6 tests
php tests/harness_files_mtime_timezone.php                               # 12 aserciones
cd .. && python3 tests/playwright_mis_archivos_fechas.py                 # E2E con jsuarez
```

El harness crea un archivo con `touch()` a un epoch fijo y compara:
round-trip DB con `FilesystemTime` (epoch correcto), control negativo
(`createFromTimestamp` sin zona → +18000 s), dry-run que no muta, apply que
corrige y snapshot, e idempotencia.

## Modal "Enlace Compartido": el campo de fecha debe nacer habilitado (2026-11-06)

**Regla**: el estado `shareForm` de `fileManager()` en
`resources/views/files/index.blade.php` DEBE incluir siempre la clave
`never_expires`. `openDetailModal()`, el reset inicial y el reset post-creación
tienen que declararla.

**Por qué**: el input usa `:disabled="shareForm.never_expires"`. Si la clave no
existe en el objeto (Alpine la evalúa a `undefined`), el atributo `disabled="disabled"`
queda aplicado y el operador no puede escribir una fecha de caducidad hasta
marcar y desmarcar "Sin vencimiento" — exactamente el síntoma reportado.

```js
// correcto en los tres sitios:
this.shareForm = { permissions: 'read', password: '', expires_at: '', never_expires: false };
```

Verificación E2E: `tests/playwright_mis_archivos_fechas.py` asserta que el input
nace habilitado, que el toggle deshabilita/rehabilita, y que una fecha
personalizada (`2026-12-20T21:29`) se persiste tal cual en `shares.expires_at`.

## Solicitud de backup de producción (change `solicitud-backup-mis-archivos`, 2026-11-06)

Submódulo de Mis Archivos: el cliente pide el material del árbol de
producción ("Producción") de una carpeta-día y recibe un enlace público de
solo lectura con 2 días de vigencia.

### Los dos árboles

| | Origen | Producción |
|---|---|---|
| Raíz | `Tcloud/Disco_{A,B,C,E,F,H,I}/…/<canal>/<DDMMYYYY>/` | `Tcloud/Disco_D/backup/<NN_Canal_BK>/<DDMMYYYY>/` |
| Storage | p. ej. `01 Caracol Tv` (id 6) | `30 Television Bk` (id 34) |
| Origen | discos locales | **NFS** `192.168.0.131:/media/difusor02/Backup_TV` (nfs4, modo `hard`) |

La producción **no la genera Tcloud**: la escribe el sistema de difusor02.
Tcloud solo la localiza y la comparte. No hay que copiar nada.

### El vínculo NO se deduce del nombre

`09 Redmas` ↔ `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`,
`13_Telecafe_BK` y `13_Telepacifico_Bk` comparten prefijo. El vínculo se
declara en el **storage de origen**:

```sql
storage_providers.backup_storage_id   -- → id del storage raíz del backup (34)
storage_providers.backup_folder_name  -- → '01_Caracol_BK'
```

Se administra con:

```bash
cd app
php artisan backups:map-storages --root=34                 # reporte (dry-run)
php artisan backups:map-storages --root=34 --apply --yes   # escribe los pares confiables
php artisan backups:map-storages --root=34 --map=6:01_Caracol_BK --apply --yes
```

El comando solo escribe los pares con un token compartido **único** (nombre
normalizado sin el prefijo numérico ni el sufijo `BK`). El resto queda
pendiente de mapeo explícito con `--map=<storage_id>:<carpeta>`: son 21 de 28
canales con mapeo automático; los que faltan por ambigüedad de nombre
(`01_Caracol_BK`, `01_Caracol_internacional`, `02_Canal_Rcn_bk`,
`04_Canal_UNO_BK`, `14_Cosmovicion_bk`, `19_Capital_BK`, `30_Calitv`,
`51_Blu_BK`) los declara el operador.

### Invariantes del resolver (`App\Services\MisArchivos\BackupResolver`)

1. **Anclaje**: la fila del día se busca por
   `(storage_provider_id = backup_storage_id, path = '<carpeta>/<DDMMYYYY>')`.
   NUNCA por nombre entre storages: `files` tiene **dos catálogos paralelos**
   del mismo árbol (el 34 con los días recientes y el 5 `00 Discos` con días
   viejos) porque el índice UNIQUE es `(storage_provider_id, path)`.
2. **Disponibilidad EN DISCO, nunca en el catálogo**: 536 carpetas-día
   catalogadas frente a 368 en disco (168 fantasma, 31 %). Resolver desde
   `files` entregaría enlaces a carpetas que ya no existen.
3. **Contención por cadena**, sin `realpath()`: los exports NFS son `hard` y
   un `realpath()` colgado bloquea PHP-FPM.
4. **Guarda de montaje**: `MountGuard::isNetworkMountDown()`. OJO:
   `isNetworkMount()` solo reconoce el punto de montaje **exacto** y devuelve
   false para `Disco_D/backup/01_Caracol_BK/05112026`; la resolución por
   ancestro vive en `MountGuard::enclosingNetworkMount()`.
5. **El catálogo no se escribe con SQL**: si la fila del día falta, se
   materializa con `StorageSyncService::syncFolder()` (la vía canónica del
   módulo).

### El share lo crea el servicio, no `ShareController::store()`

El cliente **no** tiene permiso sobre el storage 34 (escala de privilegio
deliberada y acotada). `ShareController::store()` autoriza con `read` sobre el
`storage_provider_id` del archivo, así que la ruta normal daría 403.
**No se debilita ese endpoint**: `BackupRequestService` valida por su cuenta
(`read` sobre el storage de ORIGEN) y crea el `Share` con `permissions=read`,
`expires_at = now + config('backups.link_validity_days')` (2 días) y
`created_by = solicitante`.

El cliente **no** recibe filas en `user_storages` para el storage de backup:
el árbol de producción no aparece en su listado de storages y el acceso es
solo el enlace temporal.

### `backup_requests` es la fuente de verdad, no el share

`shares.file_id` es FK `ON DELETE CASCADE` y el árbol rota (~13 carpetas-día
rodantes): cuando la carpeta-día sale del disco, el prune borra su fila de
`files` y el share **muere solo**, dejando un 404 mudo. `backup_requests`
sobrevive y reporta el material como no disponible.

### API

| Endpoint | Notas |
|---|---|
| `GET /files/backup-requests/eligibility?file_id=` | ficha resuelta del modal |
| `GET /files/backup-requests` | historial del usuario (`{data: [...]}`) |
| `POST /files/backup-requests` | `{file_id, motivo}` → `{request, share}` |
| `DELETE /files/backup-requests/{id}` | revoca la solicitud |

**Orden de registro obligatorio**: estas rutas se declaran **antes** de
`Route::resource('files')` en `routes/web.php`, porque el resource declara
`GET /files/{file}` y ese comodín se tragaría `GET /files/backup-requests`.
No reordenar esas líneas.

### Verificación

```bash
cd app
php tests/harness_backup_requests.php   # 38 aserciones (PG + NFS reales)
php vendor/bin/phpunit tests/Unit/BackupResolverTest.php --no-coverage
php tests/harness_route_contract.php
php tests/harness_shares_module.php
```

E2E en navegador (script local, no versionado: el repo ignora `/tests/`):
`python3 tests/playwright_backup_request.py` — botón, modal, validación del
motivo, día sin backup, envío, historial, enlace público que responde 200,
revocación y cero errores de JavaScript.

### Rollback

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit>            # el change va en 3 commits
cd app && php artisan migrate:rollback --step=2   # quita tabla y columnas
```

Las dos columnas y la tabla son aditivas. Si se borra `backup_requests`, los
shares ya creados siguen sirviendo hasta vencer su vigencia (son filas de
`shares`, tabla aparte).

<!-- ODD:BEGIN -->
## Organic Driven Development (ODD)

This project uses ODD. ODD corre en cada request; no hace falta pedir un workflow, un plan ni task tracking.

Protocolo: autorizar (solo lectura salvo que se autorice implementar) -> explorar (leer el código y los requisitos existentes primero) -> resolver incertidumbre (investigación o una sola pregunta de producto, solo si hay una decisión real abierta) -> clasificar (chico vs grande por el test de reanudación) -> registrar antes de la primera escritura (documento de feature) -> implementar tarea por tarea (commits por unidad de trabajo, tests y docs junto al comportamiento) -> cerrar (reportar el resultado verificado, los checks fallidos o pendientes y el próximo paso).

Clasificación: una tarea es chica solo si el resultado está entendido, el riesgo está contenido y se podría retomar desde el pedido original más `git diff` solamente. Es grande solo si ese test de reanudación falla. Los conteos de archivos, comandos, tests o líneas nunca deciden el tamaño.

El trabajo grande autorizado crea exactamente un documento de feature en `odd/tasks/<feature-name>.md` con las secciones `Specs` (S1..Sn), `Tasks` (T1..Tn), `Acceptance Criteria`, `Verification`, `Progress`, `Next Step` y `Log` (L1..). Mantenlo actualizado con ediciones puntuales; nunca reescribas el archivo entero para cambiar una línea de tarea.

Tareas: una línea por tarea, IDs estables, `S#` enlazado, ruta (inline o delegated), estado, commit y evidencia observada. Un checkbox exige prueba observada. Registra el commit que contiene cada tarea.

Ítems de alto riesgo: 1) datos o efectos irreversibles que no se pueden reconstruir, 2) seguridad, 3) cambiar o quitar contratos que otros ya consumen, 4) concurrencia, 5) entrega o entorno, 6) ningún test detectaría una regresión. Cerrá cada cambio de código con `Risk: item N (motivo)` o `Risk: none`. Un ítem de alto riesgo exige un verificador independiente antes de cerrar.

TDD: resolvé on/off desde la configuración del proyecto o una elección explícita del usuario. TDD habilitado exige un RED observado antes de implementar, luego GREEN, luego REFACTOR. TDD deshabilitado igual exige verificaciones funcionales. La presencia de tests no habilita TDD.

Entrega: las unidades de trabajo se commitean en porciones revisables de unas 400 líneas modificadas en la branch de la feature, con tests y docs junto al comportamiento. La estrategia de entrega de la feature decide qué pasa cuando el acumulado supera el presupuesto: `ask-on-risk` (por defecto), `auto-chain`, `single-pr` o `exception-ok`. Elegir una forma nunca autoriza push, creación de PR ni merge.

Recuperación: escribí primero el progreso local, después el espejo, y leé ambos de vuelta. Si el espejo no está disponible, marcalo explícitamente como pendiente; nunca afirmes un éxito que no ocurrió.

Herramientas: `odd status` muestra el progreso, `odd validate` revisa el documento de feature, `odd mirror` escribe la copia de recuperación, `odd lines` informa el conteo de líneas modificadas, `odd new` genera un documento de feature.
<!-- ODD:END -->
