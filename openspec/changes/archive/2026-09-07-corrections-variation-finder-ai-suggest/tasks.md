## 1. Backend: servicio de agrupación

- [x] 1.1 Nuevo servicio `AiVariationGrouperService` en `app/app/Services/Ia/` con prompt especializado anti-marca + JSON schema estricto.
- [x] 1.2 Método `estimateTokens(array $variants): int` que aproxima input tokens (4 chars/token + 200 fixed).
- [x] 1.3 Método `estimateCostUsd(int $tokens, ?string $model): float` con tabla de precios hardcoded (gpt-4o-mini, gpt-4o, claude-3-haiku, claude-3-sonnet, glm-4.5).
- [x] 1.4 Tests en `tests/Feature/AiVariationGrouperServiceTest.php` con 11 tests cubriendo: token estimation, cost estimation, parser well-formed, low-confidence filter, empty groups, markdown fences, invalid JSON, missing fields.

## 2. Backend: endpoint controller

- [x] 2.1 Nuevo método `CorreccionesController::variationsAiSuggest(Request)` que orquesta findVariations + AiVariationGrouperService.
- [x] 2.2 Ruta registrada: `POST /ia/correcciones/variations/ai-suggest`.
- [x] 2.3 Manejo de errores: switch OFF → 503 reason=`switch_off`; sin API key → 503 reason=`no_api_key`; LLM timeout → 503 reason=`timeout_or_network`; parse failure → 503 reason=`parse_failed` con `raw_excerpt`.

## 3. Frontend: botón AI Suggest en el panel

- [x] 3.1 Botón "Sugerir correcciones con IA" debajo del "Buscar variantes", en color púrpura para distinguir.
- [x] 3.2 Estado Alpine nuevo: `variationAiModal`, `variationAiLoading`, `variationAiHint`.
- [x] 3.3-3.5 Tooltip explica el flujo. Botón disabled si 0 variantes sin regla.

## 4. Frontend: modal de resultados AI

- [x] 4.1 Modal con header "AI Suggest — N grupos propuestos".
- [x] 4.2 Por cada grupo: input editable de `canonical_correct` + lista de variantes con checkbox + confidence badge (verde ≥0.8, ámbar 0.5-0.8, colapsado <0.5) + botón "Crear N" por grupo.
- [x] 4.3 Botón global "Crear todas las marcadas" → itera grupos secuencialmente, llama bulk-create uno por uno.
- [x] 4.4 Footer con `tokens_used` + `latency_ms` + `model`.

## 5. Frontend: flujo cost preview

- [x] 5.1 Click "Sugerir con IA" → primer request con `confirm_cost=true` → muestra modal preview con estimate (variants_count, tokens, USD, provider).
- [x] 5.2 Si confirma → llamar con `confirm_cost=false` → mostrar modal de resultados.
- [x] 5.3 Si cancela → cerrar preview modal sin gastar tokens.

## 6. Tests

- [x] 6.1 `tests/Feature/AiVariationGrouperServiceTest.php` con 11 tests, 22 assertions — todos pasan.
- [x] 6.2 Suite Filter=Correc: 181 tests, 2 failures pre-existentes no relacionadas con este change (verificado con `git stash`).

## 7. Verificación manual post-deploy

- [ ] 7.1 Login como admin. Abrir `/ia/correcciones` → tab "Variation Finder".
- [ ] 7.2 Buscar "abelardo" con scope 8h → ver variantes.
- [ ] 7.3 Con switch IA OFF → click botón → ver modal con error "switch_off" + hint.
- [ ] 7.4 Activar switch + API key en tab IA Suggest.
- [ ] 7.5 Volver a Variation Finder → click botón → ver modal preview con estimate.
- [ ] 7.6 Confirmar → esperar ~10-30s → ver modal con 3-5 grupos propuestos.
- [ ] 7.7 Editar canonical_correct de un grupo si querés.
- [ ] 7.8 "Crear todas las marcadas" → ver ~10 pending en tab Pendientes.

## 8. Cierre

- [ ] 8.1 Commit con mensaje `feat(corrections): ai suggest integration in variation finder`.
- [ ] 8.2 Merge + deploy.
- [ ] 8.3 `openspec archive corrections-variation-finder-ai-suggest`.
