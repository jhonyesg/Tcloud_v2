## MODIFIED Requirements

### Requirement: Archivos subidos conservan metadatos básicos
Cada `File` creado vía share upload SHALL persistir al menos: `name` (nombre original), `path` (relativo al `storage.base_path`), `size` (bytes en disco), `mime_type`, `storage_provider_id`, `owner_id`, `parent_id`, `is_folder = false`.

#### Scenario: Metadata consistente
- **WHEN** se crea el `File` desde el endpoint de share
- **THEN** los campos anteriores están poblados y `mime_type` se obtiene del UploadedFile de Laravel (no se infiere del nombre)
- **AND** la fila no requiere la columna `is_personal` (eliminada en la normalización del esquema)
