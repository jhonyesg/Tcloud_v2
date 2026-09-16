## Why

El admin revisa las reglas pendientes en `/ia/correcciones` abriendo el modal "Ejemplos en transcripciones" — ve segmentos reales donde la regla disparó, comprueba "cómo quedaría con esta regla", y detecta que el resultado sigue siendo espanglish incorrecto. Hoy no hay un camino para que el admin **delegue esa frase concreta al LLM** y obtenga una corrección contextualizada al español bien que pueda evaluar y, si le sirve, añadir al diccionario como regla nueva. Sin esa vía, las reglas pendientes se quedan en un limbo: o se aprueban sabiendo que dejarán texto mal traducido, o se rechazan y el segmento nunca se corrige. La búsqueda de contexto ya es rápida (cambio `corrections-manual-only-and-context-search`, 2026-09-05: índices GIN trgm + sin crons automáticos), así que el input para el LLM ya es barato de recuperar.

## What Changes

- Botón **"Corregir esta frase con IA"** por ejemplo dentro del modal "Ejemplos en transcripciones" (`/ia/correcciones → Pendientes → [Editar] → Contexto`). Llama a un endpoint que envía al LLM el `text_raw` del segmento + la regla padre y devuelve una corrección atómica (`wrong → correct`) en español bien.
- Cuando el admin acepta el resultado, se inserta una corrección nueva con `status='pending'` y `source='ai-context-correct-YYYY-MM-DD'`. El admin la aprueba manualmente desde la lista de pendientes como cualquier otra (consistente con la política manual-only del 2026-08-21/2026-09-05).
- Un solo intento por click. Si el resultado no convence, el botón "Reintentar" fuerza un nuevo LLM call (consume tokens). Re-abrir el modal reutiliza la última respuesta cacheada por `(correction_id, example_id)` con TTL 24 h para no re-gastar tokens.
- Tres gates de seguridad: (1) master switch `llm-correction.enabled=1` en BD (post-2026-09-05 está en 0; el admin lo activa para usar), (2) API key configurada, (3) post-filtro defensivo PHP para rechazar marcas, nombres propios y siglas (mismo patrón que `LlmCorrectionSuggester`).
- Actualizar la skill `.kilocode/skills/corrections-ai-suggest/SKILL.md` para que cubra también este nuevo flujo (mismo módulo conceptual: AI aplicado al contexto, con gates idénticos).

## Capabilities

### New Capabilities
- `corrections-ai-context-correct`: corrección inline de un ejemplo individual con LLM, desde el modal de contexto, con persistencia como regla pending y caché por (corrección, ejemplo).

### Modified Capabilities
- `llm-correction-suggestion`: añadir la familia de reglas `ai-context-correct-*` (nuevo origen) bajo la misma política master switch OFF-by-default; el nuevo flujo se expone como `CorrectionsAiContextCorrectInline` (nuevo).
- `correcciones-review-srt-inline`: el modal de Contexto añade botones "Corregir esta frase con IA" + resultado inline + acciones "Aprobar y crear regla" / "Solo ver" / "Descartar" / "Reintentar".

## Impact

- Nuevos archivos:
  - `app/app/Services/Ia/AiContextCorrectService.php` (servicio: orquesta cache + LLM call + post-filtro + persistencia).
  - `app/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php` (endpoint REST).
  - `app/tests/Unit/CorrectionsAiContextCorrectTest.php` (tests del servicio + cache + gates).
- Archivos modificados:
  - `app/routes/web.php`: nueva ruta bajo el grupo de `/ia/correcciones`.
  - `app/resources/views/ia/correcciones/index.blade.php`: integración del modal (botones + estado Alpine.js).
  - `app/app/Services/Ia/LlmCorrectionSettings.php`: sin cambios de API; sigue exponiendo `bool('enabled')`, `apiKey()`, `primary_model`, etc.
  - `app/app/Console/Commands/...`: nada — el flujo es 100% manual bajo pedido.
  - `.kilocode/skills/corrections-ai-suggest/SKILL.md`: añadir sección "Corrección inline desde el modal de contexto".
- Caché: nuevo `cache_ttl` configurable (`config('corrections.ai_context_correct.cache_ttl', 86400)`). Reusa `Cache::store()` existente (Redis en prod).
- Modelo: `Correction` ya soporta `status='pending'` + `source` libre; ningún cambio de schema.
- LLM: usa el mismo flujo que `aiSuggestNow` — `LlmCorrectionSettings` + `CallsLlmChatCompletion` trait. Sin nuevos providers.
- Sin migraciones de BD.

## Non-goals

- No automatiza la corrección; el admin sigue siendo el gatekeeper y pulsa cada botón.
- No traduce la transcripción completa de un archivo; opera frase a frase, ejemplo a ejemplo.
- No crea estado `superseded` ni relaciones padre-hija en `corrections`; las hijas son entradas independientes filtradas por `source`.
- No cambia `apply-corrections` ni `transcription:tick`; el resultado entra al diccionario por la vía normal de aprobación manual.
- No emite LLM tokens automáticos: el botón solo dispara cuando el admin lo pulsa.
- No replica el suggester global (`corrections:ai-suggest`) ni el auto-cycle: cada flujo tiene su origen de datos y su UI.