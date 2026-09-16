# Tasks: Contexto del segmento en correcciones pendientes

## 1. Backend — poblar `source_segment_id` en producers

- [x] En `app/app/Services/Ia/EnEsMixMiner.php`, en el método que itera candidatos antes de llamar a `propose()`, agregar lookup:
  ```php
  $segmentId = DB::table('transcription_segments')
      ->whereRaw('text_raw ILIKE ?', ['%' . $this->escapeIlike($candidate['wrong']) . '%'])
      ->orderByDesc('created_at')
      ->value('id');
  ```
  Pasar `$segmentId` como 4to argumento a `propose()`.
- [x] Agregar helper `private function escapeIlike(string $s): string` que escape `%`, `_`, `\` para ILIKE.
- [x] Si no hay match (segmento purgado), pasar `null` y continuar sin error.
- [x] Mismo cambio en `app/app/Services/Ia/LlmCorrectionSuggester.php` antes del `propose()`.
- [ ] Verificar que el artisan command `corrections:mine-en-es` sigue funcionando con el nuevo argumento (correr batch pequeño en dev).
- [ ] Verificar que `corrections:ai-suggest` (o equivalente) sigue funcionando.

## 2. Backend — endpoint detalle de segmento

- [x] Agregar método `sourceSegment(int $id)` en `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Carga Correction con `sourceSegment.transcription`.
  - 404 si `source_segment` es null.
  - Devuelve JSON con `segment` (id, segment_index, start_seconds, end_seconds, text_raw, text) y `transcription` (id, file_name).
- [x] Agregar ruta en `app/routes/web.php`:
  ```php
  Route::get('/correcciones/{id}/source-segment', [CorreccionesController::class, 'sourceSegment'])->whereNumber('id');
  ```
- [x] En el método `pending()` del controller, cambiar `->with('proposedBy', 'sourceSegment')` a `->with('proposedBy', 'sourceSegment.transcription')`.
- [x] Mismo cambio en el método `approved()`.
- [ ] Validar con `php -l` los archivos modificados.
- [ ] Verificar con `php artisan route:list` que la nueva ruta está registrada.

## 3. UI — columna "Contexto" en tabla pendientes

- [x] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Agregar `<th>Contexto</th>` después de la columna Corrección en el `<thead>` del tab Pendientes. Mantener `hidden md:table-cell` para responsive.
  - Agregar `<td>` con `snippetHtml(c)` envuelto en `<button @click="openSegmentContext(c)">` que abre el modal.
  - Si `c.source_segment` es null, mostrar `<span class="text-slate-400 text-xs">—</span>`.
- [x] Agregar métodos Alpine en el data del componente:
  - `snippetHtml(c)` — centra ~100 chars alrededor del `wrong_text`, escapa HTML, marca con `<mark>` rojo.
  - `escapeHtml(s)` — escapa `&`, `<`, `>`, `"`, `'`.
- [ ] Verificar visualmente que el highlight se ve bien (rojo claro, padding mínimo, font-semibold).

## 4. UI — columna "Contexto" en tabla aprobadas

- [x] Mismo cambio (th + td + snippetHtml) en el `<thead>` y `<tbody>` del tab Aprobadas.
- [x] Las legacy (sin source_segment) muestran "—".

## 5. UI — modal "Contexto del segmento"

- [x] Agregar estado Alpine: `segmentContext: { open: false, loading: false, correction: null, data: null }`.
- [x] Agregar modal HTML después del modal "Eliminar en lote", mismo patrón visual:
  - Header: "Contexto del segmento".
  - Loading state con spinner.
  - Cuando cargó: header con timecode formateado (HH:MM:SS) y segment_index, link a `/ia/api-transcriptor/{transcription_id}` con file_name.
  - Sección "Original" con `text_raw` y `wrong_text` resaltado en rojo.
  - Sección "Corregido" (solo si `text !== text_raw`) con `text` y `correct_text` resaltado en verde.
  - Botón "Cerrar".
- [x] Handlers Alpine:
  - `openSegmentContext(c)` — abre modal, fetch al endpoint, guarda data.
  - `closeSegmentContext()` — resetea estado.
  - `formatHms(seconds)` — convierte segundos a `HH:MM:SS`.
  - `highlightedRaw()`, `highlightedText()` — wrappean `highlightInText()` con el color correspondiente.
  - `highlightInText(text, target, color)` — escapa, marca target con `<mark>` del color pedido.
- [x] CSS `max-height: 60vh; overflow-y: auto` en los `<p>` de Original/Corregido para textos largos.
- [x] Manejo de error: si el endpoint devuelve 404, mostrar mensaje "Esta corrección no tiene segmento origen (no se enlazó cuando fue creada)". No cerrar el modal.
- [x] Manejo de error de red: cerrar modal y alert.

## 6. Verificación end-to-end

- [x] Validar sintaxis: `php -l` sobre todos los archivos PHP modificados.
- [x] Validar JS: revisar sintaxis de los métodos Alpine (no es trivial validar sin lint, hacerlo manualmente).
- [ ] Probar manualmente:
  - Ir a `/ia/correcciones`, tab Pendientes.
  - Verificar que las nuevas correcciones (generadas con el miner/AI Suggest post-deploy) muestran snippet con highlight.
  - Click en un snippet → modal se abre con timecode + texto.
  - Cerrar modal → estado se resetea.
  - Verificar que correcciones legacy (sin source_segment_id) muestran "—".
- [ ] Tab Aprobadas: verificar que las nuevas aprobadas muestran snippet, las legacy muestran "—".
- [ ] Probar en móvil: la columna debe estar `hidden md:table-cell` (no rompe layout).
- [ ] Probar edge case: si `wrong_text` no aparece textualmente en `text_raw`, debe mostrar el text_raw completo sin highlight y tooltip.
- [ ] Medir duración del miner antes/después (debe incrementarse <500ms para corridas típicas).

## 7. (Opcional) Tour guiado

- [x] Verificar si `TcloudTour` tiene paso del módulo correcciones. Si sí, agregar mención a la nueva columna y modal.
  N/A: grep en `app/resources/views/ia/correcciones/index.blade.php` no encuentra `TcloudTour`. El módulo correcciones no tiene tour interactivo (consistente con `corrections-pending-edit-delete` y `corrections-context-examples` previos). Constraint `interactive_tours_must_include_new_features` no aplica.
- [x] Si no hay tour, marcar N/A (constraint aplica solo a módulos que ya tienen tour).
