## REMOVED Requirements

### Requirement: Persistencia de la decisión del regulador en cache transcriptor:tick:last_decision

**Reason**: La cache `transcriptor:tick:last_decision` solo la consumía el endpoint `/ia/api-transcriptor/regulator-cause` (eliminado). El regulador sigue calculando `signals_evaluated`, `decision`, `reason` y `batch_computed` por su cuenta, pero ya no se persiste en Redis para servir a una UI que no existe.

**Migration**: La decisión cruda sigue escribiéndose en `storage/logs/laravel.log` con el patrón `TranscriptionTickCommand`. Si el operador quiere auditar histórico: `grep "decision=" storage/logs/laravel.log | tail -50`. La pestaña Configuración muestra `cfgRuntime.next_batch` en vivo sin pasar por la cache. Si en el futuro se quiere un panel de diagnóstico, esta cache y este endpoint se reintroducen juntos.

### Requirement: Endpoint `/ia/api-transcriptor/latency`

**Reason**: El endpoint de latencia p50/p95 por etapa alimentaba el partial `_pipeline-diagnostics.blade.php` que se elimina con la simplificación de UI.

**Migration**: Las marcas temporales siguen en BD; para auditoría se calculan percentiles con SQL.

### Requirement: Endpoint `/ia/api-transcriptor/regulator-cause`

**Reason**: El endpoint servía la cache `transcriptor:tick:last_decision` (también eliminada). La pestaña Configuración muestra `cfgRuntime.next_batch` en vivo sin pasar por este endpoint.

**Migration**: El regulador sigue decidiendo; la decisión queda en log y en `cfgRuntime`.
