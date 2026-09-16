# Change: Resiliencia y reporte de errores en "Escanear storages"

## Why

El botón **"Escanear storages"** del módulo API Transcriptor falla con un error genérico al final del lote. La causa raíz es que la tabla `storage_providers` no tiene aplicadas las columnas `folder_layout` y `allow_parent_overlap` (migración `2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php` está en el repo pero nunca se ejecutó en producción). Apenas `DiskScannerService::computeExcludedSubpaths()` consulta `allow_parent_overlap`, PostgreSQL devuelve SQLSTATE 42703 y el comando artisan muere sin escribir estado final a la cache.

El usuario ve un modal que termina "FINALIZADO" con un mensaje de error vacío porque:

1. **No hay try/catch por storage** en `ScanAndSubmitCommand::handle()` — un solo storage con SQL roto tumba el batch entero.
2. **No hay try/catch global** en el comando — cuando explota, la cache queda con `status='starting'/'running'` para siempre y el polling del frontend nunca recibe un estado terminal.
3. **El UI no muestra el mensaje real** del backend — solo `alert('Error de conexión')` genérico.
4. **El tick automático (`transcription:tick`)** reutiliza `transcription:scan-and-submit` y falla en el mismo punto cada 2 minutos sin que nadie lo note.

El bug existía pero estaba oculto porque hasta hoy ningún storage con `base_path` anidado tenía `transcription_enabled=true`. El storage 133 (`Blu_Digital`) activó la explosión.

## What Changes

- **Aplicar migración faltante**: crear las columnas `folder_layout` y `allow_parent_overlap` en `storage_providers` (idempotente, no rompe datos).
- **Try/catch por storage** en `ScanAndSubmitCommand::handle()`: si un storage falla, se registra el error, se continúa con el siguiente y la cache se actualiza con el detalle por storage.
- **Try/catch global** en `ScanAndSubmitCommand::handle()`: cualquier excepción no controlada escribe `status='error'` a la cache con el mensaje original, para que el polling termine con un estado definitivo.
- **UI error reporting**: el modal "Escanear storages" muestra el mensaje del backend (`batchResult.message`) en lugar de un alert genérico.
- **Cobertura implícita del tick automático**: `TranscriptionTickCommand` ya llama al mismo comando, así que las protecciones #2/#3 lo blindan automáticamente. No requiere cambios adicionales.

## Impact

- **Specs AFFECTED**: `transcription-api-orchestrator` (nuevos requirements para resiliencia del batch y reporte de error).
- **Specs AFFECTED (migration)**: requiere ejecutar `php artisan migrate` para aplicar `2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php`.
- **Code affected**:
  - `app/app/Console/Commands/ScanAndSubmitCommand.php` — agregar try/catch por storage y global, escribir `status='error'` a la cache en caso de fallo.
  - `app/resources/views/ia/api-transcriptor/index.blade.php` — usar `batchResult.message` cuando exista en lugar de alert genérico.
  - `app/database/migrations/2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php` — confirmar que ya es idempotente (lo es: usa `Schema::table` addColumn que es no-op si existe la columna en PostgreSQL moderno, aunque mejoraremos la verificación).
- **Sin cambios en el scanner**: la lógica de deduplicación y descubrimiento queda intacta.
- **Sin cambios en el modelo de datos**: solo se aplican columnas ya diseñadas y referenciadas por el código.

## Non-goals

- No refactorizar el flujo del batch ni cambiar el comportamiento de éxito (solo agregar resiliencia).
- No cambiar el formato de la respuesta del endpoint `/batch-status` (extender con campos opcionales sí es aceptable).
- No agregar retries automáticos: el operador decide cuándo reintentar manualmente.
- No cambiar la lógica de dedup o layout del scanner.
- No migrar la columna `transcription_priority` (esa parte de la migración ya está bien).