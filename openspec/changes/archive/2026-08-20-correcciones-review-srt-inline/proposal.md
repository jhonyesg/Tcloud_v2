## Why

En la pestaña "Revisar transcripciones" de `/ia/correcciones`, el modal de detalle tiene un botón "Ver SRT completo" que es un `<a target="_blank">` que abre la página del job en `/ia/api-transcriptor/jobs/{id}`. Esto saca al admin del modal y rompe el flujo de revisión (pierde el scroll de segmentos cambiados, la decisión, las notas). El endpoint JSON `GET /api-transcriptor/jobs/{id}/transcript` ya existe y devuelve `srt_content`, `plain_text` y `segments` con timestamps.

## What Changes

- Convertir el botón "Ver SRT completo" del modal de detalle de revisión en un toggle que expande un panel dentro del mismo modal, cargando el SRT vía AJAX al endpoint JSON existente.
- El panel muestra los segmentos con `start_label → text` en un `<div>` con scroll interno, sin salir del modal.
- Mantener el enlace al SRT completo en pestaña nueva como acción secundaria (botón pequeño "SRT original"), por si el admin quiere ver el detalle en la página completa.
- Carga lazy del transcript: el primer click hace fetch al endpoint; los siguientes toggles usan el cache local hasta cerrar el modal.

## Capabilities

### New Capabilities

- `correcciones-review-srt-inline`: panel inline en el modal de revisión de transcripciones que muestra el SRT completo sin navegar a otra página.

### Modified Capabilities

_Ninguno._ El cambio es puramente UI en una vista existente; no modifica ningún requirement de las specs actuales (la spec `transcription-corrections` no cubre esta interacción de UI).

## Impact

- `app/resources/views/ia/correcciones/index.blade.php` — reemplazo del `<a target="_blank">` (línea ~554) por un botón toggle + nuevo bloque expandible con scroll.
- Estado Alpine nuevo: `showFullTranscript`, `transcriptLoading`, `transcriptData`.
- Sin cambios en backend (reutiliza `GET /api-transcriptor/jobs/{id}/transcript`).
- Sin migración.
- No afecta `CorreccionesController` ni `ApiTranscriptorController`.

## Non-Goals

- No modifica el endpoint `/transcript` ni el límite `TRANSCRIPT_SEGMENTS_MAX`.
- No cambia el modal de detalle en sí (segmentos cambiados, decisión, notas siguen iguales).
- No añade descarga del SRT desde el modal (botón secundario ya cubre abrir en nueva pestaña).
- No toca la página `/ia/api-transcriptor/jobs/{id}` (queda accesible vía el botón "SRT original").
