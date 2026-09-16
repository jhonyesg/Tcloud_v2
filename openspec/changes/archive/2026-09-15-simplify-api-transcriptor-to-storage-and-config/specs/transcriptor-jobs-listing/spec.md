## REMOVED Requirements

### Requirement: El listado de trabajos se pagina y filtra en servidor
### Requirement: Los scopes terminales se ordenan por fecha de finalización
### Requirement: El conteo del total no recorre la tabla completa
### Requirement: El parámetro `only=jobs` omite el bloque de storages
### Requirement: El filtro de estado se intersecta con el scope
### Requirement: La búsqueda cubre `original_name`
### Requirement: Sub-tab Fallidos separada de Completados

**Reason**: Toda la spec documenta la pestaña "Trabajos" del módulo `/ia/api-transcriptor` (sub-tabs pending/completed/failed/all, búsqueda por filename, paginación 50/100, filtro de estado, intersección con scope, `?only=jobs` para no recalcular storages al cambiar de página). Esa pestaña se elimina en el change `simplify-api-transcriptor-to-storage-and-config` porque el upstream no está enviando trabajos y la UI solo mostraba listas vacías o stuck sin acción posible.

**Migration**: Los datos siguen en la tabla `transcriptions` y son consultables:
- Para auditoría: queries SQL directas sobre `transcriptions` filtrando por `state`, `created_at`, `file_id`, `job_id`.
- Para el operador: el dashboard (`/dashboard`) y Mis Avisos (`/ia/avisos-inteligentes`) muestran el estado por storage sin pasar por esta UI.
- Para el detalle de un job concreto: grep por `id` o `job_id` en `transcriptions` y abrir el `.srt` desde disco si existe.