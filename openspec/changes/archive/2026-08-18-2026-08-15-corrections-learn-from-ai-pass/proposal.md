# Change: Aprender de las correcciones IA para alimentar el diccionario

## Why

El pase de coherencia IA (`TranscriptionCoherencePass`) corrige el spanglish residual que el diccionario no cubre. Cada corrección IA es una oportunidad de **aprendizaje**: si extraemos el par `wrong → correct` de cada segmento corregido y lo proponemos como corrección `pending`, el admin lo aprueba y entra al diccionario. Así, la **primera pasada (diccionario)** captura cada vez más frases y se necesita menos IA con el tiempo.

Hoy ese conocimiento se pierde: la IA corrige el texto, se guarda, y el diccionario no aprende nada. El resultado es que la IA corrige las mismas frases una y otra vez (ej. "in this moment" → "en este momento") sin que el diccionario las absorba.

## What Changes

### 1. Extracción de pares `wrong → correct` tras el pase IA

En `TranscriptionCoherencePass`, cuando la IA corrige un segmento, comparar `text_raw` (original del transcriptor) con `text` (corregido por IA) y extraer los pares de palabras/frases que cambiaron.

### 2. Proponer los pares como correcciones `pending`

Los pares extraídos se insertan como `Correction` con `status=pending`, `source='ai-coherence-learn'`, `risk_level=medium` (requieren aprobación humana). El admin los revisa en `/ia/correcciones` y los aprueba/rechaza.

### 3. Filtro de calidad

Solo se proponen pares que:
- No existan ya como `pending` o `approved` (idempotencia).
- Sean frases de 1-4 palabras (no segmentos enteros).
- No sean nombres propios/marcas (reutilizar `EnEsRuleClassifier`).
- No sean traducciones EN→ES puras de una sola palabra ambigua (dejar al admin decidir).

## Non-goals

- **No** auto-aprobar: los pares aprendidos entran como `pending` para revisión humana.
- **No** reemplazar el pase IA: el diccionario y la IA coexisten; el aprendizaje solo reduce la carga de IA.
- **No** tocar `text_raw` ni el flujo de parseo.

## Impact

- **Code affected (modificado):**
  - `app/app/Services/Ia/TranscriptionCoherencePass.php` (extraer pares + proponer)
  - `app/app/Services/Ia/CorrectionService.php` (método para proponer pares aprendidos)
- **Migrations:** ninguna.
- **Riesgos:** bajo — solo inserta `pending` (no afecta el texto guardado ni el diccionario activo hasta que el admin apruebe).
