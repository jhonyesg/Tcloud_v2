# Design: Aprender de las correcciones IA para alimentar el diccionario

## Context

El pase de coherencia IA corrige el spanglish residual. Cada corrección es conocimiento que hoy se pierde. Queremos extraer los pares `wrong → correct` y proponerlos como `pending` para que el admin los apruebe y entren al diccionario, reduciendo la carga de IA con el tiempo.

## Goals / Non-Goals

**Goals:**
- Extraer pares `wrong → correct` de cada segmento corregido por IA.
- Proponerlos como `pending` (revisión humana), no auto-aprobar.
- Idempotencia: no duplicar pares ya existentes.
- Filtrar ruido (nombres propios, marcas, segmentos enteros).

**Non-Goals:**
- No auto-aprobar.
- No reemplazar el pase IA.
- No tocar `text_raw` ni el flujo de parseo.

## Decisions

### D1. Extracción de pares por diff de tokens

Al corregir un segmento, comparar `text_raw` (original) con `text` (corregido). Alinear por tokens y extraer los pares de palabras/frases que cambiaron.

**Implementación:** tokenizar ambos textos, alinear con LCS (longest common subsequence) y extraer los segmentos que difieren. Para cada diferencia, el `wrong` es el texto original y el `correct` el corregido.

**Por qué:** captura exactamente lo que la IA cambió, sin inventar pares.

### D2. Proponer como `pending` con `source='ai-coherence-learn'`

Los pares se insertan con `status=pending`, `risk_level=medium`, `source='ai-coherence-learn'`. El admin los revisa en `/ia/correcciones`.

**Por qué:** el flujo de aprobación existente ya maneja `pending`; no hay que crear UI nueva.

### D3. Filtro de calidad

Solo se proponen pares que:
- No existan como `pending`/`approved` (idempotencia por `wrong_normalized`).
- Sean de 1-4 palabras (no segmentos enteros).
- No sean nombres propios/marcas (`EnEsRuleClassifier::looksLikeBrandOrProperNoun`).
- No sean ruido (`EnEsRuleClassifier::classify` → NOISE).

**Por qué:** evita llenar la cola de pendientes con basura.

### D4. Tope por corrida

Límite de pares propuestos por transcripción (ej. 5) para no saturar la cola de pendientes.

**Por qué:** control de volumen; el admin no debe ahogarse en pendientes.

## Risks / Trade-offs

- **Riesgo bajo:** solo inserta `pending`; no afecta el texto guardado ni el diccionario activo.
- **Volumen de pendientes:** mitigado por filtro de calidad + tope por corrida.
- **Falsos pares:** el admin revisa antes de aprobar; `risk_level=medium` los marca como "revisar".
- **No hay migración ni cambio de schema.**
