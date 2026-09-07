# Spec Delta: corrections-context-aware-correction

## Purpose

Permitir que el admin pida al LLM una corrección **contextualizada** del segmento que está viendo: el LLM recibe los segmentos vecinos (±5) además del segmento objetivo, y puede traducirlo al español bien usando el contexto real de la transcripción (dominio,登場人物, fechas, lugares). Cuando el admin aprueba, la corrección se persiste como regla `wrong → correct` del segmento completo, de modo que el motor `applyToText` la aplique retroactivamente a transcripciones donde aparezca esa misma frase.

---

## ADDED Requirements

### Requirement: El LLM recibe segmentos vecinos ±5 antes del segmento objetivo

`AiContextAwareService::correctExample(Correction $parent, array $example, bool $forceFresh = false)` SHALL consultar `transcription_segments` por `transcription_id` ordenado por `segment_index` y SHALL construir un "snippet de contexto" con los 5 segmentos anteriores, los 5 siguientes y el segmento objetivo. El snippet SHALL incluirse en el system prompt del LLM antes del segmento objetivo y SHALL etiquetar explícitamente cada segmento con su índice (`[#N]`) para que el LLM identifique el segmento objetivo sin ambigüedad.

#### Scenario: Vecinos disponibles, el LLM traduce con contexto
- **WHEN** admin hace click en "Corregir este segmento con IA" sobre el ejemplo cuyo `text_raw` es "The access to inadequate arms of fuel for part of men of death, the difficulties that are presenting in sales mentality and the conflicts interpersonal not resulted."
- **AND** los vecinos 5 anteriores mencionan "el sector energético", "las importaciones de crudo" y "los subsidios al ACPM"
- **THEN** el LLM devuelve `{correct: "El acceso a brazos de combustible insuficientes para la muerte de hombres, las dificultades que se presentan en la mentalidad de ventas y los conflictos interpersonales no resueltos.", reason: "traducción contextual: 'arms of fuel' = combustible según el dominio energético de los vecinos, 'men of death' mantenido en abstracto por falta de referente claro en el snippet", risk: "medium"}`
- **AND** la UI muestra la corrección con el botón Aprobar / Solo ver / Reintentar.

#### Scenario: Vecinos sin información útil, el LLM conserva el original
- **WHEN** los vecinos ±5 no aportan contexto adicional sobre el dominio del segmento objetivo
- **THEN** el LLM devuelve `{correct: <text_raw original>, reason: "Sin contexto adicional suficiente para reconstruir; se conserva el original.", risk: "low"}`
- **AND** la UI muestra el mismo `text_raw` original con un reason explicativo.

### Requirement: Aprobar persiste regla wrong→correct del segmento completo

Cuando el admin hace click en Aprobar, el sistema SHALL insertar una fila en `corrections` con:
- `wrong_text = $example['text_raw']` (segmento completo, no solo la regla padre).
- `correct_text = $candidate['correct']`.
- `wrong_normalized = mb_strtolower(trim(wrong_text))`.
- `status = 'pending'`.
- `source = 'ai-context-correct-context-' . today('YYYY-MM-DD')`.
- `risk_level` derivado de `candidate['risk']` (`low`/`medium`/`high`).

#### Scenario: Aprobación con regla de segmento largo
- **WHEN** admin aprueba la corrección `{wrong: "The access to inadequate arms of fuel...", correct: "El acceso a brazos de combustible..."}`
- **THEN** la fila en `corrections` tiene `wrong_text` con todo el segmento largo (puede exceder 100 caracteres), `status='pending'`, `source='ai-context-correct-context-2026-09-05'`.
- **AND** la nueva fila aparece en la pestaña Pendientes filtrable por origen `ai-context-correct-context-YYYY-MM-DD`.

#### Scenario: Aprobación bloqueada por duplicado
- **WHEN** ya existe una corrección `pending` o `approved` con el mismo `wrong_normalized`
- **THEN** el endpoint responde 409 con `{existing_id: <id>}` y la UI mantiene el resultado visible.

### Requirement: Cache por (corrección, ejemplo, día) con TTL configurable

El servicio SHALL cachear la respuesta del LLM bajo `ai_context_aware:{correction_id}:{example_id}:{YYYY-MM-DD}` con TTL configurable (`config('corrections.ai_context_aware.cache_ttl', 86400)`, default 24 h). El botón "Reintentar" SHALL forzar nueva llamada (ignora cache).

#### Scenario: Re-abrir el modal muestra cache hit
- **WHEN** admin cierra el modal de contexto y lo reabre menos de 24 h después
- **THEN** la última respuesta cacheada se muestra sin gastar tokens.
- **AND** "Reintentar" sigue invocando al LLM de nuevo y refresca la cache.

### Requirement: Mismas gates que el flujo anterior

El servicio SHALL respetar los mismos gates: `LlmCorrectionSettings::bool('enabled') === true` y `apiKey() !== ''`. Si el master switch está OFF o falta la API key, SHALL responder con el mismo contrato JSON 503 que `ai-suggest-now` (`{ok: false, reason, hint, api_key_source?}`).

#### Scenario: Switch OFF bloquea el botón
- **WHEN** admin hace click con `llm-correction.enabled = 0`
- **THEN** el endpoint responde 503 con hint "Activa el toggle 'Habilitado' en el tab IA Suggest."
- **AND** la UI muestra el banner ámbar con el motivo y un botón Reintentar disponible.

### Requirement: Post-filtro defensivo anti-marca/sigla (versión inline)

El servicio SHALL validar la salida del LLM contra la lista `protected_brands` + exclusiones dinámicas de `CorrectionProtectedTermsService`. Si el `correct` toca una marca/sigla protegida, SHALL descartar el candidato y responder con el mismo contrato `{ok: false, reason: "El LLM propuso modificar una marca o nombre propio; candidato descartado."}`. A diferencia del suggester global, **NO** se aplica el filtro de longitud atómica (porque aquí el `correct` puede ser una oración completa).

#### Scenario: LLM propone reemplazar una marca protegida
- **WHEN** el segmento contiene "el equipo usa Word Enterprise" y Word Enterprise está en `protected_brands`
- **AND** el LLM devuelve `{correct: "...procesador de texto empresarial..."}` modificando la marca
- **THEN** el post-filtro descarta el candidato y la UI muestra el banner de descarte.
