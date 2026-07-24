## ADDED Requirements

### Requirement: StorageProvider resolves inherited transcription scope
El sistema SHALL exponer un helper `StorageProvider::resolveInheritedTranscriptionScope(int $rootId): array<int>` que, dado un storage ID, retorne un array de IDs que incluye el storage root más todos sus descendientes con `transcription_enabled=true`, recursivamente, donde un descendiente es cualquier storage cuyo `base_path` comience con `parent.base_path + '/'`.

#### Scenario: Storage without descendants
- **WHEN** se invoca `resolveInheritedTranscriptionScope(47)` y "01 Emisoras 01" (47) no tiene descendientes con `transcription_enabled=true`
- **THEN** el helper retorna `[47]`

#### Scenario: Storage with one descendant
- **WHEN** se invoca `resolveInheritedTranscriptionScope(47)` y "03 La W Bogota" (63) es descendiente directo de 47 con `transcription_enabled=true`
- **THEN** el helper retorna `[47, 63]`

#### Scenario: Storage with deep descendant chain
- **WHEN** "A" tiene como hijo a "B" que tiene como hijo a "C", todos con `transcription_enabled=true`
- **THEN** `resolveInheritedTranscriptionScope(A.id)` retorna `[A.id, B.id, C.id]`

#### Scenario: Descendant with allow_parent_overlap=true is included
- **WHEN** "B" es descendiente de "A" con `transcription_enabled=true` y `allow_parent_overlap=true`
- **THEN** B se incluye en el scope (la herencia es una capa de lectura, no afecta el scanner)

#### Scenario: Descendant with transcription_enabled=false is excluded
- **WHEN** "B" es descendiente de "A" con `transcription_enabled=false`
- **THEN** B NO se incluye en el scope (no aporta transcripciones)

#### Scenario: Cycle prevention
- **WHEN** el grafo de dependencias tiene un ciclo (imposible por construcción con `base_path`, pero defensivo)
- **THEN** el helper no itera infinitamente; usa un set `seen` para marcar IDs visitados

### Requirement: Storage files query aggregates inherited scope
El sistema SHALL, cuando el endpoint `storageFiles` recibe `storage_id` de un storage con scope heredado (`resolveInheritedTranscriptionScope retorna != [self]`), retornar la unión de archivos de TODOS los storages en el scope, agregando un campo `source_storage_id` a cada file para identificar el dueño real.

#### Scenario: Emisoras 01 with La W descendant
- **WHEN** `GET /api/transcriptor/storage-files?storage_id=47&mode=today` y 63 es descendiente activo
- **THEN** el response incluye archivos de storage 47 (emisoras: CARACOL, BLURADIO, etc.) Y archivos de storage 63 (LA_W)
- **AND** cada file lleva `source_storage_id` apuntando a su storage real
- **AND** el response incluye un objeto `scope` con `self`, `descendants` y `storage_ids`

#### Scenario: Storage without descendants
- **WHEN** `storage_id=6` (Caracol TV, sin descendientes con TX)
- **THEN** el response NO incluye el campo `scope` (no aplica herencia)
- **AND** se comporta idénticamente a la versión actual (solo storage 6)

#### Scenario: Search aggregates across scope
- **WHEN** se hace búsqueda `q=wradio` en un storage con La W como descendiente
- **THEN** los resultados incluyen archivos `wradio_xxx.mp3` de La W

### Requirement: Transcription markers reflect inherited scope
El sistema SHALL, al consultar el flag `has_transcription` o el contador `transcribed_count`, considerar las `Transcription` rows de cualquier storage en el scope heredado, no solo del storage que se consulta.

#### Scenario: Folder in parent shows inherited transcriptions
- **WHEN** se abre el árbol de carpetas de "01 Emisoras 01" (47) y una carpeta pertenece físicamente a "03 La W Bogota" (63)
- **THEN** los archivos dentro muestran `has_transcription=true` si tienen fila en `transcriptions` apuntando al `File` real de storage 63

#### Scenario: transcribed_count is inclusive
- **WHEN** el response retorna `transcribed_count`
- **THEN** cuenta las transcripciones de todos los files en el scope (no solo del storage self)

### Requirement: UI badge indicates inherited scope
El sistema SHALL, en el panel de storages del módulo API Transcriptor, mostrar un badge "N hijos" al lado de cualquier storage que tenga descendientes con `transcription_enabled=true`. El badge SHALL incluir un tooltip que liste los nombres de los storages hijos.

#### Scenario: Storage with descendant shows badge
- **WHEN** "01 Emisoras 01" tiene "03 La W Bogota" como descendiente activo
- **THEN** el badge muestra "1 hijo" con tooltip "Hereda de: 03 La W Bogota"

#### Scenario: Storage without descendants shows no badge
- **WHEN** un storage no tiene descendientes con TX activa
- **THEN** no se muestra el badge

### Requirement: Tick and batch dispatch ignore virtual scope
El sistema SHALL mantener el `transcription:tick` y el dispatcher de batches operando EXCLUSIVAMENTE sobre storages reales con `transcription_enabled=true`. La herencia virtual es una capa de SOLO LECTURA y nunca afecta al trabajo de transcripción.

#### Scenario: Tick processes each real storage exactly once
- **WHEN** el tick corre
- **THEN** el scanner itera solo `StorageProvider::transcriptionEnabled()->get()` (sin scope helper)
- **AND** ningún storage se procesa dos veces por aparecer en el scope de otro

#### Scenario: Batch dispatch uses file's real storage_provider_id
- **WHEN** un batch despacha jobs
- **THEN** cada `ConvertAndTranscribeJob` recibe `file_id` cuyo `File.storage_provider_id` es el storage real
- **AND** el priority se calcula con el `transcription_priority` del storage real, no del scope helper
