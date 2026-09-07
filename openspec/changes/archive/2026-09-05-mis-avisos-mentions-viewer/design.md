# Design — Visor integrado de menciones (Mis Avisos Fase 4)

## Context

Ver proposal.md (Why). Restricciones vigentes del código:

- `MentionsSearchService` (app/app/Services/Ia/MentionsSearchService.php) es la **costura** declarada de acceso a menciones/segmentos: "Cualquier consulta de coincidencias o segmentos para un cliente DEBE pasar por aquí". Ya junta en `visibleHitsQuery()` las tablas necesarias: `segment_keyword_hits`, `keywords`, `transcription_segments` (con `start_seconds`), `transcriptions`, `files`, `storage_providers`.
- El feed (`todayHits`) y el histórico (`searchHistory`) comparten base pero cada uno mapea sus filas por su cuenta; el mapeo de `file_url` está duplicado y apunta al endpoint equivocado.
- El reproductor de archivos vive en `FileController::view` → `app/resources/views/files/preview.blade.php` (Alpine, `<video>`/`<audio>` con `src="/media/{id}/preview"`, streaming por X-Accel-Redirect con soporte Range). No acepta segundo inicial.
- El corte existe: `MediaClipController::clip` (`POST /files/{file}/clip`, modo legacy `segments: [{start,end}]`, `preview=true` para previsualizar, resultado descargado vía stream). Gate actual: solo `canUseMediaEditor()` + cupo mensual; **no verifica acceso al archivo** (ni `clip`, ni `thumbnails`, ni `reclip`).
- `transcription_segments` tiene índice por `transcription_id` y columnas `segment_index, start_seconds, end_seconds, text`; transcripciones de radio pueden tener miles de segmentos.
- Auth del proyecto: `Session::get('user_id')` (nunca `auth()->user()`). Rutas de Mis Avisos bajo `['auth', 'misavisos']`.

## Goals / Non-Goals

Goals: un solo lugar que decida qué puede hacer el cliente sobre un hit (ver transcripción / ver archivo / cortar); transcripciones grandes navegables sin consultas pesadas; tablas filtrables compartidas entre en vivo e histórico.

Non-Goals: ver proposal.md (sección Non-goals).

## Decisions

### D1 — La costura crece: `MentionsSearchService` es el módulo profundo del visor

Nueva interfaz en la costura existente (mismo archivo, misma regla de acceso, cero lógica de acceso en controladores):

```
MentionsSearchService
├── visibleHitsQuery(User)                                    (ya existe)
├── todayHits(User, array $filters, int $perPage): Paginator  (ampliada: filtros + paginación)
├── searchHistory(User, array $filters): Paginator            (ya existe)
├── visibleTranscription(User, int $transcriptionId): ?array       ← NUEVO (meta + capabilities)
├── pageVisibleSegments(User, int $transcriptionId, ?int $anchorSegmentId,
│       ?int $afterIndex, ?int $beforeIndex, int $limit): ?array  ← NUEVO (ventana de segmentos)
└── hitRow($r): array                                         ← NUEVO privado (mapeo único de fila)
```

- `visibleTranscription` retorna `null` si la transcripción no es visible (el controlador responde 404, sin revelar existencia). Visibilidad = `transcription.state = 'done'` Y `file.storage_provider_id ∈ accessibleStorageIds()`.
- `pageVisibleSegments` valida la misma visibilidad y resuelve la ventana: con `anchorSegmentId` centra ~`window/2` segmentos antes y después del ancla; con `afterIndex`/`beforeIndex` entrega la página siguiente/anterior por `segment_index`. Ambos métodos retornan también `total_segments` y los cursores (`first_index`, `last_index`) que el modal necesita para pedir más.
- `hitRow()` unifica el mapeo de fila (id, keyword, snippet, matched_at, filename, `file_view_url` con `?t=`, storage, minute_label, transcription_id, segment_id, **capabilities**). Feed, histórico (y el controlador de histórico, que hoy re-mapea) usan el mismo mapeo: fix del bug y localidad en un solo movimiento.

Alternativas rechazadas: (a) servicio nuevo `TranscriptViewerService` — duplicaría la regla de intersección que la costura ya encapsula (dos lugares que drift); (b) chequeos en el controlador — interface de módulo shallow y reglas regadas.

### D2 — Ventana anclada, no transcripción completa

Parámetro de configuración en `app/config/avisos.php`: `'transcript' => ['window' => 120, 'page' => 60]` (segmentos por defecto alrededor del ancla / por expansión). La primera carga trae ~120 segmentos centrados en la mención; el modal pide más con cursores al acercarse a los bordes (pre-fetch a ~80% del scroll). Queries: range-scan acotado sobre el índice de `transcription_segments` por `transcription_id` + `segment_index` — nada de `SELECT *` completo sobre millones de filas (restricción operativa del servidor).

Alternativas: (a) cargar todo como hace el modal admin de correcciones — aceptable ahí (uso admin puntual), insostenible para clientes concurrentes con transcripciones de 3-6 h; (b) virtualización pura en cliente — sigue necesitando server paging, mismo trabajo del lado servidor con más complejidad de UI.

### D3 — Reproductor: deep-link barato HOY + embebido sincronizado en el modal

- Deep-link: `files/preview.blade.php` lee `?t=<seg>` (pasado desde `FileController::view` a la vista) y en `x-init` hace `el.currentTime = t` sobre `loadedmetadata`. Beneficia a toda la app (compartible, back-button) y es el fallback si JS del modal falla. El parámetro se documenta como segundo entero; valores inválidos → 0.
- Embebido: el modal incluye su propio `<video>/<audio>` con `src="/media/{id}/preview"` (mismo streaming nginx con Range). Estado Alpine del modal:

```
transcriptModal = {
  open, loading, error,
  meta: { file_id, file_name, storage_name, duration_seconds, can_view_file, can_clip },
  anchor: { segment_id, start_seconds },
  segments: [ { id, segment_index, start_seconds, end_seconds, text } ],
  firstIndex, lastIndex, totalSegments,
  loadingBefore, loadingAfter,
  playerEl, activeIndex,
}
```

  Eventos: click en segmento → `playerEl.currentTime = seg.start_seconds` (+play); `timeupdate` → búsqueda del segmento activo por índice sobre la ventana ya ordenada (los segmentos se agregan ordenados; mantener puntero incremental, no re-escanear). Auto-scroll inicial al ancla con `scrollIntoView` tras `x-nexttick`.
- Los links de archivo de las tablas apuntan a `/files/{id}/view?t=…` (reproduce en página completa); el modal es la experiencia primaria desde una mención.

Alternativas: solo deep-link (pierde la sincronización transcripción↔audio que pide el negocio); solo modal (el archivo deja de tener deep-link utilizable fuera del módulo). Ambos comparten endpoint de streaming, no hay duplicación de backend.

### D4 — Capabilities calculadas en el servidor, dentro de la costura

`visibleHitsQuery()` amplía su SELECT con: `us.permissions` (LEFT JOIN `user_storages us` del usuario sobre `f.storage_provider_id`), `sp.type`, y el flag del usuario `media_editor_enabled` (bind del servicio, no join). `hitRow()` deriva:

- `can_view_file` = `hasReadLevel(us.permissions) || owner` (admin siempre).
- `can_clip` = `can_view_file` && `media_editor_enabled` && `sp.type === 'local'` (el cupo mensual se verifica en el endpoint, como hoy, y su rechazo se muestra con el mensaje del servidor).

La UI solo pinta según estos flags. Motivo: la regla de intersección vive en la costura; derivar capabilities en el navegador duplicaría reglas y derivaría con el tiempo. No se cambia la regla de negocio (transcription_access NO implica read): simplemente el cliente con transcription_access-sin-read sigue viendo la mención y la transcripción, sin acciones de archivo.

### D5 — Corte desde el modal: reuso del endpoint existente

`_clip-panel.blade.php` prellena `start = floor(ancla.start_seconds)`, `end = ceil(ancla.end_seconds)` (editables, validación cliente start<end) y llama al endpoint existente: primero `POST /files/{file}/clip` con `preview=true` (stream temporal vía `serveTemp`, ya implementado) y al confirmar sin `preview` (descarga final; respeta cupo y registra `MediaEditJob`). Estados Alpine en `clipPanel = { open, start, end, previewUrl, busy, error }`. No se replica FFmpeg ni thumbnails en el visor (los thumbnails del editor de archivos quedan fuera del panel mínimo v1).

Alternativa rechazada: navegar al editor de archivos con pre-selección — obliga a abrir un módulo distinto (lo que el negocio explícitamente no quiere) y el editor completo pesa demasiado para el flujo de una mención.

### D6 — Fix-along de acceso en `MediaClipController`

`clip`, `thumbnails`/`thumb` y `reclip` ganan un chequeo privado compartido: admin ∥ propietario del archivo ∥ permiso read en el storage del archivo (misma semántica que `FileController::checkFilePermission`, reutilizable extrayéndolo a un trait/concern si se prefiere — decisión menor del implementador). `history` ya filtra por `user_id`. Motivo para incluirlo aquí: el visor entrega ids de archivo a clientes de avisos; sin este cierre, un cliente con editor habilitado podría cortar archivos de storages que ni siquiera ve. Es un fortalecimiento compatible con la spec del editor (cortar en storages de solo lectura con permiso read sigue permitido).

### D7 — Frontend: partials, no frameworks nuevos

`app/resources/views/mis-avisos/index.blade.php` (hoy 598 líneas) se descompone en:

- `app/resources/views/mis-avisos/_table-hits.blade.php` — tabla compartida por 'live' e 'history' (props Alpine: `mode`), columnas fecha/hora | emisora | archivo | keyword | minuto | contexto | acciones; paginación server-side; fila clickable → modal.
- `app/resources/views/mis-avisos/_transcript-modal.blade.php` — modal con pestañas internas: transcripción (lista de segmentos) y reproductor sincronizado arriba cuando `meta.can_view_file`.
- `app/resources/views/mis-avisos/_clip-panel.blade.php` — panel de corte mínimo (D5).

Un solo componente Alpine raíz (`misAvisosPage()`) con sub-objetos de estado (`liveFilters`, `historyFilters`, `transcriptModal`, `clipPanel`) para no partir el estado entre componentes hermanos. Los filtros del histórico se sincronizan con la query string (`history.replaceState`) para link compartible; los del feed en vivo viven en memoria (polling). Patrón visual/estilos copiado del modal de revisión de `app/resources/views/ia/correcciones/index.blade.php` (lista de segmentos con índice y tiempos ya existe como referencia de UI).

### D8 — Feed en vivo filtrable y paginado

`todayHits(User, array $filters, int $perPage = 25)`: aplica `q` (misma condición snippet/segmento que `searchHistory`), `storage_ids` (intersección con accesibles), `keyword_id`; `whereDate(matched_at, today())`; retorna `LengthAwarePaginator` (mismo contrato que histórico → la tabla compartida consume lo mismo). El controlador `feed()` retorna `{ data, current_page, last_page, total, server_time }`. El polling (~20 s) re-pide la página activa con sus filtros; `total` incrementa → badge "N nuevas" sin romper la página vista. La ruta `/mis-avisos/feed` gana `throttle:30,1` (polling 3/min por pestaña, margen para varias pestañas) para simetría con el resto.

## Risks / Trade-offs

- [Transcripciones con miles de segmentos] → ventana anclada + cursores (D2), queries por range-scan indexado, sin agregaciones.
- [`text` de segmento puede ser enorme concatenado] → la ventana limita bytes por respuesta; `page` configurable en config si se observa presión.
- [Polling paginado puede "perder" hits nuevos si el cliente está en página 2] → badge con `total` en vez de auto-salto de página; comportamiento explícito en la spec (tabla ordenada por fecha desc).
- [El fix de MediaClipController (D6) cambia endpoints existentes] → solo agrega 403 para accesos que hoy ya son ilegítimos; smoke-test del editor en `/files` incluido en tasks.
- [`?t=` con archivos cuyo moov atom está al final] → el streaming ya usa X-Accel-Redirect con Range (soporte móvil existente); el seek en `loadedmetadata` evita el caso de metadata no cargada.
- [Doble reproductor (modal vs página)] → mismo endpoint de streaming, mismo `?t=`; el modal es lazy (no carga `<video>` hasta abrir) para no pesar la página principal.

## Migration Plan

1. Backend aditivo primero (costura + endpoints nuevos + fix `file_view_url` + D6), luego frontend. Sin migraciones de BD.
2. Despliegue único; rollback = revert del commit (sin datos en riesgo; los jobs de export no cambian).
3. Validación post-deploy: abrir una mención real → modal anclado; link `?t=` → seek correcto; corte de prueba con usuario admin; grep de logs sin errores nuevos.

## Open Questions

- Ninguna que bloquee specs o tasks. El tamaño exacto de ventana (`window`/`page`) es un valor de config ajustable post-deploy sin cambiar código.

## Evolución post-implementación (validación E2E con Playwright, 2026-09-06/07)

Decisiones que cambiaron o se agregaron tras validar con el usuario final (ver tasks.md §8 y evidencias/):

- **D3 modificada**: "Ver" unifica video+transcripción en el modal (autoplay en el minuto). El deep-link `/files/{id}/view?t=` queda como vía alternativa (y se corrigió su bug preexistente de JSON crudo en el atributo `x-data`).
- **D9 (nueva)**: deep-link "Archivos" a la carpeta contenedora (`/files?storage_id&folder&highlight_file`) con resaltado ámbar y scroll con reintentos; `restoreNavState()` blindado contra el deep-link y guardia `_initDone` porque Alpine ejecuta `init()` dos veces (auto + `x-init`).
- **D10 (nueva)**: botón "Volver a Mis Avisos" en el header del editor de corte, visible solo con origen avisos (`cameFromAvisos`).
- **D11 (nueva)**: `mediaKind()` decide video/audio por extensión — los mimes en BD están invertidos en cientos de miles de filas (.mp3 como video/mp4, .m4a como audio/mp4).
- **Fix-alongs adicionales de bugs preexistentes**: `scheduleCleanup` bloqueaba ~300s (exec sin redirigir pipes), `preview_url` sin extensión (404 siempre), export con filtro de emisora roto desde Fase 3 (closure sin capturar `$search`), `clip/thumbs/reclip` sin chequeo de acceso al archivo.
- **Hallazgo operativo**: hay otra sesión/agente editando este repo en paralelo (introdujo una regresión en `hitSelect` durante la implementación). El grep de costura (7.3) y la validación final la capturaron.
