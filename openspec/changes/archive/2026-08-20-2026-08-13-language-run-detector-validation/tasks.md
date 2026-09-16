# Tasks: Validar el detector de idioma por racha

## 0. Arranque en sesión nueva

- [ ] Leer `proposal.md` y `design.md` de esta carpeta. **Las restricciones del
      design no son opcionales**: dos de ellas ya causaron incidentes.
- [ ] Comprobar el estado del diccionario:
      ```sql
      SELECT status, risk_level, count(*) FROM corrections GROUP BY 1,2;
      ```
      Esperado: ~126 activas, ~2.534 en cuarentena, **0 de una palabra**.
      Las pendientes **no** estarán en 0: el cron `corrections:cycle-suggestions`
      corre cada 4 h y va acumulando propuestas. Ver la nota de abajo.
- [ ] Comprobar que ningún canal está excluido:
      ```sql
      SELECT count(*) FROM channel_languages WHERE apply_corrections = false;
      ```
      Esperado: **0**. Si no es 0, alguien reintentó el enfoque por canal.

## 1. Comando de análisis

- [ ] `corrections:analyze-language-runs`, read-only, con opciones:
      `--days=` (ventana), `--channel=` (repetible), `--min-tokens=4`,
      `--en-ratio=0.4`, `--out=` (CSV).
- [ ] Resolver primero los `transcription_id` desde `transcriptions`, luego traer
      segmentos por lotes con `whereIn('transcription_id', ...)`.
      **Nunca** filtrar `transcription_segments` por fecha ni por contenido.
- [ ] Clasificar con `EnEsRuleClassifier::looksSpanishWord()` + `es_stopwords`.
- [ ] Agrupar rachas por transcripción, ordenando por `segment_index`. Un
      segmento indeterminado no corta la racha; uno español sí.
- [ ] Salida: histograma global, por día y por canal.
- [ ] `EXPLAIN` de la consulta principal: sin `Seq Scan` sobre
      `transcription_segments`.

## 2. Muestra etiquetable

- [ ] Exportar CSV con 100 rachas de 1-2 y 100 de 5+, cada fila con: id de
      transcripción, canal, `segment_index`, texto de la racha, y **dos segmentos
      vecinos a cada lado** para dar contexto.
- [ ] Columna `veredicto` vacía para rellenar a mano (`defecto` / `legítimo`).
- [ ] Entregar el CSV al admin.

## 3. Análisis del etiquetado

- [ ] Importar el CSV etiquetado y calcular la matriz de confusión.
- [ ] Comprobar los criterios de éxito del `proposal.md`:
      ≥85 % de aciertos en cada extremo, estabilidad entre días de ±10 puntos.
- [ ] Barrer los parámetros `--en-ratio` y `--min-tokens` para ver si otra
      combinación separa mejor.

## 4. Decisión

- [ ] Escribir la conclusión en este mismo `tasks.md`.
- [ ] Si valida → abrir change nueva para el detector.
- [ ] Si no valida → documentar por qué y **no forzarlo**. Es el tercer enfoque;
      los dos anteriores se cayeron al medirlos y eso fue un acierto, no un fallo.

## Verificación

- [ ] `php -l` sobre lo tocado.
- [ ] Suite completa. **Base de referencia: 427 tests, 4 fallos y 11 errores
      preexistentes** en Correo, permisos y shares, más dos aserciones de
      reflexión desfasadas (`AiSuggestCommandTest`, `CorreccionesRiskLevelTest`).
      Cualquier fallo distinto es nuevo.
- [ ] El comando es read-only: no escribe en `corrections` ni en
      `transcription_segments`.

---

## Estado del sistema al cerrar la sesión del 2026-08-13

### Lo que funcionó y está en producción

| | |
|---|---|
| **Ejemplos de contexto** en `/ia/correcciones` | Botón "Ver ejemplos": busca en vivo dónde dispara una regla, con el índice GIN. 0,2–7 s, cacheado. Ver `2026-08-13-corrections-context-examples/` |
| **Limpieza del diccionario** | 306 reglas de una palabra borradas, 49 inertes, 29 traducciones en cuarentena. De 3.055 a 126 activas |
| **Mínimo de 3 palabras** en productores | `corrections.min_suggestion_words` |
| **Trigramas con ancla** en `cycle-suggestions` | Antes bigramas: producía `of emergency`→`de emergency` |
| **`ChannelSlug`** | 11 tests, cubre las dos convenciones de nombre |
| **Clasificador ampliado** | Cubo `NOISE`, `isOrthographicVariant()`, `looksSpanishWord()` |

### Lo que NO funcionó

| enfoque | por qué falló |
|---|---|
| FK `source_segment_id` | 0 de 3.055 filas pobladas; su consulta era un seq scan de 8,3 GB con sort sin índice |
| Umbral de similitud 85 % | Dejaba pasar `presidenta`→`presidente` (90 % de parecido, cambia el género) |
| Denylists (`looksSpanishNoun`) | Solo saben lo que se les enseñó: `emergency` no estaba y generó `of emergency`→`de emergency` |
| Exclusión por canal | Demasiado gruesa: Teleislas también emite en español, y el defecto aparece en todos los canales |
| `lang_fix` del motor externo | **Sin efecto medible**: 5,73 % de inglés en las transcripciones que pasaron por él frente a 5,53 % en las que no |

### Mediciones de referencia (2026-08-13)

- Corpus: 20,6 M segmentos, 8,3 GB, 195 k transcripciones, 108 canales.
- Día: 5.447 transcripciones, 742.857 segmentos.
- Tokens no españoles: **4,52 %**.
- Segmentos: 84,5 % limpios · 11,8 % mixtos · 3,7 % mayoría inglés.
- Rachas de inglés: **59,1 % en rachas de 1-2 segmentos**.
- Peores canales: `uniminuto` 28,1 %, `teleisla` 25,5 %, `lafmplus` 15,3 %
  (los tres, contenido legítimo: música y criollo raizal).
- Mejores: `telemedellin` 0,56 %, `radiouno` 0,98 %, `bluradio` 1,01 %.

### El cron sigue produciendo, y produce a medias

A las 16:09 del 2026-08-13, ya con los cambios puestos,
`corrections:cycle-suggestions` insertó 5 propuestas. Sirven de prueba en vivo:

```
  ciudad of cali        → ciudad de cali          ✓ sirve
  ciudad of pereira     → ciudad de pereira       ✓
  día of hoy            → día de hoy              ✓
  organisms of socorro  → organisms de socorro    ✗ deja inglés dentro
  future of america     → future de america       ✗ deja inglés dentro
```

**Lo bueno**: las cinco son trigramas. El mínimo de 3 palabras funciona.

**Lo malo**: `heuristicSpanish()` traduce la preposición y deja el sustantivo tal
cual, así que **por diseño** produce frases a medio traducir cuando el sustantivo
es inglés. El filtro `looksSpanishNoun()` no caza `organisms` ni `future` porque
el plural inglés en `-s` es indistinguible del español y `future` acaba en vocal.

Es el mismo límite de la morfología descrito en `design.md`. Mientras no se
resuelva, **hay que revisar a mano lo que proponga este cron**, o desprogramarlo
como ya se hizo con `corrections:ai-suggest` y `corrections:mine-en-es`.

### Decisiones que siguen abiertas

- [ ] **Desprogramar o arreglar `corrections:cycle-suggestions`.** Es el único
      productor automático que sigue activo, y aproximadamente la mitad de lo que
      propone deja inglés en el resultado.

- [ ] **21 reglas activas de 2 palabras** (4.445 aplicaciones). Cinco dejan inglés
      en el resultado: `of security`→`de security`, `of emergency`→`de emergency`.
      Con el mínimo en 3 no nacen más, pero estas ya están dentro.
- [ ] **Repaso retroactivo**. Nunca se corrió. La ventana de 3 días son 9.003.231
      segmentos: **lanzarlo desde el botón "Re-aplicar"** de `/ia/correcciones`,
      que tiene candado y progreso, no desde una sesión de terminal.
      Borrar una regla no revierte lo que ya escribió; solo el repaso lo repara,
      porque recalcula desde `text_raw`.
- [ ] **Reclamar el `lang_fix`** al proveedor del transcriptor
      (192.168.0.138:9000). Se está pagando una etapa sin efecto.
- [ ] **`EnglishResidualSegmentDetector` y los productores** no consultan
      `channel_languages`. Quedó pendiente y hoy es irrelevante, porque no hay
      canales excluidos.
- [ ] **`correction_protected_terms`** tiene 6 entradas y mal formadas
      (`supongamos en black friday` en vez de `Black Friday`). Las marcas
      colombianas no están protegidas.
