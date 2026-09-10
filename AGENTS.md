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
