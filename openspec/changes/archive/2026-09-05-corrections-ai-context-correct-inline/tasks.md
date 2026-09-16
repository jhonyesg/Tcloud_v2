# Tasks: corrections-ai-context-correct-inline

## 1. Servicio AiContextCorrectService

- [ ] 1.1 Crear `app/app/Services/Ia/AiContextCorrectService.php` con método `suggest(array $example, Correction $parent, ?bool $forceFresh = false): array` que orquesta: gate (settings->enabled + apiKey), cache hit/miss, llamada al LLM con prompt atómico, post-filtro con `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()`, persistencia en cache.
- [ ] 1.2 Implementar `approve(Correction $parent, array $example, array $body): Correction` que crea la fila pending con `source='ai-context-correct-YYYY-MM-DD'`, idempotente vía lookup por `wrong_normalized`+status pending/approved (409 si colisión).
- [ ] 1.3 Añadir `app/config/corrections.php` con bloque `ai_context_correct` (`cache_ttl` default 86400) y `app/config/llm-correction.php` no se toca.
- [ ] 1.4 Implementar el método privado que construye el prompt atómico (system + user) — reutilizar el patrón de mensajes de `LlmCorrectionSuggester`, especializado para "traduce esta frase al español bien, devuelve JSON {wrong, correct, reason, risk}".

## 2. Endpoints REST

- [ ] 2.1 Crear `app/app/Http/Controllers/Ia/CorreccionesAiContextCorrectController.php` con dos métodos: `suggest(int $correctionId, int $exampleId)` y `approve(Request $request, int $correctionId, int $exampleId)`.
- [ ] 2.2 Validar que `exampleId` pertenece a `correctionId` (reusar `CorrectionContextFinder::examples($correction)` y buscar por id; 404 si no).
- [ ] 2.3 Manejar los gates con el mismo contrato que `aiSuggestNow`: 503 si switch OFF, 503 si falta API key, 502 si HTTP 5xx del LLM, 504 si timeout. Capturar en `try/catch` con clasificación 401/403/5xx/timeout.
- [ ] 2.4 Añadir rutas en `app/routes/web.php` dentro del grupo admin: `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` y `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve`.

## 3. UI en el modal de contexto

- [ ] 3.1 En `app/resources/views/ia/correcciones/index.blade.php` (zona de cada ejemplo, líneas 1820-1838), añadir un botón "Corregir esta frase con IA" visible solo si `aiSuggestStatus.has_api_key && aiSuggestStatus.enabled !== false` (leer el master switch del mismo payload).
- [ ] 3.2 Añadir estado Alpine `aiContextCorrect: { [exampleId]: { loading, result, error } }` en el componente, con tres acciones: aprobar, solo ver, reintentar.
- [ ] 3.3 Reutilizar `apiFetch` y `showToast` existentes; al aprobar con éxito, mostrar toast "Regla añadida a pendientes" y cerrar la caja IA sobre ese ejemplo.

## 4. Skill y documentación

- [ ] 4.1 Añadir en `.kilocode/skills/corrections-ai-suggest/SKILL.md` la sección "Corrección inline desde el modal de contexto" con: endpoint, payload, contrato de respuesta, gates, post-filtro, deduplicación, ejemplo de uso.
- [ ] 4.2 Documentar el flujo end-to-end en el description del change (`openspec/changes/corrections-ai-context-correct-inline/proposal.md`) si hace falta — no, ya está.

## 5. Tests

- [ ] 5.1 `app/tests/Unit/AiContextCorrectServiceTest.php`: gate 503 cuando settings->enabled=false; gate 503 cuando apiKey=''; cache hit omite LLM; cache miss invoca LLM; post-filtro descarta marca; post-filtro descarta sigla; aprobación inserta pending; aprobación con duplicado devuelve conflict; reintentar limpia cache.
- [ ] 5.2 `app/tests/Feature/CorreccionesAiContextCorrectControllerTest.php`: rutas devuelven 200/404/409/503/502 según caso.

## 6. Validación final

- [ ] 6.1 `php artisan migrate:status` (no debe haber nuevas).
- [ ] 6.2 `openspec validate corrections-ai-context-correct-inline --type change` debe pasar.
- [ ] 6.3 `./vendor/bin/phpunit --testsuite Unit --filter "AiContextCorrectService|CorreccionesAiContextCorrect"` — todos los tests pasan.
- [ ] 6.4 Verificación manual: admin activa `llm-correction.enabled`, abre Contexto de una pendiente real, dispara IA sobre un ejemplo, aprueba y ve la regla en la pestaña Pendientes filtrada por origen `ai-context-correct-*`.

## Notas de deploy

- Sin migraciones de BD. Sin cambios de esquema.
- La cache `ai_context_correct:*` se queda en Redis con TTL 24 h; expira sola.
- Si el admin tiene el master switch en OFF (default post-2026-09-05), el endpoint responde 503 con el mismo mensaje y CTA que `ai-suggest-now` — comportamiento consistente.