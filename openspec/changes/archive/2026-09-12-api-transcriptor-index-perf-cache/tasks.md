# Tasks

> Convención del proyecto: los IDs de tarea son numéricos y empiezan en 1. Marcar `[x]` solo cuando la tarea + verificación estén completas.

## 1. Migración: índice `text_pattern_ops`

- [ ] 1.1 Crear `app/database/migrations/2026_09_12_200001_add_base_path_pattern_index_to_storage_providers.php` con `$withinTransaction = false` y `CREATE INDEX CONCURRENTLY IF NOT EXISTS storage_providers_base_path_pattern_idx ON storage_providers (base_path text_pattern_ops)`. Patrón de referencia: `app/database/migrations/2026_05_21_000004_add_files_listing_composite_index.php`.
- [ ] 1.2 Verificar: `php artisan migrate --pretend` muestra el `CREATE INDEX CONCURRENTLY` esperado (no error de sintaxis, no warning de transacción).
- [ ] 1.3 Aplicar en dev/staging: `php artisan migrate`. Confirmar con `pg_indexes` que el índice existe.

## 2. Cache del scope en `StorageProvider`

- [ ] 2.1 En `app/app/Models/StorageProvider.php`, renombrar el método actual `resolveInheritedTranscriptionScope()` a `computeInheritedTranscriptionScope()` (privado).
- [ ] 2.2 Agregar constantes `SCOPE_CACHE_PREFIX = 'transcriptor.scope.inherited.'` y `SCOPE_CACHE_TTL_DEFAULT = 300`.
- [ ] 2.3 Crear el nuevo `resolveInheritedTranscriptionScope(int $rootId): array` público que:
  - Lee TTL de `SystemSetting::get('transcriptor_scope_cache_ttl', self::SCOPE_CACHE_TTL_DEFAULT)`.
  - Si TTL ≤ 0, llama directo al compute (bypass = freno de emergencia).
  - Si TTL > 0, usa `Cache::remember(SCOPE_CACHE_PREFIX . $rootId, $ttl, fn () => compute(...))`.
- [ ] 2.4 Agregar helper estático `forgetInheritedTranscriptionScope(int $rootId): void` que ejecuta `Cache::forget(SCOPE_CACHE_PREFIX . $rootId)`.
- [ ] 2.5 Verificar que `inheritedTranscriptionScopeInfo()` sigue funcionando (delega internamente al nuevo wrapper cacheado, no requiere cambio).
- [ ] 2.6 Verificar con `php -l app/app/Models/StorageProvider.php`.

## 3. Invalidación en `toggleStorage`

- [ ] 3.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`, dentro de `toggleStorage()` después del `$this->funnel->invalidate($rootId)` existente, agregar `StorageProvider::forgetInheritedTranscriptionScope((int) $rootId)`.
- [ ] 3.2 Verificar con `php -l app/app/Http/Controllers/Ia/ApiTranscriptorController.php`.

## 4. Settings UI (SystemSetting) opcional

- [ ] 4.1 Decidir: ¿se expone `transcriptor_scope_cache_ttl` en la pestaña Configuración del módulo Transcriptor? **Recomendación**: NO en este change (es freno de emergencia, no UX). El operador puede ajustarlo vía `tinker` o futuro tab de tuning. Documentar en AGENTS.md como setting reservado.

## 5. Harness de regresión

- [ ] 5.1 Crear `app/tests/harness_api_transcriptor_index_perf.php` que:
  - Tag único `htip_<8-hex>`.
  - Login con sesión admin real (siguiendo el patrón de `harness_mis_avisos_viewer.php`).
  - Cold hit: `GET /ia/api-transcriptor`, medir con `microtime(true)` antes/después, assertar `< 200 ms`.
  - Warm hit: segundo GET, assertar `< 30 ms`.
  - Invalidación: `POST /api-transcriptor/storages/{id}/toggle` (volver al estado original), verificar `Cache::has('transcriptor.scope.inherited.' . $rootId) === false` antes del toggle y `=== true` después (warm repoblado).
  - Verifica que `StorageFunnelService::countsForScope` sigue respondiendo idéntico (60 s TTL sin cambios).
  - Limpieza: try/finally borra todas las filas con prefijo `htip_*` y todas las keys cache con prefijo `htip_*`.
- [ ] 5.2 Ejecutar: `cd app && php tests/harness_api_transcriptor_index_perf.php` → exit 0.

## 6. Verificación manual (smoke test)

- [ ] 6.1 Hard reload `/ia/api-transcriptor` en navegador. Medir TTFB del primer request en DevTools → debe ser < 200 ms en producción tras warm-up.
- [ ] 6.2 Segundo reload → TTFB < 30 ms.
- [ ] 6.3 Toggle de un storage en UI → siguiente reload refleja el cambio inmediatamente.
- [ ] 6.4 Verificar en logs que no hay error 500 ni warning nuevo: `tail -50 /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log`.

## 7. Rollback (sin deploy de emergencia)

- [ ] 7.1 Verificar freno operativo: `php artisan tinker --execute="SystemSetting::set('transcriptor_scope_cache_ttl', '0');"` → comportamiento vuelve al baseline (sin cache, pero latencia original).
- [ ] 7.2 Verificar rollback completo vía migración: `php artisan migrate:rollback --step=1` (solo borra el índice, no toca el cache; el cache sigue siendo un speedup sin el índice).
- [ ] 7.3 Si se requiere reversión total del cache: `git revert <commit-hash>` + reload de PHP-FPM.

## 8. Documentación

- [x] 8.1 Actualizar `AGENTS.md` sección "Convenciones de BD" (si existe) o crear nota con:
  - El cache del scope es **provincial** (5 min default). Si alguien muta `storage_providers.base_path` o `transcription_enabled` debe llamar `StorageProvider::forgetInheritedTranscriptionScope($rootId)`.
  - `SystemSetting('transcriptor_scope_cache_ttl')` controla el TTL; `0` = bypass.
- [x] 8.2 Crear spec nueva `openspec/specs/transcriptor-index-load/spec.md` con los requisitos del behaviour.

## Follow-up (fuera de scope, registrado para futuro worktree)

- [ ] F.1 **Refactor de jerarquía explícita en `storage_providers`**: agregar columna `parent_storage_id` con FK, eliminar la inferencia por `base_path LIKE`. Beneficia a TODOS los módulos que consumen StorageProvider. Crear nuevo change `2026-MM-DD-storage-providers-explicit-hierarchy/` cuando se priorice.
