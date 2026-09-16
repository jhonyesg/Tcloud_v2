## Why

El módulo API Transcriptor es el único módulo de la plataforma que tarda 800 ms – 1.5 s en frío al cargar `/ia/api-transcriptor`. El resto de módulos (Mis Avisos, Mis Archivos, Dashboard, Avisos Inteligentes) responden en 200-500 ms.

Investigando `ApiTranscriptorController::indexData()` (líneas 217-309 del archivo `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`), encontré que el método invoca `StorageProvider::resolveInheritedTranscriptionScope()` **tres veces por cada uno de los 190 `storage_providers`** = ~570 walks recursivos del árbol de scopes por carga de página. Adicionalmente, `StorageProvider::resolveRootIdFor()` ejecuta un `LIKE` seq scan sobre `storage_providers` × 190, porque la tabla **no tiene índice en `base_path`** (verificado con `pg_indexes`: solo existe `storage_providers_pkey`).

El propio autor ya documentó este costo en el comentario de la línea 209 del controller: *"Paginar la tabla de Trabajos no necesita recalcular los storages, y ese bloque cuesta ~430ms (resolveInheritedTranscriptionScope por storage)"*. La salida provisional que él dejó (`?only=jobs` para saltar el bloque) es un parche: solo evita el cálculo al paginar, pero la carga inicial sigue siendo lenta.

**Causa raíz**: `resolveInheritedTranscriptionScope()` no está cacheada. Mientras el resto de helpers pesados del módulo (`StorageFunnelService::cantidadFor` con 5 min, `StorageFunnelService::countsForScope` con 60 s) sí lo están, este — que es **el más caro** — no.

**Volumen que lo hace sentir**: en producción (DB consultada 2026-09-12) hay 190 storage_providers (70 con `transcription_enabled=true`) y 387 651 transcriptions (`done 249 604`, `pending 113 424`, `dead 22 808`). Cada carga del módulo dispara ~210 BFS recursivos sobre este árbol en el peor caso (cuando las caches adyacentes expiraron).

## What Changes

- Cachear `StorageProvider::resolveInheritedTranscriptionScope()` con TTL configurable (default **300 s / 5 min**, ajustable vía `SystemSetting('transcriptor_scope_cache_ttl', 300)`), siguiendo el mismo patrón que `StorageFunnelService::countsForScope()`.
- Invalidar la cache del scope en cada mutación relevante:
  - `ApiTranscriptorController::toggleStorage()` — ya invalida `StorageFunnelService`; sumamos el forget del scope del root afectado.
  - Comando `transcription:scan-and-submit` y cualquier otro punto que mute `storage_providers.base_path` o `transcription_enabled` (verificar en el grep del scan).
- Agregar índice **`CREATE INDEX CONCURRENTLY ... text_pattern_ops`** sobre `storage_providers(base_path)` para que el cold path y los `resolveRootIdFor` (LIKE) no caigan en seq scan.
- Migración nueva `2026_09_12_xxxxxx_add_base_path_pattern_index_to_storage_providers.php` con `$withinTransaction = false` (mismo patrón que `2026_05_21_000004_add_files_listing_composite_index.php`).
- Helper estático `StorageProvider::forgetInheritedTranscriptionScope(int $rootId)` para centralizar el `Cache::forget()` y reutilizar en invalidaciones (DRY).
- Spec nueva `transcriptor-index-load` que define el contrato de latencia (cold <200 ms, warm <30 ms) y la invariante de invalidación.

## Capabilities

### New Capabilities
- `transcriptor-index-load`: la carga inicial de `/ia/api-transcriptor` SHALL completarse en menos de 200 ms en frío y menos de 30 ms en caliente, con invalidación correcta ante toggles de `transcription_enabled`. La implementación DEBE cachear `resolveInheritedTranscriptionScope()` con TTL configurable (default 5 min) y DEBE invalidar la cache del scope del root afectado en cada mutación relevante.

## Impact

- `app/app/Models/StorageProvider.php` — método estático gana wrapper con `Cache::remember` + helper `forgetInheritedTranscriptionScope()`. Sin cambio de firma pública.
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `toggleStorage()` agrega un `forgetInheritedTranscriptionScope()` junto al `$this->funnel->invalidate()` que ya hace (1 línea).
- `app/app/Console/Commands/TranscriptionTickCommand.php` (y batch command si aplica) — agregar forget al mutar storage.
- `app/database/migrations/2026_09_12_xxxxxx_add_base_path_pattern_index_to_storage_providers.php` — nueva, índice `text_pattern_ops`.
- `app/app/Services/Ia/StorageFunnelService.php` — sin cambios (su callback de `computeScopeCounts` se beneficia del cache del scope vía el helper estático cacheado, pero no requiere edición).
- Sin cambios en UI, sin cambios en JS/Alpine de la vista.
- Sin cambio de esquema (solo índice), sin breaking change de contrato de cache existente.

## Non-goals

- **No** se hace el refactor de jerarquía explícita con `parent_id` (sustituir la inferencia por `base_path LIKE`). Ese cambio es estructural y toca todos los módulos que consumen `StorageProvider` (Mis Avisos, Mis Archivos, Avisos Inteligentes). Queda como follow-up en `tasks.md`.
- **No** se cambia el TTL de `StorageFunnelService::countsForScope` (60 s) ni de `cantidadFor` (5 min). Suficientes para su rol.
- **No** se agrega cache a nivel de `indexData()` completo. Esa capa es más invasiva (toda la invalidación se vuelve frágil) y el cuello de botella real es solo el BFS del scope.
- **No** se introduce una segunda capa de cache (Laravel cache + Redis cache). Se usa la cache existente (`Cache::remember` con el store default, que ya es Redis en producción según `config/cache.php`).
- **No** se cambian los endpoints `storageFiles` ni `bulkDispatch`. Solo el camino de `indexData()`.
