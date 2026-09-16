# correcciones-status-cache Specification

## Purpose
TBD - created by archiving change 2026-09-13-perf-audit-and-improve. Update Purpose after archive.

## Requirements

### Requirement: Cache de `/ia/correcciones/ai-suggest-status` con TTL 30s

El sistema SHALL cachear la respuesta de `GET /ia/correcciones/ai-suggest-status` en Redis con TTL 30 s. Key: `correcciones:ai_suggest_status`.

#### Scenario: Cache hit
- **GIVEN** la key existe y fue poblada hace menos de 30 s
- **WHEN** el navegador hace `GET /ia/correcciones/ai-suggest-status`
- **THEN** el sistema retorna el JSON cacheado sin ejecutar el compute de estado
- **AND** la respuesta es `< 50 ms`

### Requirement: Cache de `/ia/correcciones/mining-status` con TTL 30s

El sistema SHALL cachear la respuesta de `GET /ia/correcciones/mining-status` con TTL 30 s. Key: `correcciones:mining_status`.

#### Scenario: Cache hit
- **WHEN** la key existe y fue poblada hace menos de 30 s
- **THEN** la respuesta es `< 50 ms`

### Requirement: Cache de `/ia/correcciones/ai-suggest-settings` con TTL 60s

El sistema SHALL cachear la respuesta de `GET /ia/correcciones/ai-suggest-settings` con TTL 60 s. Key: `correcciones:ai_suggest_settings`.

Settings cambian muy raramente; 60 s de staleness es aceptable para una UI que solo muestra valores booleanos / numericos.

#### Scenario: Cache hit
- **WHEN** la key existe y fue poblada hace menos de 60 s
- **THEN** la respuesta es `< 30 ms`

### Requirement: Invalidacion al cambiar settings

El sistema SHALL invalidar las 3 caches (`ai_suggest_status`, `mining_status`, `ai_suggest_settings`) cuando se ejecute `PUT /ia/correcciones/ai-suggest-settings`.

#### Scenario: Cambio de settings refresca cache
- **GIVEN** las caches estan pobladas
- **WHEN** el operador hace `PUT /ia/correcciones/ai-suggest-settings`
- **THEN** las 3 keys son borradas
- **AND** el siguiente GET ejecuta compute fresco
