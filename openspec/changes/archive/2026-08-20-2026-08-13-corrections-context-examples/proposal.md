# Change: Ejemplos de contexto en transcripciones al moderar correcciones

## Why

En `/ia/correcciones` el admin ve `wrong_text` y `correct_text`, puede editarlos, aprobar o rechazar — pero no puede ver **en qué transcripción aparece la palabra**. Cada decisión se toma a ciegas sobre una regla que se aplica globalmente a 20,6 M de segmentos.

Reporte textual del admin:

> *"hay ocasiones donde en teoría hay una palabra pero leo la transcripción y va otra palabra con un tono, un contexto totalmente diferente"*

Y es exactamente lo que pasa. Ejemplo real encontrado al construir esto: la regla aprobada `different → diferentes` (8.573 aplicaciones) convierte *"**Different** tips de maquinaria amarilla"* en *"**diferentes** tips de maquinaria"*. Otra: `ahorita → ahora` reescribe el inicio de frase *"**Ahorita** yo creo que…"* como *"**ahora** yo creo que…"*, comiéndose la mayúscula.

El módulo ya resolvía la dirección contraria (transcripción → correcciones que se le aplicaron, pestaña "Revisión de transcripciones"). Faltaba **corrección → dónde aparece**.

## What Changes

Un botón "Ver ejemplos" en las tablas de Pendientes y Aprobadas abre un modal con hasta 5 apariciones reales, **una por transcripción**, cada una con archivo, timestamp, el texto como lo transcribió el motor, y cómo quedaría aplicando *solo* esa regla.

Los ejemplos se buscan **en vivo** al abrir el modal, no se almacenan.

### Por qué búsqueda en vivo y no la FK `source_segment_id`

Existe `corrections.source_segment_id` (FK a `transcription_segments`) desde la migración original, y la propuesta previa `2026-08-12-corrections-pending-segment-context` la usaba. Se descartó por tres razones verificadas contra producción:

1. **Está vacía al 100 %**: `approved 3009 / pending 35 / rejected 11` → **0** filas con `source_segment_id`. Poblarla solo hacia adelante no ayuda a lo que el admin revisa hoy.
2. **Su consulta es la que ya saturó producción una vez.** `EXPLAIN` de lo que especificaba esa propuesta (`text_raw ILIKE '%…%' ORDER BY created_at DESC`):
   ```
   Parallel Seq Scan on transcription_segments  (cost=0..754,673)
     Filter: (text_raw ~~* '%engrosador%')
   Sort Key: created_at DESC        ← sin índice
   ```
   `text_raw` no tiene índice y `created_at` tampoco.
3. **Guarda un solo segmento arbitrario.** Para juzgar una regla global hacen falta varias muestras: si en 4 de 5 ejemplos el contexto es raro, se rechaza.

La búsqueda en vivo funciona con las 3.055 correcciones existentes sin backfill, devuelve varias muestras y nunca queda desactualizada.

Esa propuesta previa queda intacta y sin implementar.

## Non-Goals

- **No** hay cambios de esquema ni migración; `source_segment_id` se queda como está, sin usar.
- **No** se tocan los producers (`EnEsMixMiner`, `LlmCorrectionSuggester`): con búsqueda en vivo, poblar la FK es redundante.
- **No** hay backfill de histórico.
- **No** se modifica `job-detail.blade.php`: el modal enlaza a la vista de lectura existente, sin ancla ni scroll al segmento.
- **No** hay reproductor de audio.
- **No** se toca la lógica de aprobación, rechazo ni aplicación del diccionario.
