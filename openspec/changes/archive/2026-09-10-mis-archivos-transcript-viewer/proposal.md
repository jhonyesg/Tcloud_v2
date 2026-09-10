## Why

Los clientes (y gestores con clientes) que tienen `transcription_access` en un storage asignado pueden ver la transcripción completa de un medio desde **Mis Avisos**, pero no desde **Mis Archivos**, donde actualmente solo se ofrece el reproductor a pantalla completa. El operador quiere que el archivo en Mis Archivos tenga una acción "Ver transcripción" que abra el mismo modal ya pulido (reproductor + segmentos + búsqueda) cuando aplique, dejando intacto el flujo actual para quienes no tengan acceso a transcripciones.

## What Changes

- Nuevo endpoint `GET /files/{id}/transcription` que retorna el mismo shape que `GET /mis-avisos/transcriptions/{id}` (reuso de `MentionsSearchService::visibleTranscription` + `pageVisibleSegments`).
- El listado `GET /files` se enriquece con `transcription_id` por archivo (LEFT JOIN acotado a `transcriptions.state='done'`, 1 sola query, cache existente sigue sirviendo).
- El modal de Mis Avisos (`mis-avisos/_transcript-modal.blade.php`) se extrae a `resources/views/components/transcript-viewer.blade.php` y se conecta a un `Alpine.store('transcriptViewer')` con estado y métodos reutilizables (`open`, `close`, `visibleSegments`, `onSegmentClick`, `onPlayerTime`, `onSegmentsScroll`, `loadBefore`, `loadAfter`, `highlightKeyword`, `mediaKind`, `hmsLabel`).
- Mis Archivos (`files/index.blade.php`) gana un botón nuevo en la columna Acciones, `@click` invocando el store con el `file` activo. El botón se renderiza solo si se cumplen las cinco condiciones de visibilidad (ver Capabilities).
- Mis Avisos (`mis-avisos/index.blade.php`) se adapta para usar el mismo partial + store. El comportamiento visible para el cliente queda idéntico.
- Reglas de negocio preservadas: misma intersección `transcription_access ∩ transcription_enabled` y mismas capabilities (`can_view_file`, `can_clip`) calculadas en el servidor. 404 opaco para IDs sin acceso (no revela existencia).

## Capabilities

### New Capabilities

- `mis-archivos-transcript-viewer`: requisitos de UX del módulo de archivos (condiciones de visibilidad del botón, navegación al visor, apertura desde el deep-link ya soportado).

### Modified Capabilities

- `mentions-viewer`: añade el escenario "apertura del visor desde un entry point distinto a una mención" (Mis Archivos) y formaliza que el modal es invocable desde cualquier contexto que entregue `(file_id, transcription_id)` con permiso. El comportamiento visible desde Mis Avisos no cambia.

## Impact

- **Controllers**: `FileController::index` (añadir join/select), `FileController::storages` (incluir `transcription_access` por storage para gating en frontend), nuevo método `FileController::transcription(File $file)`.
- **Models**: `File` gana relación opcional `transcription()` (`hasOne` por `file_id` UNIQUE) — no rompe nada, es aditiva.
- **Services**: `MentionsSearchService` no cambia; se reusa tal cual desde el nuevo controller.
- **Views**: extracción de `_transcript-modal.blade.php` a `components/transcript-viewer.blade.php`; `files/index.blade.php` añade un botón y `@include`; `mis-avisos/index.blade.php` se adapta al store.
- **Frontend**: nuevo `Alpine.store('transcriptViewer')` registrado en `layouts.app` o en cada blade que lo use (decisión en design.md).
- **Routes**: nuevo `GET /files/{id}/transcription` dentro del grupo `auth` (en `routes/web.php`).
- **Migrations**: ninguna. `transcriptions.file_id` ya es UNIQUE, `user_storages.transcription_access` ya existe, `storage_providers.transcription_enabled` ya existe.
- **Performance**: 1 LEFT JOIN extra por listing de archivos; el índice `transcriptions(state, created_at)` no se usa porque filtramos por `file_id` (que es UNIQUE) — costo despreciable.
- **Compatibilidad**: ninguna breaking change. Clientes sin transcripción siguen viendo el botón "play" como antes. Clientes con transcripción obtienen un botón nuevo.

## Non-goals

- No se cambia la regla `transcription_access` ni se relaja el gating: si el cliente no tiene acceso, NO ve el botón (decisión UX confirmada por el usuario).
- No se modifica el editor de corte (`MediaClipEditorUi`) ni el endpoint de corte: el visor sigue ofreciéndolo como acción opcional desde el pie del modal.
- No se tocan storages externos (S3, NFS): el alcance del cambio es solo Mis Archivos + el visor compartido. El deep-link `?focus=bg-…` no se ve afectado.
- No se agrega búsqueda full-text server-side dentro del modal: la búsqueda cliente-side sobre la ventana cargada ya existe en `_transcript-modal.blade.php` y se preserva.
- No se introduce paginación nueva ni se cambia `transcript.window` / `transcript.page` / `transcript.max_page` (config existente).
