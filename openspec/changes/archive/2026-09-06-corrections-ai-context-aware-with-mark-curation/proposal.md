## Why

El flujo actual de corrección IA inline (change `corrections-ai-context-correct-inline`, archivado el 2026-09-05) llama al LLM con `wrong_text` + `text_raw` del segmento aislado. Validado por emulación con Playwright, el LLM responde "Fragmento en inglés sin contenido en español discernible; se conserva el texto original ante la imposibilidad de reconstruir el segmento sin inventar información" — porque **no tiene contexto adyacente**. La transcripción completa, los segmentos vecinos y los nombres de archivo están disponibles en la BD pero no llegan al prompt. Resultado: el admin puede traducir/corregir, pero solo a costa de reintentar hasta dar con un segmento donde el contexto interno baste.

Adicionalmente, la UI actual cae en patrones AI-generados que la skill `frontend-design` marca como tells (cream background, ALL CAPS, SaaS-card kit con tres tarjetas idénticas y borde-radius uniforme). El cambio mejora **el prompt Y el modal**, ambos diseñados con la skill.

## What Changes

- **Vecinos ±5 segmentos al prompt**: el servicio consulta `transcription_segments` por `transcription_id` ordenado por `segment_index` y construye un snippet de contexto (5 anteriores + 5 siguientes + segmento objetivo) que se antepone al `text_raw` en el system prompt. El LLM ahora puede reconstruir el segmento en español cuando los vecinos mencionan el dominio (combustible, muerte, ventas — el caso de la captura).
- **Curación de marcas en dos modos**:
  - **Manual**: el admin selecciona una palabra del `text_raw` (texto seleccionado nativamente) y pulsa "Proteger marca" → POST al endpoint `/ia/correcciones/protected-terms` (ya existe `CorrectionProtectedTermsService`).
  - **Sugerido por LLM**: nuevo botón "Detectar marcas en este segmento" → segunda llamada LLM con prompt "lista tokens que parezcan marca/sigla/nombre propio en el siguiente texto" → checkboxes en la UI → admin confirma → mismo endpoint.
- **Traducción como regla `wrong → correct` del segmento completo**: el botón "Aprobar y crear regla" persiste la corrección con `wrong_text = text_raw` (puede ser todo el segmento) y `correct_text = traducción IA`. El motor `applyToText` ya hace búsqueda por substring; un `wrong` largo es más restrictivo y baja falsos positivos. Esto cubre el caso "vamos a traducirla para agregarla basada en el contexto".
- **Reducir UI a un patrón de timeline**: una sola columna por ejemplo (texto con highlights + botón inline al lado de la palabra seleccionada). Botones Aprobar / Proteger / Reintentar en una barra compacta inferior por ejemplo. Sin ALL CAPS, sin tres tarjetas idénticas, sin emojis como decoración.
- **Misma política manual-only**: el master switch `llm-correction.enabled` y la API key siguen siendo los únicos gates. Coste por click con vecinos ~1,5-2k tokens. Cache 24 h por `(correction_id, segment_id, date)`; reintentar consume. La skill `corrections-ai-suggest` se actualiza con la sección del nuevo flujo.

## Capabilities

### New Capabilities
- `corrections-context-aware-correction`: el servicio envía vecinos ±5 y devuelve una corrección que puede ser regla atómica, traducción del segmento completo, o "no se puede reconstruir" honesto. Persistencia como regla wrong→correct.
- `corrections-mark-curation-inline`: curación de marcas protegidas desde el modal de contexto, manual (selección de texto) y LLM-sugerida, vía `CorrectionProtectedTermsService`.

### Modified Capabilities
- `corrections-ai-context-correct`: el flujo existente añade un parámetro `neighbor_window` (default 5), reescribe el system prompt para reflejar el snippet de contexto, y mueve el botón "Aprobar y crear regla" al modelo `wrong=segmento_largo, correct=traducción`.
- `correcciones-review-srt-inline`: el modal de Contexto se rediseña como timeline (un renglón por ejemplo con highlights) y reemplaza los tres botones Aprobar/Solo ver/Reintentar por una barra compacta de acciones contextuales.

## Impact

- Nuevos archivos:
  - `app/app/Services/Ia/AiContextAwareService.php` (deep module: una sola entrada `correctExample`, varios métodos privados).
  - `app/app/Services/Ia/AiBrandSuggestionService.php` (módulo separado, interfaz estrecha `suggestBrands(string $text): array`).
  - `app/app/Http/Controllers/Ia/CorreccionesAiContextAwareController.php`.
  - `app/app/Http/Controllers/Ia/ProtectedTermsInlineController.php` (POST /ia/correcciones/protected-terms inline).
  - `app/tests/Unit/AiContextAwareServiceContractTest.php`.
  - `app/tests/Unit/AiBrandSuggestionServiceContractTest.php`.
- Archivos modificados:
  - `app/app/Services/Ia/AiContextCorrectService.php`: deprecación gradual (se conserva como wrapper que delega al nuevo con vecinos default 0).
  - `app/app/Services/IA/CorrectionProtectedTermsService.php`: añadir método público `addFromModal(string $term, ?int $userId): array` con guardrail anti-duplicados.
  - `app/routes/web.php`: dos rutas nuevas + una ruta del wrapper deprecado.
  - `app/resources/views/ia/correcciones/index.blade.php`: rediseño del modal de contexto. Plantillas Inline + Alpine `brandSuggestions` + text selection handler.
  - `app/public/css/...`: tipografía y espaciado del nuevo modal según `frontend-design` skill.
  - `.kilocode/skills/corrections-ai-suggest/SKILL.md`: añadir "Corrección IA con contexto ampliado y curación de marcas".
- Sin migraciones de BD (la tabla de protected terms ya existe).
- Coste por click: ~1,5-2k tokens vs ~1k actual (vecinos añaden contexto). Cache 24 h.

## Non-Goals

- No cambia el motor de apply (`apply-corrections`); consume la nueva regla como cualquier otra.
- No automatiza curación: el admin sigue siendo quien decide qué marca entra.
- No reescribe las 27 reglas pendientes automáticamente; cada corrección IA es per-ejemplo.
- No introduce nuevo `status` en `corrections` ni tablas nuevas; usa `source='ai-context-correct-context-YYYY-MM-DD'`.
- No traducir transcripciones completas de un archivo: sigue siendo per-ejemplo.
- No reemplaza al suggester global (`corrections:ai-suggest`); ambos coexisten con orígenes diferentes.
