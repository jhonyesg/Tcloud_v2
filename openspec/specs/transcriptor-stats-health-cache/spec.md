# transcriptor-stats-health-cache Specification

## Purpose
TBD - created by archiving change 2026-09-12-api-transcriptor-pending-perf. Update Purpose after archive.

## Requirements

### Requirement: Cache de `/stats` con TTL configurable

El sistema SHALL cachear la respuesta combinada de `GET /ia/api-transcriptor/stats` en Redis con TTL configurable. La key SHALL ser `transcriptor:stats:combined` y SHALL incluir los siguientes campos:
- `local`: contadores por estado (`state => count`) del GROUP BY sobre `transcriptions`.
- `upstream`: respuesta del transcriptor upstream vía `TranscriptorApiClient::getStats()`.
- `cached_at`: timestamp ISO8601 del momento en que se pobló la cache.

El TTL SHALL ser legible desde `SystemSetting('transcriptor_stats_cache_ttl', 60)`. Un valor de `0` SHALL desactivar el cache (bypass, llama directo). El rango válido es `[0, 3600]` segundos.

#### Scenario: Cache hit dentro del TTL
- **GIVEN** la key `transcriptor:stats:combined` existe y fue poblada hace menos de 60 s
- **WHEN** el navegador hace `GET /ia/api-transcriptor/stats`
- **THEN** el sistema retorna el JSON cacheado SIN ejecutar el `GROUP BY` ni el HTTP call al upstream
- **AND** el tiempo de respuesta es `< 50 ms`

#### Scenario: Cache miss fuera del TTL
- **GIVEN** la key `transcriptor:stats:combined` no existe o expiró
- **WHEN** el navegador hace `GET /ia/api-transcriptor/stats`
- **THEN** el sistema ejecuta el `GROUP BY` local + el HTTP call al upstream, almacena el resultado en cache y lo retorna

#### Scenario: Invalidación al mutar transcripciones
- **WHEN** se crea, completa, falla o se reintenta una `Transcription` (cualquier mutación que afecte el GROUP BY local)
- **THEN** el sistema invalida `transcriptor:stats:combined` vía `Cache::forget(...)`
- **AND** el siguiente `GET /stats` ejecuta el compute (fresh)

### Requirement: Cache de `/health` con TTL configurable

El sistema SHALL cachear la respuesta de `GET /ia/api-transcriptor/health` en Redis con TTL configurable. La key SHALL ser `transcriptor:health:combined` y SHALL incluir la respuesta del HTTP call a `/health` del upstream.

El TTL SHALL ser legible desde `SystemSetting('transcriptor_health_cache_ttl', 30)`. Un valor de `0` SHALL desactivar el cache (bypass). Rango válido `[0, 3600]` segundos.

#### Scenario: Cache hit dentro del TTL
- **GIVEN** la key `transcriptor:health:combined` existe y fue poblada hace menos de 30 s
- **WHEN** el navegador hace `GET /ia/api-transcriptor/health`
- **THEN** el sistema retorna el JSON cacheado SIN ejecutar el HTTP call al upstream
- **AND** el tiempo de respuesta es `< 20 ms`

#### Scenario: Cache miss fuera del TTL
- **GIVEN** la key `transcriptor:health:combined` no existe o expiró
- **WHEN** el navegador hace `GET /ia/api-transcriptor/health`
- **THEN** el sistema ejecuta el HTTP call al upstream, almacena el resultado en cache y lo retorna

#### Scenario: Bypass con TTL=0
- **GIVEN** `SystemSetting::set('transcriptor_health_cache_ttl', '0')` (freno de emergencia)
- **WHEN** se invoca el endpoint
- **THEN** el sistema NO consulta ni escribe la cache, ejecuta el HTTP call directamente cada vez

### Requirement: Header `Cache-Control` en endpoints cacheados

El sistema SHALL emitir el header HTTP `Cache-Control: max-age=30, private` en las respuestas de `/stats` y `/health` para que el navegador no revalide en cada navegación entre pestañas (mientras el navegador mantiene la conexión viva).

#### Scenario: Header presente
- **WHEN** el navegador hace `GET /ia/api-transcriptor/stats`
- **THEN** la respuesta incluye `Cache-Control: max-age=30, private`

### Requirement: Invalidación en toggleStorage

El sistema SHALL invalidar `transcriptor:stats:combined` y `transcriptor:health:combined` cuando se ejecute `POST /api-transcriptor/storages/{id}/toggle` (porque el `transcription_enabled` afecta qué storages cuentan para el funnel del día, que es parte de `local`).

#### Scenario: Toggle refresca stats
- **GIVEN** el operador abrió `/ia/api-transcriptor` y vio stats cacheadas
- **WHEN** el operador ejecuta `POST /api-transcriptor/storages/{id}/toggle`
- **THEN** la key `transcriptor:stats:combined` es borrada
- **AND** el siguiente `GET /stats` ejecuta compute fresco
