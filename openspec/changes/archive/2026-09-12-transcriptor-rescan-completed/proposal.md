# transcriptor-rescan-completed

## Why

El admin mejoró el motor de transcripción externa (modelo, parámetros, preprocesamiento) y quiere reprocesar transcripciones que **ya terminaron OK** para beneficiarse de la mejora sin esperar a que se generen archivos nuevos. Hoy el botón "Escanear storages" + los flags `--include-failed` no llegan a las filas `state='done'`:

- `DiskScannerService::scanStorage()` usa `whereNotExists(transcriptions)` — cualquier fila existente (incluyendo `done`) bloquea el escaneo.
- `collectFailedCandidates()` sólo mira `state='error'`.
- El único escape actual es el endpoint síncrono `POST /jobs/{id}/reprocess`, **uno a uno**, con `set_time_limit(600)` y bloqueando el navegador — inviable para "todo lo de hoy" o "todo el histórico".

Necesitamos un camino bulk paralelo al que ya existe para fallidos, con el mismo patrón operativo (mismo modal, mismo flujo background, mismo regulador de cola Redis), para que el admin pueda decir "reprocesa todo lo de hoy" o "reprocesa el histórico" con un click.

## What changes

### Nuevo flag `--include-done` en el comando `transcription:scan-and-submit`

Hermano semántico del actual `--include-failed`. Mismo trato, distinto `state`. Por defecto apagado (backward-compatible: el comportamiento vigente no cambia).

### Nuevo método `DiskScannerService::collectDoneCandidates()`

Espejo exacto de `collectFailedCandidates()` (DiskScannerService.php:306-371), pero filtrando por `state='done'` en vez de `state='error'`.

### Nuevo checkbox "Incluir completados" en el modal "Escanear storages"

Debajo del checkbox "Reintentar fallidos", mismo estilo visual (mismo fondo `bg-amber-50 border-amber-200`), con tooltip explicativo.

### Nuevo campo `done_rescan` en el response de `estimateScan`

Cuenta candidatos `state='done'` con `finished_at` filtrado por scope (`today` / `range` / `all`), con el mismo guardarraíl de 50k que ya tiene la estimación de archivos faltantes.

### Sin migración, sin tabla de versiones, sin cambios al modelo

El `srt_content` viejo **se sobreescribe** cuando el job nuevo termina OK (lo hace `TranscriptionSubmitService::submit`). Si el job nuevo falla upstream, la fila queda en `state='error'` con el `srt_content` viejo intacto como fallback — exactamente el mismo comportamiento que ya tiene el path de `--include-failed`.

## Impact

- **Operador**: nuevo checkbox "Incluir completados" en el modal que ya usa. Tooltip explica qué hace. Estimación previa muestra el conteo de candidatos.
- **Backend**: comando, servicio y controller crecen ~140 líneas (espejo del path failed). Cero migraciones. Cero cambios al modelo.
- **DB**: cada fila reprocesada bumpea `retries` (1 por reproceso). No hay columna nueva. No hay tabla nueva.
- **Costos API externa**: cada reproceso es 1 llamada real a la API. El operador decide cuándo lanzar.
- **Módulo de Avisos / menciones**: si `generate_alerts=true` (default), el reproceso re-entra al matching de keywords con el nuevo `srt_content`. Si `generate_alerts=false`, no. Mismo comportamiento que el path de fallidos.
- **Concurrencia**: `ConvertAndTranscribeJob` es `ShouldBeUnique` con `uniqueFor=900s` keyed por `file_id`. `collectDoneCandidates` debe limpiar ese lock antes del dispatch para evitar que el job sea deduplicado silenciosamente por Laravel (detalle en `tasks.md`).

## Non-goals

- ❌ Tabla de versiones / historial de transcripciones antes/después
- ❌ Backup del `srt_content` viejo a archivo `.bak` en disco
- ❌ Comparación antes/después en la UI
- ❌ Reproceso individual desde una fila de la tabla de jobs (eso ya existe como `Reprocesar` por job)
- ❌ Cambios al modelo `Transcription` o al schema
- ❌ Nueva migración

## Acceptance criteria

1. Con `state='done'` y un archivo accesible, el checkbox "Incluir completados" dispara el flujo bulk y termina con la fila en `state='done'` (nuevo resultado) o `state='error'` (fallo upstream) tras el reproceso. El `srt_content` viejo sólo se pierde si el nuevo tuvo éxito.
2. Con `state='error'`, `state='queued'`, `state='processing'` o `state='dead'`, el flag `--include-done` no los toca (sólo `state='done'`).
3. La estimación previa muestra el conteo correcto de completados filtrado por scope (`today` / `range` / `all`).
4. El regulador de cola Redis (`scan_max_dispatch_per_cycle` + `computeDispatchBatch`) sigue aplicando — el reproceso bulk no puede inundar la cola.
5. `dispatch_paused=true` sigue cortando el envío (sólo crea filas pending, no encola). Mismo comportamiento que el resto.
6. El flag `--include-done` se combina con `--include-failed` sin conflicto (cada uno mira su `state`).
7. El `srt_content` viejo se conserva mientras el job está en vuelo; sólo se sobreescribe cuando el upstream confirma el nuevo resultado.
8. Con `generate_alerts=true`, el nuevo `srt_content` entra al matching de keywords y puede generar nuevas alertas.
9. Con `generate_alerts=false`, el reproceso NO genera alertas (mismo control que el resto del módulo).
