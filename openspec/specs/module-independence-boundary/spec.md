## Purpose

Especifica que la elegibilidad de un archivo para transcripción se resuelve por la cadena de ancestros del storage (no por el storage del file row). Esto es la regla que `WatermarkReconciler` aplica consistentemente: un file en storage 5 (parent) pero bajo el base_path de storage 61 (hijo) cuenta como elegible porque storage 61 es descendant con transcription_enabled = true. La invariante: `transcription_enabled` se evalua por cadena de ancestros, no por `files.storage_provider_id`.


## Requirements

### Requirement: La elegibilidad se resuelve por la cadena de ancestros

El sistema SHALL determinar si un archivo es target de transcripción por la **cadena de ancestros de su ruta absoluta** (existe al menos un storage que lo cubre con `transcription_enabled = true`), y SHALL NOT determinarlo por `transcription_enabled` del storage al que pertenece la fila `files`. Esta regla SHALL aplicarse de forma consistente en el descubrimiento, en `TodayPendingService::aggregate()`, en `actionableFileIds()` y en `TranscriptorWorkEstimator`.

#### Scenario: Fila en un hijo apagado bajo un ancestro habilitado
- **WHEN** una fila `files` pertenece a un storage con `transcription_enabled = false` cuya ruta cae bajo un ancestro con `transcription_enabled = true`
- **THEN** el archivo cuenta como elegible para transcripción
- **AND** aparece en el panel de pendientes y en la estimación

#### Scenario: El conteo no pierde archivos por el storage de la fila
- **WHEN** se compara la elegibilidad evaluada por cadena de ancestros contra la evaluada por el storage de la fila
- **THEN** la primera SHALL ser mayor o igual
- **AND** el sistema SHALL usar la primera (medición de referencia: 28.530 vs 19.456 archivos de hoy, con `00 Discos` aportando 9.927)

#### Scenario: Ningún ancestro habilita el archivo
- **WHEN** ni el storage de la fila ni ninguno de sus ancestros tiene `transcription_enabled = true`
- **THEN** el archivo no es elegible y no se crea transcripción

### Requirement: Rutas idénticas normalizadas son nodos equivalentes

Cuando dos o más storages tienen `base_path` normalizado (`rtrim(base_path,'/')`) idéntico, el sistema SHALL tratarlos como **nodos equivalentes sobre la misma ruta física**: SHALL NOT crear una relación padre/hijo entre ellos, SHALL NOT fusionarlos, y SHALL resolver el empate de dueño de forma determinista. El backfill SHALL reportarlos y el alta/edición de un storage con ruta idéntica a uno existente SHALL advertirlo.

#### Scenario: Par con ruta idéntica tras normalizar
- **WHEN** los storages 132 (`/…/La_voz/`) y 142 (`/…/La_voz`) existen con el mismo `base_path` normalizado
- **THEN** ninguno recibe `parent_storage_id` del otro
- **AND** ambos siguen existiendo como gestores independientes con sus asignaciones de usuario

#### Scenario: Desempate cuando uno de los dos transcribe
- **WHEN** el storage 66 tiene `transcription_enabled = true` y su gemelo 146 el mismo `base_path` normalizado con `transcription_enabled = false`
- **THEN** `ownerOf()` devuelve el storage 66
- **AND** el candado `source_absolute_path` garantiza una sola transcripción para la ruta

#### Scenario: Alta de un storage con ruta ya existente
- **WHEN** el admin crea un storage cuyo `base_path` normalizado ya existe en otro storage
- **THEN** el sistema lo guarda, reporta la equivalencia en la respuesta, y NO lo enlaza como hijo del existente

### Requirement: Mis Archivos es el único dueño y escritor de `files`

El sistema SHALL tratar `files` como la tabla soberana del módulo Mis Archivos. Solo `StorageSyncService` (vía `FileRegistry::ensure()`) y las acciones de usuario de Mis Archivos SHALL crear, actualizar o eliminar filas de `files`. API Transcriptor SHALL NOT escribir, borrar, trashear ni reparentar filas de `files`.

#### Scenario: El descubrimiento del transcriptor no crea filas
- **WHEN** API Transcriptor descubre archivos candidatos a transcribir
- **THEN** no ejecuta ningún INSERT, UPDATE ni DELETE sobre `files`

#### Scenario: El transcriptor no crea carpetas
- **WHEN** API Transcriptor necesita la jerarquía de carpetas de una ruta
- **THEN** usa los `parent_id` de las filas `files` existentes y NO materializa carpetas faltantes

#### Scenario: Fila faltante se resuelve invocando a Mis Archivos
- **WHEN** API Transcriptor detecta que un storage habilitado no tiene inventario reciente (por ejemplo, primera corrida del día sin filas para hoy)
- **THEN** invoca la interfaz pública de Mis Archivos para poner el inventario al día
- **AND** NO escanea el filesystem por su cuenta

### Requirement: API Transcriptor consume `files` en modo lectura

El sistema SHALL permitir que API Transcriptor resuelva sus candidatos con una consulta de solo lectura sobre `files` unida a `storage_providers`, usando las columnas ya pobladas por Mis Archivos (`path`, `size`, `file_modified_at`, `parent_id`, `storage_provider_id`, `is_folder`, `is_trashed`). El descubrimiento SHALL NOT usar `scandir`, `stat`, `filemtime` ni `filesize`.

#### Scenario: Candidatos resueltos en una sola query
- **WHEN** el descubrimiento corre para un storage habilitado
- **THEN** resuelve los candidatos con una consulta que filtra `transcription_enabled`, `is_folder = false`, `is_trashed = false`, `file_modified_at` de hoy, `size >= min_file_size_bytes` y `file_modified_at < cutoff`
- **AND** no abre ni recorre el directorio en disco

#### Scenario: El filtro de tamaño se evalúa contra la columna size
- **WHEN** una grabación quedó en 0 bytes porque el stream cayó
- **THEN** la fila `files` ya la representa y el filtro `size >= min_file_size_bytes` la descarta sin crear transcripción
- **AND** no se ejecuta `ffprobe` ni `ffmpeg` sobre ella

#### Scenario: El archivo sigue creciendo
- **WHEN** `files.file_modified_at` de un archivo es posterior a `now - scan_min_age_seconds`
- **THEN** el archivo se omite en este ciclo y se retoma cuando Mis Archivos actualice su `file_modified_at` en un sync posterior

### Requirement: La invocación del inventario es una llamada, no un escaneo

Cuando API Transcriptor necesita inventario que no está en `files`, SHALL invocar la interfaz pública de Mis Archivos (`StorageSyncService::syncFolder` / `syncRootFolder`, equivalente al botón "Actualizar") y SHALL NOT acceder al filesystem directamente. La invocación SHALL estar sujeta a un límite de frecuencia por storage para no competir con el cron de 15 minutos ni con el navegador del usuario.

#### Scenario: Inventario vacío en un storage habilitado
- **WHEN** un storage con `transcription_enabled = true` no tiene ninguna fila `files` para el día actual
- **THEN** el descubrimiento invoca el sync de Mis Archivos una vez para ese storage
- **AND** espera el resultado antes de resolver candidatos

#### Scenario: Límite de frecuencia evita tormenta de syncs
- **WHEN** el descubrimiento corre repetidamente y un storage sigue sin inventario
- **THEN** la invocación del sync respeta una ventana mínima (por defecto 15 min, alineada con el cron de Mis Archivos)
- **AND** las corridas dentro de la ventana se saltan sin invocar

#### Scenario: El sync devuelve lock ocupado
- **WHEN** la invocación del sync devuelve `status = 'locked'` porque el cron o un usuario ya están sincronizando
- **THEN** el descubrimiento usa el inventario que ya existe en `files` y NO reintenta en el mismo ciclo

### Requirement: `transcription_enabled` no altera el comportamiento de Mis Archivos

El sistema SHALL NOT usar `storage_providers.transcription_enabled` para decidir la pertenencia, el dueño, el parentesco ni la visibilidad de una fila de `files`. Encender o apagar la transcripción de un storage SHALL NOT crear, mover, actualizar ni eliminar ninguna fila de `files`.

#### Scenario: Apagar la transcripción de un hijo no reparenta archivos
- **WHEN** un admin apaga `transcription_enabled` del storage hijo B que vive dentro del padre A
- **THEN** ninguna fila de `files` cambia su `storage_provider_id` ni su `parent_id`
- **AND** el padre A sigue sin reclamar el subárbol de B

#### Scenario: La delegación al sub-storage es geométrica
- **WHEN** Mis Archivos encuentra, al sincronizar, que la ruta pertenece a un storage más específico
- **THEN** delega al sub-storage por profundidad de ruta, sin consultar `transcription_enabled`

#### Scenario: Encender la transcripción no altera el navegador
- **WHEN** un admin enciende `transcription_enabled` de un storage
- **THEN** el listado, las cuotas, los shares y la papelera de Mis Archivos no cambian

### Requirement: Borrar en Mis Archivos no destruye transcripciones

El vínculo `transcriptions.file_id` SHALL NOT propagar el borrado de una fila `files` hacia la transcripción. La transcripción SHALL sobrevivir al borrado del archivo, conservando su `source_absolute_path` como identificador estable y quedando su `file_id` en `NULL` cuando la fila de origen desaparezca.

#### Scenario: Usuario borra un archivo transcrito
- **WHEN** un usuario borra en Mis Archivos una grabación que tiene transcripción `done`
- **THEN** la transcripción, sus segmentos, sus keyword matches y sus alert logs siguen existiendo
- **AND** `transcriptions.file_id` queda `NULL` y `source_absolute_path` conserva la ruta
- **AND** la papelera puede purgar el archivo sin perder el resultado transcrito

#### Scenario: Purga de papelera no arrastra resultados
- **WHEN** el cron `trash:purge` elimina definitivamente un archivo con transcripción
- **THEN** el resultado transcrito permanece consultable por Avisos Inteligentes y Correcciones

#### Scenario: La reconciliación no re-crea la transcripción si el archivo vuelve
- **WHEN** el mismo archivo físico reaparece tras un borrado y Mis Archivos crea una fila nueva
- **THEN** el descubrimiento reutiliza la transcripción existente por `source_absolute_path` en lugar de crear otra

### Requirement: Ningún módulo fuera del transcriptor escribe su bandera

El sistema SHALL restringir la escritura de `storage_providers.transcription_enabled` a los endpoints de API Transcriptor. Ningún controlador, comando o vista de Mis Archivos, Avisos Inteligentes, Correcciones o administración SHALL modificarla.

#### Scenario: La pantalla de admin de storages no expone el toggle de transcripción
- **WHEN** un admin abre la administración de storages
- **THEN** no encuentra el control de `transcription_enabled` allí; solo existe en `/ia/api-transcriptor`

#### Scenario: Los módulos consumidores leen, no escriben
- **WHEN** Avisos Inteligentes o Correcciones necesitan saber si un storage transcribe
- **THEN** leen la bandera y no la modifican
