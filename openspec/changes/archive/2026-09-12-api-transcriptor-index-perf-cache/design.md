## Decisión: cache del scope + índice `text_pattern_ops`

Tres opciones evaluadas en la conversación de explore. Esta es la opción **D** del menú original.

| Opción | Latencia esperada | Esfuerzo | Por qué no |
|--------|-------------------|----------|-----------|
| A. Solo cache de scope (sin índice) | Cold 150-200 ms, warm <20 ms | Bajo | El cold path (post-invalidación) sigue padeciendo seq scan ~210 ms. |
| B. Cache de `indexData()` entero | <10 ms warm | Medio | Invalidación frágil; cualquier mutación queda stale. |
| **D. Cache de scope + índice `text_pattern_ops`** | **Cold 30-80 ms, warm <20 ms** | **Bajo-medio** | **Elegida.** Trata el síntoma Y la causa raíz del cold path. |
| E. Jerarquía explícita con `parent_id` | <20 ms en TODOS los módulos | Alto | Cambio estructural; queda como follow-up. |

## Diseño del cache

### Helper estático en `StorageProvider`

Agregar cache **alrededor** de `resolveInheritedTranscriptionScope()` sin cambiar su firma ni el algoritmo. El cache es por `rootId` y se invalida explícitamente.

```php
private const SCOPE_CACHE_PREFIX = 'transcriptor.scope.inherited.';
private const SCOPE_CACHE_TTL_DEFAULT = 300; // 5 min

public static function resolveInheritedTranscriptionScope(int $rootId): array
{
    $ttl = (int) SystemSetting::get(
        'transcriptor_scope_cache_ttl',
        self::SCOPE_CACHE_TTL_DEFAULT
    );
    $ttl = max(0, min(3600, $ttl)); // [0, 1h]
    if ($ttl === 0) {
        return static::computeInheritedTranscriptionScope($rootId); // bypass
    }
    return Cache::remember(
        self::SCOPE_CACHE_PREFIX . $rootId,
        $ttl,
        fn () => static::computeInheritedTranscriptionScope($rootId)
    );
}

// Renombrar el método actual a computeInheritedTranscriptionScope() (privado).
```

`inheritedTranscriptionScopeInfo()` también se beneficia automáticamente (internamente llama a `resolveInheritedTranscriptionScope`).

### Helper de invalidación

```php
public static function forgetInheritedTranscriptionScope(int $rootId): void
{
    Cache::forget(self::SCOPE_CACHE_PREFIX . $rootId);
}
```

### TTL configurable vía SystemSetting

`SystemSetting('transcriptor_scope_cache_ttl')` permite subirlo o bajarlo sin deploy. Por qué: el operador puede necesitar 60 s en horarios de scan pesado (más fresco) o 30 min en operación normal (más rápido). Default 300 s.

Si el operador pone `0`, el cache se bypass-ea (vuelve al comportamiento actual sin cache). Esto sirve como **freno de emergencia** si la cache causara inconsistencia.

## Puntos de invalidación

Solo mutaciones de `storage_providers` que afecten la geometría del scope.

| Punto | Acción |
|-------|--------|
| `ApiTranscriptorController::toggleStorage()` | Después de `$this->funnel->invalidate($rootId)`, agregar `StorageProvider::forgetInheritedTranscriptionScope($rootId)`. `$rootId` ya está calculado en la misma función. |
| `TranscriptionTickCommand` / `transcription:scan-and-submit` | Solo si el comando toca `base_path` o `transcription_enabled` (verificar con grep). Si no, no necesita invalidación — la cache ya refleja el estado actual del scope. |
| Migración nueva de BD (índice) | No requiere invalidación (solo agrega índice, no muta datos). |

**Nota**: `toggleStorage` es el único punto que muta `transcription_enabled` desde la UI; es además la única mutación de la geometría del scope. Si en el futuro alguien agrega un endpoint que edite `base_path`, ese endpoint debe llamar `forgetInheritedTranscriptionScope($rootId)`.

## Índice `text_pattern_ops`

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS
  storage_providers_base_path_pattern_idx
  ON storage_providers (base_path text_pattern_ops);
```

- **Por qué `text_pattern_ops`**: la query `WHERE base_path LIKE '/path/%'` usa un índice `text_pattern_ops` (optimizado para prefijo). Un índice BTREE normal no se usa con `LIKE '/algo/%'` salvo en C-locale específico.
- **Por qué `CONCURRENTLY`**: la tabla tiene 190 filas hoy, pero puede crecer; CONCURRENTLY evita lock exclusivo de la tabla durante el `CREATE`.
- **Por qué `IF NOT EXISTS`**: idempotente para retries de migración.
- **Migración Laravel**:

```php
<?php
// app/database/migrations/2026_09_12_200001_add_base_path_pattern_index_to_storage_providers.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS storage_providers_base_path_pattern_idx ON storage_providers (base_path text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS storage_providers_base_path_pattern_idx');
    }
};
```

`$withinTransaction = false` es **obligatorio** porque `CREATE INDEX CONCURRENTLY` no puede correr dentro de una transacción de migración (verificado en `2026_05_21_000004_add_files_listing_composite_index.php` y AGENTS.md nota sobre este patrón).

## Compatibilidad

- Firma de `StorageProvider::resolveInheritedTranscriptionScope(int $rootId): array` **no cambia**. Todos los callers siguen funcionando idéntico.
- `inheritedTranscriptionScopeInfo()` no se toca: ya delega en `resolveInheritedTranscriptionScope()` y hereda el cache automáticamente.
- `StorageFunnelService::computeScopeCounts()` llama a `resolveInheritedTranscriptionScope()`; ahora se beneficia del cache sin editar.
- No hay cambio de comportamiento observable cuando el cache está frío (warm-up). El primer hit tras deploy hace el mismo trabajo que antes, pero los siguientes son instantáneos.
- El setting `transcriptor_scope_cache_ttl=0` (bypass) sirve como rollback operativo sin deploy.

## Validación

- Nuevo harness `tests/harness_api_transcriptor_index_perf.php` que:
  1. Hace `GET /ia/api-transcriptor` cold → mide latencia, asserta < 200 ms.
  2. Hace segundo hit warm → mide latencia, asserta < 30 ms.
  3. Llama `POST /api-transcriptor/storages/{id}/toggle` → verifica que la key del cache del scope afectado desaparece (`Cache::has(...) === false`).
  4. Verifica que el `StorageFunnelService` de 60 s sigue funcionando (no fue tocado).

- Smoke test manual post-deploy:
  1. Hard reload `/ia/api-transcriptor`, medir con DevTools Network → primer request < 200 ms TTFB.
  2. Toggle `transcription_enabled` en un storage → siguiente reload refleja el cambio inmediatamente (no espera TTL).
  3. Poner `SystemSetting('transcriptor_scope_cache_ttl', 0)` y verificar que la carga vuelve a la latencia baseline (rollback).

## Riesgos

| Riesgo | Mitigación |
|--------|-----------|
| Cache stale si un endpoint mute `base_path` sin invalidar | Único mutador hoy: ningún endpoint UI toca `base_path`. Si se agrega uno, debe llamar `forgetInheritedTranscriptionScope`. Documentar en AGENTS.md. |
| TTL demasiado largo en horario de scan | El operador puede bajar el TTL a 60 s sin deploy (SystemSetting). |
| Índice `text_pattern_ops` no se usa por C-locale | Verificado: la columna es `text` (no `varchar`), el operador `LIKE` con prefijo fijo sí usa el índice. |
| Race en invalidación + lectura concurrente | Mismo patrón que `WatermarkReconciler::CacheEpoch`: leer un valor "viejo" (≤ TTL) es aceptable, nunca datos incorrectos. Documentar en caveats. |
| `Cache::remember` ejecuta la query de scope durante un rebuild masivo | Si el operador lanza 10 toggles seguidos, hay 10 rebuilds. Aceptable: cada rebuild es ≤15 ms. |

## Follow-up (no incluido en este change)

Refactor de jerarquía explícita en `storage_providers` con columna `parent_storage_id` y FK, eliminando la inferencia por `base_path LIKE`. Beneficios:

- Scope tree trivial con un solo `WITH RECURSIVE` PL/pgSQL o join recursivo.
- Otros módulos (Mis Avisos, Mis Archivos, Avisos Inteligentes) heredan el mismo speedup.
- Eliminación del `base_path LIKE` global.

Es worktree aparte, propuesta separada.
