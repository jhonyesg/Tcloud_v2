# Change: Purga de reglas inertes e inseguras, y filtro en el origen

## Why

Al revisar una corrección con el modal de ejemplos recién construido, el admin encontró este segmento:

```
Como lo transcribió:
  ...abrir la explotación comercial de competitions como el Mundial and the
  inversion private debe adoptarse, pero mediante un processor genuino and
  inclusive, and not a travel of ultimatums.

Cómo quedaría con esta regla:
  ...abrir la explotación comercial de competiciones como el Mundial and the
  inversion private...
```

La regla arregla una palabra y deja la frase igual de rota. Su diagnóstico: *"palabras sola no porque genera problemas, cambia el significado de todas las palabras y en vez de ayudar a mejorar, degrada"*.

La auditoría de esa hipótesis encontró tres problemas distintos, ninguno igual al enunciado:

### 1. El criterio no es el número de palabras

De las 328 reglas aprobadas de una palabra, las buenas (`mas`→`más`, `region`→`región`, `echa`→`hecha`, `quando`→`cuando`) y las dañinas (`different`→`diferentes`, `top`→`cima`) se mezclan. Y al revés: `of love`→`de love` y `of security`→`de security` son de dos palabras y producen espanglish puro.

El criterio que separa es **si el par arregla ortografía o cambia significado**, que es la política que el proyecto ya adoptó el 2026-08-11. La existente `isSpellingFix()` la implementaba con un umbral de similitud del 85 %, insuficiente: `presidenta`→`presidente` se parece un 90 % y cambia el género.

### 2. Cuarenta y nueve reglas inertes

`wrong_text` idéntico a `correct_text`: no cambian nada y el corrector las recorre en cada pasada sobre 20,6 M de segmentos. `internacionales`→`internacionales` acumulaba 368 aplicaciones sin efecto.

Dos causas, ambas bugs:
- `LlmCorrectionSuggester::isSpellingFix()` devolvía `true` ante un par idéntico, dándolo por arreglo ortográfico.
- `EnEsRuleClassifier` las etiquetaba `KEEP` con motivo *"corrección de tilde/mayúsculas"*, porque el test de tilde pliega ambos lados al mismo ASCII y salían iguales.

### 3. Veintinueve traducciones activas

Escaparon a la cuarentena de agosto y se aplican hoy, con 23.699 aplicaciones: `the world`→`el mundo`, `this moment`→`este momento`, `the government`→`el gobierno`.

## What Changes

### Clasificador

- Cubo nuevo `NOISE`, comprobado **antes** que el de tilde, para pares exactamente iguales.
- `isMisspelledSpanish()`: evidencia positiva de que una palabra con forma española está mal escrita (consonante doble imposible: `pp`, `ff`, `ss`, `tt`…). Descarta expresamente lo que no es español mal escrito sino otro idioma — terminación consonántica ajena (`innocent`, `different`), `-y` final (`interdisciplinary`), sufijos ingleses.
- `isOrthographicVariant()`: la puerta de los reemplazos cortos. Cuatro patrones ortográficos concretos en vez de un umbral de similitud — tilde/mayúsculas, h muda, e protética, consonante doble imposible.

### Guardrails en el origen

- `isSpellingFix()` rechaza los no-op y, por encima del umbral de similitud, exige encajar en un patrón ortográfico.
- El suggester descarta el cubo `NOISE` con motivo `no_op`.
- `CorrectionService::isEnEsTranslation()` veta también `NOISE`, cubriendo las tres puertas (miner, suggester y alta por sistema).

### Herramienta

`corrections:prune-suggestions`, read-only por defecto. Separa dos clases por su riesgo:

- **inertes**: borrado seguro en cualquier estado, no obliga a reaplicar nada.
- **inseguras**: solo se borran en `pending`. Sobre `approved` informa y remite a `corrections:quarantine-en-es`, porque quitarlas exige una corrida retroactiva.

`corrections:quarantine-en-es` aprende a contar el cubo nuevo, que si no habría descartado en silencio.

### Ejecutado

23 pendientes borradas (10 inertes + 13 inseguras). Quedan 10: cinco frases y cinco typos españoles reales (`opportunidades`, `professionales`, `necessariamente`, `Possiblemente`, `Affectados`).

## Non-Goals

- **No** se borra nada de `approved`. Las 386 activas quedan en un informe; las 29 traducciones y las 38 inertes esperan decisión.
- **No** se lanza ninguna corrida retroactiva.
- **No** se borra por número de palabras: el criterio es ortografía frente a significado.
- **No** se toca `risk_level` de ninguna regla.
