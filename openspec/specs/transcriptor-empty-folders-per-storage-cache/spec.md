# transcriptor-empty-folders-per-storage-cache Specification

## Purpose
TBD - created by archiving change 2026-09-12-api-transcriptor-pending-perf. Update Purpose after archive.

## Requirements

### Requirement: Cache por-storage de `/empty-folders`

El sistema SHALL refactorizar el cache del endpoint `GET /ia/api-transcriptor/empty-folders` para usar **una key por storage** en lugar de una key global única. La key SHALL ser `transcriptor:empty_folders:{storage_id}:max{maxDirs}` y SHALL tener TTL **600 s** (10 min).

El endpoint SHALL ensamblar la respuesta final agregando los resultados cacheados por storage; solo los storages sin cache disparan el `scandir()` del filesystem.

#### Scenario: Todos los storages cacheados (warm)
- **GIVEN** todas las keys `transcriptor:empty_folders:{storage_id}:max{maxDirs}` existen en cache
- **WHEN** el navegador hace `GET /ia/api-transcriptor/empty-folders`
- **THEN** el sistema retorna el JSON ensamblado en menos de **20 ms** sin ejecutar ningún `scandir()`
- **AND** el JSON resultante es idéntico al que se retornaría si todos los storages se escanearan en frío

#### Scenario: Algunos storages cacheados (parcial warm)
- **GIVEN** 50 storages tienen cache y 20 no
- **WHEN** el navegador hace `GET /ia/api-transcriptor/empty-folders`
- **THEN** el sistema hace `scandir()` solo sobre los 20 sin cache, lee de Redis los 50 cacheados, ensambla el resultado

#### Scenario: Cold path (nada cacheado)
- **GIVEN** ninguna key `transcriptor:empty_folders:*` existe
- **WHEN** el navegador hace `GET /ia/api-transcriptor/empty-folders`
- **THEN** el sistema escanea todos los storages (comportamiento actual), popula las keys por storage y retorna el JSON

### Requirement: Race-safe scan inicial

Cuando dos requests llegan al mismo tiempo y ninguno tiene cache, el sistema SHALL usar `Cache::lock()` para serializar el scan de cada storage. El segundo request SHALL esperar el resultado del primero (en lugar de duplicar el `scandir()`).

#### Scenario: Dos requests concurrentes al cold path
- **GIVEN** ninguna key de storage está cacheada
- **WHEN** dos requests `GET /empty-folders` llegan en paralelo
- **THEN** solo UN `scandir()` corre por storage (el segundo espera el lock)
- **AND** ambos requests retornan el mismo JSON

### Requirement: Invalidación en toggleStorage

El sistema SHALL invalidar TODAS las keys `transcriptor:empty_folders:{storage_id}:max{maxDirs}` cuando se ejecute `POST /api-transcriptor/storages/{id}/toggle`. Esto se hace porque el toggle de `transcription_enabled` cambia el conjunto de storages que el endpoint escanea, y porque `base_path` puede haber cambiado.

#### Scenario: Toggle limpia la cache del storage afectado
- **GIVEN** las keys de empty-folders están pobladas
- **WHEN** el operador ejecuta `POST /api-transcriptor/storages/{id}/toggle`
- **THEN** la key `transcriptor:empty_folders:{id}:max{maxDirs}` es borrada
- **AND** las keys de OTROS storages siguen pobladas (ahorramos scan innecesario)
