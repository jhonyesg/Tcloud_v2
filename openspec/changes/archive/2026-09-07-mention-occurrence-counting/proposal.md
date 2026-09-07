# Proposal — Conteo de ocurrencias por mención y navegación a cada aparición

## Why

El motor crea UN hit por segmento (UNIQUE transcripción+segmento+keyword). Si la palabra clave aparece **varias veces dentro del mismo segmento**, el hit no lo refleja: el cliente ve "1 mención" cuando la palabra se dijo varias veces, y no hay forma de saltar a cada aparición. Además el hit no registra la posición de la coincidencia dentro del segmento, imposible de inferir después (el segmento puede durar minutos).

Ejemplo real del usuario: una grabación menciona la keyword 5 veces en un mismo segmento y el visor muestra un único registro sin detalle.

## What Changes

- **Columna `occurrences`** en `segment_keyword_hits`: número de veces que la keyword aparece en el texto del segmento (conteo accent-insensitive, misma normalización del motor: asciiLower). Backfill de los hits existentes recalculando sobre `transcription_segments.text`.
- **KeywordMatcher**: al crear cada hit calcula y persiste `occurrences` (sin queries por match: mismo texto ya cargado).
- **Visor (mis-avisos)**:
  - Feed/histórico: cada fila muestra el conteo ("×5" junto al minuto) cuando `occurrences > 1`.
  - Modal de transcripción: sobre el segmento ancla (y cualquier segmento con `occurrences > 1`), **marcadores de aparición** — cada aparición resaltada individualmente y clicable para seek interpolado (posición relativa del match dentro del texto → tiempo entre start_seconds y end_seconds del segmento).
- **Costura intacta**: `MentionsSearchService::hitRow()` expone `occurrences` y `segment_text` no cambia (el modal ya recibe el texto del segmento).

## Capabilities

### New Capabilities
- `mention-occurrence-detail`: conteo de ocurrencias por hit y navegación a cada aparición dentro del segmento desde el visor.

## Impact

- Migración: `segment_keyword_hits.occurrences` (smallint, default 1) + backfill de hits existentes en la misma migración (UPDATE por join con keywords/segments, accent-insensitive con translate()).
- `KeywordMatcher::run()`: calcular ocurrencias al armar cada hit (misma normalización asciiLower del texto y la keyword).
- `MentionsSearchService::hitRow()`: exponer `occurrences` (y el texto del segmento ya viaja en el modal).
- Front mis-avisos: badge "×N" en la fila; modal con resaltado por aparición y seek interpolado.
- Riesgo bajo: columna aditiva, backfill en migración, sin cambios de contrato en el motor.

## Non-goals

- No se crean sub-hits por aparición (el UNIQUE triple del motor se mantiene).
- No se cambian las reglas de acceso ni la entrega.
- No se implementan marcadores para matches que crucen cortes de segmento.