# Spec: transcription-corrections (delta)

<!-- DELTA: este archivo es un delta aplicado sobre openspec/specs/transcription-corrections/spec.md al archivar el change. -->

## ADDED Requirements

### Requirement: El extractor `ai-coherence-learn` produce pares auditables

El sistema SHALL garantizar que cualquier corrección insertada con `source='ai-coherence-learn'` cumpla simultáneamente:

1. `source_segment_id` está poblado (no NULL), apuntando a un `transcription_segments` existente.
2. `wrong_text` tiene **5 palabras o más** contadas sobre Unicode español. Política (cambios 2026-08-18, feedback admin): las reglas de 1-4 palabras son find/replace demasiado genérico que ignora contexto y produce espanglish (lesson learned del 2026-08-15: 2.465 reglas auto-aprobadas palabra-por-palabra, 205.000 aplicaciones dañinas). Solo sobreviven reglas con suficientes palabras para preservar tono, intención y registro del segmento. El extractor SHALL **NO emitir** el segmento entero como `wrong` aunque haya cambiado completo, sino solo el fragmento mínimo que cambió (diff word-level entre el texto pre-IA y post-IA).
3. `wrong_text` pasa el filtro `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` (excluir marcas y nombres propios).
4. `EnEsRuleClassifier::classify(wrong, correct)` no retorna bucket `NOISE` ni `QUARANTINE`. Si retorna `QUARANTINE` (traducción EN→ES literal), el extractor SHALL descartar el par silenciosamente en logs de debug, no proponerlo.

#### Scenario: Pase de coherencia corrige un segmento y extrae un par válido
- **WHEN** `TranscriptionCoherencePass` recibe un segmento con `id=12345, text="The cooperativas están dotadas of two motors"` (pre-IA, post-diccionario) y la IA responde `text="Las cooperativas están dotadas de dos motores"`
- **THEN** el extractor identifica que el cambio fue el segmento completo de 9 palabras y el diff word-level NO encuentra ningún par de 5+ palabras (todos los cambios son swaps individuales the↔las, of↔de, two↔dos de 1 palabra cada uno). El extractor SHALL descartar todos los pares por la regla de longitud y NO crear ninguna fila `Correction`. Loggea `info('par descartado por longitud <5 palabras: {wrong}')`.

#### Scenario: Pase de coherencia intenta emitir el segmento entero y el extractor lo descarta
- **WHEN** la IA reescribe completamente un segmento de 9 palabras sin producir ningún par de 5+ palabras
- **THEN** el extractor NO crea ninguna fila `Correction` (no hay nada traducible como find/replace útil) y loggea `info('TranscriptionCoherencePass: sin pares extraíbles del segmento {id}')`.

#### Scenario: Pase de coherencia propone un par single-word (3 o menos palabras) y el extractor lo descarta
- **WHEN** el diff word-level produce `wrong="the", correct="la"` (1 palabra cada uno, swap típico de EN→ES)
- **THEN** el extractor descarta el par por la regla `wc < 5` antes de llamar a `proposeLearned()`, loggea `info('par descartado por longitud <5 palabras: the→la')`. Esto evita que el ruido vuelva a llenar la cola de pendientes cada 2 minutos cuando corre la cron `transcription:tick`.

#### Scenario: Pase de coherencia propone un par que es marca propia
- **WHEN** la IA cambia "Open English" → "Open English" (sin cambio) o cambia un nombre propio detectado por `looksLikeBrandOrProperNoun()`
- **THEN** el extractor descarta el par antes de llamar a `proposeLearned()`, evita filas `Correction` espurias, y loggea `info('par descartado por brand/proper noun: {wrong}')`.

#### Scenario: Pase de coherencia propone una traducción EN→ES literal larga (5+ palabras)
- **WHEN** la IA cambia "the aprueba today in this moment emergency" → "la aprueba hoy en este momento de emergencia" (par de 7 palabras) y el `EnEsRuleClassifier` lo marca como `REVIEW` (no `QUARANTINE` por la longitud pero sí contenido traducido)
- **THEN** el extractor emite el par como pendiente `risk_level='medium'`. El admin lo revisará manualmente. Loggea `info('par propuesto: {wrong}→{correct}')`.

---

### Requirement: El extractor `ai-coherence-learn` popula `source_segment_id` mediante hidratación post-INSERT

El sistema SHALL garantizar que cualquier corrección insertada con `source='ai-coherence-learn'` tenga `source_segment_id` poblado (no NULL) **dentro de la misma transacción** que crea los `transcription_segments`. La hidratación se ejecuta como un único `UPDATE` con JOIN entre `corrections` y `transcription_segments` filtrado por `transcription_id`, `source='ai-coherence-learn'`, `source_segment_id IS NULL`, `created_at > now() - 5 minutes` y `position(c.wrong_text in ts.text_raw) > 0`.

#### Scenario: Hidratación exitosa tras el pase IA
- **WHEN** el pase de coherencia inserta 3 filas `corrections` con `wrong_text='the'`, `wrong_text='of'`, `wrong_text='two'` (source_segment_id=null todavía), y luego el caller `TranscriptionProcessor::persistSegmentsAndUpdate` ejecuta `INSERT INTO transcription_segments` para esa transcripción y llama a `$coherencePass->hydrateCoherenceLearnedSourceSegments($transcriptionId)`
- **THEN** el UPDATE-JOIN resuelve cada `wrong_text` contra `position(wrong_text in ts.text_raw)`, popula `source_segment_id` con el `ts.id` correspondiente, y el log `info('TranscriptionCoherencePass: hydrated N source_segment_id(s)')` reporta el conteo.

#### Scenario: Hidratación parcial cuando un wrong_text no se encuentra en ningún text_raw
- **WHEN** la IA emite un par `wrong='xyz'` que no aparece textualmente en ningún segmento de esa transcripción
- **THEN** esa fila queda con `source_segment_id` NULL y SHALL ser marcada como `triage:orphan` por la Capa 2 del comando `corrections:triage-pending`. Las otras filas que sí matcheen se hidratan normalmente.

---

### Requirement: El admin puede ejecutar triage en capas desde `/ia/correcciones`

El sistema SHALL exponer en `/ia/correcciones` un botón "Triage pendientes (N)" en el header que ejecute el flujo definido en la capability `corrections-pending-triage`. Esta capability es transversal: usa el scheduler existente del módulo y la cache de runs.

#### Scenario: Admin dispara triage desde el header de correcciones
- **WHEN** el admin hace click en "Triage pendientes (6.035)" en el header de `/ia/correcciones`
- **THEN** la UI abre un modal de confirmación que muestra conteo actual, opciones `[dry-run] [auto-approve-keep] [cancelar]` y al confirmar hace POST a `/ia/correcciones/triage-pending` con el body correspondiente. La UI abre el modal de progreso que muestra las capas en tiempo real via polling a `/ia/correcciones/triage-pending/{runId}` (mismo patrón que `applyRetroactive`).

#### Scenario: Triage termina y la UI refresca el conteo
- **WHEN** el run del triage termina (status=done o error según cache)
- **THEN** la UI refresca el conteo de pending en el header (debe bajar significativamente), muestra el reporte por capa, y si hubo auto-approve abre el toast de undo con el `bulk_action_id`.
