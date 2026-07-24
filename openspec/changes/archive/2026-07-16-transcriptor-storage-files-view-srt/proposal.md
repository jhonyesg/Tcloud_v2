## Why

En el módulo **API Transcriptor → Storages → "Ver archivos"** (modal que lista `File` por `StorageProvider`), los archivos que ya tienen una `Transcription` asociada solo muestran un badge plano `Transcrito` / `Pendiente`. No hay forma de saltar desde ese modal a la vista detalle de la transcripción para ver el SRT (modo habitual de consumir la transcripción: como subtítulos en VLC o cualquier reproductor). El usuario tiene que memorizar el nombre, ir al tab Jobs y buscar — fricción innecesaria cuando el archivo ya está en pantalla. Esto bloquea la navegación primaria del módulo y entorpece la auditoría/verificación de transcripciones sobre archivos concretos.

## What Changes

- El endpoint `GET /ia/api-transcriptor/storages/{id}/files` (`ApiTranscriptorController::storageFiles`) devolverá, por cada archivo, `transcription_id` y `transcription_state` cuando exista una `Transcription` asociada (no solo `has_transcription: bool`).
- En la vista `resources/views/ia/api-transcriptor/index.blade.php`, dentro del modal "Ver archivos" (modos `browse`, `today`, `yesterday` y `search`), cuando `f.transcription_id` exista, el nombre del archivo será un `<a>` que navega a `/ia/api-transcriptor/jobs/{transcription_id}` — la vista `job-detail` ya existente que muestra el SRT, metadata, segmentos y acciones contextuales (Reintentar si `error`/`dead`).
- El badge plano `Transcrito` se reemplaza por el link; el badge `Pendiente` se mantiene para archivos sin `Transcription` asociada.
- El comportamiento aplica a **todos los estados** (`pending`, `queued`, `processing`, `done`, `error`, `dead`) — el job-detail ya muestra el contexto apropiado para cada uno.

## Capabilities

### New Capabilities
- `transcriptor-storage-files-srt-link`: navegación desde el listado de archivos de un storage hacia el detalle de la transcripción asociada para ver el SRT.

### Modified Capabilities
- (ninguna — los specs existentes cubren el pipeline de transcripción, no la UX de navegación del listado de archivos)

## Impact

- **Backend**: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` método `storageFiles` (líneas 236-381). Reemplazar `Transcription::pluck('file_id')` por un mapa `file_id → {id, state}` y agregar esos campos al JSON por archivo.
- **Frontend**: `app/resources/views/ia/api-transcriptor/index.blade.php` — dos bloques donde se renderiza la fila de archivo (modos `browse`/`today`/`yesterday` en líneas ~314-340, y modo `search` en líneas ~351-390). Reemplazar el badge condicional actual por un `<a>` que apunta a `/ia/api-transcriptor/jobs/{f.transcription_id}` cuando `f.transcription_id` esté presente.
- **Rutas**: sin cambios. Se reutiliza `GET /ia/api-transcriptor/jobs/{id}` y la vista `job-detail.blade.php` ya existentes.
- **Migraciones**: no requiere.
- **Compatibilidad**: el JSON de `storageFiles` gana dos campos (`transcription_id`, `transcription_state`); consumidores existentes que solo lean `has_transcription` siguen funcionando.

## Non-goals

- No se agrega reproductor embebido de audio/video en el listado (la vista `job-detail` ya es el destino de detalle; extenderla con player es alcance de un cambio futuro).
- No se cambia la lógica de selección múltiple del modal ni el flujo "Procesar carpeta/día".
- No se modifica el scanner (`scanStorage` lee DB vs disco) — eso es alcance de un cambio aparte.
- No se cambian los badges `Pendiente` para archivos sin `Transcription` asociada.
- No se cambia la vista `job-detail` ni el endpoint `/jobs/{id}`.