## Why

Hoy el Variation Finder muestra 100+ variantes de la palabra buscada con su estado de cobertura. El admin tiene que leer cada fila, decidir si es un typo válido del nombre buscado, y agrupar las que comparten `correct` para hacer bulk-create. En la prueba con "abelardo" había ~80 variantes "Sin regla" y el admin tuvo que identificar manualmente las 20 más frecuentes para crear reglas.

Esto escala mal: con corpus grande (cientos de miles de segmentos) y nombres largos con muchos typos fonéticos, el admin revisa cientos de filas en vez de validar lo que el LLM ya podría haber agrupado.

## What Changes

Una nueva sección **"AI Suggest"** dentro del panel Variation Finder que:

1. **Botón "Sugerir correcciones con IA"** debajo del input principal (visible sólo si `llm-correction.enabled = 1` y la búsqueda ya devolvió resultados).
2. **Backend endpoint** `POST /ia/correcciones/variations/ai-suggest`:
   - Recibe `{ word, since, limit, min_count }` y reusa la query existente para traer las variantes sin regla.
   - Las pasa al LLM con un prompt especializado: "Estas son variantes literales de '{word}'. Agrupá las que son typos fonéticos o variantes tipográficas del MISMO nombre y proponé una normalización canónica para cada grupo."
   - Devuelve `{ groups: [{ canonical_correct, variants: [{wrong, count, confidence, reason}], tokens_used, latency_ms, model }`.
3. **UI del AI Suggest** (modal o panel expandible):
   - Lista de grupos propuestos, cada grupo con su `canonical_correct` y las variantes incluidas.
   - Por cada variante: checkbox pre-marcado según `confidence` (≥0.8 = marcado, <0.8 = sin marcar).
   - Edit inline del `canonical_correct` por grupo (por si el LLM se equivoca).
   - Botón "Crear N correcciones para este grupo" → usa el endpoint bulk-create existente.
   - Botón "Crear todas las marcadas" → itera grupos seleccionados y crea N correcciones pending en bulk.
4. **Costo controlado**: el endpoint pide al admin confirmar antes de gastar tokens (preview de "se procesarán X variantes con modelo Y, ~Z tokens estimados").
5. **Idempotente**: usa el `findVariations` existente para evitar query duplicada.
6. **Respeta gates existentes**: `system_settings.llm-correction.enabled = 1` y API key configurada. Si están OFF, el botón está deshabilitado con tooltip.

## Capabilities

### New Capabilities
- `corrections-variation-finder-ai-suggest`: LLM-powered grouping + normalization suggestion de variantes literales dentro del Variation Finder. Distinto de `llm-correction-suggestion` (escaneo global, sin scope) y de `corrections-ai-context-aware-with-mark-curation` (corrección por ejemplo con contexto). Éste agrupa variantes descubiertas por SQL y pide al LLM sólo el `correct` canónico.

## Impact

- **Código**:
  - `app/app/Services/Ia/AiVariationGrouperService.php`: nuevo servicio que arma el prompt, llama al LLM, parsea la respuesta JSON.
  - `app/app/Http/Controllers/Ia/CorreccionesController.php`: nuevo método `variationsAiSuggest(Request)` que orquesta findVariations + AiVariationGrouperService.
  - `app/resources/views/ia/correcciones/index.blade.php`: nueva sección "AI Suggest" en el panel del Variation Finder con botón + modal de resultados.
  - `app/routes/web.php`: nueva ruta `POST /ia/correcciones/variations/ai-suggest`.
- **APIs/Routes**: 1 nuevo endpoint POST. Sin breaking changes.
- **Migraciones**: ninguna.
- **Tests**: nuevo test file `tests/Feature/AiVariationGrouperServiceTest.php` para el parser de respuesta JSON del LLM (con respuestas mockeadas).
- **Costo**: el admin decide cuándo gastar tokens. Típicamente 1 llamada al LLM por búsqueda (~$0.001-$0.01 por query según modelo).

## Non-goals

- **No reemplaza el flujo manual** del Variation Finder — es un atajo opcional para reducir el ruido.
- **No es fuzzy matching** (Levenshtein) — el LLM recibe variantes literales y decide cuál es el typo de cuál. Si dos variantes son "diferentes fonéticamente" pero no en la lista, el LLM no las va a inventar.
- **No edita reglas existentes** — sólo propone reglas NUEVAS para variantes "Sin regla".
- **No toca el motor de apply** — sigue siendo `CorrectionService::applyRetroactively()`.
- **No reemplaza AI Suggest global** (`corrections:ai-suggest`) — siguen casos de uso distintos.
