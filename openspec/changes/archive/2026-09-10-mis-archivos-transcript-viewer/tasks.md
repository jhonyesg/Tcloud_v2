## 1. Backend — modelo y listado de archivos

- [x] 1.1 Añadir relación `transcription()` en `app/app/Models/File.php` (`hasOne` por `file_id` UNIQUE contra `App\Models\Transcription`)
- [x] 1.2 Enriquecer `FileController::index` (línea 39) con subquery de `transcription_id`: añadir al `$query->addSelect([...])` el `leftJoinSub` con `SELECT id FROM transcriptions WHERE file_id = files.id AND state='done' LIMIT 1`. Verificar que el cache `folder_listing:*` sigue serializando el nuevo campo
- [x] 1.3 Extender `FileController::storages` (línea 963) para incluir `transcription_access` (bool) por storage en el array que devuelve al frontend
- [x] 1.4 Verificar manualmente que la respuesta de `GET /files?…` incluye `transcription_id` por archivo y que `GET /user/storages` incluye `transcription_access` por storage

## 2. Backend — endpoint nuevo de transcripción por archivo

- [x] 2.1 Crear método `FileController::transcription(Request $request, File $file, MentionsSearchService $search)` siguiendo D3 del design.md: lookup `transcription_id` por `file_id`, luego reuso `visibleTranscription` + `pageVisibleSegments`
- [x] 2.2 Manejar los tres casos de respuesta: 200 con meta+segments, 404 opaco para sin acceso, 404 para sin transcripción done
- [x] 2.3 Registrar la ruta `GET /files/{file}/transcription` en `app/routes/web.php` dentro del grupo con middleware `auth` (mismo grupo que `GET /files/{file}/download`)
- [x] 2.4 Verificar manualmente con `curl`/`apiFetch`: cliente con acceso → 200; cliente sin acceso → 404; archivo sin transcripción → 404

## 3. Frontend — extracción del visor a componente reusable

- [x] 3.1 Crear `app/resources/views/components/transcript-viewer.blade.php` copiando el contenido de `app/resources/views/mis-avisos/_transcript-modal.blade.php` y reemplazando todas las referencias `transcriptModal.*` por `Alpine.store('transcriptViewer').*` (búsqueda/reemplazo mecánico)
- [x] 3.2 Definir `Alpine.store('transcriptViewer', { … })` en `app/resources/views/layouts/app.blade.php` dentro del listener `alpine:init` existente, con: estado completo (`open`, `loading`, `error`, `meta`, `segments`, `firstIndex`, `lastIndex`, `totalSegments`, `loadingBefore`, `loadingAfter`, `search`, `activeIndex`, `anchorSegmentId`, `hitKeyword`, `pendingSeek`), acciones (`openFor(file | row)`, `openRow(row, opts)`, `close()`, `loadBefore()`, `loadAfter()`, `toggleKeywordFilter()`, `isKeywordFilterActive()`, `openFilesTab()`, `openClipFromAnchor()`), selectores (`visibleSegments()`, `mediaKind()`, `highlightKeyword(seg)`, `hmsLabel(seconds)`, `plain0(seg)`) y eventos de DOM (`onSegmentClick`, `onPlayerTime`, `onPlayerLoaded`, `onSegmentsScroll`, `seekToSegment`, `seekToTime`)
- [x] 3.3 Reemplazar en `app/resources/views/mis-avisos/index.blade.php`: cambiar `@include('mis-avisos._transcript-modal')` por `@include('components.transcript-viewer')`, eliminar el bloque `transcriptModal: { … }` y todas las funciones del visor del componente `misAvisos`, cambiar las llamadas `openTranscript(row, opts)` por `Alpine.store('transcriptViewer').openRow(row, opts)`
- [x] 3.4 Mantener `app/resources/views/mis-avisos/_transcript-modal.blade.php` intacto (no borrar todavía — ver rollback en design.md). Marcado como `@deprecated` en un comentario
- [x] 3.5 Validar manualmente el visor Mis Avisos: misma funcionalidad (abrir desde mención, búsqueda, click-para-tiempo, scroll incremental, corte, "Abrir en Mis Archivos")

## 4. Frontend — botón en Mis Archivos

- [x] 4.1 En `app/resources/views/files/index.blade.php`, dentro del componente `fileManager`, agregar variable reactiva `currentStorageTranscriptionAccess: false`
- [x] 4.2 Hidratar `currentStorageTranscriptionAccess` en `enterStorage(storageId, storageName)` buscando el storage activo en `availableStorages` y leyendo `transcription_access`
- [x] 4.3 En la columna Acciones agregar un botón nuevo con `x-show` para gating de 5 condiciones y `@click.stop="Alpine.store('transcriptViewer').openFor({ file_id, transcription_id })"`
- [x] 4.4 Al final del blade, agregar `@include('components.transcript-viewer')`
- [x] 4.5 Validar manualmente: cliente con acceso + archivo con transcripción → botón visible y abre modal; cliente sin acceso → botón oculto; archivo sin transcripción → botón oculto; click normal sobre fila sigue abriendo viewer nativo

## 5. Backend — feature flag de rollback

- [x] 5.1 Implementar feature flag `FEATURE_MIS_ARCHIVOS_TRANSCRIPT_VIEWER` vía `window.tcloudFeatures` inyectado desde el layout; helper `transcriptViewerFeatureEnabled()` en `fileManager` para gating reactivo
- [x] 5.2 Documentar el flag en `AGENTS.md` sección "Operaciones" con el comando para activarlo/desactivarlo

## 6. Backend — tests opcionales

- [x] 6.1 Harness `tests/harness_mis_archivos_transcript_viewer_1_4.php` verifica que el listado expone `transcription_id` y `storages` expone `transcription_access`
- [x] 6.2 Harness `tests/harness_mis_archivos_transcript_viewer_2_4.php` cubre los tres casos del endpoint nuevo (404 sin transcripción, 404 opaco sin acceso, 200 con shape completo)

## 7. Validación final

- [x] 7.1 `openspec validate --changes mis-archivos-transcript-viewer --strict` → 0 errores
- [x] 7.2 `php artisan view:clear`
- [x] 7.3 Smoke test E2E con Playwright headless contra producción: login `jsuarez` → Mis Archivos → `01 Caracol Tv` → `09092026` → botón "Ver transcripción" en `caracol_09092026_220002.mp4` → modal abre con `file_name + storage + duración + video player 984×320 + segmentos + buscador`. Screenshot en `/tmp/kilo/screenshots/video_size_check.png`. Cliente sin acceso → botón NO aparece; archivo sin transcripción → botón NO aparece
- [x] 7.4 Cache del listing (`folder_listing:*` en Redis) sirve el campo `transcription_id` después del bump manual de `folder_gen:{storageId}:{parentId}` (operación de deploy documentada en AGENTS.md)
- [x] 7.5 Consola del navegador: 0 errores JS al abrir el modal desde ambos entry points (validado vía Playwright headless)

## 8. Fixes encontrados durante validación E2E

Tres bugs aparecieron durante la validación con Playwright contra producción. Se arreglaron en el mismo change antes del archivo:

- [x] 8.1 **Bug del nombre del método en el store**: el método `open(target)` sobrescribía la propiedad booleana `open: false` del `Alpine.store('transcriptViewer')`. Después, `tv.open` retornaba la función (truthy) y el modal aparecía al cargar la página (interceptando clicks). **Fix**: renombrar `open` → `openFor` (en `layouts/app.blade.php` línea ~804 y en el `@click` del botón Mis Archivos en `files/index.blade.php`). `openRow` también actualizado para llamar a `openFor` internamente.
- [x] 8.2 **Subquery del listing no aplicaba en la rama `sync=1`**: el cliente Mis Archivos SIEMPRE llama con `sync=1&nb=1` (silentSync al navegar). En esa rama, el controller retorna `syncFolderWithReport(...)` directo y mi `addSelect(transcription_id)` se ignoraba. Resultado: el frontend veía `files_with_transcription: 0`. **Fix**: en `FileController::index` rama `sync=1`, después de `syncFolderWithReport()`, hacer un lookup bulk en `transcriptions WHERE state='done'` y enriquecer cada archivo con `transcription_id`. Mismo patrón que la rama normal.
- [x] 8.3 **Warning `this.$nextTick is not a function`**: dentro de la arrow function `openFor`, `this` no apuntaba al store, así que `this.$nextTick(...)` lanzaba error visible en el modal. **Fix**: reemplazar por `setTimeout(fn, 0)` (semánticamente equivalente para asegurar que Alpine renderice antes del `scrollIntoView`).

## 9. Mejora visual post-feedback

- [x] 9.1 **Tamaño del video player**: el `<video>` tenía `max-h-48` (192px). El usuario pidió más alto. **Fix**: cambiar a `max-h-72 sm:max-h-80` (288px / 320px) + `aspect-video` para mejor uso del ancho horizontal. Validado en Playwright: video renderiza 984×320px en escritorio.
