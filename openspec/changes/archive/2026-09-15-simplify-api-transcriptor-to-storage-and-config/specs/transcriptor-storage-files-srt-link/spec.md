## REMOVED Requirements

### Requirement: Endpoint `storageFiles` expone id y estado de la Transcription asociada
### Requirement: Modal "Ver archivos" muestra SRT y transcripción por archivo
### Requirement: Botón "Procesar carpeta/día" encola jobs sobre los archivos del modal

**Reason**: La spec documenta el endpoint `GET /ia/api-transcriptor/storages/{id}/files` que listaba los archivos de un storage con su estado de transcripción, el modal "Ver archivos" que mostraba el SRT inline, y los botones "Procesar carpeta" / "Procesar día" del modal. Toda esa superficie se elimina con la simplificación de UI en el change `simplify-api-transcriptor-to-storage-and-config`.

**Migration**: 
- Para ver archivos por storage: usar la navegación principal `/files?storage_id=X` (vista Mis Archivos filtra por storage_provider_id).
- Para ver el SRT de un archivo concreto: `/ia/mis-archivos` (si está implementado en el storage) o el visor de Mis Avisos (`mis-archivos-transcript-viewer`).
- Para procesar todos los archivos de un día: `php artisan transcription:scan-and-submit --days=0 --batch=200` (HOY) o `--days=1` (AYER).