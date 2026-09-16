# Tasks: Reintento automático de transcripciones fallidas

## 1. Backend — Servicio

- [x] En `app/app/Services/Ia/DiskScannerService.php`, agregar método público `collectFailedCandidates(StorageProvider $storage, int $maxRetries = 3): array` que busca `Transcription` con `state='error'`, `retries < maxRetries`, verifica accesibilidad del archivo y resetea a `pending` (o promueve a `dead` si no accesible).

- [x] En `app/app/Services/Ia/TranscriptionSubmitService.php`, modificar `markError()` para incrementar `retries` en cada fallo y promover automáticamente a `state='dead'` con mensaje "[Auto] Max retries (N) alcanzado" cuando `retries >= max_retries`.

## 2. Backend — Comando

- [x] En `app/app/Console/Commands/ScanAndSubmitCommand.php`:
  - Agregada opción `--include-failed` a la signature.
  - Nueva Fase 1.5 que llama `collectFailedCandidates()` por cada storage (con try/catch aislado).
  - Stats extendidas en el cache: `failed_recovered`, `failed_promoted_to_dead`, `failed_skipped_max_retries`.
  - `total_candidates` ahora suma los `reset_to_pending`.

## 3. Backend — Controller

- [x] En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`, `processBatch()` lee `include_failed` del body y agrega `--include-failed` al comando solo cuando es true.

## 4. Frontend — UI

- [x] Variable Alpine `batchIncludeFailed: false` agregada al estado.
- [x] Checkbox "Reintentar fallidos" agregado al modal "Escanear storages" con tooltip explicativo.
- [x] `runBatch()` envía `include_failed: this.batchIncludeFailed` en el body.
- [x] Sección de stats "Reintento de fallidos" agregada al panel de resultados con tres contadores: Recuperados / Promovidos a dead / Saltados (max retries).

## 5. Config

- [x] `transcriptor.max_retries` (default 3) agregado a `config/transcriptor.php` con env var `TRANSCRIPTOR_MAX_RETRIES`. Verificado que se carga correctamente.

## 6. Verificación

- [x] **Happy path con 885 errores reales**: `php artisan transcription:scan-and-submit --include-failed --no-dispatch` reencoló 826 archivos (estado: error → pending, retries=1) y promovió 59 a dead (archivo no accesible en disco).
- [x] **Auto-promoción a dead**: invocando `markError()` manualmente sobre una Transcription con `retries=2`, el resultado fue `state='dead'`, `retries=3`, `error_message='[Auto] Max retries (3) alcanzado. Third failure'`.
- [x] **Cache con nuevos campos**: verificado el payload del cache incluye `failed_recovered: 826`, `failed_promoted_to_dead: 59`, `failed_skipped_max_retries: 0`, `total_candidates: 826`.
- [x] **Comportamiento default intacto**: ejecutar sin `--include-failed` muestra el flujo normal (no reencoló fallidos, no agregó stats de fallidos al cache).
- [x] **Resultado final en BD**: 98 transcripciones nuevas en `done` (de 37696 → 37794), 38 nuevos `error` (transcriptor externo con problemas), 784 todavía `pending` siendo procesados por workers.

## 7. Rollback

- [x] Plan de rollback: `git revert` de los 5 archivos modificados. El flag `--include-failed` siendo opt-in (default OFF) significa que el comportamiento default no cambia. La auto-promoción a dead en `markError()` es la única pieza siempre activa — para revertirla basta con revertir `TranscriptionSubmitService.php`.