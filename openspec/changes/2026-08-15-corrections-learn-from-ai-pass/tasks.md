# Tasks: Aprender de las correcciones IA para alimentar el diccionario

## 1. Backend: extracción de pares en `TranscriptionCoherencePass`

- [ ] En `TranscriptionCoherencePass::apply()`, cuando la IA corrige un segmento:
  - Comparar `text_raw` (original) con `text` (corregido).
  - Extraer pares `wrong → correct` por diff de tokens (LCS).
  - Devolver los pares junto con los segmentos corregidos.

## 2. Backend: método para proponer pares aprendidos en `CorrectionService`

- [ ] Agregar método `proposeLearned(string $wrong, string $correct, ?int $segmentId = null): ?Correction`:
  - Idempotencia: si ya existe `pending`/`approved` con el mismo `wrong_normalized`, no duplicar.
  - Insertar con `status=pending`, `risk_level=medium`, `source='ai-coherence-learn'`.
  - Retornar null si el par ya existe o es de baja calidad.

## 3. Backend: filtro de calidad

- [ ] Filtrar pares que:
  - Sean de 1-4 palabras (no segmentos enteros).
  - No sean nombres propios/marcas (`EnEsRuleClassifier::looksLikeBrandOrProperNoun`).
  - No sean ruido (`EnEsRuleClassifier::classify` → NOISE).

## 4. Backend: tope por transcripción

- [ ] Limitar a N pares propuestos por transcripción (default 5).

## 5. Verificación

- [ ] Con un segmento corregido por IA, se genera un par `pending` con `source='ai-coherence-learn'`.
- [ ] El par no se duplica si ya existe.
- [ ] Nombres propios/marcas no se proponen.
- [ ] El admin puede aprobar el par y entra al diccionario.

## 6. Archivar

- [ ] Mover a `archive/2026-08-15-2026-08-15-corrections-learn-from-ai-pass/`.
