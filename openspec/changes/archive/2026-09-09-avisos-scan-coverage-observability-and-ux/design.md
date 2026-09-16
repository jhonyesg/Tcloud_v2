## Context

Ver `proposal.md` para la motivación. Estado actual que da forma a este diseño:

- **Change 2 archivado** (`avisos-scan-coverage-reconciler-and-partition`) dejó:
  - `WatermarkReconciler` con `ensureFor*` + `driftReport` + `rewindPair`
  - Hooks en `Keyword`, `UserKeyword`, `UserStorage` (updated), `UserAlertsInteligente`
  - Tabla `watermark_audit_log` con 3 índices (rows creciendo)
  - UI Cobertura con paginación, filtros, preview
  - Scaffolding para partición de `segment_keyword_hits` (no activa)
- **Hallazgos durante implementación**:
  - **#1 Cache stale**: `coverage()` usa `Cache::remember` con TTL 60s. Tras rewind, la UI muestra el estado anterior hasta 60s.
  - **#2 UserStorage::created hook faltante**: el hook está en `static::updated` con `wasChanged`. Inserciones directas con `transcription_access=true` no disparan.
  - **#3 Drift positivo huérfano sin auto-fix**: el Reconciler los lista pero no los borra (decisión documentada). Hoy: 8 huérfanos. Operador debe escribir SQL.
  - **#4 Full scan sin polling**: el botón "Escaneo completo" muestra "lanzado" y termina — el admin no sabe cuándo puede continuar.
  - **#5 coverage() legacy obsoleto**: el controller ya no lo usa (usa `coveragePaginated`). Riesgo de uso externo sin saber que retorna todo sin paginar.
  - **#6 Audit log sin UI**: para responder "¿quién movió este par?" hay que escribir SQL directo.

## Goals / Non-Goals

**Goals:**
- Audit log visible y consultable desde la UI (no SQL directo).
- Cache siempre fresco tras mutación.
- Full scan con feedback en vivo.
- Huecos pequeños cerrados (UserStorage::created, fix_orphans, deprecate, validación).
- Tabla archive para que el log no crezca indefinidamente.

**Non-Goals:**
- No se introduce exportador a CSV/Excel (queda como backlog).
- No se rediseña el modelo de auditoría (sigue append-only, sigue siendo JSONB en metadata).
- No se cambia el contrato de los hooks existentes.
- No se activa la partición de `segment_keyword_hits` (4.4 MB, fuera de scope).
- No se introduce autenticación SSO / OAuth (no relacionado).

## Decisions

### Decisión 1 — Cache epoch via contador en BD

**Por qué:** invalidar cache via TTL (60s) es trivial pero miente. La opción más limpia es un "epoch" que se incrementa en cada mutación de `keyword_scan_watermarks` y se usa como parte de la cache key.

**Implementación:**
- Tabla `system_settings` con key `coverage_cache_epoch` (entero, default 0).
- Cada mutación (`WatermarkReconciler::rewindPair`, `ensureForKeyword` después de inserts, `AuditAdminAction` middleware en rewind/full) llama `CacheEpoch::bump()` que ejecuta `UPDATE system_settings SET value = value + 1 WHERE key = 'coverage_cache_epoch'` (atómico en PG).
- `coveragePaginated()` lee el epoch y lo incluye en la cache key: `coverage:{epoch}:{storageId}:{q_md5}:{perPage}:{page}`.
- Costo: 1 UPDATE por mutación (~1-2 ms). Lectura: 1 SELECT (~0.5 ms) ya cacheada por Eloquent.

**Alternativa considerada:** usar Redis `INCR` directo. Descartada porque el proyecto prefiere SystemSetting como contrato de configuración (ver AGENTS.md).

### Decisión 2 — Full scan en background con polling estructurado

**Por qué:** el patrón `scanRunStatus` ya existe para `runScanBackground` (escaneo normal). Lo extendemos a `runFullScanBackground` con su propio runId.

**Implementación:**
- `POST /avisos-inteligentes/scan/full-bg` (admin): encola worker que llama `AvisosScanService::runFullScan` en loop con tope de tiempo. Cache key `full_scan_bg:{runId}`.
- `GET /avisos-inteligentes/scan/full-bg/{runId}/status` (admin): devuelve `{ status, scanned, hits, iterations, started_at, finished_at }`.
- `POST /avisos-inteligentes/scan/full-bg/{runId}/stop` (admin): cancela cooperativa.
- UI: polling cada 2s al endpoint de status. Modal muestra: iteración / pares escaneados / hits nuevos / tiempo transcurrido.

**Anti-duplicado:** mismo patrón que `scanRunBackground` — pointer `full_scan_bg:active` en cache evita dos full scans simultáneos.

**Decisión clave:** el full scan vía polling corre en un worker supervisord o en el mismo flujo del controller (similar a como `scan-run` ya corre en background via `execBackground`). Para simplificar, lo corremos en el mismo proceso del controller con `set_time_limit(0)` y `fastcgi_finish_request()` — equivalente al patrón existente.

### Decisión 3 — Tabla `watermark_audit_log_archive` + comando de archivado

**Por qué:** mover filas a una tabla paralela en vez de borrar preserva audit histórico sin penalizar queries sobre el log activo. La tabla nueva puede particionarse más adelante si crece.

**Schema:**
```sql
CREATE TABLE watermark_audit_log_archive (
    id, actor_user_id, action, keyword_id, storage_id,
    before_value, after_value, metadata jsonb, created_at,
    archived_at timestamp(0) DEFAULT now()
);
CREATE INDEX wal_archive_actor_time_idx ON ... (actor_user_id, created_at);
CREATE INDEX wal_archive_action_time_idx ON ... (action, created_at);
```

**Comando `avisos:archive-audit-log --days=90`:**
1. Cuenta filas con `created_at < now() - INTERVAL '90 days'`.
2. Si `--dry-run`, imprime el conteo y termina.
3. Si sin `--dry-run`, hace `INSERT INTO watermark_audit_log_archive SELECT *, now() FROM watermark_audit_log WHERE created_at < ...` en transacción + `DELETE` del mismo set.

**Política:** admin corre mensualmente o configura cron. La política por defecto es 90 días; configurable vía `--days=N`.

### Decisión 4 — Hook `UserStorage::created` para cubrir inserts directos

**Por qué:** si un desarrollador/admin hace `INSERT INTO user_storages (..., transcription_access=true)`, el hook `updated` no dispara (no es UPDATE). El `created` hook cubre ese caso.

**Implementación:** en `UserStorage::booted()`, añadir `static::created` que llama `WatermarkReconciler::ensureForUser($us->user_id)` si `transcription_access=true`. Para inserts con `transcription_access=false`, no hace nada (mismo razonamiento que el caso true→false).

### Decisión 5 — Fix orphans vía UI

**Por qué:** los 8 huérfanos actuales no se borran porque la decisión es "que el operador decida". Pero obligar al admin a escribir SQL para borrar 8 filas es fricción innecesaria.

**Implementación:** `POST /avisos-inteligentes/scan/reconcile` con payload `{ dry_run: bool, user_id: int?, fix_orphans: bool, confirmed: bool }`. Si `fix_orphans=true && confirmed=true && !empty(orphan[])`, hace `DELETE FROM keyword_scan_watermarks WHERE (keyword_id, storage_provider_id) IN (orphan_set)`. Cada delete registra en `watermark_audit_log` con `action='reconcile'` y `metadata={deleted: N}`.

**Anti-peligro:** requiere `confirmed=true` (el admin debe aceptar explícitamente). Sin eso, 409 con preview.

### Decisión 6 — `@deprecated` en `coverage()` legacy

**Por qué:** borrarlo rompe cualquier caller externo que no conozcamos. Mejor deprecation con docblock apuntando al reemplazo.

**Implementación:** añadir `@deprecated since 2026-09-22 use coveragePaginated()` en el docblock + `trigger_error(..., E_USER_DEPRECATED)` solo si `config('app.debug')` y no en producción.

### Decisión 7 — Validación de rewind sobre (k,s) huérfanos

**Por qué:** un rewind sobre un par cuyo storage fue borrado (FK cascade dejó la fila) sería no-op silencioso. Mejor detectar y 404 antes.

**Implementación:** en `AvisosInteligentesController::rewindWatermark`, antes de llamar `rewindPair`, verificar:
- `StorageProvider::find($storage_provider_id)` existe.
- `Keyword::find($keyword_id)` existe.
- Si no, 404 con mensaje claro.

Costo: 2 SELECT por rewind. Negligible.

## Diagrama de la nueva arquitectura

```
┌────────────────────────────────────────────────────────────────────┐
│           UI — Pestaña Cobertura (3 sub-pestañas)                  │
└────────────────────────────────────────────────────────────────────┘
  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────────┐
  │  Cobertura       │ │   Auditoría      │ │   Acciones           │
  │  (paginada)      │ │   (audit log)    │ │   (rewind + full)    │
  │  filtros: q, sid │ │   filtros: actor │ │   preview rewind     │
  │  preview rewind  │ │   filtros: kw    │ │   full scan polling  │
  │                  │ │   filtros: since │ │                      │
  │  cache key:      │ │                  │ │   background worker  │
  │  coverage:{ep}:… │ │   sin cache      │ │   runId              │
  └──────────────────┘ └──────────────────┘ └──────────────────────┘


┌────────────────────────────────────────────────────────────────────┐
│                  Cache epoch invalidation                          │
└────────────────────────────────────────────────────────────────────┘
  Mutación (rewind/hook/reconcile)
       │
       ▼
  WatermarkReconciler::bumpEpoch() ──▶ UPDATE system_settings
       │                                   SET value = value + 1
       │                                   WHERE key = 'coverage_cache_epoch'
       ▼
  Próximo coverage() lee epoch ──▶ cache key incluye epoch nuevo
       │                              → cache miss → fresh data
       ▼
  ✅ UI ve el estado real inmediatamente tras mutación


┌────────────────────────────────────────────────────────────────────┐
│              Audit log archival (no rompe el append-only)          │
└────────────────────────────────────────────────────────────────────┘
  avisos:archive-audit-log --days=90
       │
       ▼
  BEGIN
    INSERT INTO watermark_audit_log_archive SELECT *, now()
      FROM watermark_audit_log
      WHERE created_at < now() - INTERVAL '90 days';
    DELETE FROM watermark_audit_log
      WHERE created_at < now() - INTERVAL '90 days';
  COMMIT
```

## Schema de la tabla archive

```php
// 2026_09_22_120000_create_watermark_audit_log_archive_table.php
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('watermark_audit_log_archive')) return;
        Schema::create('watermark_audit_log_archive', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 32);
            $t->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $t->foreignId('storage_id')->nullable()->constrained('storage_providers')->nullOnDelete();
            $t->timestamp('before_value')->nullable();
            $t->timestamp('after_value')->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->timestamp('created_at');
            $t->timestamp('archived_at');

            $t->index(['actor_user_id', 'created_at'], 'wal_archive_actor_time_idx');
            $t->index(['action', 'created_at'], 'wal_archive_action_time_idx');
            $t->index(['keyword_id', 'storage_id', 'created_at'], 'wal_archive_ks_time_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('watermark_audit_log_archive'); }
};
```

## Public API adicional

```php
// AvisosScanService — extender
public function coveragePaginated(?int $storageId, string $q, int $perPage, int $page): array;
// coverage() queda como @deprecated

// WatermarkReconciler — extender
public function bumpEpoch(): void;  // llamado en cada mutación

// AuditLogArchiver (nuevo)
class AuditLogArchiver {
    public function __construct() {}
    public function countOlderThan(int $days): int;
    public function archive(int $days): array;  // retorna { archived: N }
}

// CacheEpoch (helper)
class CacheEpoch {
    public static function get(): int;
    public static function bump(): int;  // retorna nuevo valor
}
```

## Hallazgos de la auditoría (resumen visual)

```
┌──────────────────────────────────────────────────────────────────┐
│ Implementado por Change 2 (archivado)                            │
├──────────────────────────────────────────────────────────────────┤
│ ✅ Hooks reactivos (Keyword, UserKeyword, UserStorage::updated,  │
│    UserAlertsInteligente)                                        │
│ ✅ WatermarkReconciler central                                   │
│ ✅ watermark_audit_log append-only                               │
│ ✅ UI Cobertura con paginación + filtros + preview               │
│ ✅ Scaffolding de partición (no activa)                          │
│                                                                  │
│ Hallazgos pendientes que este change cierra:                    │
├──────────────────────────────────────────────────────────────────┤
│ 🔲 #1 Cache stale tras rewind                  → CacheEpoch       │
│ 🔲 #2 UserStorage::created hook faltante       → Hook             │
│ 🔲 #3 Drift positivo sin auto-fix              → fix_orphans      │
│ 🔲 #4 Full scan sin polling                    → Polling          │
│ 🔲 #5 coverage() legacy obsoleto               → @deprecated      │
│ 🔲 #6 Audit log sin UI                         → UI + endpoint    │
│                                                                  │
│ Backlog diferido (no objeto):                                    │
├──────────────────────────────────────────────────────────────────┤
│ • 12.1 Particionar segment_keyword_hits (>10M filas)             │
│ • 12.3 Endpoint para cliente (sus propios rewinds)               │
│ • 12.5 Política de retención definitiva                          │
│ • Exportador CSV/Excel                                           │
└──────────────────────────────────────────────────────────────────┘
```

## Risks / Trade-offs

- **[Cache epoch: race]** dos mutaciones concurrentes en mismo epoch podrían leer epoch antes del bump. Mitigación: el `UPDATE ... SET value = value + 1 WHERE key = ?` es atómico en PG; ambos bumps incrementan (no se pisan). Lecturas pueden ser del epoch anterior (cache miss pero datos viejos), siguiente lectura ve nuevo. **Severidad baja.**
- **[Archive en producción]** mover muchas filas en una sola transacción puede bloquear el log. Mitigación: chunking de 1000 filas + transacción por chunk. Documentado en tareas.
- **[Full scan background]**: requiere PHP-FPM con `max_execution_time` suficiente. Mitigación: `set_time_limit(0)` + `fastcgi_finish_request()`.
- **[UserStorage::created hook]**: si se inserta con `transcription_access=false`, no hace nada (correcto). Pero si se hace UPDATE posterior que cambia a true, el hook `updated` actual ya cubre. **No hay duplicación.**
- **[@deprecated coverage()]**: si el código se reusa, recibe warning solo en `APP_DEBUG=true`. Producción silencioso. Si hay caller no-mío, seguirá funcionando idéntico al actual.

## Migration Plan

### Step 1 — Cache epoch + UI fixes (sin downtime)
1. Crear `CacheEpoch` service.
2. Añadir `bumpEpoch()` a `WatermarkReconciler` (en `rewindPair`, `ensureFor*`).
3. Modificar `coveragePaginated` para incluir epoch.
4. UI Cobertura: el botón "Activar histórico" ahora invalida el cache inmediatamente (vía el bump).
5. Verificar: rewind + refrescar UI muestra el nuevo estado sin esperar 60s.

### Step 2 — Audit log UI + endpoint (sin downtime)
1. Crear tabla `watermark_audit_log_archive`.
2. Crear `AuditLogArchiver`.
3. Añadir endpoint `GET /scan/audit` paginado.
4. UI: sub-pestaña Auditoría con tabla y búsqueda.
5. Verificar: query por actor / action / keyword retorna resultados correctos.

### Step 3 — Hygiene (sin downtime)
1. `UserStorage::created` hook.
2. `POST /scan/reconcile` con `fix_orphans`.
3. `@deprecated` en `coverage()`.
4. Validación de rewind sobre (k,s) existentes.

### Step 4 — Full scan polling (sin downtime)
1. `POST /scan/full-bg` en background.
2. `GET /scan/full-bg/{runId}/status`.
3. UI: polling cada 2s, muestra progreso.

### Rollback
- Cada step es independiente. Si falla uno, los anteriores quedan en pie.
- Para revertir `coveragePaginated` con epoch: quitar epoch de la cache key (vuelve al comportamiento anterior). Sin impacto en BD.
- Para revertir el polling de full scan: revertir el controller y la UI; el endpoint POST /full (sincrónico) sigue existiendo.

## Open Questions

1. **¿Exportador CSV?** → Asumido NO en este change (queda como backlog).
2. **¿Polling de full scan también para el normal (cron)?** → Asumido NO; el cron es interno y no necesita UI.
3. **¿`coverage()` se borra o se queda deprecado?** → Asumido: queda deprecado (cero costo, cero riesgo de romper nada).
4. **¿La política de retención debe ser configurable?** → Asumido SÍ (--days flag) con default 90.
