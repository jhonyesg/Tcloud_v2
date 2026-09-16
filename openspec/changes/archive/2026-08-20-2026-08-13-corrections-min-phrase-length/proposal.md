# Change: Mínimo de tres palabras para las sugerencias automáticas

## Why

Decisión del admin tras revisar el diccionario con el modal de ejemplos: *"una palabra sola crea mucho espanglish, así que eso como sugerencias de correcciones ya no va a ir, tiene que ir la palabra completa o las frases completas"*.

La auditoría confirma el diagnóstico y lo amplía: **el problema no se detiene en la palabra suelta**. Las reglas que más degradaban eran bigramas:

```
  of love      -> de love          604 aplicaciones
  of security  -> de security      585
  of emergency -> de emergency
  of cali      -> de cali
```

Todas de dos palabras, y todas dejan el espanglish intacto porque solo traducen la preposición. Con tres palabras la regla se ancla a su contexto y deja de disparar donde no debe.

### De dónde salían

`CycleSuggestionsCommand` extraía **bigramas** `function_en + siguiente_token` y los proponía con `heuristicSpanish()`, que traduce la preposición y deja el sustantivo tal cual. Su filtro `looksSpanishNoun()` era una denylist: `emergency` no estaba en ella ni encajaba en su lista de sufijos ingleses, así que pasó.

Es el mismo patrón que el propio código critica en `LlmCorrectionSuggester`: *"La defensa anterior era una DENYLIST… es parcheo uno a uno por diseño"*.

## What Changes

### Umbral configurable

`corrections.min_suggestion_words` (default **3**). Se aplica en las tres puertas automáticas:

- `LlmCorrectionSuggester`: rechaza con motivo `frase_demasiado_corta`.
- `CorrectionService::mineEnEsMix()` y `aiSuggestEnEsMix()`: `isTooShortToPropose()`.
- `CycleSuggestionsCommand`: extrae trigramas, no bigramas.

**No** afecta al alta manual del admin en `/ia/correcciones`. Si un humano decide que una palabra concreta hay que corregirla, es su criterio; lo que se corta es la generación automática.

### Trigramas con ancla española

El n-grama incorpora la palabra **anterior** a la function word, y esa palabra debe ser española:

```
  antes:  of emergency              -> de emergency        dispara en cualquier frase
  ahora:  servicios of emergency    -> servicios de emergencia   solo en su contexto
```

### Prueba positiva de morfología

`EnEsRuleClassifier::looksSpanishWord()` sustituye a la denylist. En vez de enumerar lo que no es español, comprueba que la grafía **pueda** serlo: descarta dígrafos ajenos (th, sh, ck, k, w), terminaciones consonánticas imposibles, `-y` final y sufijos exclusivamente ingleses.

### Guardrail sin excepciones

`isSpellingFix()` deja de ser vía de escape del umbral. Antes un arreglo ortográfico de una palabra podía colarse; ahora el umbral se aplica primero y sin condiciones, y el test ortográfico queda solo como red por si se baja el umbral en config.

## Ejecutado en producción

| Operación | Resultado |
|---|---|
| Cuarentena de traducciones activas | 29 reglas a `risk_level=high` (reversible con `--revert`) |
| Purga de aprobadas inertes | 38 borradas |
| Pendientes de una palabra | 5 borradas |
| Pendientes en frase, sanas | 3 aprobadas |
| Pendientes en frase, traducen | 2 rechazadas |
| **Pendientes restantes** | **0** |

Aprobadas: `Andanda grabando con el camarógrafo`→`Anduve…`, `trayetto con el lado desayuno.`→`trayecto…`, `Stuff mal, mal, mal un año.`→`Está mal…`. Las tres son typos del ASR en español, ancladas en cinco o más palabras.

Rechazadas: `Give me a sum.`→`Dame un resumen.` (traducción pura) y `Hey, el Americano 4 cate las muñequitas`→`Oye, … capté …` (traduce `Hey`→`Oye` además de arreglar `cate`→`capté`).

## Non-Goals

- **No** se restringe el alta manual del admin.
- **No** se borra ninguna regla aprobada que haya modificado texto; las traducciones van a cuarentena, que es reversible.
- **No** se tocan las 133 aprobadas ambiguas: requieren criterio humano y el modal de ejemplos existe para eso.
