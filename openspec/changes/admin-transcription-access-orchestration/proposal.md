## Why

`/ia/avisos-inteligentes` actualmente hace una sola cosa: gestiona alertas por keyword. El admin no tiene forma de decidir a qué cliente le "abrimos la puerta" a las transcripciones que api-transcriptor ya produjo. Esa separación es la que el código perdió el 18-08-2026, costó 44 horas, y se documentó como lección en `ApiTranscriptorController:587-600`. Vamos a recuperar esa capacidad **sin volver a acoplar api-transcriptor con la concesión de acceso por cliente**.

## What Changes

- Migración que agrega `user_storages.transcription_access` (boolean, default `false`). Vive en el pivote existente `user_storages`, no en una tabla nueva.
- `User::storageProviders()` limpia el `withPivot('transcription_enabled', ...)` obsoleto y suma `transcription_access`.
- `AvisosInteligentesController::show()` envía, por storage del cliente, su `transcription_access` real.
- `user-detail.blade.php` sustituye el badge read-only "Transcribe / Sin transcripción" (que era espejo del flag global de api-transcriptor) por un toggle interactivo por storage. Añade un banner read-only con el contador global de api-transcriptor.
- Endpoint nuevo `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access` (única escritura).
- `KeywordMatcher::run()` filtra matches por `(user, storage)` donde `transcription_access = true`. Aplica desde el deploy. Históricos NO se borran.
- `MisAvisosController` filtra la lista de matches del cliente por la misma bandera.
- Paso nuevo en `TcloudTour` para el toggle.
- Documenta en `design.md` el contrato de datos que consumirá el futuro módulo cliente "Mis Transcripciones" (fuera de scope de este change).

## Capabilities

### New Capabilities
- `admin-transcription-access`: capacidad de orquestación admin-only que define cómo se concede, persiste y consulta el acceso por (cliente, storage) a las transcripciones producidas. Incluye el contrato que el futuro módulo cliente deberá respetar.

### Modified Capabilities
- `keyword-alerts`: agrega requisito de que `KeywordMatcher` respete `transcription_access` antes de crear match o enviar email.
- `client-alerts-view`: agrega requisito de que `MisAvisosController` filtre los matches visibles por `transcription_access`.

## Impact

- **Migración**: nueva `add_transcription_access_to_user_storages`.
- **Modelos**: `User`, `UserStorage` (`transcription_access` en fillable + cast).
- **Controlador**: `App\Http\Controllers\Ia\AvisosInteligentesController` (`show`, nuevo método `toggleStorageAccess`, ruta nueva en `routes/web.php`).
- **Vistas**: `resources/views/ia/avisos-inteligentes/user-detail.blade.php` y `index.blade.php`.
- **Servicios**: `App\Services\Ia\KeywordMatcher` (filtro prospectivo).
- **Cliente existente**: `App\Http\Controllers\MisAvisosController` (filtro de listado).
- **Tour**: `public/js/interactive-tour.js` (paso nuevo para el toggle).
- **Sin impacto** en `ApiTranscriptorController`, `StorageProvider`, `DiskScannerService`, `TranscriptionPollingService`, `TranscriptionProcessor`, `CorreccionesController`. La bandera `storage_providers.transcription_enabled` sigue siendo autoritativa del pipeline y la única que la escribe es `ApiTranscriptorController::toggleStorage`.

## Non-goals

- No se construye el módulo cliente "Mis Transcripciones". Queda como contrato en `design.md`.
- No hay acciones masivas (bulk grant/revoke). El opt-in es uno a uno, manual.
- No se siembra `transcription_access = true` para ninguna fila existente. Default `false`.
- No se borran `keyword_matches` históricos; solo se aplica el filtro prospectivamente.
- No se rate-limita nada en este change (será trabajo del futuro módulo cliente).
