# Design: cómo se mide la validación

## Restricciones que NO se pueden saltar

Van primero porque dos de ellas ya causaron incidentes.

### `transcription_segments` no admite full-scans

20,6 M filas, **8,3 GB**. Una consulta que la recorra entera satura producción; ya
pasó una vez y hubo que matarla a mano.

- `created_at` **no tiene índice**. Ordenar o filtrar por ahí degenera en seq scan.
- El único índice de texto es `idx_transcription_segments_text_gin`, GIN trigram,
  y está sobre la columna **`text`**, *no* sobre `text_raw` (esa no tiene ninguno).
- pg_trgm no sirve patrones de menos de 3 caracteres: por debajo, el planner
  vuelve al seq scan.
- Columnas con índice utilizable: `id` (PK) y `transcription_id`.

**Cómo acceder aquí**: resolver primero los `transcription_id` de la ventana desde
la tabla `transcriptions` (~195 k filas, barata), y luego traer segmentos con
`whereIn('transcription_id', ...)` en lotes. Es lo que hacen los scripts de
medición existentes y va sobrado.

Verificar SIEMPRE con `EXPLAIN` antes de dar por buena una consulta nueva.

### El diccionario no traduce

Corrige español (tildes, typos del ASR). Traducir EN→ES a posteriori degrada el
texto. La causa real es que el motor devuelve inglés sobre audio español, y eso se
arregla en el transcriptor, no aquí.

### Ninguna regla de una sola palabra

Mínimo 3 palabras para los productores automáticos
(`corrections.min_suggestion_words`). El alta manual del admin no pasa por el
umbral, a conciencia.

## Cómo se clasifica un segmento

Reutilizar lo que ya existe y está probado:

- `EnEsRuleClassifier::looksSpanishWord(string): bool` — prueba **positiva** de
  morfología española. Descarta dígrafos ajenos (th, sh, ck, k, w), terminaciones
  consonánticas imposibles, `-y` final y sufijos ingleses.
- `config('corrections.english_residual.es_stopwords')` — lista de stopwords
  españolas.

Un segmento se clasifica como inglés cuando **≥40 %** de sus tokens de 3+ letras
no son ni stopword española ni `looksSpanishWord`. Segmentos de menos de 4 tokens
se marcan **indeterminado** y no rompen una racha (son "sí", "gracias", ruido).

⚠ Ese 40 % y ese mínimo de 4 tokens son **parámetros a calibrar también**, no
constantes sagradas.

### Trampas ya pisadas en este clasificador

- `gestión` pliega a `gestion` y acaba en `-tion`. Sin el corte por diacrítico se
  daba por inglesa. Ya está resuelto: si la palabra lleva `áéíóúüñ`, es española.
- `-ive` está **fuera** de la lista de sufijos ingleses: en español lo llevan
  `vive`, `revive`, `sobrevive`.
- `-able`/`-ible` y `-sion` también fuera: `posible`, `amable`, y `inversion` es
  "inversión" sin tilde.
- **El plural inglés en `-s` es indistinguible del español.** `organisms`,
  `terms`, `moments`, `forms` pasan por españoles. Es el límite conocido de la
  morfología y la razón de que las denylists fallaran.

## El algoritmo de rachas

```
  segmentos de UNA transcripción, ordenados por segment_index
        │
        ▼
  clasificar: es | en | indeterminado
        │
        ▼
  agrupar rachas de `en` consecutivos
  (un `indeterminado` NO corta la racha; un `es` sí)
        │
        ▼
  histograma de longitudes + muestra por cubo
```

Se calcula **por transcripción**, en memoria, sobre segmentos ya cargados. No
requiere ninguna consulta adicional sobre `transcription_segments`.

## Qué tiene que producir el análisis

1. **Histograma de longitudes** global, por día y por canal.
2. **Estabilidad entre días**: la proporción de rachas 1-2 no debe moverse más de
   ±10 puntos.
3. **Muestra etiquetable**: 100 rachas cortas y 100 largas, exportadas a CSV con
   los segmentos vecinos en español, para que un humano marque
   `defecto` / `legítimo`. Sin este paso la validación es circular — estaríamos
   midiendo el clasificador contra sí mismo.
4. **Matriz de confusión** una vez etiquetada.

El punto 3 es el que de verdad decide. Los dos enfoques anteriores fallaron
precisamente por dar por buena una separación que solo existía en el agregado.

## Fuente de canal

`ChannelSlug::fromFilename()` ya existe y está probado (11 tests). Cubre las dos
convenciones del corpus:

```
  teleisla_13082026_073002.mp4            → teleisla
  15_abc_atlantico_19072026_154003.mp3    → abc_atlantico
```

La tabla `channel_languages` tiene los 108 canales con su idioma como
**metadato**. Ninguno está excluido y **no deben volver a excluirse**: sirve como
prior para interpretar los resultados, nunca como veto.

## Qué hacer con el resultado

| resultado | siguiente paso |
|---|---|
| Separación validada | Implementar el detector; rachas cortas → cola de re-transcripción |
| Frontera distinta | Ajustar umbrales y repetir la validación |
| Sin separación | Descartar el enfoque 3 y reconsiderar. **No forzarlo.** |

En los tres casos, las rachas cortas **no se parchean con el diccionario**:
`salute mental` → `salud mental` es un fallo de reconocimiento, no de ortografía.
Parchearlo con find/replace es reabrir el agujero del que se acaba de salir.
