# Design: corrections-ai-context-correct-inline

## Context

Ver proposal.md — Why. Estado previo verificado en código:

- El modal de contexto (`/ia/correcciones → Pendientes → [Contexto]`) muestra una lista de ejemplos con "Como lo transcribió" y "Cómo quedaría con esta regla" (`app/resources/views/ia/correcciones/index.blade.php:1820-1838`). Cada ejemplo es un objeto `{id, transcription_id, segment_index, text_raw, text, file_name, ...}` servido por `GET /ia/correcciones/{id}/contexto` (`app/app/Http/Controllers/Ia/CorreccionesController.php:127`) a través de `CorrectionContextFinder` (ahora rápido con índices GIN trgm del 2026-09-05).
- El suggester global (`LlmCorrectionSuggester`, `app/app/Services/Ia/LlmCorrectionSuggester.php:455`) ya tiene `looksLikeBrandOrProperNoun()` y el filtro contra `config('llm-correction.protected_brands')` — lo reutilizamos.
- El gate "503 si falta switch o key" ya existe en `aiSuggestNow` (CorreccionesController:1422-1438). Reusamos el mismo patrón.
- Master switch: `LlmCorrectionSettings::bool('enabled')`; tras el change del 2026-09-05 está persistido en `=0`, así que el flujo nuevo estará bloqueado hasta que el admin lo active desde `/ia/correcciones → IA Suggest`.

## Goals / Non-Goals

Goals:
- Endpoint único por ejemplo con TTL de cache 24 h.
- Post-filtro defensivo idéntico al suggester global.
- Persistencia de aprobaciones como `pending` con `source='ai-context-correct-YYYY-MM-DD'`.
- UX inline sobre el ejemplo concreto (no en lote).

Non-Goals:
- Variantes de 3 opciones por click, regenerar con temperatura, o A/B de modelos.
- Auto-aprobación: las hijas siempre son `pending`.
- Estado `superseded` ni relación padre-hija persistida.
- Cambios en `LlmCorrectionSettings`, `Correction` model, migraciones, ni en el suggester global.

## Decisions

### D1 — Servicio dedicado `AiContextCorrectService`
Reutiliza `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` para el post-filtro y `LlmCorrectionSettings` + `CallsLlmChatCompletion` para el LLM. La diferencia con el suggester global es:
- Input atómico (un solo segmento) en vez de muestra de 200 segmentos.
- Output atómico (`{wrong, correct}` con `wrong === text_raw`) en vez de lista de candidatos.
- Cache por `(correction_id, example_id, today)` con TTL configurable.

Se opta por un servicio nuevo en vez de reusar `LlmCorrectionSuggester` porque las firmas de entrada/salida divergen y `LlmCorrectionSuggester` ya tiene 470 líneas con bastante lógica específica de minería.

### D2 — Cache por ejemplo, invalidable
Clave: `ai_context_correct:{correction_id}:{example_id}:{YYYY-MM-DD}`. TTL: `config('corrections.ai_context_correct.cache_ttl', 86400)` (24 h). La fecha entra en la clave para que un ejemplo servido ayer no se mezcle con la respuesta de hoy (si el admin re-corre). El botón "Reintentar" hace `Cache::forget()` antes de la llamada para forzar LLM call fresco.

Por qué incluir fecha: si la regla padre cambió desde ayer y el admin reabre el modal, queremos una respuesta nueva. Si la regla no cambió, el TTL de 24 h es suficiente.

### D3 — Post-filtro idéntico al suggester global
Reusamos `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` sin duplicar la lógica. Si el LLM devuelve un candidato que matchea una marca/nombre propio, el servicio responde 200 con `{ok: false, reason: "El LLM propuso modificar una marca o nombre propio; candidato descartado."}` y la UI muestra un mensaje neutro + botón "Reintentar".

### D4 — Persistencia como pending con origen trazable
Aprobación: `Correction::create(['wrong_text' => $wrong, 'correct_text' => $correct, 'wrong_normalized' => $normalized, 'status' => 'pending', 'risk_level' => $risk, 'proposed_by' => $adminId, 'source' => 'ai-context-correct-' . today, 'applies_count' => 0])`. `risk_level` se deriva del LLM: si reporta cambio de marca el LLM devuelve `risk='high'`; si es una traducción pura, default `medium` (alineado con el resto del módulo). Idempotencia: query `Correction::whereIn('wrong_normalized', [...])->whereIn('status',['pending','approved'])` antes de insertar; si hay colisión, 409 con `existing_id`.

### D5 — Endpoints RESTful bajo `/ia/correcciones`
- `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` → 200 con corrección o 503 (gates) o 502 (LLM HTTP error) o 504 (timeout). La validación del `(correctionId, exampleId)` resuelve el segmento real desde el contexto cacheado de la corrección (`CorrectionContextFinder::examples()`) y rechaza 404 si el exampleId no pertenece a la corrección padre.
- `POST .../ai-context-correct/approve` → 201 con `{correction_id}` o 409 (duplicado) o 422 (validación del cuerpo).

### D6 — UI inline sobre el ejemplo (no en lote)
Un botón "Corregir esta frase con IA" pequeño, justo debajo del bloque "Cómo quedaría con esta regla" del ejemplo. Al click, el bloque se reemplaza por un panel con la respuesta + tres acciones. El estado vive en el componente Alpine `contextModal` y se keyea por `example.id`. Re-abrir el modal restaura el último resultado desde cache.

Esto evita ensanchar la modal con un panel global y mantiene el flujo natural: el admin lee el ejemplo y, si no le gusta la salida, dispara la IA.

### D7 — Sin jobId async
El endpoint es síncrono (5-30 s). El cliente (Alpine) muestra un spinner y, si vence el timeout de fetch del navegador (default sin valor; apiFetch no impone), la respuesta del LLM queda parcialmente cacheada: el admin puede re-abrir el modal y la cache le mostrará el último resultado. El timeout interno del LLM (`LLM_TIMEOUT_SECONDS`, default 60 s) actúa como red de seguridad.

### D8 — Botón bloqueado si falta API key
El endpoint `/ia/correcciones/ai-suggest-status` ya devuelve `api_key_source` y `has_api_key`. La UI hace `if (!aiSuggestStatus?.has_api_key) disable-button` y muestra tooltip. Sin fetch al endpoint de corrección, así no se gasta un ciclo HTTP en algo que va a fallar.

## Risks / Trade-offs

- [Token por ejemplo] → Cada click consume 1 LLM call (~1-5 k tokens). El admin debe saber que cada "Reintentar" cuesta. La UI muestra el contador `tokens_used` en la respuesta y un log operativo lo registra.
- [Cache miss si el admin re-abre al día siguiente] → Aceptado: 24 h es un balance razonable. Si el admin quiere resultado fresco al día siguiente, pulsa "Reintentar".
- [Post-filtro demasiado agresivo] → Si el LLM devuelve una corrección útil pero técnicamente toca una marca, el filtro la descarta. Aceptamos eso como defensa-en-profundidad — el admin puede reintentar con un prompt más permisivo en el futuro (out of scope).
- [Sin historial de iteraciones] → La cache solo guarda la última respuesta. Si el admin reintenta 3 veces, solo la última queda. Es por diseño (cuesta almacenar).
- [Cache store] → Reusa Redis (Cache::store('default') en prod). Sin nuevo almacenamiento.

## Migration Plan

Sin migraciones de BD. Deploy:
1. Code: nuevo servicio + controlador + ruta + UI + skill.
2. `php artisan migrate` (ninguna nueva).
3. Verificación manual: admin activa `llm-correction.enabled` en `/ia/correcciones → IA Suggest`, abre "Contexto" de una pendiente, click "Corregir esta frase con IA" sobre un ejemplo, valida el resultado.
4. Rollback: `git revert` + eliminar las dos rutas. La cache `ai_context_correct:*` en Redis queda huérfana pero expira sola en 24 h.

## Open Questions

Ninguna.
