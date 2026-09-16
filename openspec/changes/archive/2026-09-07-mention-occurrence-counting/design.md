# Design — Conteo de ocurrencias y navegación por aparición

## Context

- El motor (`KeywordMatcher::run`) crea UN hit por (transcripción, segmento, keyword). El texto del segmento ya está cargado al armar los hits.
- Normalización del motor: `Keyword::asciiLower()` (Str::ascii + lower) para texto y keyword — el conteo debe usar la MISMA normalización para que "Álvaro" cuente como "alvaro".
- `MentionsSearchService::hitRow()` ya mapea las filas de hits para el visor; el modal recibe los segmentos con su texto.
- El visor ya sabe resaltar la keyword dentro del texto (`highlightKeyword`, accent-aware) — el marcador por aparición se monta sobre eso.

## Decisions

### D1 — Columna `occurrences` + backfill en la migración

`segment_keyword_hits.occurrences SMALLINT NOT NULL DEFAULT 1`. El backfill se hace en la propia migración con SQL puro por rendimiento (333k hits → un UPDATE por join):

```sql
UPDATE segment_keyword_hits h
SET occurrences = GREATEST(1, (length(ascii_lower(s.text)) - length(replace(ascii_lower(s.text), ascii_lower(k.normalized), ''))) / GREATEST(length(k.normalized), 1))
FROM keywords k, transcription_segments s
WHERE k.id = h.keyword_id AND s.id = h.segment_id;
```

`ascii_lower` no existe como función SQL — en Postgres se simula con `lower(translate(text, 'ÁÉÍÓÚÜÑáéíóúüñç', 'aeiouunaeiouunc'))`. La migración crea una función auxiliar `tcloud_ascii_lower(text)` IMMUTABLE para no repetir el translate, y la deja (documentada) para el backfill; el runtime PHP NO la usa (usa asciiLower de Keyword).

Índice adicional: NO se agrega (occurrences no se consulta como filtro; solo se muestra).

### D2 — Conteo en el motor (sin queries extra)

En `KeywordMatcher::run()`, al iterar segmentos: `substr_count($asciiText, $keywordNorm)` y guardar `occurrences` en el array del hit (mínimo 1, que siempre coincide porque el hit existe solo si hubo match). Sin toques a la firma pública.

### D3 — Exposición por la costura y UI

- `hitRow()`: `'occurrences' => max(1, (int) $r->occurrences)`.
- Feed/histórico (`_table-hits`): junto al minuto, badge `×N` cuando `occurrences > 1` (title "N apariciones en el segmento").
- Modal: `highlightKeyword` ya resalta la keyword; se añade navegación por aparición — cada `<mark>` de la keyword recibe un índice y un click handler con el tiempo interpolado: `start + (posRelativa / longitudTexto) * (end - start)` (posRelativa = posición del match en el texto original). El seek usa el tiempo del click (`seekToTime(t)`), reutilizando el player.

### D4 — Sin sub-hits

El UNIQUE triple del motor se mantiene: 1 hit por segmento, con contador. El feed no se infla y el cliente navega las apariciones dentro del modal. Si en el futuro se quisiera historizar cada aparición, es una tabla aparte (fuera de scope).

## Risks / Trade-offs

- [Interpolación aproximada] → los segmentos largos no distribuyen el habla uniformemente; es "tiempo aproximado" documentado en la spec (el usuario ajusta con el player). Suficiente frente a la alternativa (alineación por audio, inviable).
- [Backfill de 333k filas en migración] → un UPDATE por join con GIN? No: usa PK de segments y FK de keywords; con `WHERE h.occurrences IS NULL` no aplica (columna nueva NOT NULL) — corre completo pero es un UPDATE simple; si tarda, se tolera en ventana de deploy.
- [Migración con función auxiliar] → la función `tcloud_ascii_lower` queda en BD; documentada y usada solo por el backfill.

## Migration Plan

1. Migración (columna + backfill) → engine (`occurrences` al armar hits) → costura (`hitRow`) → UI (badge + marcadores).
2. Rollback: drop column; el motor con columna extra es compatible hacia atrás (insert ignora si no existe? No: el insert la incluye — rollback requiere revertir código también; aceptable).