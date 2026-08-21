# Tasks: Resiliencia y reporte de errores en "Escanear storages"

## 1. Migración (DB)

- [x] Reforzar `app/database/migrations/2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php` con guardas `Schema::hasColumn()` antes de cada `addColumn()` y DROP IF EXISTS para el CHECK constraint.
- [x] Verificar estado de la migración: `[1013] Ran` en `php artisan migrate:status`. `Schema::hasColumn('storage_providers', 'folder_layout')` = YES, `allow_parent_overlap` = YES, `transcription_priority` = NO. **No fue necesario aplicar de nuevo: ya estaba aplicada.**
- [x] Verificar columnas: storage 133 (Blu Digital) tiene `transcription_enabled=1`, `folder_layout='flat'`, `allow_parent_overlap=false`. Las queries del scanner funcionan correctamente.

## 2. Backend — Resiliencia del comando

- [x] En `app/app/Console/Commands/ScanAndSubmitCommand.php`, envolver el cuerpo de `handle()` en un try/catch global que escribe `status='error'` a la cache (`transcription_batch:<runId>`) cuando hay excepción fatal.
- [x] Dentro del `handle()`, agregar try/catch alrededor de `$scanner->scanStorage($storage, ...)` para que un fallo en un storage no aborte el batch completo. Acumular errores en `$perStorageErrors[]`.
- [x] Al finalizar el comando (status='queued' o nuevo 'partial'/'error'), escribir `per_storage_errors` a la cache cuando no esté vacía y un `message` legible.
- [x] **Bugfix detectado durante verificación**: el modo `--no-dispatch` retornaba `Command::SUCCESS` sin escribir estado final a la cache. Ahora también escribe `status='queued'/'partial'/'error'` en ese modo.

## 3. Frontend — Reporte de errores en el modal

- [x] En `app/resources/views/ia/api-transcriptor/index.blade.php`, en el método `pollBatch()`, agregar manejo de `status='partial'`, fallback del path del log, y propagación de `per_storage_errors` desde la respuesta.
- [x] En el template del modal "Escanear storages", agregar sección visible cuando `batchResult.message` existe, con estilo condicional (rojo si `errors > 0`) y lista de `per_storage_errors`.
- [x] Eliminar el `alert('Error de conexión')` genérico: ahora se asigna a `batchResult.message` para que se muestre en el panel estilizado.

## 4. Verificación

- [x] Happy path: `php artisan transcription:scan-and-submit --days=0 --batch=5 --run-id=verify_xxx --no-dispatch` procesa 31 storages, retorna status='queued' con `total_candidates=1`, `per_storage_errors=[]`, cache completa.
- [x] Storage con `base_path` inexistente: el scanner retorna candidatos=0 sin lanzar excepción (controlado por `if (!is_dir($basePath))` al inicio de `scanStorage()`). No ejerce el try/catch por storage, pero confirma que el código defensivo interno sigue funcionando.
- [x] `transcription:scan-and-submit` sin `--no-dispatch` y sin `--run-id`: retorna correctamente sin escribir a cache.
- [x] Lint PHP: `php -l` pasa sin errores en los 3 archivos modificados (`ScanAndSubmitCommand.php`, migración, vista Blade).
- [x] **Bug pre-existente encontrado durante validación**: el polling del frontend (`pollBatch()`) NO capturaba `status='queued'`, que es el estado final del comando cuando termina sin errores. El usuario veía el spinner "Procesando lote..." indefinidamente aunque el batch ya había terminado. **Fix**: agregada la condición `status === 'queued'` al ternario de terminación en `pollBatch()`.
- [ ] Pendiente manual: confirmar en el navegador que el modal muestra el panel de resultados al recibir `status='queued'` (caso exitoso) o `status='error'/'partial'` (caso con error).

## 5. Rollback

- [x] Plan de rollback documentado: `git revert` de los 3 archivos modificados y `php artisan migrate:rollback --step=1` (la migración agregada tiene `down()` que restaura el estado previo).