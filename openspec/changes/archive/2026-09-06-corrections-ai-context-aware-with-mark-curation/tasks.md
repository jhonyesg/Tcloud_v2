# Tasks: corrections-ai-context-aware-with-mark-curation

## 1. Servicio deep module AiContextAwareService

- [x] 1.1 Crear `app/app/Services/Ia/AiContextAwareService.php` con **una sola entrada pública** `correctExample(Correction $parent, array $example, bool $forceFresh = false, ?int $neighborWindow = 5): array`. Todo lo demás (vecinos, prompt, gate, cache, post-filtro, llamada LLM, mapeo de errores) queda privado. Aplicar la skill `codebase-design` literalmente: depth en la interfaz, no en la implementación.
- [x] 1.2 Implementar `findNeighbors(transcriptionId, segmentIndex, halfWindow): array` privado — single-shot `WHERE segment_index BETWEEN ? AND ?` ordenado en PHP.
- [x] 1.3 Implementar `buildSystemPrompt(array $neighbors, array $parent, array $targetExample): string` privado con 4 capas (rol, snippet con etiquetas `#[-5]…#[+5]` `#[OBJETIVO]`, regla padre, schema de respuesta JSON).
- [x] 1.4 Implementar `buildUserPrompt(array $neighbors, array $parent, array $targetExample): string` privado.
- [x] 1.5 Implementar `cacheKey(parent, example, neighborWindow, date): string` y `cacheTtl()` privado.
- [x] 1.6 Implementar `looksLikeProtectedBrand(string $value): bool` privado que reusa `CorrectionProtectedTermsService::terms()` (sin filtro de longitud).
- [x] 1.7 Implementar `mapLlmError(Throwable $e): array` privado (mapea 401/403/5xx/timeout a 503/502/504 contract).

## 2. AiBrandSuggestionService y endpoint protected-terms

- [x] 2.1 Crear `app/app/Services/Ia/AiBrandSuggestionService.php` con **una sola entrada pública** `suggestBrands(string $text): array<int, string>`. Cache `ai_brand_suggest:{sha256(text)}:{date}` TTL 3600s. Post-filtro: excluye marcas ya en `CorrectionProtectedTermsService::terms()`.
- [x] 2.2 Crear `app/app/Http/Controllers/Ia/ProtectedTermsInlineController.php` con método `store(Request $request)` que valida el término (≥2 chars, no stopword común) y llama a `CorrectionProtectedTermsService::addFromModal($term, $exampleId, $userId)`.
- [x] 2.3 Añadir método `addFromModal(string $term, ?int $exampleId, int $userId): array` a `app/app/Services/Ia/CorrectionProtectedTermsService.php` con guardrail de duplicados (case-insensitive SELECT o UPSERT) y refresh de la cache interna del service. Devuelve `['term' => ..., 'id' => ..., 'is_new' => bool]`.
- [x] 2.4 Añadir ruta en `app/routes/web.php`: `POST /ia/correcciones/protected-terms` dentro del grupo admin+auth.

## 3. Endpoints REST y wrapper deprecado

- [x] 3.1 Crear `app/app/Http/Controllers/Ia/CorreccionesAiContextAwareController.php` con métodos `suggest(int $correctionId, int $exampleId)` y `approve(Request, int, int)` que delegan a `AiContextAwareService`.
- [x] 3.2 Añadir rutas en `app/routes/web.php`:
  - `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` (nuevo, llama a `AiContextAwareService`).
  - `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve` (similar al anterior, con `source='ai-context-correct-context-...'`).
- [x] 3.3 Mantener el endpoint `aiSuggestNow` legacy funcionando para el suggester global (no cambia).

## 4. Rediseño del modal aplicando `frontend-design`

- [x] 4.1 Eliminar ALL CAPS en etiquetas del modal: cambiar `CORRECCIÓN IA`, `CÓMO QUEDARÍA`, `COMO LO TRANSCRIBIÓ` a sentence case.
- [x] 4.2 Cambiar "Corregir esta frase con IA" → "Traducir este segmento con IA". "Aprobar y crear regla" → "Aprobar y agregar regla".
- [x] 4.3 Quitar el fade-and-slide-up genérico al abrir el modal. Solo mantener el fade-in de 150ms en el resultado IA cuando el LLM responde.
- [x] 4.4 Sustituir las tres tarjetas idénticas por un timeline: el ejemplo se renderiza como un solo renglón con el `text_raw` y highlights; la corrección IA aparece como nodo anexo al mismo renglón.
- [x] 4.5 Añadir barra compacta inferior con "Proteger marca" (habilitado si hay selección de texto), "Detectar marcas", "Aprobar y agregar regla", "Solo ver", "Reintentar".
- [x] 4.6 Implementar handler Alpine de selección de texto: captura `document.getSelection()` dentro del `text_raw` y expone el texto seleccionado al estado del ejemplo.
- [x] 4.7 Implementar vista de checkboxes para marcas detectadas por LLM, con "Agregar seleccionadas" en la barra inferior.

## 5. Tests

- [x] 5.1 `app/tests/Unit/AiContextAwareServiceContractTest.php`: gate 503 si settings->enabled=false; gate 503 si apiKey=''; cache hit omite LLM; cache miss invoca LLM con snippet de vecinos; post-filtro descarta marca protegida; aprobación inserta pending con `source='ai-context-correct-context-YYYY-MM-DD'`; aprobación con duplicado devuelve conflict; reintentar limpia cache.
- [x] 5.2 `app/tests/Unit/AiBrandSuggestionServiceContractTest.php`: cache hit/miss; post-filtro excluye marcas ya protegidas; el método público expone solo `suggestBrands`.

## 6. Validación final

- [x] 6.1 `openspec validate corrections-ai-context-aware-with-mark-curation --type change` debe pasar.
- [x] 6.2 `./vendor/bin/phpunit --testsuite Unit --filter "AiContextAwareService|AiBrandSuggestionService"` — todos los tests pasan.
- [x] 6.3 Verificación manual con Playwright: login → abrir modal de contexto → click "Traducir este segmento con IA" sobre un ejemplo con vecinos en español que mencionen el dominio → confirmar que la traducción llega.
- [x] 6.4 Verificación manual: seleccionar texto dentro del `text_raw` → "Proteger marca" → ver respuesta 201 con `is_new:true`.

## 7. Skill update

- [x] 7.1 Añadir en `.kilocode/skills/corrections-ai-suggest/SKILL.md` la sección "Corrección IA con contexto ampliado y curación de marcas" con: snippet de vecinos, flujo de curación manual, flujo de detección LLM, idempotencia de protected-terms, post-filtro sin longitud.
- [x] 7.2 Actualizar el description de la skill para reflejar que cubre los tres flujos (suggester global, corrección inline con contexto, curación de marcas).

## Notas de deploy

- Sin migraciones de BD.
- La cache `ai_context_aware:*` queda en Redis con TTL 24 h; expira sola.
- El endpoint anterior `ai-context-correct` (change archivado) sigue activo como wrapper que delega con `neighbor_window=0` por compatibilidad si alguien lo invoca; sin embargo, la UI ya no lo usa.
