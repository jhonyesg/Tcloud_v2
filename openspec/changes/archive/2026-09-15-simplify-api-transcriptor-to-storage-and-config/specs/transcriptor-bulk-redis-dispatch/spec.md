## REMOVED Requirements

### Requirement: Endpoint `bulk-dispatch` encola a Redis en menos de 1 segundo
### Requirement: `bulk-dispatch` con `ids[]` selectivos
### Requirement: `bulk-dispatch` devuelve contadores `{enqueued, skipped_queued, errors}`

**Reason**: Las requirements documentan el endpoint `POST /ia/api-transcriptor/jobs/bulk-dispatch` y sus scenarios de uso desde la UI (checkbox de selección + botón "Procesar N seleccionados ahora" en la sub-tab Trabajos). El endpoint y la UI desaparecen con la pestaña Trabajos en el change `simplify-api-transcriptor-to-storage-and-config`.

**Migration**: El dispatch bulk sigue siendo posible vía el tick automático (Phase 2 con `computeDispatchBatch`) y vía `transcription:scan-and-submit --batch=N` desde CLI. Para escenarios donde el operador quería re-intentar jobs específicos en bulk, `transcription:retry-batch-upstream --max-age-hours=168` cubre el caso desde el scheduled semanal.