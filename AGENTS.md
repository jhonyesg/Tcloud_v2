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
| `harness_dashboard_partials.php` | `dashboard-modular-partials` | **Contrato de partials auto-gated del dashboard**: 22 aserciones sobre gating (no renderiza cuando flag=false), shape por contexto (admin/client), privacidad (cliente NO incluye `hits_`/`audit_`/`drift_*`), separación de contexto (`bg-jobs-active` solo admin), y presencia de selectores `data-dashboard-partial="..."` para tours. |

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
