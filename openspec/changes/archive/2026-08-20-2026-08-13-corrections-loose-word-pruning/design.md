# Design: Purga de reglas inertes e inseguras

## Por qué el umbral de similitud no servía

La puerta anterior para reemplazos de una o dos palabras era `similar_text() >= 85 %`. Lo que deja pasar:

```
  presidenta  -> presidente     90 %   cambia el GÉNERO
  innocent    -> inocente       87 %   es una TRADUCCIÓN
  Powerball   -> Powerball     100 %   no cambia NADA
```

Y lo que necesita seguir dejando pasar, según lo que el proyecto ya decidió y `LooseWordGuardrailTest` fija:

```
  mas          -> más
  echa         -> hecha
  strategia    -> estrategia
  difficultades-> dificultades
```

Parecerse no distingue unas de otras. La forma de la palabra sí.

## Los cuatro patrones ortográficos

`isOrthographicVariant()` acepta solo lo que encaje en uno de estos, todos reversibles y sin carga semántica:

```
  1. tilde/mayúsculas   fold(a) === fold(b)              mas -> más
  2. h muda             quitando h son iguales           echa -> hecha
  3. e protética        b === "e"+a, y a empieza s+cons  strategia -> estrategia
  4. consonante doble   pp/ff/ss/tt/mm/bb/dd/gg/zz/vv/jj opportunidades -> oportunidades
     imposible
```

El punto 3 se apoya en que el español no admite s+consonante en inicio de palabra — de ahí *estrategia*, *España*, *espacio*.

## La distinción que costó dos intentos

El primer `isMisspelledSpanish()` devolvía `true` para todo lo que no fuera grafía española válida. Confundía dos cosas:

```
   opportunidades   'pp' imposible, pero forma española   -> español MAL ESCRITO
   innocent         acaba en 't', imposible en español    -> otra LENGUA
```

Ambas son "no español válido", pero solo la primera se arregla corrigiendo la ortografía; la segunda se arregla traduciendo, que es justo lo que el diccionario no debe hacer. Por eso el método descarta **primero** los indicios de lengua extranjera y solo después busca la evidencia positiva de typo:

```
  looksNonSpanish (th, sh, ck, k, w…)      -> false, es extranjera
  termina en t,c,m,p,g,b,f,v,h             -> false, es extranjera
  termina en -y (salvo hoy, muy, rey…)     -> false, es extranjera
  sufijo -tion/-ity/-ous/-ive/-ance/…      -> false, es extranjera
  ─────────────────────────────────────────────────────────────────
  consonante doble imposible               -> true, español mal escrito
```

Sufijos deliberadamente **fuera** de esa lista porque también son españoles: `-able`/`-ible` (*posible*, *amable*) y `-sion`, porque `inversion` es *inversión* sin tilde, no una palabra inglesa.

Es conservador a propósito: un typo por letra omitida (`infrastructura`) no da evidencia positiva y cae del lado prudente. Se pierde una sugerencia legítima, que es más barato que admitir un cambio de significado.

## Orden en el clasificador

El cubo `NOISE` va **primero**, antes que el test de tilde:

```
  classify("Powerball", "Powerball")
     │
     ├─ 0. wrong === correct ?  ──── sí ──> NOISE          ← nuevo
     │
     ├─ 1. fold(a) === fold(b) ? ─── sí ──> KEEP (tilde)   ← aquí caían antes
     ...
```

Un par idéntico también pliega al mismo ASCII, así que el test de tilde lo daba por bueno. La comprobación nueva es de igualdad **exacta**, para no llevarse por delante `mas`→`más`, que sí cambia el texto.

## Seguridad del borrado

| Clase | Estado | ¿Borrable? | Por qué |
|---|---|---|---|
| Inerte | cualquiera | sí | Por definición no cambió ningún texto: no hay nada que revertir |
| Insegura | `pending` | sí | Nunca se aplicó |
| Insegura | `approved` | **no** | Ya modificó transcripciones; quitarla exige corrida retroactiva |

El comando refleja esa tabla: con `--status=approved --apply` borra las inertes y avisa por pantalla de las inseguras que deja intactas.

## Archivos

| Archivo | Cambio |
|---|---|
| `app/app/Services/Ia/EnEsRuleClassifier.php` | Cubo `NOISE`, `isMisspelledSpanish()`, `isOrthographicVariant()` |
| `app/app/Services/Ia/LlmCorrectionSuggester.php` | `isSpellingFix()` corregido; rechazo de `NOISE` |
| `app/app/Services/Ia/CorrectionService.php` | `isEnEsTranslation()` veta también `NOISE` |
| `app/app/Console/Commands/PruneNoiseCorrectionsCommand.php` | **nuevo** — `corrections:prune-suggestions` |
| `app/app/Console/Commands/QuarantineEnEsRulesCommand.php` | Cuenta y muestra el cubo `NOISE` |
| `app/tests/Unit/EnEsRuleClassifierTest.php` | 19 tests nuevos |
| `app/tests/Unit/LooseWordGuardrailTest.php` | `internacionales`→`internacionales` pasa de aceptado a rechazado |

## Nota sobre `LooseWordGuardrailTest`

El caso `'idéntico' => ['internacionales', 'internacionales']` estaba en el grupo de "arreglos ortográficos que pasan". Esa expectativa **era el bug**: es la que dejaba entrar las 49 reglas inertes. Se movió al grupo de rechazos, junto con tres casos nuevos (`presidenta`→`presidente`, `ahorita`→`ahora`, `innocent`→`inocente`) que el umbral de similitud dejaba pasar.
