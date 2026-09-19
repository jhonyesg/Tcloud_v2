## Purpose

Comportamiento del sync cuando existen storages duplicados o mergeados: garantiza que el listado y la delegación usan solo storages canónicos.

## Requirements

### Requirement: Listing prioriza storage canónico

`StorageSyncService::currentListing()` SHALL deduplicar storages que comparten el mismo `physical_path_normalized`, prefiriendo el canonical (no mergeado) sobre cualquier otro.

#### Scenario: Listing omite storages mergeados

- **WHEN** dos storages comparten `physical_path_normalized` (ej. 132 y 142 ambos en `La_voz/`)
- **AND** storage 142 está mergeado (`duplicate_of_storage_id=132`)
- **THEN** SHALL retornar solo el contenido de storage 132
- **AND** SHALL NO incluir filas del storage 142

#### Scenario: Listing resuelve ambigüedad entre storages no mergeados

- **WHEN** dos storages comparten path y ninguno está mergeado (estado pre-merge)
- **THEN** SHALL preferir el canonical según `StorageProvider::canonicalFor(path)` (más transcriptions linkeadas, id menor como tie-break)
- **AND** SHALL log warning `storage_sync.duplicate_path_listing` con el storage ignorado

### Requirement: Sync delegation excluye storages mergeados

`StorageSyncService::findMoreSpecificStorage(int $absolutePath, int $excludeStorageId): ?StorageProvider` SHALL retornar solo storages canónicos (donde `duplicate_of_storage_id IS NULL`).

#### Scenario: Búsqueda ignora storages mergeados

- **WHEN** se busca el storage más específico para `/Tcloud/.../La_voz/`
- **AND** storage 142 está mergeado
- **THEN** SHALL retornar storage 132 (canonical)
- **AND** SHALL NO incluir 142 en el cálculo de peso (`strlen(base_path)`)

### Requirement: WatermarkReconciler respeta merge state

`Ia\WatermarkReconciler::ensureForStorage()` SHALL retornar error si el storage está mergeado. Los storages mergeados no deben participar en el watermark tracking.

#### Scenario: Reconciliación sobre storage mergeado falla claramente

- **WHEN** se invoca reconciliación sobre storage con `duplicate_of_storage_id IS NOT NULL`
- **THEN** SHALL retornar error con mensaje: "storage X está mergeado en Y; reconcilie sobre Y"
- **AND** SHALL NO crear/actualizar watermarks en el storage mergeado
