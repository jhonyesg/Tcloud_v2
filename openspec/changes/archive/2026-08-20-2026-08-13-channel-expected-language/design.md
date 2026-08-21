# Design: por qué el idioma se decide por racha y no por canal

> Corrección del enfoque, 2026-08-13. La propuesta original excluía canales
> enteros. **No sirve**, y se revirtió el mismo día. Este documento deja el
> motivo y la medición para que nadie lo vuelva a intentar.

## Por qué el canal es la unidad equivocada

Objeción del admin, confirmada con datos:

1. **Teleislas también emite en español.** Excluir el canal deja sin corregir todo
   su material español.
2. **Hay transcripciones en español que el motor devuelve en inglés**, en
   cualquier canal. La exclusión por canal no las ve, y son precisamente el
   defecto que hay que cazar.

Su criterio, que resultó ser el correcto:

> *"No puede haber conversaciones que están en español y en un momento u otro hay
> inglés metido en medio, siguiendo la línea de la conversación, porque sería
> incongruente. Es congruente con propagandas, con música, pero no con
> entrevistas."*

La unidad de decisión no es el canal ni el segmento aislado: es la **racha de
segmentos consecutivos** en el mismo idioma dentro del hilo de la transcripción.

## La medición que lo confirma

300 transcripciones del 2026-08-13, segmentos ordenados por `segment_index`,
clasificados como inglés cuando ≥40 % de sus tokens no son españoles. Longitud de
las rachas de inglés consecutivo:

```
  longitud   rachas   segmentos   % del inglés total
     1         463        463         32,0 %
     2         196        392         27,1 %      ─┐ 59,1 % en rachas de 1-2
     3          78        234         16,2 %
     4          29        116          8,0 %
     5          20        100          6,9 %
     6+         38        226          9,8 %
```

Y el contenido separa igual de limpio:

**Rachas de 1-2 segmentos — español mal transcrito, sin excepción en la muestra:**
```
  "It's a campo de action that the psiquiatria and the salute mental ayudar much"
                                                     └─ "salud mental"
  "This edific was a temple in Alabama"
   └─ "edificio"
  "Ministerio TEMIAS Nos amamos siempre en familia Respetamos los valores
   Lake we come together talk about the things that matter"
   └─ arranca en español y se desliza al inglés a mitad de segmento
```

**Rachas de 8 o más — canciones, sin excepción en la muestra:**
```
  "Talking to yourself in the bathroom, losing your mind in the mirror"
  "Shed a tear cause I'm missing you"
  "And now I'm in a sea of lights"
```

El 59,1 % del inglés está en rachas de una o dos segmentos, es decir, **es
defecto**, no contenido. Eso invierte la conclusión anterior, que daba casi todo
el inglés por legítimo basándose en el agregado por canal.

## Qué se conserva y qué se revierte

| pieza | destino |
|---|---|
| Tabla `channel_languages` y los 108 canales | **se conserva** como metadato |
| `ChannelSlug::fromFilename()` | **se conserva**, es correcto y está probado |
| `apply_corrections = false` en teleisla/uniminuto/lafmplus | **revertido** |
| `CorrectionService::appliesToTranscription()` | queda, sin nadie que lo active |
| `excludedTranscriptionIds()` en la corrida retroactiva | queda, devuelve vacío |

El campo `language` sigue siendo útil como *prior* — un canal marcado `en` hace
más probable que una racha larga sea legítima — pero **nunca como veto**.

## Diseño propuesto para el detector por racha

```
  segmentos de una transcripción, ordenados por segment_index
        │
        ▼
  clasificar cada uno: es | en | indeterminado (<4 palabras)
        │
        ▼
  agrupar en rachas consecutivas de inglés
        │
        ├── racha corta (1-2)  ─────▶  DEFECTO del motor
        │                              → marcar para re-transcribir
        │                              → NO parchear con el diccionario
        │
        ├── racha media (3-4)  ─────▶  revisión humana
        │
        └── racha larga (5+)   ─────▶  contenido legítimo
                                       → no tocar, excluir de la métrica
```

Umbrales a configurar, no a fijar en código: la frontera 2/3 y 4/5 sale de una
muestra de 300 transcripciones de un solo día y hay que recalibrarla.

Nota de implementación: esto se calcula **por transcripción**, sobre sus segmentos
ya cargados y ordenados. No requiere ninguna consulta nueva sobre
`transcription_segments` más allá de la que ya hace `TranscriptionReviewService`.
