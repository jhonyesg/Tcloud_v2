## ADDED Requirements

### Requirement: Endpoint de procesamiento de corte
El sistema SHALL exponer `POST /files/{file}/clip` que acepta segmentos de tiempo y retorna el archivo procesado como descarga.

#### Scenario: Request válido con un segmento
- **WHEN** se envía `POST /files/{id}/clip` con body `{ "segments": [{"start": 10, "end": 90}] }`
- **THEN** el servidor construye la ruta física del archivo usando `storage->base_path + file->path`
- **THEN** ejecuta `ffmpeg -i {input} -ss 10 -to 90 -c copy {temp_output}`
- **THEN** retorna el archivo temp como `StreamedResponse` con header `Content-Disposition: attachment; filename="{nombre}_corte.{ext}"`
- **THEN** elimina el archivo temp tras completar la descarga

#### Scenario: Request válido con múltiples segmentos
- **WHEN** se envía `POST /files/{id}/clip` con body `{ "segments": [{"start": 0, "end": 30}, {"start": 60, "end": 90}] }`
- **THEN** el servidor construye el comando FFmpeg con `filter_complex` para concatenar los segmentos
- **THEN** para mp4 usa re-encode (`-c:v libx264 -preset fast -c:a aac`)
- **THEN** para mp3 usa `-c:a libmp3lame`
- **THEN** para m4a usa `-c:a aac`
- **THEN** retorna el archivo concatenado como descarga con nombre `{nombre}_corte.{ext}`

#### Scenario: Storage tipo S3 rechazado
- **WHEN** el archivo pertenece a un storage de tipo S3
- **THEN** el servidor responde HTTP 422 con mensaje "Solo disponible para storage local"

#### Scenario: Archivo físico no encontrado
- **WHEN** la ruta física calculada no existe en el filesystem
- **THEN** el servidor responde HTTP 404 con mensaje descriptivo

#### Scenario: FFmpeg falla
- **WHEN** el proceso FFmpeg termina con código de salida distinto de 0
- **THEN** el servidor limpia cualquier archivo temp creado
- **THEN** responde HTTP 500 con el mensaje de error de FFmpeg

### Requirement: FFmpeg disponible en el contenedor PHP
El Dockerfile del contenedor PHP SHALL incluir la instalación de FFmpeg.

#### Scenario: FFmpeg ejecutable desde PHP
- **WHEN** el controlador ejecuta `which ffmpeg` o `ffmpeg -version`
- **THEN** el comando retorna con código 0 (FFmpeg disponible en el PATH del contenedor)

### Requirement: Validación de segmentos
El endpoint SHALL validar la estructura de los segmentos antes de ejecutar FFmpeg.

#### Scenario: Segmentos inválidos
- **WHEN** se envía un segmento donde `start >= end` o donde los valores son negativos
- **THEN** el servidor responde HTTP 422 con detalles de validación
- **THEN** no se ejecuta ningún proceso FFmpeg
