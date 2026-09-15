## Purpose

Define el contrato del writer de `files.mime_type` cuando el disk scanner descubre un archivo nuevo: el valor persistido debe reflejar la naturaleza real del archivo (video vs audio) según su extensión, no un valor hardcoded. Esto garantiza que los consumidores aguas abajo (`MentionsSearchService::classifyMediaKind` y el spec `mis-avisos-media-kind`) reciban datos correctos sin necesidad de conocer el origen del archivo.

## ADDED Requirements

### Requirement: El disk scanner deriva `files.mime_type` de la extensión del archivo
Cuando `DiskScannerService` registra un archivo nuevo encontrado en disco (`registry->ensure(...)`), **SHALL** poblar `files.mime_type` derivándolo de la extensión del nombre del archivo, NO con un valor hardcoded. El mapa mínimo es:
- `.mp4` → `video/mp4`
- `.mkv` → `video/x-matroska`
- `.mp3` → `audio/mpeg`
- `.m4a` → `audio/mp4`
- `.opus` → `audio/opus`
- `.flac` → `audio/flac`
- `.wav` → `audio/wav`
- `.aac` → `audio/aac`

Para extensiones fuera del mapa, el scanner **SHALL** persistir `application/octet-stream` (en vez de inventar un mime inexistente).

#### Scenario: Descubrimiento de archivo .mp4
- **WHEN** el scanner encuentra `/base_path/dmY/grabacion.mp4` en un storage habilitado
- **THEN** la fila `files` resultante tiene `mime_type = 'video/mp4'`

#### Scenario: Descubrimiento de archivo .mp3 de una radio
- **WHEN** el scanner encuentra `/base_path/dmY/lafm_140002.mp3` en un storage habilitado de una radio
- **THEN** la fila `files` resultante tiene `mime_type = 'audio/mpeg'`

#### Scenario: Descubrimiento de archivo .opus
- **WHEN** el scanner encuentra `/base_path/dmY/audio.opus`
- **THEN** la fila `files` resultante tiene `mime_type = 'audio/opus'`

#### Scenario: Extensión desconocida
- **WHEN** el scanner encuentra un archivo cuya extensión no está en el mapa (por ejemplo `.xyz`)
- **THEN** la fila `files` resultante tiene `mime_type = 'application/octet-stream'`

### Requirement: El mapa de extensiones es compartido y testeable
La función que mapea extensión → mime_type **MUST** ser un método público/estático testeable (no una expresión enterrada en `DiskScannerService`). El método **SHALL** aceptar el nombre del archivo (no solo la extensión) y devolver el mime normalizado según el mapa del Requirement anterior. La función **SHALL** ser la misma referencia que ya usa `FileScannerService::getMimeType()` para mantener un único mapa de extensiones en el proyecto.

#### Scenario: Misma función en ambos scanners
- **WHEN** se llama al mapper con `'audio.mp3'` desde `DiskScannerService` o desde `FileScannerService`
- **THEN** ambas llamadas devuelven `'audio/mpeg'`
- **AND** existe un test unitario que cubre el mapa completo (todas las extensiones del Requirement anterior)

### Requirement: No se persiste `'video/mp4'` para archivos de audio
Cualquier ruta de `DiskScannerService` que escriba `files.mime_type = 'video/mp4'` para un archivo cuya extensión está en la lista de audio del mapa **MUST** fallar en revisión de código y en harness de regresión. Esto incluye (pero no se limita a) la rama `grouped_by_subfolder`, la rama `flat` y el helper de registro.

#### Scenario: Scanner sobre archivo .wav nunca produce `video/mp4`
- **WHEN** el scanner procesa un archivo `.wav`
- **THEN** el `files.mime_type` resultante es `'audio/wav'` (o el equivalente en el mapa), nunca `'video/mp4'`
