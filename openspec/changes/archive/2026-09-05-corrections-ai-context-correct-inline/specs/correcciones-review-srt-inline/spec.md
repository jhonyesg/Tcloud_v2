# Spec Delta: correcciones-review-srt-inline

## ADDED Requirements

### Requirement: Cada ejemplo del modal de contexto expone un flujo AI contextualizado

El modal "Ejemplos en transcripciones" (abierto desde el botón "Contexto" de una corrección pendiente) SHALL mostrar, sobre cada ejemplo listado, un botón "Corregir esta frase con IA" que solicite al endpoint `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` una corrección atómica de la frase. Tras la respuesta, SHALL aparecer tres acciones: "Aprobar y crear regla", "Solo ver" y "Reintentar".

#### Scenario: El admin ve la corrección inline en el mismo ejemplo
- **WHEN** admin hace click en "Corregir esta frase con IA" sobre un ejemplo del modal
- **THEN** el modal muestra sobre ese ejemplo una caja nueva con la corrección propuesta por el LLM (sustituto de "Cómo quedaría con esta regla" para esa interacción)
- **AND** los botones "Aprobar y crear regla", "Solo ver" y "Reintentar" aparecen junto a la corrección propuesta.

#### Scenario: El modal vuelve a su estado base tras aprobar
- **WHEN** admin hace click en "Aprobar y crear regla" exitosamente
- **THEN** el modal cierra la caja de IA, vuelve al estado "Cómo lo transcribió / Cómo quedaría con esta regla"
- **AND** la nueva regla queda visible en la pestaña "Pendientes" filtrada por origen `ai-context-correct-YYYY-MM-DD`.

#### Scenario: Otros ejemplos del mismo modal no consumen tokens
- **WHEN** admin abre el modal con 4 ejemplos y hace click en "Corregir esta frase con IA" solo sobre uno
- **THEN** los otros 3 ejemplos siguen mostrando su "Cómo quedaría con esta regla" original sin llamadas al LLM
- **AND** el admin puede clickear el botón en cada ejemplo individualmente.

#### Scenario: Reintentar invoca al LLM de nuevo
- **WHEN** admin hace click en "Reintentar" sobre la caja IA
- **THEN** se ejecuta una nueva llamada al LLM (ignora cache)
- **AND** la corrección propuesta se reemplaza con la nueva respuesta.

### Requirement: API key nunca se transmite al frontend

El endpoint SHALL aceptar sólo `correctionId` y `exampleId` en la URL; la API key SHALL leerse del backend (`LlmCorrectionSettings::apiKey()`). El frontend SHALL mostrar "Configurar API key" en lugar del botón cuando `aiSuggestStatus` indique que falta la key, sin pedirla al usuario.

#### Scenario: Modal de contexto deshabilita el botón IA si falta la key
- **WHEN** `LlmCorrectionSettings::apiKey() === ''`
- **THEN** el botón "Corregir esta frase con IA" se renderiza deshabilitado con tooltip "Configura la API key en /ia/correcciones → IA Suggest"
- **AND** ningún fetch sale del navegador hacia el endpoint de corrección.
