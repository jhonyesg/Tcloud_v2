# Change: Pase de coherencia IA sobre segmentos con inglés residual

## Why

El pipeline de transcripción aplica el diccionario de correcciones (`CorrectionService::applyToSegments`) al parsear el SRT, pero el diccionario solo corrige frases exactas. El transcriptor ASR produce **spanglish** (mezcla de inglés y español) que el diccionario no cubre, dejando transcripciones incoherentes.

**Evidencia (2026-08-15):** 6,222 segmentos de hoy tienen frases EN residuales sin corregir. Ejemplos reales:
- "Encuentro, **the** siento del Cristo..."
- "por la **magnitude of the** tragedy... del reporte **official**"
- "viajo **approximately with** 10 toneladas **of equips**"
- "Tenemos **in this moment** bancos de sangre"

El detector `EnglishResidualSegmentDetector` ya identifica estos segmentos (score 0-1), y el LLM (minimax-m3 vía Kilo Gateway) ya está configurado y probado. Un piloto con 6 segmentos reales mostró que el LLM corrige el spanglish a español coherente **sin dejar rastro de inglés** y respetando nombres propios.

## What Changes

### 1. Nuevo servicio `TranscriptionCoherencePass`

Servicio que, dado un segmento con inglés residual, llama al LLM para corregir el texto a español coherente. Reutiliza el trait `CallsLlmChatCompletion` y `LlmCorrectionSettings`.

### 2. Integración en `TranscriptionProcessor::persistSegmentsAndUpdate`

Después de aplicar el diccionario (`applyToSegments`), se detectan los segmentos con inglés residual (`EnglishResidualSegmentDetector::scoreSegment`) y se corrigen con IA **solo los que superan el umbral**. El resultado se guarda como `text` (el `text_raw` conserva el original del transcriptor).

### 3. Configuración (DB-overridable)

Nuevos settings en `TranscriptorSettings`:
- `ai_coherence_enabled` (bool, default true)
- `ai_coherence_threshold` (float, default 0.4 — mismo que el detector)
- `ai_coherence_max_segments` (int, default 20 — tope por transcripción para controlar costo)
- `ai_coherence_model` (str, default el de `llm-correction.model`)

## Non-goals

- **No** reemplazar el diccionario: el diccionario sigue siendo el primer barrido (rápido y gratis).
- **No** corregir con IA segmentos sin inglés residual (ahorro de costo/latencia).
- **No** tocar `text_raw` (inmutable, original del transcriptor).
- **No** modificar el grabador ni el envío a la API.

## Impact

- **Code affected (nuevo):**
  - `app/app/Services/Ia/TranscriptionCoherencePass.php`
- **Code affected (modificado):**
  - `app/app/Services/Ia/TranscriptionProcessor.php`
  - `app/app/Services/Ia/TranscriptorSettings.php`
- **Migrations:** ninguna.
- **Riesgos:** medio — introduce una llamada LLM en el hot path del parseo. Mitigado por: solo segmentos con inglés residual, tope por transcripción, y fallback a texto original si el LLM falla.
