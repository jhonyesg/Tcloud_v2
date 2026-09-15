## ADDED Requirements

### Requirement: Scanner deriva `files.mime_type` desde la extensión, no hardcoded
`DiskScannerService::ensureFile()` y la rama `grouped_by_subfolder` que descubren archivos candidatos **SHALL** poblar la columna `files.mime_type` derivándola de la extensión del nombre del archivo, según el mapa definido en el spec `disk-scanner-file-mime-type`. **SHALL NOT** persistir `'video/mp4'` literal para todos los archivos descubiertos (comportamiento buggy previo a este cambio).

#### Scenario: Archivo `.mp4` se registra con `video/mp4`
- **WHEN** el scanner encuentra `base_path/dmY/grabacion.mp4`
- **THEN** el `File` registrado tiene `mime_type='video/mp4'`

#### Scenario: Archivo `.mp3` de radio se registra con `audio/mpeg`
- **WHEN** el scanner encuentra `base_path/dmY/lafm_140002.mp3` bajo un storage de radio (`grouped_by_subfolder` o `flat`)
- **THEN** el `File` registrado tiene `mime_type='audio/mpeg'`
- **AND** el spec `mis-avisos-media-kind` consume ese valor correctamente como `media_kind='radio'`

#### Scenario: Regresión: scanner nunca escribe `video/mp4` para audio
- **WHEN** existe un harness de regresión que procesa archivos `.mp3`, `.opus`, `.flac`, `.wav`, `.aac`, `.m4a` con el scanner
- **THEN** NINGUNA fila `files` resultante queda con `mime_type='video/mp4'` para esos archivos
