# Design: Pase de coherencia IA sobre segmentos con inglés residual

## Context

El pipeline de transcripción (`TranscriptionProcessor::persistSegmentsAndUpdate`) aplica el diccionario de correcciones al parsear el SRT. El diccionario (2,667 reglas) corrige frases exactas pero no el spanglish residual (frases largas en inglés, mezclas tipo "the siento", "in this moment"). El detector `EnglishResidualSegmentDetector` ya puntúa cada segmento (score = en/(en+es)) y el LLM (minimax-m3 vía Kilo Gateway) ya está configurado y probado en un piloto.

## Goals / Non-Goals

**Goals:**
- Que cada transcripción salga en español coherente, sin spanglish residual.
- Corregir con IA **solo** los segmentos que el diccionario no pudo arreglar (inglés residual).
- Conservar `text_raw` (original del transcriptor) inmutable.
- Controlar costo/latencia con tope por transcripción y fallback seguro.

**Non-Goals:**
- No reemplazar el diccionario (sigue siendo el primer barrido).
- No corregir segmentos sin inglés residual.
- No tocar el grabador ni el envío a la API.

## Decisions

### D1. Nuevo servicio `TranscriptionCoherencePass` con trait `CallsLlmChatCompletion`

Servicio dedicado que encapsula la llamada al LLM para corregir texto. Reutiliza `CallsLlmChatCompletion` (punto único de cambio HTTP) y `LlmCorrectionSettings` (config DB-overridable).

**Por qué:** separa la responsabilidad de "corregir texto con IA" del parseo, y reutiliza la infraestructura LLM existente. Alternativa descartada: meter la lógica en `CorrectionService` — mezcla dos responsabilidades (diccionario vs. LLM).

### D2. Integración en `persistSegmentsAndUpdate` después del diccionario

Flujo:
1. Parsear SRT → segmentos.
2. Aplicar diccionario (`applyToSegments`) → `text` corregido por reglas.
3. Para cada segmento, `EnglishResidualSegmentDetector::scoreSegment(text)`.
4. Si `score >= threshold` y `ai_coherence_enabled`, agrupar los segmentos flagged y corregirlos con IA en **una sola llamada** (batch).
5. Guardar el texto corregido por IA como `text` (el `text_raw` queda con el original del transcriptor).

**Por qué:** el diccionario primero (rápido/gratis), IA solo donde hace falta. Batch en una llamada reduce latencia y costo.

### D3. Tope por transcripción (`ai_coherence_max_segments`)

Si una transcripción tiene más de N segmentos flagged, solo se corrigen los primeros N (los más recientes). Evita que una transcripción con mucho inglés (ej. una canción en inglés) dispare un costo alto.

**Por qué:** control de costo. Una emisora de música en inglés no debe corregirse entera.

### D4. Fallback seguro

Si el LLM falla (timeout, HTTP error, respuesta inválida), se conserva el texto del diccionario (sin IA) y se loguea. Nunca se rompe el parseo por un fallo de IA.

**Por qué:** el parseo es crítico; la mejora de coherencia es secundaria. Un fallo de IA no debe perder la transcripción.

### D5. Config DB-overridable en `TranscriptorSettings`

Nuevos settings: `ai_coherence_enabled`, `ai_coherence_threshold`, `ai_coherence_max_segments`, `ai_coherence_model`. Siguen el patrón existente (schema + `SystemSetting`).

**Por qué:** permite ajustar umbral/tope/modelo desde la UI sin redeploy, igual que el resto de settings del transcriptor.

## Risks / Trade-offs

- **Costo LLM:** mitigado por tope por transcripción y solo segmentos flagged.
- **Latencia en el parseo:** una llamada batch por transcripción con inglés residual. Aceptable (el polling ya es async).
- **Falsos positivos del detector:** "a" (preposición ES) puede marcarse como EN. Mitigado por el umbral y porque el LLM recibe contexto completo del segmento.
- **Alucinación del LLM:** mitigado por prompt estricto (no inventar, respetar nombres propios) y temperatura 0.2.
- **No hay migración ni cambio de schema.**
