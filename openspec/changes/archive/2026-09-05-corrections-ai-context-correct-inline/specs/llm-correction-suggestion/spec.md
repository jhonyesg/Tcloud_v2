# Spec Delta: llm-correction-suggestion

## ADDED Requirements

### Requirement: Admin can trigger an AI-context-correct pass inline on a single example

El sistema SHALL exponer un flujo de corrección inline por ejemplo desde el modal "Ejemplos en transcripciones" de `/ia/correcciones`. El flujo SHALL estar expuesto vía dos endpoints nuevos:

- `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` — solicita al LLM una corrección contextualizada de la frase del ejemplo y devuelve `{wrong, correct, reason, model, tokens_used}`.
- `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve` — persiste la corrección aceptada como `pending` con `source='ai-context-correct-YYYY-MM-DD'`.

Ambos SHALL respetar los mismos gates que `aiSuggestNow`: master switch `llm-correction.enabled=1` en `system_settings` y `LlmCorrectionSettings::apiKey()` no vacía. Las respuestas SHALL ser idempotentes en cache durante `config('corrections.ai_context_correct.cache_ttl', 86400)` segundos.

#### Scenario: La corrección inline consume el mismo master switch
- **WHEN** admin hace click en "Corregir esta frase con IA"
- **AND** `llm-correction.enabled = 0` en `system_settings`
- **THEN** el endpoint responde 503 con el mismo contrato y mensaje que `/ia/correcciones/ai-suggest-now`.

#### Scenario: Aprobación entra al pool de pendientes con origen trazable
- **WHEN** admin aprueba una corrección IA inline
- **THEN** se inserta una fila en `corrections` con `status='pending'`, `source='ai-context-correct-YYYY-MM-DD'`, `risk_level` derivado del LLM (`medium` por default)
- **AND** la nueva fila aparece en la pestaña "Pendientes" filtrable por origen `ai-context-correct-*`.

#### Scenario: Idempotencia por cache entre re-aperturas del modal
- **WHEN** admin abre y cierra el modal de contexto varias veces dentro de 24 h
- **THEN** el segundo click sobre el mismo ejemplo muestra la respuesta cacheada sin consumir tokens
- **AND** el botón "Reintentar" sigue invocando al LLM y refrescando la cache.

#### Scenario: Auto-filtrado por origen en la UI de pendientes
- **WHEN** admin filtra la pestaña "Pendientes" por origen `ai-context-correct-2026-09-05`
- **THEN** la lista muestra solo las correcciones inline IA creadas ese día, independientemente del modo (masivas, individuales, de ejemplo).
