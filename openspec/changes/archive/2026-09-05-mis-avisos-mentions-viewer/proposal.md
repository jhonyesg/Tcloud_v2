# Proposal — Visor integrado de menciones (Mis Avisos Fase 4)

## Why

Mis Avisos ya detecta y reparte menciones, pero el cliente no puede actuar sobre ellas: el link del archivo apunta a un endpoint que solo sirve imágenes (`/files/{id}/preview` → HTTP 400 en video/audio), no hay forma de ver la transcripción completa anclada al minuto de la mención, ni generar un corte desde el módulo, y el feed en vivo/histórico son listas de tarjetas sin filtros por emisora o keyword (el backend ya acepta esos filtros; la UI no los envía). Esto impide que el cliente juegue con sus datos y regenere reportes profesionales.

## What Changes

- **Bugfix**: `file_url` de feed/histórico apunta a `/files/{id}/view` (reproductor real) en vez de `/files/{id}/preview` (solo imágenes).
- **Deep-link con minuto**: `/files/{id}/view?t=<segundos>` posiciona el reproductor (`files/preview.blade.php`) en el segundo exacto de la mención.
- **Modal de transcripción**: nuevo endpoint cliente `GET /mis-avisos/transcriptions/{id}` (meta + segmentos con ventana anclada alrededor del `segment_id` de la mención), servido por `MentionsSearchService` respetando transcription_access ∩ alcance keyword→store. Modal con scroll al segmento ancla, highlight, y reproductor sincronizado (click en segmento → seek).
- **Tabla con filtros**: feed en vivo e histórico pasan de tarjetas a tablas compartidas con búsqueda, rango de fechas, filtro multi-emisora y filtro por keyword; paginación real; exportación usa los filtros activos.
- **Feed en vivo filtrable**: `todayHits()` acepta los mismos filtros (q, storage_ids, keyword_id).
- **Corte desde el módulo**: panel mínimo de corte dentro del modal que reusa `POST /files/{file}/clip` (preview + descargar), prellenado con el rango del segmento ancla, sujeto a `media_editor_enabled` y cupo mensual existentes.
- **Capabilities por fila**: cada hit expone `can_view_file` y `can_clip` calculados en la costura de acceso (cierra la brecha transcription_access vs permiso read).
- **Seguridad (fix-along)**: `POST /files/{file}/clip` y endpoints de thumbnails verifican permiso read/owner/admin sobre el archivo fuente (hoy solo validan `canUseMediaEditor`).

## Capabilities

### New Capabilities
- `mentions-viewer`: modal de transcripción anclada a la mención, deep-link del reproductor al minuto exacto, capabilities por fila y corte desde el módulo.

### Modified Capabilities
- `client-alerts-view`: el feed en vivo acepta filtros (emisora, keyword, búsqueda) y se presenta como tabla con paginación.
- `mentions-historical-export`: el histórico se presenta como tabla que expone filtros storage/keyword ya soportados por el backend; la exportación aplica exactamente los filtros activos de la tabla.
- `media-editor-access-control`: el endpoint de clip y los endpoints de thumbnails exigen permiso de lectura/propiedad/admin sobre el archivo fuente, además del flag del editor.

## Impact

- Backend: `MisAvisosController` (nuevos endpoints + filtros), `MentionsSearchService` (extensión de la costura: `visibleTranscription`, windowing de segmentos, capabilities), `MediaClipController` (chequeo de acceso a archivo), `FileController::view`/`files.preview` (parámetro `?t=`), `app/routes/web.php`.
- Frontend: `app/resources/views/mis-avisos/index.blade.php` se divide en partials (`_table-hits`, `_transcript-modal`, `_clip-panel`) sin cambiar de stack (Blade + Alpine + Tailwind).
- Config: `app/config/avisos.php` (tamaño de ventana de segmentos).
- **No requiere migración de base de datos** (usa columnas existentes: `segment_id`, `start_seconds`, `transcription_id`).
- Riesgo controlado: consultas de segmentos son range-scan por índice `(transcription_id, segment_index)`; sin consultas masivas.

## Non-goals

- No se implementa envío automático de reportes por correo (solo export manual, como está decidido).
- No se cambia la regla de negocio de alertas por correo (solo día actual, cadencia elegible).
- No se reescribe el editor de cortes completo del módulo de archivos: se reusa su endpoint con un panel mínimo.
- No se migra el frontend a otro framework ni build step.
- No se modifica el motor de matching ni la dedup de keywords entre clientes.
