# Tasks: Pase de coherencia IA sobre segmentos con inglés residual

## 1. Backend: nuevo servicio `TranscriptionCoherencePass`

- [ ] Crear `app/app/Services/Ia/TranscriptionCoherencePass.php`:
  - Usar trait `CallsLlmChatCompletion`.
  - Método `correctSegments(array $segments): array` que recibe segmentos con `text` y devuelve los corregidos.
  - Construir system prompt (traducir spanglish a español, respetar nombres propios, no inventar, temperatura 0.2).
  - Enviar batch en una sola llamada al LLM.
  - Parsear respuesta JSON (con fallback regex si el modelo devuelve prosa).
  - Si falla, devolver los segmentos sin cambios y loguear.

## 2. Backend: settings en `TranscriptorSettings`

- [ ] Agregar al schema de `TranscriptorSettings`:
  - `ai_coherence_enabled` (bool, default true)
  - `ai_coherence_threshold` (float, default 0.4)
  - `ai_coherence_max_segments` (int, default 20)
  - `ai_coherence_model` (str, default el de `llm-correction.model`)

## 3. Backend: integración en `TranscriptionProcessor`

- [ ] En `persistSegmentsAndUpdate`, después de `applyToSegments`:
  - Si `ai_coherence_enabled`, usar `EnglishResidualSegmentDetector::scoreSegment` para detectar segmentos flagged.
  - Corregir con IA solo los primeros `ai_coherence_max_segments` flagged (batch).
  - Guardar el texto corregido como `text` (el `text_raw` queda con el original).

## 4. Verificación

- [ ] Con un segmento con spanglish, el `text` queda en español coherente sin rastro de inglés.
- [ ] `text_raw` conserva el original del transcriptor.
- [ ] Segmentos sin inglés residual no se envían al LLM.
- [ ] Con > `ai_coherence_max_segments` flagged, solo se corrigen los primeros N.
- [ ] Si el LLM falla, la transcripción se guarda igual (state=done) con el texto del diccionario.

## 5. Archivar

- [ ] Mover a `archive/2026-08-15-2026-08-15-transcription-ai-coherence-pass/`.
