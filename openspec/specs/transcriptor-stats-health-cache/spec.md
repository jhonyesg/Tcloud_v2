# transcriptor-stats-health-cache Specification

## Purpose
TBD - created by archiving change 2026-09-12-api-transcriptor-pending-perf. Update Purpose after archive.

## Requirements

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
