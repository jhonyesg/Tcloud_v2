# Spec Delta: corrections-ai-context-correct → deprecación parcial

## REMOVED Requirements

### Requirement: Admin puede pedir al LLM una corrección contextualizada por ejemplo

Este requisito queda **reemplazado** por `corrections-context-aware-correction` (change `corrections-ai-context-aware-with-mark-curation`, 2026-09-05). La nueva versión alimenta al LLM con vecinos ±5 segmentos para que pueda traducir contextos completos.

### Requirement: Aprobar una sugerencia IA crea una corrección pending con origen ai-context-correct-*

Este requisito queda **extendido** por `corrections-context-aware-correction`: el `source` cambia a `ai-context-correct-context-YYYY-MM-DD` para distinguir la corrección con contexto ampliado de la versión original.

---

## MODIFIED Requirements

### Requirement: Sólo una pasada por click

Sin cambios en el contrato externo: el botón SHALL ejecutar exactamente una llamada al LLM por click. Variantes (3 opciones, regenerar con otra temperatura, etc.) siguen fuera de alcance. Lo que cambia es la **composición interna** del prompt (ahora incluye los vecinos) — pero eso es un detalle de implementación, no de contrato.

#### Scenario: Una sola respuesta por click
- **WHEN** admin hace click en "Corregir este segmento con IA" sin reintentar
- **THEN** la respuesta del LLM se entrega tal cual; el admin decide entre Aprobar / Solo ver / Reintentar.

### Requirement: API key nunca se loguea en claro

Sin cambios. El log `ai_context_correct.served` SHALL continuar sin incluir la API key ni el cuerpo completo del prompt.

#### Scenario: Logs operativos no exponen secretos
- **WHEN** admin invoca el flujo correctamente
- **THEN** `laravel.log` contiene `ai_context_correct.served {correction_id, example_id, model, tokens_used, latency_ms, neighbor_window}` y NO contiene la API key ni el cuerpo completo del prompt.
