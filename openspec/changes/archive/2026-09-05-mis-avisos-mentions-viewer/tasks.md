# Tasks — Visor integrado de menciones (Mis Avisos Fase 4)

## 1. Costura de acceso (MentionsSearchService)

- [x] 1.1 Extraer `hitRow()` privado en app/app/Services/Ia/MentionsSearchService.php y unificar el mapeo de filas de `todayHits` y del mapper duplicado en `MisAvisosController::history`
- [x] 1.2 Corregir el mapeo de URL de archivo en `hitRow()`: `file_view_url = /files/{id}/view?t={floor(start_seconds)}` (reemplaza el `file_url` roto que apunta a `/files/{id}/preview`)
- [x] 1.3 Ampliar `visibleHitsQuery()` con LEFT JOIN a `user_storages` (permissions) y `storage_providers.type`, y exponer `media_editor_enabled` del usuario; derivar `can_view_file` y `can_clip` en `hitRow()`
- [x] 1.4 Ampliar `todayHits(User, array $filters, int $perPage = 25)` para aceptar q / storage_ids / keyword_id (misma semántica que `searchHistory`) y retornar LengthAwarePaginator con `whereDate(matched_at, today())`
- [x] 1.5 Implementar `visibleTranscription(User, int $transcriptionId): ?array` (meta del medio + total_segments + capabilities; null si no visible o transcription.state != done)
- [x] 1.6 Implementar `pageVisibleSegments(User, int $transcriptionId, ?int $anchorSegmentId, ?int $afterIndex, ?int $beforeIndex, int $limit): ?array` con range-scan por (transcription_id, segment_index), ventana centrada en ancla y cursores first_index/last_index
- [x] 1.7 Agregar `'transcript' => ['window' => 120, 'page' => 60]` a app/config/avisos.php y usarlo en 1.6
- [x] 1.8 Tests del harness (tests/harness_*.php): visibilidad 404-ish (null), ventana anclada correcta, cursores, capabilities por fila (con/sin read, editor on/off, storage no local)

## 2. Endpoints y rutas

- [x] 2.1 `MisAvisosController::feed()` passthrough de filtros (q, storage_ids, keyword_id) + paginación; respuesta `{ data, current_page, last_page, total, server_time }`
- [x] 2.2 `MisAvisosController::transcription(int $id, Request $request)` → `visibleTranscription` + primera `pageVisibleSegments` (ancla opcional); 404 si null
- [x] 2.3 `MisAvisosController::transcriptionSegments(int $id, Request $request)` → expansiones por after_index/before_index; 404 si no visible; throttle moderado en la ruta
- [x] 2.4 Registrar rutas en app/routes/web.php dentro del grupo `['auth', 'misavisos']`: `GET /mis-avisos/transcriptions/{transcriptionId}` y `GET /mis-avisos/transcriptions/{transcriptionId}/segments`; agregar `throttle:30,1` a `/mis-avisos/feed`
- [x] 2.5 `FileController::view(int $id, Request $request)`: pasar `startSeconds = max(0, (int) $request->query('t', 0))` a la vista files/preview

## 3. Seguridad del editor de medios (fix-along)

- [x] 3.1 Extraer/reutilizar chequeo de acceso a archivo (admin ∥ owner ∥ permiso read en storage) accesible desde MediaClipController (método privado o concern compartido con FileController)
- [x] 3.2 Aplicar el chequeo en `MediaClipController::clip`, `thumbnails`, `thumb` y `reclip` (reclip: el job debe ser del propio usuario); respuestas 403/404 sin revelar existencia
- [x] 3.3 Smoke manual del editor en /files: cortar archivo propio sigue funcionando; cortar archivo ajeno sin permiso → 403; thumbnails igual
- [x] 3.4 Test de harness para 3.2 (usuario con editor on sin permiso → 403; con read → 200)

## 4. Frontend: tablas compartidas con filtros

- [x] 4.1 Crear app/resources/views/mis-avisos/_table-hits.blade.php: tabla (fecha/hora | emisora | archivo | keyword | minuto | contexto | acciones) usada por tabs live e history, con paginación server-side y acciones por fila según can_view_file / can_clip
- [x] 4.2 Estado Alpine en `misAvisosPage()`: `liveFilters = { q, storage_ids: [], keyword_id, page }` y `historyFilters = { q, from, to, storage_ids: [], keyword_id, page }`; historial sincroniza filtros a query string con history.replaceState
- [x] 4.3 Selectores de filtros en ambas tabs: multi-select de emisoras (datos de `/mis-avisos/storages`) y select de keywords propios; cambiar filtro → página 1 + refetch; polling del feed re-pide la página activa con filtros y muestra badge "N nuevas" cuando total crece
- [x] 4.4 Integrar _table-hits en index.blade.php reemplazando las listas de tarjetas de live e history (el bloque de export existente envía historyFilters tal cual; verificar requestExport ya recibe storage_ids/keyword_id)

## 5. Frontend: modal de transcripción sincronizado

- [x] 5.1 Crear app/resources/views/mis-avisos/_transcript-modal.blade.php: modal (patrón visual de ia/correcciones) con meta del medio, lista de segmentos (índice, tiempo, texto) y highlight del ancla; estado Alpine `transcriptModal` según design D3
- [x] 5.2 Carga inicial anclada (anchor segment de la fila clickeada) + auto-scroll al ancla; pre-fetch de cursores al 80% del scroll (before/after) con estados loadingBefore/loadingAfter
- [x] 5.3 Reproductor embebido lazy cuando meta.can_view_file: click en segmento → seek+play; timeupdate → resaltar segmento activo (puntero incremental); sin reproductor si can_view_file=false
- [x] 5.4 Acciones del modal: "Abrir archivo en pestaña" usa file_view_url (?t=); botón cortar solo si can_clip; manejo de errores del servidor mostrando su mensaje

## 6. Frontend: panel de corte

- [x] 6.1 Crear app/resources/views/mis-avisos/_clip-panel.blade.php: inicio/fin prellenados desde el segmento ancla (editables, validación start<end), botón "Previsualizar" (POST clip preview=true → reproducir URL de serveTemp) y "Generar y descargar" (POST sin preview)
- [x] 6.2 Estados Alpine `clipPanel` con busy/error; mensajes de rechazo del servidor (flag, cupo mensual, permiso) mostrados textualmente; cierre del panel vuelve al modal sin perder scroll del ancla

## 7. Validación final

- [x] 7.1 php -l / pint sobre archivos tocados; ejecutar harness de sesiones/menciones existentes para descartar regresiones
- [x] 7.2 Recorrido E2E manual: hit en vivo → tabla filtrada por emisora → modal anclado → seek sincronizado → corte preview → corte descargado; histórico → export con filtros activos → CSV fiel
- [x] 7.3 Verificación de costura: grep de que ningún controlador cliente consulta segment_keyword_hits/transcription_segments fuera de MentionsSearchService
- [x] 7.4 `openspec validate --strict` y archivar si el usuario lo pide

## 8. Extras implementados durante la validación E2E (Playwright, 2026-09-06/07)

- [x] 8.1 Unificar botones Ver/Transcripción: "Ver" abre el modal con el medio en autoplay desde el minuto de la mención y la transcripción anclada (no hay dos acciones separadas)
- [x] 8.2 Recuadro de búsqueda dentro del modal: filtra segmentos cargados insensible a mayúsculas/tildes, contador "N de M seg.", resaltado amarillo con tokens
- [x] 8.3 Resaltado accent-aware de la keyword ("alvaro uribe" marca "Álvaro Uribe") vía regex por clases de acentos
- [x] 8.4 Deep-link "Archivos" a la carpeta del medio: `/files?storage_id&folder&highlight_file` — landing con resaltado ámbar y scroll centrado; restoreNavState blindado (`_deepLinkActive`) y guardia anti doble-init (`_initDone`); scroll con reintentos que sobrevive al silentSync
- [x] 8.5 Botón "Volver a Mis Avisos" en el header del editor de corte, visible solo con origen avisos (`cameFromAvisos`)
- [x] 8.6 `mediaKind()` por extensión (mime en BD invertido en cientos de miles de filas: .mp3↔video/mp4, .m4a↔audio/mp4) en el modal y en files/preview
- [x] 8.7 Reescritura de files/preview.blade.php (bug preexistente: JSON crudo dentro del atributo x-data rompía el HTML); data en bloque script
- [x] 8.8 Fixes preexistentes en MediaClipController: scheduleCleanup bloqueaba ~300s (pipes sin redirigir), preview_url sin extensión → 404 siempre (también en /files), clip/thumbs/reclip sin chequeo de acceso al archivo
- [x] 8.9 MentionsExportJob: closure del filtro storage_ids usaba $search sin capturar → todo export con filtro de emisora fallaba desde Fase 3
- [x] 8.10 hitSelect sin f.storage_provider_id (regresión de edición en paralelo) → feed/histórico 500; corregido y endurecido con isset()
- [x] 8.11 E2E documentado con Playwright: 10 capturas en evidencias/ (modal, búsqueda, deep-link carpetas, editor con/sin botón, regreso a avisos)
