## Context

Ver `proposal.md` para motivación. Resumen del estado actual que motiva el diseño:

- `DiskScannerService::scanStorage()` (`app/app/Services/Ia/DiskScannerService.php:237`) escribe literal `'mime_type' => 'video/mp4'` al crear una fila `files` para cualquier archivo descubierto, aunque su propio filtro en línea 175 acepta extensiones de audio (`.mp3`, `.opus`, `.flac`, `.wav`, `.aac`).
- Existe una función `FileScannerService::getMimeType(string $filename): string` (`app/app/Services/FileScannerService.php:143-201`) que ya contiene el mapa correcto de extensión → mime_type, incluyendo `.mp3 → audio/mpeg`, `.m4a → audio/mp4`, `.wav → audio/wav`, `.opus`, `.flac`, `.aac`. Esta función NO está en uso por el disk scanner.
- `MentionsSearchService::classifyMediaKind($mimeType)` (`app/app/Services/Ia/MentionsSearchService.php:523-535`) deriva `media_kind` desde `files.mime_type`. Es correcta y no se modifica.
- Filas históricas con `mime_type='video/mp4'` cuyo nombre termina en `.mp3` (y otras extensiones de audio) están en `files` y necesitan reconciliación sin saturar el servidor (constraint `no_consultas_pesadas_masivas_servidor`).

## Goals / Non-Goals

**Goals:**
- Eliminar el hardcoded mime_type del writer del disk scanner.
- Reutilizar el único mapa de extensiones existente (`FileScannerService::getMimeType`) para no duplicar la tabla de mime types en dos lugares.
- Proveer un comando artisan de reconciliación one-shot que repare `files.mime_type` sin afectar `.mp4` legítimos, con default dry-run y operación chunked liviana.
- Cubrir el contrato con un harness de regresión ejecutable.

**Non-Goals:**
- Cambiar `MentionsSearchService::classifyMediaKind` o cualquier consumidor aguas abajo.
- Introducir un campo `media_kind` a nivel de `storage_providers` (la fuente de verdad sigue siendo `files.mime_type`).
- Migración de esquema (no se necesita: `files.mime_type` ya existe).
- Cron que ejecute la reconciliación periódicamente (operación one-shot operada por el admin tras el deploy).
- Reescribir archivos `.mp4` legítimos (siguen siendo `video/mp4`).

## Decisions

### Decisión 1: Inyectar `FileScannerService` en el constructor de `DiskScannerService` (vs helper estático nuevo)
- **Decisión**: Inyectar `FileScannerService` vía constructor de `DiskScannerService` (mismo patrón que `StorageSyncService` ya usa en `app/app/Services/StorageSyncService.php:18`). Reemplazar `'mime_type' => 'video/mp4'` por `$this->scanner->getMimeType($c['name'])`.
- **Por qué**: Single source of truth. Cualquier extensión agregada al mapa en el futuro se propaga automáticamente a ambos scanners sin sincronización manual. La función ya existe, ya está testeada implícitamente y ya cubre el rango de extensiones que el disk scanner acepta.
- **Alternativa considerada**: Crear un helper estático `MimeTypes::fromExtension($name)` y mover el mapa ahí. **Rechazada** porque introduce un archivo nuevo y un namespace adicional cuando `FileScannerService::getMimeType()` ya cumple el rol. Se puede refactorizar después si el mapa se vuelve un problema de organización, pero no es alcance de este fix.
- **Alternativa considerada**: Hardcodear el `match` inline en `DiskScannerService`. **Rechazada** porque duplica el mapa.

### Decisión 2: Comando artisan en `app/app/Console/Commands/Avisos/` (vs Services/Cron)
- **Decisión**: Crear `app/app/Console/Commands/Avisos/ReconcileFileMimeTypes.php` (namespace `App\Console\Commands\Avisos`), firma `avisos:reconcile-file-mime-types`. Co-locado con los demás comandos de avisos (`WatermarkReconciler`, `AuditLogArchiver`, etc. referenciados en `AGENTS.md`).
- **Por qué**: Consistencia organizacional con el resto del módulo de avisos. Los comandos del módulo viven en ese namespace.
- **Alternativa considerada**: Ponerlo en `Services`. **Rechazada** — Laravel artisan vive en `Console/Commands`.

### Decisión 3: Default `--dry-run`, `--apply` explícito, chunks de 500
- **Decisión**: El comando requiere `--apply` para mutar. Sin flag imprime conteo y sale 0. Procesa candidatos en chunks de 500 IDs ascendentes. Log con prefijo `files.mime_reconcile.*`.
- **Por qué**: Cumple `no_consultas_pesadas_masivas_servidor` (constraint del proyecto). Default dry-run es el patrón seguro para comandos de mutación masiva (cf. `avisos:archive-audit-log --dry-run`). El tamaño de chunk 500 evita transacciones largas en PG.
- **Alternativa considerada**: Chunk de 1000. **Rechazada** — 500 es suficiente para throughput de admin one-shot y reduce el blast radius por iteración si algo falla.
- **Alternativa considerada**: Auto-apply sin flag. **Rechazada** — demasiado riesgo para una mutación que toca una tabla grande.

### Decisión 4: Query candidata = `WHERE mime_type='video/mp4' AND name ~* '\.(mp3|m4a|opus|flac|wav|aac)$'`
- **Decisión**: El SELECT inicial solo trae filas con `mime_type='video/mp4'` (la firma del bug) cuyo `name` (columna indexada en `files`) termina en una extensión de audio. NO escanea filas con `mime_type` ya correcto (cero filas = cero UPDATE, lógica idempotente).
- **Por qué**: El bug solo afecta archivos etiquetados como `video/mp4` que en realidad son audio. Tocar filas con otros `mime_type` sería una mutación fuera de scope.
- **Alternativa considerada**: Escanear TODA la tabla `files` y re-derivar para todos. **Rechazada** — sería una agregación masiva sobre el histórico (violación de constraint) y mutaría filas correctas innecesariamente.

### Decisión 5: Harness bajo `app/tests/harness_disk_scanner_mime_type.php` (mismo patrón que los demás)
- **Decisión**: Harness ejecutable directamente contra PG/Redis, tag único por corrida (prefijo `hdms_<8-hex>`), con `h_ok`/`h_fail`/`h_check`. Cubre: (a) DiskScannerService con archivos `.mp3` y `.opus` produce `mime_type='audio/mpeg'` y `'audio/opus'` respectivamente; (b) `classifyMediaKind` los clasifica como `'radio'`; (c) comando `--dry-run` reporta conteo > 0 sin mutar; (d) comando `--apply` cambia exactamente las filas del tag y deja `.mp4` intacto.
- **Por qué**: Cobertura de regresión sin levantar PHPUnit; mismo patrón que `harness_mis_avisos_viewer.php`, `harness_mis_avisos_clip_limit.php` (referenciados en `AGENTS.md`). Permite validar end-to-end contra el Postgres/Redis reales.
- **Alternativa considerada**: PHPUnit puro. **Rechazada** — los harnesses del proyecto ya documentan este patrón y son los que el runbook pide correr tras cambios en módulos análogos.

## Risks / Trade-offs

- **[Riesgo] Inyectar `FileScannerService` cambia el contrato del constructor de `DiskScannerService`.** Cualquier caller externo (test unitario, otra ruta que instancie `DiskScannerService` manualmente) necesita pasar el nuevo arg. → **Mitigación**: Auditar callers con `grep -rn "new DiskScannerService"` antes del cambio. Si Laravel lo resuelve por container (caso normal con `app()->make()`), no hay impacto. Documentar en el PR la auditoría.
- **[Riesgo] Filas con `mime_type='video/mp4'` cuyo `name` realmente termina en `.mp4` no se tocan, pero ¿qué pasa con archivos `.mkv` o `.webm` que el scanner podría descubrir en el futuro?** → **Mitigación**: El comando solo mira extensiones de audio; `.mkv`, `.webm`, `.mov`, `.avi` no entran en la reconciliación. Si el operador necesita re-clasificarlos, el comando se extiende después. Para este fix, el bug está limitado a audio.
- **[Riesgo] El filtro `WHERE mime_type='video/mp4'` no usa índice si la columna no está indexada.** → **Mitigación**: Verificar el plan con `EXPLAIN` antes del comando. Si la tabla es muy grande y no hay índice, considerar agregar índice condicional vía migración fuera de este change. (Auditar en `app/database/migrations/` si existe índice `files_mime_type_idx` o similar; si no, agregar `CREATE INDEX CONCURRENTLY` en una migración separada previa al runbook.)
- **[Riesgo] El comando re-mapea filas en transacciones cortas, pero un crash a mitad deja BD parcialmente mutada.** → **Mitigación**: Por diseño, la mutación es idempotente: re-ejecutar `--apply` no toca filas ya correctas. La atomicidad por chunk (1 UPDATE por 500 IDs) reduce el blast radius.
- **[Trade-off] El comando no distingue archivos `.mp3` legítimos de archivos `.mp3` que un admin renombró por error.** → **Aceptado**: La lógica del fix es "confiar en la extensión como fuente de verdad". Si un admin renombra `.wav` a `.mp3` para forzar una clasificación, está actuando contra el sistema y eso queda fuera de scope.

## Migration Plan

### Pasos de deploy
1. **Pre-deploy**: Auditar callers de `DiskScannerService` (grep en `app/`). Confirmar que ninguno instancia la clase manualmente con args posicionales.
2. **Deploy del writer fix**: Merge del cambio. `php artisan config:cache` para limpiar opcache de PHP-FPM.
3. **Validar writer nuevo**: Correr el transcriptor una vez (`transcription:scan-and-submit --days=0`) sobre un storage de prueba; confirmar en BD que un archivo `.mp3` se persiste con `mime_type='audio/mpeg'`.
4. **Correr reconciliación**: `php artisan avisos:reconcile-file-mime-types` (dry-run). Si el conteo es razonable, `php artisan avisos:reconcile-file-mime-types --apply`. Log en `laravel.log` con `files.mime_reconcile.*`.
5. **Verificar en UI**: Recargar Mis Avisos con un cliente de prueba. Las emisoras de radio deben mostrar ícono ámbar `fa-radio`. Confirmar con el cliente real que motivó el reporte.
6. **Correr harness de regresión**: `cd app && php tests/harness_disk_scanner_mime_type.php`. Exit 0 esperado.

### Rollback
- El writer fix es backward-compatible: si se revierte el merge, el comportamiento buggy vuelve. No se rompe ninguna fila existente.
- El comando de reconciliación es idempotente: re-ejecutar no duplica mutaciones.
- Si el comando produce comportamiento inesperado, agregar un paso `php artisan tinker --execute="\DB::table('files')->where('id', ...)->update(['mime_type' => 'video/mp4'])"` con los IDs específicos registrados en el log `files.mime_reconcile.updated` (que captura los IDs tocados).

## Open Questions

Ninguna al cierre del design. Los dos unknowns principales (`fuente de verdad: mime vs storage` y `qué hacer con históricos`) se resolvieron en explore mode antes de escribir el proposal.
