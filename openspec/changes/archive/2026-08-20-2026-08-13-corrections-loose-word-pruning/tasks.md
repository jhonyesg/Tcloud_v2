# Tasks: Purga de reglas inertes e inseguras

## 1. Clasificador

- [x] Cubo `NOISE` comprobado antes que el test de tilde, con igualdad exacta.
- [x] `isMisspelledSpanish()`: descarta primero los indicios de lengua extranjera, luego busca la consonante doble imposible.
- [x] `isOrthographicVariant()`: tilde/mayúsculas, h muda, e protética, consonante doble.
- [x] Dejar `-able`/`-ible` y `-sion` fuera de los sufijos ingleses (`posible`, `inversión`).

## 2. Guardrails en el origen

- [x] `isSpellingFix()` rechaza no-op y exige patrón ortográfico por encima del umbral.
- [x] El suggester descarta `NOISE` con motivo `no_op`.
- [x] `CorrectionService::isEnEsTranslation()` veta `NOISE` además de `QUARANTINE`.

## 3. Herramientas

- [x] `corrections:prune-suggestions` con `--apply`, `--status`, `--noise-only`; read-only por defecto.
- [x] Inseguras solo se borran en `pending`; sobre `approved` avisa y no toca.
- [x] `corrections:quarantine-en-es` cuenta y muestra el cubo `NOISE`.

## 4. Tests

- [x] 19 tests nuevos en `EnEsRuleClassifierTest` (cubo NOISE, typo vs cambio semántico, sufijos españoles).
- [x] `LooseWordGuardrailTest`: `internacionales`→`internacionales` movido a rechazos; añadidos `presidenta`→`presidente`, `ahorita`→`ahora`, `innocent`→`inocente`.
- [x] Suite completa: los 4 fallos introducidos quedaron resueltos.

## 5. Ejecución

- [x] Informe de pendientes: 10 inertes + 13 inseguras de 33.
- [x] `--apply` sobre pendientes: 23 borradas, 10 conservadas.
- [x] Informe de las 386 aprobadas activas.

## Pendiente de decisión del usuario

- [ ] Las **29 traducciones aprobadas y activas** (23.699 aplicaciones): `the world`→`el mundo`, `this moment`→`este momento`, `the government`→`el gobierno`… Se arreglan con `corrections:quarantine-en-es --apply`, que las pone en `risk_level=high` sin borrarlas (reversible con `--revert`).
- [ ] Las **38 aprobadas inertes**: `corrections:prune-suggestions --status=approved --apply --noise-only`. Borrado seguro, no necesita reaplicar.
- [ ] Las **133 ambiguas** con 23.365 aplicaciones, entre ellas `of love`→`de love` y `of security`→`de security`, que producen espanglish. Requieren criterio humano; el modal "Ver ejemplos" está pensado para eso.
- [ ] Si se decide quitar traducciones ya aplicadas, hace falta `transcription:apply-corrections` para reparar el histórico.

## Notas

Cuatro sugerencias razonables cayeron con las inseguras por el criterio conservador: `com ustedes`→`con ustedes`, `infrastructura`→`infraestructura`, `acceptamos`→`aceptamos` y `Ex-Ray Dol`→`X-Ray Doll`. Ninguna se había aplicado nunca. Se pueden volver a dar de alta a mano desde "Nueva corrección", que no pasa por este filtro.
