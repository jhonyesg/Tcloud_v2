## Context

El modal de detalle de revisión de transcripciones (`/ia/correcciones → Revisar transcripciones → fila`) tiene actualmente un enlace `<a target="_blank">` que abre la página del job en otra pestaña. Eso rompe el flujo porque el admin pierde el contexto (decisión pendiente, notas, scroll de segmentos cambiados).

El backend ya expone `GET /api-transcriptor/jobs/{id}/transcript` (en `routes/web.php:193`, método `ApiTranscriptorController::transcript`) que devuelve `srt_content`, `plain_text` y `segments[]` con `segment_index`, `start_label`, `end_label`, `text`. Reutilizamos ese endpoint sin tocar backend.

## Goals / Non-Goals

**Goals:**
- Reemplazar el `<a target="_blank">` por un toggle inline dentro del mismo modal.
- Fetch lazy al endpoint JSON existente con cache por modal abierto.
- Mantener el acceso a la página completa como acción secundaria opcional.

**Non-Goals:**
- No cambiar el endpoint `/api-transcriptor/jobs/{id}/transcript` ni su paginación (`TRANSCRIPT_SEGMENTS_MAX`).
- No tocar el resto del modal (segmentos cambiados, decisión, notas).
- No mover el panel a otra ruta.

## Decisions

### 1. Toggle inline en lugar de `<a target="_blank">`
- Antes: `<a href="..." target="_blank">` (línea ~554) — rompe el flujo.
- Ahora: `<button @click="toggleFullTranscript()">` + bloque `<div x-show="showFullTranscript">`.
- Alternativa considerada: `<iframe src="...">` — descartada por problemas de sandbox, altura y consistencia con el resto del modal.

### 2. Fetch lazy con cache por sesión de modal
- Estado Alpine nuevo: `showFullTranscript`, `transcriptLoading`, `transcriptData`.
- `toggleFullTranscript()`: si `!transcriptData`, hace fetch; si ya hay data, solo alterna `showFullTranscript`.
- Cache se descarta en el handler que cierra el modal (`closeTranscriptionReviewModal`) → resetea las 3 vars a sus valores iniciales.
- Alternativa: cache por transcripción en `Map<id, data>` — descartada por simplicidad (el admin rara vez reabre el mismo modal en una sesión corta).

### 3. Endpoint reutilizado sin cambios
- `GET /api-transcriptor/jobs/{id}/transcript` ya existe (Alpine `apiFetch` con cookies/CSRF).
- No requiere cambios en backend, controllers, ni rutas.

### 4. Render del SRT: segmentos con timestamp + texto
- Lista `<div class="max-h-96 overflow-y-auto">` con `<div class="font-mono text-xs text-slate-400">[start_label]</div> <div x-text="seg.text"></div>` por segmento.
- Si el endpoint devuelve `truncated` (no en la respuesta actual pero mencionado en el controller), mostrar aviso "Mostrando primeros N segmentos".
- Plano: no usar tabla ni `<table>` (el contenido es prosa, no filas).

### 5. Acción secundaria "SRT original"
- Botón pequeño al lado del toggle: `<a target="_blank" class="text-xs text-slate-400 hover:text-brand-600">↗ SRT original</a>`.
- Abre `/ia/api-transcriptor/jobs/{id}` en nueva pestaña para el admin que quiera el detalle completo.

## Risks / Trade-offs

- [SRT largo (>1000 segmentos)] → El endpoint ya trunca con `TRANSCRIPT_SEGMENTS_MAX`. El contenedor `max-h-96 overflow-y-auto` evita que la tabla crezca.
- [Fetch lento en modal] → Mostrar spinner `fa-spinner fa-spin` durante `transcriptLoading`; el admin puede cerrar el panel si tarda.
- [Cache cross-modal] → Se descarta al cerrar el modal, evitando que la próxima apertura de OTRA transcripción muestre datos del SRT anterior.
- [Accesibilidad] → El toggle usa `<button>` (no `<a>`) para que screen readers lo anuncien como control. El panel tiene `role="region" aria-label="Transcripción completa"`.

## Migration Plan

- Deploy: editar `app/resources/views/ia/correcciones/index.blade.php` (un solo archivo). No requiere migraciones ni cambios en backend.
- Rollback: revertir el commit. La UI vuelve al `<a target="_blank">` original.

## Open Questions

Ninguno.
