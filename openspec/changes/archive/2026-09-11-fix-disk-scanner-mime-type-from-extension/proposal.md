## Why

El `DiskScannerService` escribe `'mime_type' => 'video/mp4'` literal para **todos** los archivos que descubre en disco (`app/app/Services/Ia/DiskScannerService.php:237`), aunque su propio filtro acepta extensiones de audio (`.mp3`, `.m4a`, `.opus`, `.flac`, `.wav`, `.aac` en línea 175). Esto contamina `files.mime_type` para los archivos de emisoras de radio y, aguas abajo, hace que `MentionsSearchService::classifyMediaKind()` devuelva `'tv'` para cualquier archivo — incluso los `.mp3` de radios como `01 Radio FM Bogota` o `10 Alerta Bogotá`. El usuario ve el marquetilla violeta de TV en Mis Avisos para archivos que claramente son de radio.

El contrato del spec `mis-avisos-media-kind` ya define que `media_kind` se derive de `files.mime_type` (correcto), pero el writer upstream rompe esa cadena.

## What Changes

- **Reemplazar** el hardcoded `'mime_type' => 'video/mp4'` en `DiskScannerService::ensureFile()` por una derivación real basada en la extensión del nombre del archivo (mismo mapa que ya usa `FileScannerService::getMimeType()`).
- **Agregar** comando artisan `avisos:reconcile-file-mime-types` que reescribe `files.mime_type` para filas existentes con dato erróneo (extensión de audio + mime `video/mp4`). Default `--dry-run`, chunks de 500, sin agregaciones masivas (cumple constraint `no_consultas_pesadas_masivas_servidor`).
- **Agregar** harness de regresión `harness_disk_scanner_mime_type.php` que valida: (a) que archivos nuevos de audio se persisten con `mime_type = audio/...` desde el scanner; (b) que `classifyMediaKind` produce `'radio'` para esos archivos; (c) que el comando `--dry-run` reporta conteos consistentes.

No se introducen migraciones de esquema (es un fix de escritura + reconciliación, no de modelo).

## Capabilities

### New Capabilities
- `disk-scanner-file-mime-type`: contrato del writer que persiste `files.mime_type` a partir de la extensión real del archivo descubierto, evitando el hardcoded `video/mp4`.

### Modified Capabilities
- `transcription-disk-scanner`: agrega un Requirement explícito de que `DiskScannerService` debe poblar `files.mime_type` desde la extensión del archivo (no hardcoded), incluyendo el mapa de extensiones aceptado.
- `mis-avisos-media-kind`: agrega un Requirement que documenta la existencia y contrato del comando de reconciliación `avisos:reconcile-file-mime-types` para reparar filas históricas.

## Impact

- **Servicios**: `app/app/Services/Ia/DiskScannerService.php` (writer, línea 237), `app/app/Services/FileScannerService.php` (referencia del mapa de extensiones a mime).
- **Comandos artisan nuevos**: `app/app/Console/Commands/Avisos/ReconcileFileMimeTypes.php`.
- **Harness de regresión**: `app/tests/harness_disk_scanner_mime_type.php`.
- **Sin impacto** en: spec del lado consumidor (`MentionsSearchService::classifyMediaKind` ya es correcto), la vista Blade, el JS Alpine store, ni el endpoint `/mis-avisos/feed|history` (la API no cambia).
- **Operacional**: requiere correr el comando de reconciliación una vez tras el deploy para reparar filas históricas. El writer arreglado cubre toda la actividad futura.

## Non-goals

- No se cambia el contrato de `MentionsSearchService::classifyMediaKind()` (es correcto).
- No se migra el esquema: `files.mime_type` ya existe desde `2024_01_01_000004_create_files_table.php:16`.
- No se introduce un override a nivel de `storage_providers` (la fuente de verdad sigue siendo `files.mime_type`).
- No se automatiza la reconciliación con un cron — es una operación one-shot operada por el admin.
- No se reescriben archivos `.mp4` legítimos (siguen siendo `video/mp4`).
