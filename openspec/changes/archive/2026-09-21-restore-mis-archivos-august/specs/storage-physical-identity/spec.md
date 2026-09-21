## REMOVED Requirements

### Requirement: Columna `files.base_path_snapshot`
**Reason**: La columna era una copia denormalizada de `storage_providers.base_path` que `FileObserver::saving()` mantenía sincronizada. Su propósito era alimentar `physicalPathNormalized()` para resolver cross-storage. Sin resolución cross-storage, no hay consumidor.
**Migration**: La columna se DROP. Los call sites que comparaban paths físicos vuelven a comparar `path` relativo (que sigue siendo suficiente para el modelo per-storage).

### Requirement: Helper `File::physicalPathNormalized()`
**Reason**: Construía `lower(rtrim(base_path_snapshot || '/' || path))` para resolver equivalencia entre un folder del storage padre y su mirror en el sub-storage. Sin mirror rows y sin canonical_folder_id, no hay caso de uso.
**Migration**: El helper se retira del modelo `File`. Si en el futuro se necesita path físico normalizado (ej. para evitar `..` traversal), se reintroduce como utilidad sin atarse a columnas denormalizadas.

### Requirement: Clase `App\Services\FilePhysicalIdentity`
**Reason**: Contenía `canonicalFor(File $folder)` y la lógica de matching cross-storage. Sin mirror rows, no hay identidad que canonicar.
**Migration**: La clase completa y sus tests asociados se retiran.

### Requirement: Clase `App\Services\StorageHierarchyService`
**Reason**: Mantenía la cache de padres/hijos de storages y resolvía el "storage más específico" para un path. La cache tenía 60s de TTL y debía invalidarse en cada cambio de `transcription_enabled` o `base_path`. Con el modelo simple, los storages no se auto-relacionan en el listing — la jerarquía es una propiedad del `User` y sus `user_storages`, no del sync.
**Migration**: La clase se retira. Si se necesita lookup de parent_storage_id, se hace con una consulta directa a `storage_providers.parent_storage_id` (sin cache; la latencia ya no es problema porque el listado es per-storage).
