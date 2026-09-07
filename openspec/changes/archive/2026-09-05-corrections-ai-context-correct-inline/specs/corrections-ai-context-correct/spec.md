# Spec Delta: corrections-ai-context-correct

## Purpose

Permitir que el admin delegue al LLM la corrección contextualizada al español de un ejemplo individual del modal "Ejemplos en transcripciones" en `/ia/correcciones`, evalúe el resultado inline, y — si le sirve — lo persista como corrección pending con origen `ai-context-correct-YYYY-MM-DD`. El flujo opera frase a frase, ejemplo a ejemplo, bajo pedido explícito del admin y con los mismos gates que el suggester global.

---

## ADDED Requirements

### Requirement: Admin puede pedir al LLM una corrección contextualizada por ejemplo

El sistema SHALL exponer una acción "Corregir esta frase con IA" en cada ejemplo del modal de contexto (`GET /ia/correcciones/{id}/contexto`). La acción SHALL invocar el endpoint `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` con body vacío y SHALL devolver, en respuesta 200, una corrección candidata atómica `{wrong, correct, reason, model, tokens_used}`.

#### Scenario: LLM devuelve una corrección útil
- **WHEN** admin hace click en "Corregir esta frase con IA" sobre el ejemplo cuyo `text_raw` es "The access to inadequate arms of fuel for part of men of death, the difficulties that are presenting in sales mentality and the conflicts interpersonal not resulted."
- **AND** la regla padre es `wrong="arms of fuel"`, `correct="arms de fuel"`, `risk_level="medium"`
- **THEN** el sistema llama al LLM con prompt específico de traducción contextualizada
- **AND** el LLM devuelve `{wrong: "The access to inadequate arms of fuel for part of men of death, the difficulties that are presenting in sales mentality and the conflicts interpersonal not resulted.", correct: "El acceso a brazos de combustible insuficientes para la muerte de hombres, las dificultades que se presentan en la mentalidad de ventas y los conflictos interpersonales no resueltos.", reason: "traducción contextual completa del segmento respetando el dominio (combustible/muerte/ventas)"}`
- **AND** el admin ve el resultado inline en el modal, sobre el mismo ejemplo, con tres botones: "Aprobar y crear regla", "Solo ver", "Reintentar".

#### Scenario: Botón bloqueado cuando el master switch está en OFF
- **WHEN** admin hace click en "Corregir esta frase con IA" y `llm-correction.enabled = 0` en `system_settings`
- **THEN** el endpoint responde 503 con `{error: "Suggest deshabilitado desde Configuración / IA Suggest.", hint: "Activa el toggle 'Habilitado' en el tab IA Suggest."}` (mismo contrato que `/ia/correcciones/ai-suggest-now`).

#### Scenario: Botón bloqueado cuando falta API key
- **WHEN** admin hace click y no hay API key configurada (`LlmCorrectionSettings::apiKey() === ''`)
- **THEN** el endpoint responde 503 con `{error: "LLM_API_KEY no configurada.", hint: "Pegala en el campo 'API key' del tab IA Suggest → Guardar key.", api_key_source: <source>}` (mismo contrato que `/ai-suggest-now`).

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

### Requirement: Aprobar una sugerencia IA crea una corrección pending con origen ai-context-correct-*

La acción "Aprobar y crear regla" SHALL invocar `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve` con body `{wrong, correct, reason}` y SHALL insertar una fila en `corrections` con `status='pending'`, `source='ai-context-correct-YYYY-MM-DD'`, `risk_level` derivado del LLM (`medium` por default; `high` si el LLM reporta cambio de marca o low si es puro match).

#### Scenario: Aprobación persiste pending con origen trazable
- **WHEN** admin hace click en "Aprobar y crear regla" con la respuesta `{wrong: "arms of fuel", correct: "brazos de combustible"}`
- **THEN** se inserta una fila en `corrections` con `wrong_text="arms of fuel"`, `correct_text="brazos de combustible"`, `status='pending'`, `source='ai-context-correct-2026-09-05'`, `risk_level='medium'`, `proposed_by=<admin.id>`
- **AND** el modal de contexto se cierra o vuelve al estado base
- **AND** la nueva regla aparece en la pestaña "Pendientes" filtrable por origen `ai-context-correct-2026-09-05`.

#### Scenario: Aprobación rechazada si la regla ya existe
- **WHEN** admin intenta aprobar `{wrong, correct}` y ya existe una corrección pending o approved con la misma `wrong_normalized`
- **THEN** el endpoint responde 409 con `{error: "Ya existe una corrección pending o approved con el mismo wrong_normalized.", existing_id: <id>}`
- **AND** la UI muestra el mensaje y mantiene el resultado visible para que el admin decida manualmente.

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

El botón SHALL ejecutar exactamente una llamada al LLM por click. Variantes (3 opciones, regenerar con otra temperatura, etc.) están fuera de alcance.

#### Scenario: Una sola respuesta por click
- **WHEN** admin hace click en "Corregir esta frase con IA" sin reintentar
- **THEN** la respuesta del LLM se entrega tal cual; el admin decide entre Aprobar / Solo ver / Reintentar / Descartar.

### Requirement: API key nunca se loguea en claro

El servicio SHALL registrar métricas de uso (tokens consumidos, modelo, latencia) sin incluir la API key ni el cuerpo completo del prompt. La trazabilidad para auditoría es vía `corrections.source = 'ai-context-correct-YYYY-MM-DD'` + `proposed_by` + `created_at`.

#### Scenario: Logs operativos no exponen secretos
- **WHEN** admin invoca el flujo correctamente
- **THEN** `laravel.log` contiene `ai_context_correct.served {correction_id, example_id, model, tokens_used, latency_ms}` y NO contiene la API key ni el cuerpo completo del prompt.
