# Spec: transcriptor-index-load

## ADDED Requirements

### Requirement: Cache del scope heredado de StorageProvider

El sistema SHALL cachear el resultado de `StorageProvider::resolveInheritedTranscriptionScope(int $rootId)` con TTL configurable (default 300 segundos / 5 minutos), de modo que tres invocaciones consecutivas para el mismo `rootId` dentro del TTL NO ejecuten más de un BFS recursivo sobre la tabla `storage_providers`.

El TTL DEBE ser legible desde `SystemSetting::get('transcriptor_scope_cache_ttl')`. Si el valor es `null` o no está presente, el TTL por defecto es 300 s. El valor DEBE estar acotado al rango `[0, 3600]` segundos. Un valor de `0` SHALL desactivar el cache (bypass, llama directo al compute) — esto sirve como freno de emergencia operativo.

La clave de cache SHALL ser `transcriptor.scope.inherited.{rootId}` y SHALL almacenarse en el store de cache default de Laravel (Redis en producción).

#### Scenario: Cache hit dentro del TTL
- **GIVEN** una llamada previa a `resolveInheritedTranscriptionScope($rootId)` ocurrió hace menos de 300 s
- **WHEN** se invoca `resolveInheritedTranscriptionScope($rootId)` de nuevo
- **THEN** el sistema retorna el resultado cacheado SIN ejecutar la query recursiva sobre `storage_providers`
- **AND** el tiempo de respuesta es `< 5 ms`

#### Scenario: Cache miss fuera del TTL
- **GIVEN** la cache para `transcriptor.scope.inherited.{rootId}` expiró o nunca existió
- **WHEN** se invoca `resolveInheritedTranscriptionScope($rootId)`
- **THEN** el sistema ejecuta el BFS recursivo, almacena el resultado en cache con TTL = valor actual de `SystemSetting('transcriptor_scope_cache_ttl', 300)`, y lo retorna

#### Scenario: Bypass con TTL=0
- **GIVEN** `SystemSetting::set('transcriptor_scope_cache_ttl', '0')` (freno de emergencia)
- **WHEN** se invoca `resolveInheritedTranscriptionScope($rootId)`
- **THEN** el sistema NO consulta ni escribe la cache, ejecuta el BFS directamente cada vez (comportamiento legacy)

### Requirement: Invalidación explícita ante toggle de storage

El sistema SHALL exponer `StorageProvider::forgetInheritedTranscriptionScope(int $rootId): void` para invalidar la cache del scope de un root específico.

El endpoint `POST /ia/api-transcriptor/storages/{id}/toggle` (handler `ApiTranscriptorController::toggleStorage`) DEBE llamar `forgetInheritedTranscriptionScope($rootId)` después de actualizar `transcription_enabled`, donde `$rootId` es el root del scope del storage modificado (calculado con `StorageFunnelService::resolveRootIdFor()`).

#### Scenario: Toggle refresca el scope visible de inmediato
- **GIVEN** el operador abre `/ia/api-transcriptor` y ve un storage con `transcription_enabled=true`
- **WHEN** el operador ejecuta `POST /ia/api-transcriptor/storages/{id}/toggle` con `transcription_enabled=false`
- **THEN** el siguiente `GET /ia/api-transcriptor` refleja el cambio sin esperar al TTL (la key del scope fue borrada en el toggle)
- **AND** el conteo de descendants/herencia para ese storage se recalcula en el siguiente render

#### Scenario: Idempotencia del forget
- **WHEN** se llama `forgetInheritedTranscriptionScope($rootId)` dos veces seguidas
- **THEN** ambas llamadas son no-op (no lanzan, no afectan otras keys)

### Requirement: Índice en `storage_providers.base_path`

La tabla `storage_providers` SHALL tener un índice `text_pattern_ops` sobre la columna `base_path` para que las queries de tipo `WHERE base_path LIKE '/path/%'` (usadas por `resolveRootIdFor` y por el BFS del scope) NO recurran a seq scan.

El índice SHALL llamarse `storage_providers_base_path_pattern_idx` y SHALL crearse con `CREATE INDEX CONCURRENTLY IF NOT EXISTS` (idempotente, no bloquea la tabla).

La migración que crea este índice SHALL tener `$withinTransaction = false` (obligatorio porque `CREATE INDEX CONCURRENTLY` no puede correr dentro de una transacción).

#### Scenario: Query LIKE usa el índice
- **GIVEN** el índice `storage_providers_base_path_pattern_idx` existe
- **WHEN** se ejecuta `EXPLAIN SELECT id, base_path FROM storage_providers WHERE transcription_enabled = true AND base_path LIKE '/some/path/%'`
- **THEN** el plan muestra un Index Scan sobre `storage_providers_base_path_pattern_idx` (no Seq Scan)

#### Scenario: Migración CONCURRENTLY no bloquea la tabla
- **WHEN** se ejecuta `php artisan migrate` con la nueva migración
- **THEN** las queries concurrentes de lectura/escritura sobre `storage_providers` no son bloqueadas durante la creación del índice
- **AND** la migración aparece en `php artisan migrate:status` con estado `Ran`

### Requirement: Latencia de carga del módulo

El endpoint `GET /ia/api-transcriptor` SHALL responder:
- **Cold path** (primera carga o tras invalidación masiva) en `< 200 ms` TTFB en hardware de producción.
- **Warm path** (cache caliente, todos los scopes cacheados) en `< 30 ms` TTFB.

El harness `tests/harness_api_transcriptor_index_perf.php` SHALL medir estos dos escenarios contra el servidor y reportar PASS/FAIL.

#### Scenario: Cold path cumple el SLA
- **WHEN** se ejecuta el harness después de invalidar manualmente todas las keys `transcriptor.scope.inherited.*` con `php artisan tinker`
- **THEN** el primer `GET /ia/api-transcriptor` retorna en menos de 200 ms (assertion del harness)

#### Scenario: Warm path cumple el SLA
- **WHEN** se ejecuta el segundo `GET /ia/api-transcriptor` consecutivo
- **THEN** la respuesta retorna en menos de 30 ms (assertion del harness)

### Requirement: Compatibilidad hacia atrás

El cambio SHALL NO alterar la firma pública de `StorageProvider::resolveInheritedTranscriptionScope(int $rootId): array`. Todos los callers existentes (`ApiTranscriptorController::indexData`, `ApiTranscriptorController::storageFiles`, `StorageFunnelService::computeScopeCounts`, etc.) SHALL seguir funcionando sin modificación de su código.

`inheritedTranscriptionScopeInfo()` SHALL heredar el cache automáticamente al delegar en `resolveInheritedTranscriptionScope()` — no requiere cambio.

`StorageFunnelService::countsForScope()` (TTL 60 s) y `StorageFunnelService::cantidadFor()` (TTL 5 min) SHALL mantener su TTL actual sin modificación.

#### Scenario: Firma pública invariante
- **WHEN** un caller externo invoca `StorageProvider::resolveInheritedTranscriptionScope(42)`
- **THEN** recibe el mismo array de IDs (`[42, ...descendants]`) que recibía antes del cambio
- **AND** el método sigue siendo `static` y `public`

#### Scenario: StorageFunnelService sin cambios
- **WHEN** se invoca `StorageFunnelService::countsForScope($rootId)`
- **THEN** la cache key sigue siendo `transcriptor.funnel.scope.{rootId}` con TTL 60 s
- **AND** el callback de compute se beneficia del nuevo cache del scope sin necesidad de editar el service
