## 1. Estado Alpine y handlers

- [x] 1.1 Agregar al objeto Alpine `correccionesAdmin()` las propiedades `showFullTranscript: false`, `transcriptLoading: false`, `transcriptData: null`.
- [x] 1.2 Implementar el handler `toggleFullTranscript()` que hace fetch lazy a `/api-transcriptor/jobs/{transcriptionReviewDetail.id}/transcript` si `transcriptData` es null, alterna `showFullTranscript`, y maneja el spinner.
- [x] 1.3 En el handler que cierra el modal de revisión, resetear `showFullTranscript`, `transcriptData` y `transcriptLoading` para evitar mezclar transcripts entre sesiones.

## 2. Reemplazo del `<a target="_blank">` por toggle inline

- [x] 2.1 En `index.blade.php` línea ~554, reemplazar el `<a target="_blank">` por un `<button @click="toggleFullTranscript()">` con label dinámico ("Ver SRT completo" / "Ocultar SRT completo") y spinner durante `transcriptLoading`.
- [x] 2.2 Agregar como acción secundaria un `<a target="_blank" class="text-xs text-slate-400 hover:text-brand-600">↗ SRT original</a>` que abra `/ia/api-transcriptor/jobs/{id}` para quien quiera ver el detalle completo en página aparte.

## 3. Panel expandible con segmentos

- [x] 3.1 Debajo del bloque de "Decisión", agregar `<div x-show="showFullTranscript" x-cloak role="region" aria-label="Transcripción completa">` con scroll interno `max-h-96 overflow-y-auto`.
- [x] 3.2 Dentro del panel: estado de carga (`fa-spinner fa-spin` mientras `transcriptLoading`), estado vacío (si `transcriptData?.segments` está vacío), y la lista de segmentos renderizada con `<template x-for>` mostrando `[start_label]` en `font-mono text-xs text-slate-400` y `seg.text` en `text-sm text-slate-700`.
- [x] 3.3 Mostrar aviso si el endpoint indica truncamiento (`x-show="transcriptData?.truncated"` con "Mostrando primeros N segmentos").

## 4. Validación

- [x] 4.1 Compilar la vista con `php artisan view:cache` y validar el JS con `node --check` sobre el componente Alpine extraído.
- [x] 4.2 Hacer un diff de git sobre `index.blade.php` y verificar que solo cambia el modal de revisión (no se toca ninguna otra sección de la vista).
- [x] 4.3 Actualizar `tasks.md` con las tareas completadas y dejar el change listo para archivar.
