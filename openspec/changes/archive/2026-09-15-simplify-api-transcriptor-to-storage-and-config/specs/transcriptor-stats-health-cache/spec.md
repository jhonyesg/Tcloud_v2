## REMOVED Requirements

### Requirement: Cache de `/stats` con TTL configurable
### Requirement: Cache de `/health` con TTL configurable
### Requirement: Header `Cache-Control` en `/stats` y `/health`

**Reason**: Las tres requirements documentan el cache Redis `transcriptor:stats:combined` (TTL default 300s, override vía `transcriptor_stats_cache_ttl`), el cache `transcriptor:health:combined` (TTL default 120s) y el header `Cache-Control: max-age=30, private` que servían los endpoints `GET /ia/api-transcriptor/stats` y `GET /ia/api-transcriptor/health`. Ambos endpoints se eliminan en el change `simplify-api-transcriptor-to-storage-and-config` porque solo alimentaban los contadores de la cabecera de la pestaña Trabajos y el indicador "API en línea" — piezas que también se retiran.

**Migration**: 
- Conteos por estado: SQL directo (ver migration note de `transcriptor-state-visibility`).
- Salud del upstream: el log `laravel.log` muestra los errores del polling y de `transcription:health-check` (scheduled hourly). Para ver el estado inmediato del upstream: `curl -fsS ${TRANSCRIPTOR_BASE_URL}/health` desde terminal.

### Requirement: Invalidación de caches en `toggleStorage`

**Reason**: Esta requirement (heredada de la spec) describía la invalidación de `transcriptor:stats:combined`, `transcriptor:health:combined` y `transcriptor:empty_folders:*` cuando se ejecuta `POST /ia/api-transcriptor/storages/{id}/toggle`. Con la eliminación de los endpoints y los caches, esta invalidación se reduce al cache del scope heredado (`transcriptor.scope.inherited.*`), que sigue siendo responsabilidad de `StorageProvider::forgetInheritedTranscriptionScope()` y queda cubierta por `transcriptor-index-load`.

**Migration**: `toggleStorage` solo invoca `forgetInheritedTranscriptionScope($rootId)` tras el cambio de `transcription_enabled`. Sin las invalidaciones de stats/health/empty_folders.