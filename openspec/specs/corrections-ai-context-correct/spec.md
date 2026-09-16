# corrections-ai-context-correct Specification

## Purpose
Permitir que el admin delegue al LLM la corrección contextualizada al español de un ejemplo individual del modal "Ejemplos en transcripciones" en `/ia/correcciones`, evalúe el resultado inline, y — si le sirve — lo persista como corrección pending con origen `ai-context-correct-YYYY-MM-DD`. El flujo opera frase a frase, ejemplo a ejemplo, bajo pedido explícito del admin y con los mismos gates que el suggester global.

---

## Requirements

### Requirement: Post-filtro defensivo bloquea marcas, nombres propios y siglas

El servicio SHALL aplicar el mismo post-filtro PHP que `LlmCorrectionSuggester` sobre la salida del LLM antes de devolverla al admin:
- Si `correct` contiene un término en `config('llm-correction.protected_brands')` (case-insensitive, token independiente), el candidato SE DESCARTA.
- Si `correct` modifica una sigla en mayúsculas de ≥ 2 caracteres detectada en `wrong`, el candidato SE DESCARTA.
- Si `correct` parece reescribir un nombre propio (heurística de mayúsculas o coincidencia con `wrong`), el candidato SE DESCARTA.

#### Scenario: LLM propone reemplazar una marca
- **WHEN** el admin pide corrección de un segmento que contiene "el equipo usa Word Enterprise"
- **AND** el LLM devuelve `{wrong: "Word Enterprise", correct: "procesador de texto empresarial"}`
- **THEN** el post-filtro detecta "Word Enterprise" en `protected_brands` y descarta el candidato
- **AND** el admin ve en el modal "El LLM propuso modificar una marca o nombre propio; candidato descartado" con botón "Reintentar" disponible.

#### Scenario: LLM propone expandir una sigla
- **WHEN** el admin pide corrección de un segmento que contiene "la ONU aprobó la resolución"
- **AND** el LLM devuelve `{wrong: "ONU", correct: "Naciones Unidas"}`
- **THEN** el post-filtro detecta que "ONU" es sigla en mayúsculas y descarta el candidato
- **AND** el admin ve el mismo mensaje de descarte y puede reintentar.

### Requirement: Respuestas se cachean por (corrección, ejemplo) con TTL configurable

El servicio SHALL cachear la respuesta del LLM bajo la clave `ai_context_correct:{correction_id}:{example_id}:{YYYY-MM-DD}` con TTL configurable (`config('corrections.ai_context_correct.cache_ttl', 86400)` segundos, default 24 h). El botón "Reintentar" SHALL forzar un nuevo LLM call (ignora cache y sobrescribe). El admin SHALL poder abrir el modal de contexto sin disparar LLM automáticamente.

#### Scenario: Cache hit al reabrir el modal
- **WHEN** admin cierra el modal de contexto tras obtener una corrección IA
- **AND** lo reabre menos de 24 h después
- **THEN** la respuesta cacheada se muestra automáticamente sobre el ejemplo, sin consumir tokens
- **AND** el botón "Reintentar" sigue disponible para forzar nueva llamada.

#### Scenario: Cache miss dispara nueva llamada
- **WHEN** admin abre el modal por primera vez y hace click en "Corregir esta frase con IA"
- **THEN** se invoca el LLM y el resultado se cachea y se muestra.

#### Scenario: Reintentar ignora cache
- **WHEN** admin hace click en "Reintentar"
- **THEN** se ignora la entrada en cache y se ejecuta una nueva llamada al LLM (el contador de tokens del proveedor queda registrado)
- **AND** la nueva respuesta sobrescribe la cache.

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
