# admin-storages-index-perf Specification

## Purpose
TBD - created by archiving change 2026-09-13-perf-audit-and-improve. Update Purpose after archive.

## Requirements

### Requirement: Cache del listado de Admin Storages

El sistema SHALL cachear la respuesta de `GET /admin/storages` (lista de storages + metricas agregadas) en Redis con TTL 60 s. Key: `admin:storages:index`.

El TTL SHALL ser legible desde `SystemSetting('admin_storages_cache_ttl', 60)`. Rango valido `[0, 600]`. Valor `0` = bypass.

#### Scenario: Cache hit
- **GIVEN** la key existe y fue poblada hace menos de 60 s
- **WHEN** el navegador hace `GET /admin/storages`
- **THEN** el sistema retorna el JSON cacheado
- **AND** la respuesta es `< 200 ms`

#### Scenario: Invalidacion al toggleStorage
- **GIVEN** la key esta poblada
- **WHEN** se ejecuta `POST /api-transcriptor/storages/{id}/toggle`
- **THEN** la key `admin:storages:index` es borrada
- **AND** el siguiente GET ejecuta compute fresco

#### Scenario: Invalidacion al mutar storage_providers
- **WHEN** cualquier mutacion en `storage_providers.base_path` o `transcription_enabled`
- **THEN** el sistema invalida `admin:storages:index`
