## Context

Ver `proposal.md` para la motivación. Estado actual que da forma a este diseño:

- **Change archivado `2026-09-09-avisos-keyword-storage-watermark`** dejó:
  - Tabla `keyword_scan_watermarks` con cobertura inicial poblada (518 filas hoy).
  - Hooks `Keyword::created` y `UserAlertsInteligente::saved` (SQL inline `INSERT IGNORE`).
  - UI con pestaña Cobertura (sin paginación).
  - Endpoints `coverage`, `rewind`, `runFullScan` (admin only, sin audit log).
  - `segment_keyword_hits` con 12,426 filas / 4.4 MB y crecimiento lineal.
- **Backlog heredado**: 12.1.a (UserKeyword hook), 12.1.b (UserStorage hook), 12.3 (partitioning) — todos evaluados en el archivo archivado.
- **Auditoría adicional** (security / UX / sustainability):
  - `rewindWatermark` y `runFullScan` no registran actor / timestamp / target en BD → gap de compliance.
  - 518 pares sin paginación crecerán a miles → degrada UX de la pestaña Cobertura.
  - Lógica de reconciliación duplicada en dos modelos → dificulta testing.

## Goals / Non-Goals

**Goals:**
- Cobertura sincronizada automáticamente ante TODO cambio de scope (sin intervención manual).
- Auditoría completa de acciones administrativas sensibles.
- Retención de histórico de menciones mediante DROP PARTITION (preparación para escala).
- UX de Cobertura escalable (paginación, preview, polling).

**Non-Goals:**
- No se rediseña `KeywordMatcher` ni el pipeline de matching.
- No se cambia el contrato de los watermarks existentes (la tabla y la API se mantienen).
- No se introduce un panel de auditoría completo con UI — solo el log en BD + endpoint de consulta.
- No se particiona hoy (volumen actual 4.4 MB) — se deja scaffolding + comando preparado para activar cuando se supere el trigger.

## Decisions

### Decisión 1 — Refactor a `WatermarkReconciler` (servicio centralizado)

**Por qué:** dos modelos (`Keyword`, `UserAlertsInteligente`) tienen SQL inline idéntico para crear watermarks. Un servicio centralizado:
- Facilita testing unitario (mockeable).
- Aísla la lógica de scope (quién califica para qué storage).
- Permite que un comando CLI (`avisos:reconcile-watermarks`) re-use la misma lógica sin duplicación.

**API pública del reconciler:**
```php
class WatermarkReconciler {
    // Crea watermarks faltantes para (keyword, todos los storages donde el usuario tiene acceso).
    public function ensureForUser(int $userId): int;

    // Crea watermarks faltantes para (keyword, todos los storages donde cualquier usuario con la keyword tiene acceso).
    public function ensureForKeyword(int $keywordId): int;

    // Crea watermarks faltantes para (todas las keywords del storage donde cualquier usuario con acceso las tiene).
    public function ensureForStorage(int $storageId): int;

    // Recalcula todo y retorna pares que faltan o sobran.
    public function driftReport(): array;

    // Rewind explícito de un par (similar a AvisosScanService::rewindWatermark pero auditable).
    public function rewindPair(int $keywordId, int $storageId, ?int $actorId = null): void;
}
```

### Decisión 2 — Hooks reactivos en `UserKeyword` y `user_storages`

**UserKeyword::created/saved**: dispara `WatermarkReconciler::ensureForKeyword($keywordId)`. Cubre el caso de asignación de keyword preexistente a usuario nuevo.

**user_storages.transcription_access (true→false)**: NO hace nada. El watermark persiste; la condición `transcription_access=true` en `selectCandidates` ya excluye al par.

**user_storages.transcription_access (false→true)**: dispara `WatermarkReconciler::rewindPair($keywordId, $storageId)` para re-crear el par si no existía, o mantenerlo si existía. Cubre el caso de re-habilitación.

**Decisión de dónde poner el hook:** `user_storages` no tiene modelo Eloquent en `app/app/Models/`. Opciones:
- (a) Crear `UserStorage.php` solo para el hook.
- (b) Hook directo vía `DB::table('user_storages')->updated()` en `AppServiceProvider::boot()`.

Opción (a) más limpia. Crear `UserStorage` mínimo (sin relaciones aún) con `booted()` que conecta `saved` y `updated` (usando `wasChanged('transcription_access')` para detectar transiciones).

### Decisión 3 — `watermark_audit_log` con actor explícito

**Schema:**
```sql
CREATE TABLE watermark_audit_log (
    id            BIGINT PK,
    actor_user_id BIGINT NULL,  -- NULL para acciones del sistema (hooks automáticos)
    action        VARCHAR(32),  -- 'rewind' | 'rewind_pair' | 'full_scan' | 'hook_auto' | 'reconcile'
    keyword_id    BIGINT NULL,
    storage_id    BIGINT NULL,
    before_value  TIMESTAMP(0) NULL,
    after_value   TIMESTAMP(0) NULL,
    metadata      JSONB,
    created_at    TIMESTAMP(0)
);
CREATE INDEX ON watermark_audit_log (keyword_id, storage_id, created_at DESC);
CREATE INDEX ON watermark_audit_log (actor_user_id, created_at DESC);
```

**Middleware `AuditAdminAction`:**
- Aplica a `POST /avisos-inteligentes/scan/rewind` y `POST /avisos-inteligentes/scan/full`.
- Después de la respuesta, registra la acción con `actor_user_id = session('user_id')`, payload del body como `metadata`.
- Fire-and-forget en la request: registra en cola lazy (DB facade directo) — sin overhead en la request.

### Decisión 4 — Particionamiento `RANGE` por mes en `segment_keyword_hits`

**Estrategia:**
- Tabla padre `segment_keyword_hits` queda vacía; las particiones son `segment_keyword_hits_YYYY_MM`.
- PK debe incluir `matched_at`: `UNIQUE(transcription_id, segment_id, keyword_id, matched_at)`. El índice actual `skh_unique_tsk` se recrea.
- FKs hacia transcriptions/segments/keywords se mantienen sin cambios (las particiones las heredan).
- Partition del mes actual + partición "catch-all" para meses sin partición propia.

**Comando `avisos:ensure-month-partition`:**
- Recibe mes objetivo (`--month=YYYY-MM`).
- Si no existe partición para ese mes, `CREATE TABLE segment_keyword_hits_YYYY_MM PARTITION OF segment_keyword_hits FOR VALUES FROM (...) TO (...)`.
- Idempotente.
- Cron mensual (no automatizado aquí — se documenta en AGENTS.md para que el operador lo añada cuando lo necesite).

**Retención (futuro, no objeto de este change):**
- `DROP TABLE segment_keyword_hits_2026_01` = O(1) vs `DELETE WHERE matched_at < '2026-02-01'` = O(N).
- Trigger documentado: cuando > 10M filas, particionar.

**Riesgo de la migración:**
- Requiere DROP y RECREATE de `segment_keyword_hits` (PG no permite `ALTER TABLE` no particionada → particionada).
- Tiempo de copia: ~5-10 min para las 12k filas actuales (índice UNIQUE recreation incluida).
- Plan: ventana de mantenimiento + backup pre-migración.

### Decisión 5 — Paginación server-side con filtros

**Por qué:** la pestaña Cobertura devolverá miles de pares cuando crezca. Implementar paginación usando el patrón existente del módulo (`mis-avisos-table-navigation`):
- `GET /avisos-inteligentes/scan/coverage?page=N&per_page=25&storageId=X&q=keyword_text`
- UI usa el mismo motor de paginación que la pestaña Clientes.

**Filtros:**
- `storageId`: filtra por storage.
- `q`: LIKE sobre `keywords.text`.
- Orden por `keyword_text ASC, storage_name ASC` (consistente con el orden actual).

### Decisión 6 — Preview en rewind + polling en full scan

**Rewind preview:** el `POST /rewind` recibe parámetro `preview=true` opcional. Si está, NO muta: solo ejecuta `selectCandidatesForPair($keywordId, $storageId)` y devuelve el conteo de candidatos que serán procesados. La UI muestra "Esto procesará N transcripciones".

**Full scan polling:** el `POST /full` ahora corre en background y devuelve `runId`. La UI hace polling cada 2s al endpoint `/scan/full-bg/{runId}/status` (mismo patrón que `scanRunStatus` ya existente).

## Diagrama de la nueva arquitectura

```
┌────────────────────────────────────────────────────────────────────┐
│         DISPARADORES DE RECONCILIACIÓN (reactivos)                 │
└────────────────────────────────────────────────────────────────────┘
  ┌────────────┐    ┌──────────────┐    ┌─────────────────────┐
  │ Keyword:   │    │ UserKeyword: │    │ user_storages:      │
  │ :created   │    │ :created     │    │ transcription_access│
  │            │    │ :saved       │    │ false→true          │
  └─────┬──────┘    └──────┬───────┘    └─────────┬───────────┘
        │                  │                      │
        └──────────┬───────┴────────────┬─────────┘
                   ▼                    ▼
            ┌───────────────────────────────────┐
            │  WatermarkReconciler              │
            │  ─ ensureForUser(userId)          │
            │  ─ ensureForKeyword(keywordId)    │
            │  ─ ensureForStorage(storageId)    │
            │  ─ driftReport()                  │
            │  ─ rewindPair(...) + audit_log    │
            └─────────────────┬─────────────────┘
                              │
                              ▼
            ┌───────────────────────────────────┐
            │  keyword_scan_watermarks          │
            │  PK (keyword_id, storage_id)      │
            │  scanned_until (monotónico)       │
            └───────────────────────────────────┘


┌────────────────────────────────────────────────────────────────────┐
│              ACCIONES ADMINISTRATIVAS (auditadas)                  │
└────────────────────────────────────────────────────────────────────┘

   Cliente ───► POST /scan/rewind ───► AuditAdminAction middleware
                                            │
                                            ▼
                                    WatermarkReconciler::rewindPair()
                                            │
                                  ┌─────────┴─────────┐
                                  ▼                   ▼
                          watermark_audit_log    keyword_scan_watermarks


┌────────────────────────────────────────────────────────────────────┐
│              PARTICIONAMIENTO (futuro, scaffolding)                │
└────────────────────────────────────────────────────────────────────┘

   segment_keyword_hits (parent, vacía)
       ├── segment_keyword_hits_2026_09  ← mes actual
       ├── segment_keyword_hits_2026_08  ← mes anterior
       └── segment_keyword_hits_default  ← catch-all

   UNIQUE (transcription_id, segment_id, keyword_id, matched_at)  ← propagado

   Cron mensual:
     php artisan avisos:ensure-month-partition --month=YYYY-MM
```

## Schema de la tabla de auditoría

```php
// 2026_09_15_120000_create_watermark_audit_log_table.php
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('watermark_audit_log')) return;
        Schema::create('watermark_audit_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 32);                    // rewind | rewind_pair | full_scan | hook_auto | reconcile
            $t->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $t->foreignId('storage_id')->nullable()->constrained('storage_providers')->nullOnDelete();
            $t->timestamp('before_value')->nullable();   // scanned_until antes
            $t->timestamp('after_value')->nullable();    // scanned_until después
            $t->jsonb('metadata')->default('{}');        // payload completo de la request
            $t->timestamp('created_at');

            $t->index(['keyword_id', 'storage_id', 'created_at'], 'wal_ks_time_idx');
            $t->index(['actor_user_id', 'created_at'], 'wal_actor_time_idx');
            $t->index(['action', 'created_at'], 'wal_action_time_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('watermark_audit_log'); }
};
```

## Public API adicional

```php
// AvisosScanService — extender con auditoría
public function rewindWatermark(int $keywordId, int $storageId, ?int $actorId = null): void;
public function runFullScan(array $opts = [], ?int $actorId = null): array;
```

```php
// WatermarkReconciler (nuevo)
namespace App\Services\Ia;

class WatermarkReconciler {
    public function __construct() {}

    public function ensureForUser(int $userId): int;
    public function ensureForKeyword(int $keywordId): int;
    public function ensureForStorage(int $storageId): int;
    public function driftReport(): array;
    public function rewindPair(int $keywordId, int $storageId, ?int $actorId = null): void;
}
```

## Hallazgos de la auditoría (security / UX / sustainability)

### Seguridad
```
┌────────────────────────────────────────────────────────────────┐
│ HALLAZGO                                                         │
├────────────────────────────────────────────────────────────────┤
│ 1. rewindWatermark sin audit:                                  │
│    Acción sensible (cambia cobertura sin notify al cliente)    │
│    no registra quién/cuándo/qué par. Compliance gap.           │
│    MITIGACIÓN: tabla watermark_audit_log + middleware.         │
│                                                                │
│ 2. runFullScan sin audit:                                      │
│    Acción masiva (puede tocar miles de pares).                  │
│    No se sabe quién la lanzó. Compliance gap.                   │
│    MITIGACIÓN: misma tabla, action='full_scan'.                │
│                                                                │
│ 3. Endpoints en Route::prefix('ia')->middleware(['auth','admin'])│
│    ✅ Ya están protegidos. CSRF: heredado de web.php. ✅       │
│    ✅ Throttle en los 2 POST sensibles. ✅                      │
└────────────────────────────────────────────────────────────────┘
```

### UX
```
┌────────────────────────────────────────────────────────────────┐
│ HALLAZGO                                                         │
├────────────────────────────────────────────────────────────────┤
│ 1. Cobertura sin paginar:                                       │
│    Hoy 518 filas → mañana miles. Una sola request es lenta.    │
│    No hay búsqueda ni filtro.                                   │
│    MITIGACIÓN: GET /scan/coverage paginado, filtros storage/q. │
│                                                                │
│ 2. Rewind sin preview:                                          │
│    Admin no sabe cuántos pares se verán afectados.             │
│    MITIGACIÓN: parámetro preview=true en /rewind.              │
│                                                                │
│ 3. Full scan sin feedback visual continuo:                      │
│    Hoy muestra "Full scan lanzado" y termina.                  │
│    No se ve progreso en vivo.                                   │
│    MITIGACIÓN: polling + endpoint /full/{runId}/status.        │
│                                                                │
│ 4. Cobertura no muestra a qué cliente(s) sirve:                │
│    Útil para entender impacto de un rewind.                    │
│    MITIGACIÓN: agregado opcional con COUNT(DISTINCT user_id).  │
│    (Bonificación si entra en el scope.)                        │
└────────────────────────────────────────────────────────────────┘
```

### Escalabilidad
```
┌────────────────────────────────────────────────────────────────┐
│ HALLAZGO                                                         │
├────────────────────────────────────────────────────────────────┤
│ 1. segment_keyword_hits no particionada:                        │
│    12k filas hoy. Crecimiento ~50/día.                          │
│    A 10M filas, DELETE por fecha = 30+ min con vacuum.          │
│    MITIGACIÓN: partición por mes + DROP PARTITION O(1).        │
│    (Este change deja el scaffolding; activación diferida.)     │
│                                                                │
│ 2. coverage() sin caché:                                        │
│    Cada click en la pestaña Cobertura ejecuta query pesado.    │
│    MITIGACIÓN: Cache::remember('coverage', 60s, ...).          │
│    (Bonificación si entra en el scope.)                        │
│                                                                │
│ 3. selectCandidates con 4+2 JOINs:                              │
│    Pesado para full scan. Plan estable.                         │
│    MITIGACIÓN: cubierto por índices actuales. No accionable.   │
└────────────────────────────────────────────────────────────────┘
```

### Sostenibilidad
```
┌────────────────────────────────────────────────────────────────┐
│ HALLAZGO                                                         │
├────────────────────────────────────────────────────────────────┤
│ 1. SQL de reconciliación duplicado:                             │
│    Keyword::created y UserAlertsInteligente::saved tienen       │
│    el mismo INSERT IGNORE.                                       │
│    MITIGACIÓN: WatermarkReconciler central.                    │
│                                                                │
│ 2. No hay harness de drift:                                     │
│    No se valida que el reconciler cubre todos los huecos.       │
│    MITIGACIÓN: harness_watermark_reconciler_drift.php.         │
│                                                                │
│ 3. Documentación operativa dispersa:                            │
│    Cómo lanzar un rewind / full scan / reconciler está en el   │
│    change archivado pero no en AGENTS.md.                       │
│    MITIGACIÓN: añadir entradas en la sección de monitoreo.     │
└────────────────────────────────────────────────────────────────┘
```

## Risks / Trade-offs

- **[Particionamiento: downtime]** la migración DDL requiere recrear `segment_keyword_hits`. Backups pre y post. Mitigación: ventana corta (5-10 min) en horario de bajo tráfico.
- **[Particionamiento: FKs]** la PK compuesta nueva debe incluir `matched_at`. Cambia la unicidad existente (riesgo bajo: el INSERT OR IGNORE sigue funcionando con la nueva UNIQUE).
- **[Auditoría: overhead]** cada request sensible hace un INSERT extra. ~1-2 ms por request. Aceptable.
- **[Reconciliación: idempotencia]** todos los hooks usan `insertOrIgnore` / `ON CONFLICT DO NOTHING` para evitar duplicados. Verificado en harness.
- **[Paginación: filtros]** añadir `q` requiere LIKE sobre `keywords.text`. Con 50 keywords hoy es OK; con 5000 considerar índice FULLTEXT. Diferido.

## Migration Plan

### Step 1 — Reconciler + hooks (sin downtime)
1. Crear `WatermarkReconciler`.
2. Refactorizar `Keyword::created` y `UserAlertsInteligente::saved` para usarlo.
3. Añadir hooks `UserKeyword` (nuevo modelo mínimo) y `UserStorage` (nuevo modelo mínimo).
4. Tests: harnesses de reconciliación y drift.

### Step 2 — Auditoría (sin downtime)
1. Crear tabla `watermark_audit_log`.
2. Añadir middleware `AuditAdminAction`.
3. Modificar `rewindWatermark` y `runFullScan` del controller para pasar `actor_id` y registrar.
4. Tests: harness de audit log.

### Step 3 — Particionamiento (requiere ventana corta)
1. Backup completo de `segment_keyword_hits` (snapshot PG).
2. DROP índices actuales (incluido el UNIQUE).
3. DROP TABLE `segment_keyword_hits`.
4. CREATE TABLE `segment_keyword_hits` como PARTITIONED RANGE(...).
5. COPY de los datos desde el backup.
6. Recrear partición del mes actual + partición default.
7. Recrear índices UNIQUE en cada partición.
8. ALTER TABLE sobre `alert_deliveries`/`transcriptions` si tienen FK a esta tabla.

### Step 4 — UX (sin downtime)
1. Paginación server-side en `coverage()`.
2. Preview en rewind.
3. Polling en full scan.

### Rollback
```bash
# Reconciler + hooks: revert commit. Riesgo: hooks viejos dejan huecos conocidos.
# Auditoría: DROP TABLE watermark_audit_log. Sin pérdida de datos.
# Particionamiento: lo más complejo. Plan:
#   1) DETACH particiones (cada una → tabla propia).
#   2) DROP particiones.
#   3) Recrear segment_keyword_hits como no particionada.
#   4) INSERT INTO segment_keyword_hits SELECT * FROM segment_keyword_hits_YYYY_MM.
#   5) DROP las tablas de partición.
```

## Open Questions

1. **¿El audit log debe ser visible en la UI admin?** → Asumido: NO en este change (solo BD + endpoint). Si se quiere UI, abrir change futuro.
2. **¿Las particiones de meses pasados requieren retención obligatoria?** → Asumido: NO se borra nada en este change. El operador decide vía `DROP TABLE` cuando quiera.
3. **¿Full scan debe continuar siendo admin-only?** → Asumido SÍ (decisión ya tomada en change anterior). Mantener.
4. **¿El reconciler periódico debe correr en cron?** → Asumido: el comando CLI está disponible pero no se agenda automáticamente. Operador decide cuándo correrlo (mensualmente tras un deploy, p. ej.).
