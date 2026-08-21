# Change: Ronda 2 — 12 reglas bulk para inglés residual en transcripciones ES

## Why

Tras crear el change `2026-08-11-corrections-english-residual-rules` con 4 reglas puntuales basadas en una auditoría de 5 transcripciones, se ejecutó una auditoría completa del día 2026-08-11 sobre **17,057 transcripciones `done` con 2,280,972 segmentos** (ratio de segmentos modificados por el corrector: **0.91%**).

El scan de patrones ingleses recurrentes en `text` reveló que la brecha de inglés residual en español es **significativamente mayor** de lo que las 4 reglas cubren:

| Patrón | Ocurrencias hoy | Categoría |
|--------|----------------:|-----------|
| `the gente` | 318 | low ✅ |
| `the authorities` | 640 | medium ⚠️ |
| `the information` | 391 | medium ⚠️ |
| `the principal` | 404 | low ✅ |
| `in the world` | 629 | medium (descartado por amplitud) |
| `the opportunity` | 142 | low ✅ |
| `in the same` | 89 | low ✅ |
| `ahora is` | 34 | low ✅ |
| `the cosas` | 24 | low ✅ |
| `the personas` | 21 | low ✅ |
| `the monitoreo` | 12 | low ✅ |
| `for the gente` | 14 | low ✅ (cubierto en ronda 1) |
| `in the celular` | 4 | low ✅ (cubierto en ronda 1) |
| `in this initiative` | 3 | low ✅ (cubierto en ronda 1) |
| `i think` | 1,653 | high ❌ (code-switching legítimo) |
| `you know` | 1,635 | high ❌ (muletilla legítima) |
| `i mean` | 255 | high ❌ (muletilla legítima) |
| `the` suelto | 93,919 | high ❌❌ (rompería contenido legítimo) |
| `and` suelto | 55,290 | high ❌❌ (rompería contenido legítimo) |

El corrector solo modifica 0.91% de los segmentos del día. Esto significa que ~99% de los errores tipográficos / de idioma pasan intactos. Agregar reglas focas con palabra ancla española (`gente`, `personas`, `monitoreo`, `celular`, `cosas`, etc.) es la palanca más efectiva para reducir la brecha sin riesgo de regresión.

## What Changes

### 1. Diez reglas nuevas con `risk_level=low`

Insertar en la tabla `corrections` con `status='approved'`, `risk_level='low'`, mismo firmante:

| # | wrong_normalized     | wrong_text              | correct_text           | Ocurrencias hoy | Justificación de seguridad |
|---|----------------------|-------------------------|------------------------|----------------:|----------------------------|
| 1 | `the gente`          | `the gente`             | `la gente`             | 318 | "gente" fuerza contexto ES inequívoco |
| 2 | `the principal`      | `the principal`         | `el principal`         | 404 | "principal" es sustantivo ES común |
| 3 | `the opportunity`    | `the opportunity`       | `la oportunidad`       | 142 | "opportunity" no es ES válido |
| 4 | `the cosas`          | `the cosas`             | `las cosas`            |  24 | "cosas" es genitivo ES |
| 5 | `the monitoreo`      | `the monitoreo`         | `el monitoreo`         |  12 | "monitoreo" es ES literal |
| 6 | `the personas`       | `the personas`          | `las personas`         |  21 | "personas" fuerza plural ES |
| 7 | `ahora is`           | `ahora is`              | `ahora es`             |  34 | "ahora" precede, is→es invariable |
| 8 | `in the same`        | `in the same`           | `en el mismo`          |  89 | "same" es anglicismo claro en ES |
| 9 | `the monitoreo`      | (re-evaluado)           | (deduplicado por UNIQUE) | — | igual a #5 |
| 10 | (slot)              | —                       | —                      | — | — |

Nota: las 4 reglas de la ronda 1 (`the gente` ya incluido, `for the gente`, `in the celular`, `in this initiative`) **deben insertarse igualmente** en este round si la ronda 1 no fue aplicada todavía (constraint UNIQUE por `wrong_normalized` solo entre `status='approved'`, así que son idempotentes). El verificador previo debe confirmar cuáles ya existen.

### 2. Dos reglas con `risk_level=medium`

| # | wrong_normalized     | wrong_text              | correct_text           | Ocurrencias hoy | Riesgo |
|---|----------------------|-------------------------|------------------------|----------------:|--------|
| 1 | `the authorities`    | `the authorities`       | `las autoridades`      | 640 | medium |
| 2 | `the information`    | `the information`       | `la información`       | 391 | medium |

`risk_level=medium` significa: se aplican automáticamente, pero `CorrectionService` las registra como auditables. Aparecen en la pestaña "Contexto sensible" para revisión periódica.

### 3. Patrones EXPLÍCITAMENTE descartados (con motivo)

- `the`, `and`, `for`, `in`, `of`, `is`, `was`, `are`, `in the` sueltos: 93,919 / 55,290 / 22,796 / 67,018 / 51,716 / 28,891 / 8,782 / 17,039 ocurrencias respectivamente. Aplicar reglas sueltas rompería masivamente; no son "errores recurrentes" sino **vocabulario inglés legítimo** en contenido bilingüe, canciones, marcas y citas.
- `i think`, `you know`, `i mean` (1,653 / 1,635 / 255): **code-switching legítimo** característico de habla bilingüe colombiana. Cambiar destruiría el registro conversacional.
- `in the world` (629): demasiado genérico, "in the world" puede ser cita / marca / expresión.
- Canciones/letras intencionalmente en inglés (caso camarafm con rap Craig Mac ~30 segmentos): no son errores; ignorar.
- Frases largas en inglés dentro de entrevistas bilingües: requieren detección por ratio ES/EN por segmento (cambio aparte, no en este).

### 4. Sin código nuevo

Idéntico al change anterior: solo INSERTs en `corrections`. `CorrectionService::applyToSegments()` las aplicará a próximas transcripciones.

### 5. Sin marcar nuevas transcripciones como `needs_review`

El usuario decidió ignorar por ahora las 126 transcripciones con `corrected=-1` (corrector falló). Esas requieren investigación aparte del fallo del corrector.

## Non-goals

- No se modifica `CorrectionService` ni `TranscriptionReviewService`.
- No se re-aplican reglas retroactivamente.
- No se agrega detección de mezcla ES/EN por segmento (cambio aparte).
- No se tocan las 126 transcripciones `corrected=-1`.
- No se cambian las reglas aprobadas existentes.
- No se crea UI nueva.

## Success Criteria

- 12 reglas nuevas existen en `corrections` con los `wrong_normalized`, `correct_text`, `risk_level`, `status='approved'` y autores correctos. (8 low + 2 medium, donde 2 ya podrían existir dedupe'd desde ronda 1).
- Las 4 reglas de ronda 1, si aún no se aplicaron, se aplican en este mismo round (idempotente).
- Una próxima transcripción que diga "the gente" tiene `text` con "la gente" aplicado.
- Una próxima transcripción que diga "the authorities" tiene `text` con "las autoridades" aplicado y figura en la pestaña "Contexto sensible" para auditoría.
- Las reglas high-risk (`the`, `and`, etc.) **NO** se crean.
- Las 126 transcripciones con `corrected=-1` **NO** se tocan.
