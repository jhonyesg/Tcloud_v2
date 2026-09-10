## Context

Ver `proposal.md` para la motivación. Resumen técnico del estado actual que da forma a este diseño:

- `AvisosScanService::selectCandidates()` (línea 222–281) filtra con `whereNull('h.id')` sobre `segment_keyword_hits` — SALTA cualquier transcripción que tenga AL MENOS un hit, sin importar la keyword. Esto bloquea retroactivo para keywords nuevas.
- `KeywordMatcher::run()` (línea 44–76) sí carga `alreadyIndexedKwIds` y los salta per-`(transc, keyword)`. La granularidad correcta ya existe al nivel del matcher; lo que falta es la granularidad en el `SELECT` upstream.
- `avisos_scan_cursor` (SystemSetting) es global — un solo cursor para todos los storages y todas las keywords. La unidad mínima de cobertura debería ser `(keyword_id, storage_provider_id)`.
- `segment_keyword_hits` ya tiene `UNIQUE(transcription_id, segment_id, keyword_id)` — la idempotencia hit-level ya está resuelta.
- Hoy hay 351.435 candidatos sin hits y 36 de 54 keywords aún sin hits — el modelo necesita escalarse para absorber el catch-up sin tocar 351k filas dos veces.

## Goals / Non-Goals

**Goals:**
- Cursor de cobertura por `(keyword_id, storage_provider_id)`, monotónico, race-safe.
- Modificar el barrido para que sea por par `(transc, keyword)` y no por transcripción sola.
- Habilitar retroactivo automático al alta/baja de keywords sin código adicional en los flujos de cliente.
- Modo "full scan" reanudable con reporte de progreso y límite de tiempo.
- Migración inicial sin re-escaneo (derivar watermarks de hits existentes).

**Non-Goals:**
- No se rediseña `KeywordMatcher::run()` — sólo se le pasa el hint de qué keywords ya están cubiertas.
- No se cambia la cadencia ni el dispatcher de alertas (`avisos:deliver-alerts`).
- No se introduce sharding ni particionamiento de la tabla nueva — el volumen estimado (54 keywords × ~10 storages = ~540 filas) lo hace innecesario.
- No se agrega un endpoint público nuevo — la UI existente del admin absorbe la nueva información.

## Decisions

### Decisión 1 — PK compuesta `(keyword_id, storage_provider_id)`

**Por qué:** La cobertura mínima de "ya escaneé todo lo de keyword X en storage Y hasta fecha Z" es naturalmente par. Cardinalidad estimada: 54 keywords × 10 storages ≈ 540 filas. PK compuesta hace UPSERT idempotente sin ON CONFLICT de unicidad adicional.

**Alternativa considerada:** PK surrogate + UNIQUE(keyword_id, storage_id). Descartada porque la PK compuesta ya garantiza unicidad y el surrogate sería ruido.

### Decisión 2 — Granularidad de storage, no de usuario

**Por qué:** `KeywordMatcher::candidateKeywords()` (línea 137–164) ya hace el join por `storage_provider_id`. El motor está diseñado para que UNA transcripción se escanee UNA vez para TODOS los usuarios con acceso al storage. Replicar cobertura por `(k, s, user)` contradice el motor.

**Alternativa considerada:** Por `(keyword_id, user_id)` o `(keyword_id, user_id, storage_id)`. Descartada por la razón anterior y porque dispararía cardinalidad ~54 × 7 usuarios × storages.

### Decisión 3 — `scanned_until` monotónico con GREATEST

**Por qué:** Dos corridas concurrentes (cron + manual) pueden intentar avanzar el mismo watermark. `UPDATE ... SET scanned_until = GREATEST(scanned_until, EXCLUDED.scanned_until)` es atómico en PostgreSQL y nunca retrocede el cursor.

**Alternativa considerada:** Lock pesimista (`SELECT FOR UPDATE`). Descartada por overhead — la atomicidad de `GREATEST` ya garantiza la invariante.

### Decisión 4 — Alta de keyword crea watermark con `NULL`, no con `now()`

**Por qué:** "Cliente nuevo desde hoy" es regla de negocio que el cliente decide (vía UI: "Quiero histórico explícito"). El sistema no debe asumir. `NULL` significa "no escaneado, requiere catch-up" — el siguiente cron lo recoge y procesa.

**Alternativa considerada:** Default `now()` al crear la keyword (asumir "solo desde hoy"). Descartada porque quita la opción de retroactivo automático, que es el caso de uso principal.

### Decisión 5 — Migración inicial sin re-escaneo

**Por qué:** Hay 3.221 hits existentes. De cada `(keyword_id, storage_id)`, podemos inferir `scanned_until` como `MIN(t.finished_at) WHERE h.keyword_id = ? AND storage_id = ?`. Eso cubre las keywords que ya tienen hits sin re-procesar nada.

**Trade-off:** Las keywords SIN hits hoy (36 de 54) arrancarán con `NULL` y se procesarán en el primer cron. Esto es deseado — son justo las que necesitan catch-up.

### Decisión 6 — `last_scan_run_id` apunta a `avisos_scan_runs`

**Por qué:** Auditoría: "¿cuándo fue la última vez que se escaneó esta (k,s)?" se responde con JOIN. El modelo `avisos_scan_runs` ya existe y tiene `id` BIGINT.

**Alternativa considerada:** `last_scanned_at TIMESTAMP` en el watermark. Se conserva AMBOS: `last_scanned_at` (cuándo) y `last_scan_run_id` (de qué corrida vino, para drill-down).

## Schema propuesto

```php
// 2026_09_10_120000_create_keyword_scan_watermarks_table.php

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('keyword_scan_watermarks')) {
            return;
        }
        Schema::create('keyword_scan_watermarks', function (Blueprint $t) {
            $t->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $t->foreignId('storage_provider_id')->constrained('storage_providers')->cascadeOnDelete();
            $t->timestamp('scanned_until')->nullable();
            $t->foreignId('last_scan_run_id')->nullable()->constrained('avisos_scan_runs')->nullOnDelete();
            $t->timestamp('last_scanned_at')->nullable();
            $t->timestamp('last_hit_at')->nullable();
            $t->unsignedBigInteger('candidates_total')->default(0);
            $t->unsignedBigInteger('hits_total')->default(0);
            $t->timestamps();

            $t->primary(['keyword_id', 'storage_provider_id']);
            // Barrido por storage: ¿qué keywords tienen watermark atrasado en este storage?
            $t->index(['storage_provider_id', 'scanned_until'], 'ksw_storage_scanned_idx');
            // Catch-up: ¿qué (k,s) están totalmente sin escanear?
            $t->index(['scanned_until'], 'ksw_scanned_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('keyword_scan_watermarks'); }
};
```

## Diagrama de flujo actualizado

```
┌────────────────────────────────────────────────────────────────────────┐
│                PIPELINE POST-WATERMARK (diseño)                        │
└────────────────────────────────────────────────────────────────────────┘

   ┌──────────────┐
   │  cron / cli  │
   └──────┬───────┘
          ▼
   ┌────────────────────────────────────────────┐
   │ AvisosScanService::run(opts)               │
   │  opts: {storageId?, keywordId?, from?, to?,│
   │         limit, full?, force?}              │
   └────────────────────┬───────────────────────┘
                        │
                        ▼
   ┌────────────────────────────────────────────┐
   │ selectCandidates(opts)  ← REFACTOR         │
   │  JOIN keyword_scan_watermarks w            │
   │    ON w.keyword_id = k.id                  │
   │   AND w.storage_provider_id = f.spi        │
   │  WHERE t.finished_at >= COALESCE(          │
   │         w.scanned_until, '-infinity')      │
   │    AND (h por esa keyword) IS NULL         │
   │    AND (windowHours OR noWindow OR full)   │
   └────────────────────┬───────────────────────┘
                        │
                        ▼
   ┌────────────────────────────────────────────┐
   │ Para cada (transc, keyword_id):            │
   │  KeywordMatcher::scanPair(t, kw_id, force) │
   │   └─ fanOut() → alert_deliveries           │
   └────────────────────┬───────────────────────┘
                        │
                        ▼
   ┌────────────────────────────────────────────┐
   │ UPSERT watermark  (atómico, monotónico)    │
   │ INSERT INTO keyword_scan_watermarks ...    │
   │ ON CONFLICT (keyword_id, storage_id)       │
   │ DO UPDATE SET                             │
   │   scanned_until = GREATEST(               │
   │     keyword_scan_watermarks.scanned_until,│
   │     EXCLUDED.scanned_until),              │
   │   last_scan_run_id = EXCLUDED.run_id,     │
   │   last_scanned_at = NOW(),                │
   │   candidates_total = candidates_total + ? │
   │   hits_total = hits_total + ?;            │
   └────────────────────────────────────────────┘
```

## API del servicio (pública)

```php
// AvisosScanService

// NUEVO: candidatos por (transc, keyword) en lugar de por transc sola
public function selectCandidates(array $opts = []): Collection;

// NUEVO: procesar UN par
public function scanPair(Transcription $t, int $keywordId, bool $force = false): int;

// NUEVO: UPSERT monotónico del watermark
private function bumpWatermarks(array $bumpSet, int $runId): void;

// NUEVO: full scan — barre todas las (k,s) atrasadas; reanuda
public function runFullScan(array $opts = []): array;

// NUEVO: estado de cobertura para UI
public function coverage(?int $storageId = null): array;

// NUEVO: forzar catch-up de una keyword en un storage (admin / "activar histórico")
public function rewindWatermark(int $keywordId, int $storageId, ?Carbon $to = null): void;
```

## Hook de modelo (Keyword)

```php
// app/app/Models/Keyword.php
protected static function booted(): void {
    static::created(function (Keyword $k) {
        // Crear watermark para cada storage que tenga al menos UN usuario con
        // transcription_access y habilitado. scanned_until=NULL = catch-up.
        $storages = DB::table('user_storages as us')
            ->join('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'us.user_id')
            ->where('uai.enabled', true)
            ->where('us.transcription_access', true)
            ->distinct()
            ->pluck('us.storage_provider_id');
        foreach ($storages as $sid) {
            DB::table('keyword_scan_watermarks')->insertOrIgnore([
                'keyword_id' => $k->id,
                'storage_provider_id' => $sid,
                'scanned_until' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
}
```

> Nota: NO se eliminan watermarks al borrar keyword (`keywords` ya tiene ON DELETE CASCADE sobre `keyword_scan_watermarks.keyword_id` — limpio). Si se RECREA con el mismo `text`, se le asigna `id` nuevo y arranca con `NULL` — comportamiento aceptado (keyword es entidad, no valor).

## Post-validación de Normativa de Base de Datos

Esta es la auditoría que el usuario pidió: tras diseñar el schema, validar que cumple las convenciones del proyecto y detectar qué limpiar/ajustar.

### Convención 1 — Nomenclatura snake_case de tabla y columnas

**Norma del proyecto:** todas las tablas `snake_case` plural, columnas `snake_case`. Verificado contra 95 migraciones y modelos Eloquent.

**Nuestra propuesta:** `keyword_scan_watermarks` + columnas `keyword_id`, `storage_provider_id`, `scanned_until`, `last_scan_run_id`, `last_scanned_at`, `last_hit_at`, `candidates_total`, `hits_total`, `created_at`, `updated_at`. ✅ Cumple.

### Convención 2 — Foreign keys con `foreignId()->constrained()->cascadeOnDelete()`

**Norma:** verificada en `2026_09_05_140000_mis_avisos_menciones_phase1_engine.php` y `2026_09_07_100000_create_avisos_scan_runs_table.php`. El patrón estándar es `cascadeOnDelete()` para dependencias fuertes.

**Nuestra propuesta:** tres FKs:
- `keyword_id → keywords` con `cascadeOnDelete` ✅ (si se borra la keyword, su watermark se va con ella).
- `storage_provider_id → storage_providers` con `cascadeOnDelete` ✅ (si se borra un storage, sus watermarks se van).
- `last_scan_run_id → avisos_scan_runs` con `nullOnDelete` ⚠️ (si se purga una corrida vieja, NO se borra el watermark — sólo se anula la referencia).

**Acción:** validar que `storage_providers` realmente acepta `cascadeOnDelete` desde esta tabla. Si `storage_providers` tiene hijos propios (files, etc.) con restrict, nuestro cascade propagará y podría romper. **Verificación previa al merge:** ejecutar `SELECT conname, confdeltype FROM pg_constraint WHERE conrelid = 'storage_providers'::regclass;` y confirmar que ningún hijo usa RESTRICT hacia storage_providers — si lo hay, usar `restrictOnDelete` aquí en su lugar.

### Convención 3 — PKs y unicidad explícita

**Norma:** `id` BIGINT por defecto en tablas operativas, pero `composite primary` aceptada cuando la entidad ES el par (e.g. `user_alerts_inteligentes(user_id, ...)`). No hay caso previo de PK compuesta con `foreignId` doble — pero el patrón es legal y limpio.

**Nuestra propuesta:** `primary(['keyword_id', 'storage_provider_id'])` con FKs individuales declaradas ANTES del `primary()`. ✅ Legal y explícito.

**Acción de limpieza detectada:** la tabla `user_alerts_inteligentes` existe y es pivote entre user y storage — vale la pena REVISAR en una iteración futura si su semántica debería alimentar la lista de "storages con acceso" usada por el hook de Keyword. **No objeto de este change** — se documenta para backlog.

### Convención 4 — Índices para queries reales, no para todas las columnas

**Norma:** cada índice corresponde a una consulta documentada. Ver `2026_09_07_100000_create_avisos_scan_runs_table.php` (índices en `origin+created_at`, `status+created_at`, `created_at`).

**Nuestra propuesta:** tres índices:
- PK `(keyword_id, storage_provider_id)` — barrido natural en UPSERT.
- `(storage_provider_id, scanned_until)` — "¿qué keywords están atrasadas EN este storage?" (barrido full scan por storage).
- `(scanned_until)` — "¿qué (k,s) están totalmente sin escanear?" (catch-up de keywords nuevas).

✅ Cada índice responde a una consulta documentada. Sin índices redundantes.

### Convención 5 — Timestamps `timestamp(0)` (sin fracciones de segundo)

**Norma:** verificada — todas las tablas del módulo usan `timestamp(0)` excepto donde la precisión es crítica (no es nuestro caso — los watermarks son "fecha de cierre de cobertura", la precisión de segundo es más que suficiente y reduce el tamaño en PG).

**Nuestra propuesta:** `timestamp(0)` por defecto de Laravel en migraciones generadas con `foreignId` y `timestamp()`. ✅

### Convención 6 — Defaults explícitos en columnas no-null

**Norma:** `default(0)`, `default('{}')`, `default(now())` cuando el valor es estable.

**Nuestra propuesta:** `candidates_total default(0)`, `hits_total default(0)`, `timestamps()` (created_at/updated_at default CURRENT_TIMESTAMP vía Laravel). `scanned_until`, `last_scanned_at`, `last_hit_at`, `last_scan_run_id` quedan `nullable()` — su NULL tiene significado semántico ("nunca pasó"). ✅

### Convención 7 — Sin tablas redundantes con lo que ya existe

**Auditoría:** ¿la tabla `keyword_scan_watermarks` duplica algo?
- `system_settings.avisos_scan_cursor` (cursor global) — REEMPLAZADO por esta tabla. La fila de SystemSetting se retira en esta misma PR (migración `delete_setting('avisos_scan_cursor')`).
- `avisos_scan_runs` (log de corridas) — COMPLEMENTARIO. Esta tabla guarda cobertura; `avisos_scan_runs` guarda historia de ejecuciones. Ambas necesarias.
- `segment_keyword_hits` (hits materializados) — DIFERENTE nivel: hit-level vs. coverage-level. Sin solapamiento.
- `keyword_matches` (legacy, vacía) — IGNORADA; deprecada hace tiempo.

✅ Sin redundancia.

### Convención 8 — Nombres de índices explícitos cuando hay FKs compuestas

**Norma:** cuando la PK es compuesta, los índices secundarios llevan nombre explícito para evitar el default `keyword_scan_watermarks_storage_provider_id_scanned_until_index` que es ambiguo.

**Nuestra propuesta:** nombres cortos `ksw_storage_scanned_idx`, `ksw_scanned_idx`. ✅

### Convención 9 — `useCurrent()` vs `timestamps()` para created_at/updated_at

**Norma:** el resto del módulo usa `$table->timestamps()` (no `useCurrent()`). Consistente.

**Nuestra propuesta:** `$table->timestamps()`. ✅

### Convención 10 — Sin `uuid` ni surrogate keys innecesarios

**Norma:** BIGINT id en entidades, PK compuesta cuando aplica. Sin UUIDs.

**Nuestra propuesta:** PK compuesta, sin surrogate. ✅

### Resumen de la auditoría

```
┌─────────────────────────────────────────────────────────────────────┐
│ CONVENCIÓN                                  │ CUMPLE │ ACCIÓN       │
├─────────────────────────────────────────────┼────────┼──────────────┤
│ 1. snake_case tabla + columnas              │   ✅   │ —            │
│ 2. foreignId()->constrained()->cascade      │   ⚠️   │ verificar    │
│                                             │        │ restrict en  │
│                                             │        │ storage_prov │
│ 3. PKs explícitas                           │   ✅   │ —            │
│ 4. Índices por query real                   │   ✅   │ —            │
│ 5. timestamp(0)                             │   ✅   │ default      │
│ 6. Defaults explícitos                      │   ✅   │ —            │
│ 7. Sin tablas redundantes                   │   ✅   │ retirar      │
│                                             │        │ cursor global│
│ 8. Índices con nombre explícito             │   ✅   │ —            │
│ 9. timestamps() consistente                 │   ✅   │ —            │
│ 10. Sin UUID/surrogate innecesario          │   ✅   │ —            │
└─────────────────────────────────────────────┴────────┴──────────────┘

Acción derivada: en la tarea de migración,
  a) verificar FK storage_providers antes de cascadeOnDelete
  b) eliminar SystemSetting('avisos_scan_cursor') en la misma migración
  c) NO hace falta limpieza retroactiva de otras tablas
```

## Risks / Trade-offs

- [Race: dos `run()` concurrentes actualizando el mismo `(k,s)`] → Mitigación: UPSERT con `GREATEST` en SQL es atómico en PG. Sin lock pesimista.
- [Catch-up inicial grande: 36 keywords × 351k transcripciones] → Mitigación: el cron con `limit=50` por corrida + watermark monotónico absorbe el backlog progresivamente sin re-procesar. Estimación: ~7000 corridas (3s c/u) = 5.8h absorbibles en background.
- [Hook `Keyword::created` se dispara en seeders y tests] → Mitigación: el hook hace `insertOrIgnore`; reentradas无害. Tests deben usar `DatabaseTransactions` o aceptar el side-effect.
- [Storage se borra → ON DELETE CASCADE borra watermarks → keyword pierde su cobertura] → Mitigación: aceptable, el storage no existe. Si se RECREA storage con mismo id, las keywords vuelven a escanearse (correcto — son datos nuevos).
- [Migración inicial infiere `scanned_until` desde hits existentes] → Riesgo: si una keyword tiene hits SOLO en transcripciones muy viejas y nada después, el watermark queda en esa fecha vieja y el cron no la re-procesa. **Mitigación:** la migración también inserta `scanned_until = MIN(finished_at) - 1s` para que el cron incluya al menos UNA corrida adicional y verifique (idempotente — si no hay hits nuevos, no hace nada; si los hay, los encuentra y avanza).
- [Volumen de `candidates_total` y `hits_total` crece sin techo] → Mitigación: son `unsignedBigInteger` (hasta 9.2e18), pero en la práctica se reinicializan opcionalmente vía comando admin `avisos:reset-watermark-counters` (tarea 12). No es urgencia.

## Migration Plan

1. Crear migración `2026_09_10_120000_create_keyword_scan_watermarks_table.php`.
2. Crear migración `2026_09_10_120100_seed_keyword_scan_watermarks_from_existing_hits.php` (derivar watermarks desde `segment_keyword_hits` JOIN `transcriptions` JOIN `files` por `storage_provider_id`).
3. Crear migración `2026_09_10_120200_remove_legacy_avisos_scan_cursor_setting.php` (DELETE FROM system_settings WHERE key = 'avisos_scan_cursor').
4. Verificar FKs en `storage_providers` antes del paso 1.
5. Deploy — la migración inicial puede tardar minutos (derivación de ~540 filas); probar en staging primero.
6. Monitorear `Log::info('mentions.scan_completed')` durante 24h: el primer día mostrará muchos hits por catch-up; luego se normaliza.

## Rollback

```bash
# 1. Rollback de las 3 migraciones
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan migrate:rollback --step=3

# 2. Revertir código (refactor de selectCandidates + nuevo servicio)
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash>

# 3. Reiniciar supervisord (los workers cron cachean la clase al boot)
systemctl restart 'tcloud-transcription-batch-*'
```

**Freno de emergencia alternativo** (sin deploy): `dispatch_paused=true` en `system_settings` o `TRANSCRIPTOR_DISPATCH_PAUSED=true` en `.env` no aplica aquí (no es transcriptor). El equivalente para avisos es `avisos_scan_enabled = 0` — ya implementado en `AvisosScanService::settings()`. Eso detiene el cron automático; los manuales siguen disponibles vía CLI para diagnóstico.

## Open Questions

1. **¿El admin debe poder re-escanear UNA keyword específica en UN storage vía UI?** Asumido SÍ — se agrega al menú del admin en `ia.avisos-inteligentes`. Si se prefiere reservar a CLI, ajustar tareas.md.
2. **¿La UI muestra la lista de (k,s) sin watermark a quien?** Asumido: solo admins. Si los clientes deben ver "estado de cobertura de mis keywords", eso requiere endpoint nuevo — fuera de scope.
3. **¿`scanned_until` debe ser `timestamptz` o `timestamp`?** Asumido `timestamp(0)` (sin tz) por consistencia con el resto del módulo. Si la app usa UTC en todas partes, no hay diferencia funcional; `timestamptz` sería más defensivo pero rompería la convención actual.
