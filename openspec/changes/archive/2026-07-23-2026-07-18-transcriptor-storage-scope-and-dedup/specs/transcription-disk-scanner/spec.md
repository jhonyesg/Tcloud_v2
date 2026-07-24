## ADDED Requirements

### Requirement: Layout-aware storage scan
El sistema SHALL soportar dos layouts de almacenamiento para el scanner automático: `flat` (default, comportamiento actual: `base_path/dmY/*`) y `grouped_by_subfolder` (storages consolidados: `base_path/<subcarpeta>/dmY/*`). El layout se determina por la columna `storage_providers.folder_layout` y se elige la estrategia de descubrimiento de carpetas en consecuencia.

#### Scenario: Flat layout (canales TV)
- **WHEN** un storage tiene `folder_layout='flat'`
- **THEN** el scanner busca archivos en `base_path/dmY/` (comportamiento histórico)
- **AND** esta rama se preserva como backward-compatible para todos los storages existentes que no actualicen la columna

#### Scenario: Grouped layout (emisoras consolidadas)
- **WHEN** un storage tiene `folder_layout='grouped_by_subfolder'`
- **THEN** el scanner busca archivos en `base_path/*/dmY/` iterando cada subcarpeta inmediata del base_path
- **AND** para cada subcarpeta con `dmY/` legible, escanea los archivos multimedia contenidos
- **AND** los archivos `.mp4`, `.mp3`, `.mkv`, `.opus`, `.flac`, `.wav`, `.aac` son tratados como candidatos

### Requirement: Scope-aware deduplication via parent-child storage resolution
El sistema SHALL, antes de iterar carpetas de un storage, calcular los subpaths excluidos: el conjunto de primeros segmentos de path (relativos al `base_path` del storage actual) que coinciden con el `base_path` de otros storages con `transcription_enabled=true` y `allow_parent_overlap=false`. El scanner omitirá completamente las carpetas que caen bajo esos segmentos.

#### Scenario: Parent storage with child storage enabled
- **WHEN** el storage A tiene `base_path=/foo/` y el storage B tiene `base_path=/foo/LA_W/`, ambos con `transcription_enabled=true`
- **AND** el scanner procesa el storage A
- **THEN** el scanner detecta que `LA_W` es subpath de B
- **AND** omite la carpeta `/foo/LA_W/dmY/` completa del scan de A
- **AND** registra un log `DiskScanner: skip /foo/LA_W/18072026 (storage hijo toma control)`

#### Scenario: Parent storage with no children
- **WHEN** el scanner procesa un storage sin descendientes con `transcription_enabled=true`
- **THEN** no se excluye ningún subpath y el scan procede normalmente

#### Scenario: Allow parent overlap override
- **WHEN** un storage tiene `allow_parent_overlap=true`
- **THEN** el scanner NO excluye subpaths de storages hijos (escape hatch para casos de uso que requieren duplicación intencional)

### Requirement: Absolute-path owner detection prevents cross-storage duplication
El sistema SHALL, antes de crear una nueva fila en `files` para un candidato descubierto, verificar si el `absolute_path` (= `storage_provider.base_path + '/' + file.path`) ya está registrado bajo cualquier OTRO storage. Si lo está, el scanner omite la creación sin reportar error, dejando al storage ya existente como dueño único del archivo.

#### Scenario: File already registered under another storage
- **WHEN** el scanner procesa un archivo candidato cuyo `absolute_path` coincide con un `File` existente bajo otro storage
- **THEN** el scanner omite la creación de un nuevo `File` y `Transcription`
- **AND** registra log `DiskScanner: skip <absolute_path> (dueño: storage <id> <name>)`
- **AND** el archivo mantiene su dueño actual (owner único)

#### Scenario: File not registered anywhere
- **WHEN** el scanner procesa un archivo candidato cuyo `absolute_path` no coincide con ningún `File` existente
- **THEN** el scanner crea el `File` y la `Transcription(pending)` normalmente

### Requirement: Scanner respects layout and dedup rules in single-tenant and multi-tenant configurations
El sistema SHALL combinar las reglas de layout-aware y scope-aware dedup para soportar las configuraciones operativas reales: storages individuales (TV canales) coexistiendo con storages consolidados (emisoras) y con storages específicos (radios individuales), sin generar duplicación.

#### Scenario: Emisoras consolidated + specific radio both enabled
- **WHEN** storage 47 (`01 Emisoras 01`, `grouped_by_subfolder`) y storage 63 (`03 La W Bogota`, `flat`) están ambos habilitados
- **THEN** storage 47 escanea todas las subcarpetas excepto `LA_W/` (excluida por descendencia)
- **AND** storage 63 escanea `LA_W/dmY/` pero omite archivos cuyo `absolute_path` ya está bajo storage 47
- **AND** el resultado neto es 1 archivo = 1 `File` row = 1 `Transcription` row

#### Scenario: Consolidated enabled, specific disabled
- **WHEN** storage 47 está habilitado y storage 63 no
- **THEN** storage 47 escanea todas las subcarpetas incluida `LA_W/`
- **AND** los archivos LA_W se registran bajo storage 47 (no se omite por descendencia porque el hijo no está enabled)