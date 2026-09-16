# Tasks: Mínimo de tres palabras para las sugerencias automáticas

## 1. Umbral

- [x] `corrections.min_suggestion_words` (default 3) en `config/corrections.php` y `.env.example`.
- [x] `LlmCorrectionSuggester`: rechaza por debajo del umbral con motivo `frase_demasiado_corta`, sin excepción por arreglo ortográfico.
- [x] `isSpellingFix()` queda como red por si se baja el umbral en config.
- [x] `CorrectionService::isTooShortToPropose()` aplicado en las dos rutas de producción (miner y ai-suggest).
- [x] El alta manual del admin no pasa por el umbral.

## 2. Trigramas con ancla

- [x] `collectAndExtractBigrams()` y `extractBigrams()` arrancan en `i = 1` e incorporan el token anterior.
- [x] El ancla se descarta si es una function word inglesa.
- [x] `buildCandidates()` parte el trigrama en tres y reconstruye la corrección como `ancla + heuristicSpanish(fn, noun)`.
- [x] Docblocks, `--min-freq` y mensajes de salida actualizados a "trigrama".

## 3. Prueba positiva de morfología

- [x] `EnEsRuleClassifier::looksSpanishWord()`.
- [x] `looksSpanishNoun()` de `CycleSuggestionsCommand` la usa como último filtro, tras su denylist.

## 4. Tests

- [x] 16 casos nuevos de `looksSpanishWord` (incluye `emergency`, que es el que originó la regla rota).
- [x] Suite completa: 416 tests. Los 4 fallos y 11 errores restantes son preexistentes y de otros módulos.

## 5. Operaciones en producción

- [x] `corrections:quarantine-en-es --apply` → 29 traducciones a `risk_level=high`.
- [x] `corrections:prune-suggestions --status=approved --apply --noise-only` → 38 inertes borradas.
- [x] Pendientes a cero: 5 palabras sueltas borradas, 3 frases aprobadas, 2 rechazadas por traducir.
- [ ] Repaso retroactivo `transcription:apply-corrections --days=3` (dry-run lanzado; pendiente de resultado y de la corrida real).

## Pendiente de decisión

- [ ] Las **133 aprobadas ambiguas** (23.365 aplicaciones), entre ellas `of love`→`de love` y `of security`→`de security`. El umbral nuevo impide que nazcan más, pero estas ya están dentro y siguen activas. Se revisan con "Ver ejemplos" en `/ia/correcciones`, o se cuarentenan en bloque si se prefiere cortar por lo sano.
- [ ] Revisar si el cron de `corrections:cycle-suggestions` sigue teniendo sentido: su `heuristicSpanish()` traduce la preposición y deja el sustantivo, así que por diseño produce frases a medio traducir. El ancla acota el daño, no lo elimina.
