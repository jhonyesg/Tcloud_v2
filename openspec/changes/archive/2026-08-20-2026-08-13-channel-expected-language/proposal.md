# Change: Idioma esperado por canal

> **Documento de continuidad.** Es auto-contenido a propósito: recoge las
> mediciones y las decisiones para poder retomar el trabajo sin el contexto de la
> conversación en que nació.

## Why

El objetivo declarado era llevar el espanglish de las transcripciones al 0 %. La
medición sobre las 5.447 transcripciones del 2026-08-13 demuestra que ese
objetivo **está mal planteado**: buena parte del inglés que la métrica cuenta
como defecto es contenido correctamente transcrito.

Residuo de inglés por canal (muestra de 120.000 segmentos del día):

```
  uniminuto                28,08 %      ← música en inglés
  teleisla                 25,52 %      ← criollo raizal de San Andrés
  lafmplus                 15,33 %      ← música en inglés
  ...
  telemedellin              0,56 %
  radiouno                  0,98 %
  bluradio                  1,01 %
  tropicana*             1,2 – 2,4 %
```

Los dos peores canales no contienen un solo error:

```
  uniminuto:  "Cause that's for chasing cars."      → Snow Patrol
              "The truth is bulletproof."
  lafmplus:   "Falling in love with you."           → Elvis Presley
  teleisla:   "Thank you, Teleislas..."             → emite en inglés/criollo
```

El diccionario de correcciones llevaba meses "arreglando" ese material y
produciendo espanglish donde antes había una transcripción correcta. Es el origen
real del problema que motivó toda esta línea de trabajo.

Distinguir el idioma esperado de cada canal permite:

1. **Dejar de destruir** transcripciones correctas (Teleislas, música).
2. **Medir de verdad**: el residuo en un canal español sí es un defecto. La cola
   larga ya está en 1-2 %, así que el trabajo restante es mucho menor de lo que
   la métrica global sugería.
3. **Separar el fallo real**: lo que sobreviva es del tipo `salute mental`
   (por "salud mental") o `edific` (por "edificio") — el motor traduciendo en vez
   de transcribir. Eso se re-transcribe, no se parchea.

### Dato adicional: el `lang_fix` del motor no sirve

`TranscriptorApiClient::submitNoCallback()` envía `language=es` y
`lang_fix=async`. El corrector de idioma del motor externo (192.168.0.138:9000)
escribe la columna `transcriptions.corrected`. Medición comparativa:

| | inglés en tokens | segmentos mayoría-inglés |
|---|---|---|
| `corrected = 1` (pasó por el corrector) | 5,73 % | 5,70 % |
| `corrected IS NULL` (nunca pasó) | 5,53 % | 5,10 % |

No hay diferencia. Conviene reclamarlo al proveedor antes de construir nada más
en este lado.

## What Changes

### Modelo de datos

Tabla nueva `channel_languages`:

| columna | tipo | nota |
|---|---|---|
| `slug` | varchar(80), único | derivado de `transcriptions.original_name` |
| `label` | varchar(160), nullable | nombre legible |
| `language` | varchar(8), default `es` | `es`, `en`, `mixed` |
| `apply_corrections` | boolean, default `true` | si `false`, el diccionario no toca sus segmentos |
| `notes` | text, nullable | por qué |

Hay **64 slugs** distintos en el corpus.

### Extracción del slug

Dos convenciones de nombre conviven:

```
  teleisla_13082026_073002.mp4              → teleisla
  15_abc_atlantico_19072026_154003.mp3      → abc_atlantico
```

Regla: quitar la extensión, quitar el prefijo `\d+_` inicial si lo hay, y cortar
en el primer token de 8 dígitos (la fecha). Lo anterior, unido por `_`, es el
slug. 34.497 transcripciones usan la segunda convención, así que la regla tiene
que cubrir ambas.

### Puntos de uso

1. **Aplicación del diccionario.** `CorrectionService::applyRetroactively()` y
   `applyToSegments()` saltan las transcripciones cuyo canal tenga
   `apply_corrections = false`.
2. **Detector de residuo.** `EnglishResidualSegmentDetector` no marca
   `needs_review` en canales no españoles.
3. **Productores de sugerencias.** El ciclo y el miner no extraen candidatos de
   canales no españoles: hoy es de donde salía buena parte del ruido.

## Non-Goals

- **No** se detecta música en esta change (es el paso 2 del plan, aparte).
- **No** se re-transcribe nada (paso 3).
- **No** se toca el diccionario existente: queda como está, solo frases y con
  aprobación humana.
- **No** se usa la tabla `canales`: es otro subsistema (24 slots de grabación,
  `slot_nombre` tipo `Puntual_05`) y no casa con los prefijos de archivo.

## Plan por pasos

| paso | qué | estado |
|---|---|---|
| 1 | Idioma esperado por canal | **esta change** |
| 2 | Detección de música (letra repetida) para excluirla de la métrica | pendiente |
| 3 | Re-transcribir lo que sobreviva, en vez de parchearlo | pendiente |
