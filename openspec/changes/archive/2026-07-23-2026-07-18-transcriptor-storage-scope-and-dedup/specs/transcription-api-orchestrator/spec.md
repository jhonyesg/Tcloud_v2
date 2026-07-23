## REMOVED Requirements

### Requirement: Storage transcription priority
*(Eliminado — la prioridad se asigna desde el panel del API Transcriptor directamente; mantenerla aquí generaba redundancia y no se observaba efecto operativo en el orden de procesamiento.)*

Las siguientes capacidades quedan sin efecto:
- Selección de cola Redis (`transcription-high` / `transcription-medium` / `transcription-low`) basada en `transcription_priority` del storage.
- Cálculo de prioridad compuesto `storage_priority * 10 + es_hoy ? 100 : 0 + es_manual ? 5 : 0` en `ConvertAndTranscribeJob::calculatePriority()`.
- UI de asignación de priority (`<select>` editable en `index.blade.php`, badge "P", método `savePriority()`).

#### Scenario: Todos los jobs van a una sola cola
- **WHEN** cualquier storage con `transcription_enabled=true` dispatcha un job
- **THEN** el job se encola en `queue=transcription` (single queue)
- **AND** el orden de procesamiento queda determinado por el orden de dispatch (FIFO) y la concurrencia del supervisor

### Requirement: Jobs se encolan con prioridad en Redis procesados por 10 workers paralelos
*(Eliminado — reemplazado por "Jobs se encolan en Redis y los procesan 10 workers paralelos" en la spec principal. El concepto de prioridad calculada (`storage_priority * 10 + (es_hoy ? 100 : 0) + (es_manual ? 5 : 0)`) y la selección de cola basada en umbral (`>=100` → high, `>=50` → medium, resto → low) deja de existir; todos los jobs van a una sola cola.)*
