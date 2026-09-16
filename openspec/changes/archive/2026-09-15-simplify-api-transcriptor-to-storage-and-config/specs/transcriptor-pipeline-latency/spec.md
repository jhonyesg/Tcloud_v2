## REMOVED Requirements

### Requirement: Pipeline persiste cuatro marcas temporales por Transcription

**Reason**: Mantener. Las cuatro columnas (`discovered_at`, `dispatched_at`, `started_at`, `finished_at`) en `transcriptions` siguen siendo la fuente de verdad para auditoría y diagnóstico, y se siguen escribiendo desde `TranscriptionSubmitService` y `TranscriptionPollingService`. Esta requirement se queda; **no se modifica**.

### Requirement: Endpoint `/ia/api-transcriptor/latency` con percentiles por etapa

**Reason**: Esta requirement documenta el endpoint JSON `GET /ia/api-transcriptor/latency` que devolvía `p50/p95/count_by_state` por etapa del pipeline. El endpoint y el partial `_pipeline-diagnostics.blade.php` que lo consumía se eliminan con la simplificación de UI.

**Migration**: Las marcas temporales siguen en BD; para auditoría se calculan percentiles con SQL (ver migration note en `transcription-orchestrator-runtime`).

### Requirement: Caché de percentiles en Redis para `/latency`

**Reason**: El cache `transcriptor:pipeline:percentiles:{window}` que evitaba recomputar percentiles en cada hit del endpoint se elimina junto con el endpoint.

**Migration**: N/A — el cache era del endpoint, no del cálculo. Las queries SQL directas siguen funcionando.

### Requirement: Header `Cache-Control: max-age=60, private` en `/latency`

**Reason**: Header HTTP del endpoint eliminado.

**Migration**: N/A.