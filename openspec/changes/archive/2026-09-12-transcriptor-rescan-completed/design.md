# Design — transcriptor-rescan-completed

## Overview

Agregar el flag `--include-done` al comando `transcription:scan-and-submit`, espejado del flag `--include-failed` que ya existe. Cero migración, cero modelo nuevo. El cambio toca cinco archivos y es ~140 líneas.

```
┌─────────────────────────────────────────────────────────────────────┐
│                      ADMIN UX (sin cambios estructurales)           │
├─────────────────────────────────────────────────────────────────────┤
│  Modal "Escanear storages"                                          │
│    ☐ Generar alertas                                                │
│    ☐ Reintentar fallidos                                            │
│    ☐ Incluir completados  ← NUEVO checkbox                          │
│                                                                     │
│  Estimación previa (NUEVA línea cuando hay completados):            │
│    "N transcripciones en 'done' reprocesables si marcas abajo"      │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
       POST /ia/api-transcriptor/process-batch
       body: { include_done: true, ...lo demás igual... }
                                  │
                                  ▼
       ApiTranscriptorController::processBatch()
       +3 líneas: lee flag, agrega '--include-done' al comando
                                  │
                                  ▼
       artisan transcription:scan-and-submit --include-done
                                  │
                                  ▼
       ScanAndSubmitCommand::handle()
       +25 líneas: Fase 1.6 análoga a Fase 1.5
       Para cada storage: $scanner->collectDoneCandidates(...)
                                  │
                                  ▼
       DiskScannerService::collectDoneCandidates()
       +75 líneas: espejo de collectFailedCandidates()
                                  │
                                  ▼
       Filas state='done' → reset state='pending', wipe job_id
                            bump retries, conservar srt_content
                                  │
                                  ▼
       Fase 2 (existente, sin cambios):
       ConvertAndTranscribeJob::dispatch($fileId)
       Workers supervisord procesan en paralelo
                                  │
                                  ▼
       TranscriptionSubmitService::submit()
       API externa → srt_content NUEVO (sobreescribe el viejo)
```

## Decisions

### D1. Espejo exacto de `collectFailedCandidates()`

El método nuevo `collectDoneCandidates(StorageProvider, ?fromIso, ?toIso)` copia la forma del existente línea por línea, cambiando sólo el `where('state', STATE_ERROR)` por `where('state', STATE_DONE)` y el filtro de fecha de `created_at` a `finished_at`.

Razón: mismo trato, mismo regulador, misma UX. Cero superficie nueva que aprender. El operador ya conoce el flujo de fallidos; el de completados es idéntico.

### D2. Reset SIN borrar `srt_content`

El método hace `$tx->update([...])` (no `$tx->delete()`). El reset limpia:
- `state='pending'`
- `job_id=NULL`
- `node_url=NULL`, `node_id=NULL`
- `error_message=NULL` (no había error en done, pero resetea por simetría)
- `finished_at=NULL`
- `retries=$tx->retries + 1` (bump)

**Conserva**: `srt_content`, `recorded_at`, `started_at`, `generate_alerts`, `language`, `original_name`, `discovered_at`, `dispatched_at`, `submission_committed_at`.

Razón: si el job nuevo falla upstream, el `srt_content` viejo sigue ahí como fallback. Cuando `collectFailedCandidates` la agarre en el siguiente ciclo (o el operador le dé retry manual), la fila no queda vacía. Es **exactamente** el patrón que ya usa `--include-failed` desde su implementación.

### D3. Validar accesibilidad del archivo en disco, igual que `collectFailedCandidates`

Si el archivo fue borrado/movido entre la transcripción original y el reproceso, promover la fila a `state='dead'` con mensaje claro `"Archivo no accesible en disco (...)"`. NO la dejamos en loop.

Razón: misma salvaguarda que el path de fallidos. Sin esto, el job entraría al worker, haría ffmpeg sobre un path inexistente y moriría con error críptico.

### D4. Limpiar el lock `ShouldBeUnique` antes del dispatch

`ConvertAndTranscribeJob` implementa `ShouldBeUnique` con `uniqueFor=900` keyed por `file_id` (ConvertAndTranscribeJob.php:48). Si hay un job reciente (en vuelo o terminado hace <15min) para el mismo `file_id`, Laravel deduplica el nuevo dispatch silenciosamente.

Para una fila `state='done'` el `job_id` ya está limpio (lo limpiamos en el reset), pero el lock unique en Redis/cache puede seguir vigente.

Mitigación: en `collectDoneCandidates`, después del update a `state='pending'`, ejecutar:

```php
\Illuminate\Support\Facades\Cache::forget(
    'laravel-queue:unique:' . sha1(\App\Jobs\ConvertAndTranscribeJob::class) . ':' . $fileId
);
```

Es el key que Laravel usa internamente para el lock unique (ver `Illuminate\Queue\UniqueLock`). El nombre exacto se confirma durante la implementación leyendo el método `acquire()` de `UniqueLock` en vendor.

Alternativa más simple si el nombre del cache key no es estable entre versiones de Laravel: usar `Bus::dispatchSync` con `withoutOverlapping` o llamar directamente `TranscriptionSubmitService::submit()` síncrono. Pero eso rompe el paralelismo.

**Decisión tentativa**: implementar la limpieza con `Cache::forget` keyed en el patrón conocido. Si en la práctica no funciona (caché distribuido, nombre distinto), hacer fallback a `Bus::fake()` o equivalente. Documentar el caveat en `tasks.md`.

### D5. Filtro de fecha: `finished_at` no `created_at`

A diferencia de `collectFailedCandidates` que filtra por `created_at` (línea 320), el nuevo método filtra por `finished_at`. Razón: el admin piensa en "lo que terminó hoy", no en "lo que se creó hoy". Una fila creada ayer pero terminada hoy cuenta para el scope "hoy" si se reprocesa.

Esto **se alinea** con el scope de la UI (Hoy = `finished_at` del día, Rango = `finished_at BETWEEN from AND to`). El comando pasa `$fromIso`/`$toIso` ya formateados como `YYYY-MM-DD` (mismo formato que ya usa `collectFailedCandidates`).

### D6. Sin migración, sin backup, sin versionado

Decidido en la exploración. El `srt_content` viejo se pierde sólo si el nuevo job termina OK. Si quieres auditoría real, eso es una feature aparte (`transcription_versions`) que NO entra aquí.

### D7. Estimación previa incluye el conteo de completados

`ApiTranscriptorController::estimateScan()` ya devuelve:
- `files_missing` — archivos sin transcripción en el alcance
- `error_recoverable` — transcripciones en `error` (sólo cuando `mode != 'today'`)
- `dead_irrecoverable` — transcripciones en `dead`

Agregar:
- `done_rescan` — transcripciones en `done` con `finished_at` filtrado por scope

**Costo**: 1 query con índice `(state, finished_at)` por storage. La migración `2026_09_30_120001_add_state_finished_at_index_to_transcriptions.php` ya creó ese índice (existe referencia en el exploratorio previo).

**Visibilidad**: en la vista, debajo de la línea de "error reintenables":

```html
<p x-show="batchEstimate.done_rescan != null">
    <span x-text="batchEstimate.done_rescan"></span> transcripciones en
    <strong>done</strong> reprocesables marcando "Incluir completados" abajo
</p>
```

### D8. Sin cambios al modelo, schema, ni tabla

`Transcription::$fillable` ya contiene todo lo necesario. El `update()` propuesto no toca columnas que no estén ya en el modelo.

## Risks

| # | Riesgo | Severidad | Mitigación |
|---|---|---|---|
| R1 | Lock `ShouldBeUnique` 900s deduplica el dispatch | Media | D4: limpiar cache key antes del dispatch. Si falla, fallback a submit síncrono. |
| R2 | Reproceso masivo satura la cola Redis | Baja | Ya mitigado por `computeDispatchBatch()` (ScanAndSubmitCommand.php:410-426) que respeta `scan_max_dispatch_per_cycle` y el regulador de cola. |
| R3 | Doble-click del admin dispara dos batches | Baja | `batchRunning` ya bloquea el botón durante el ciclo. Aceptable. |
| R4 | Costo API externa no acotado | Aceptada | Es lo que el admin quiere. Tooltip del checkbox lo advierte. |
| R5 | Nuevas alertas no deseadas | Baja | `generate_alerts` ya controla. Si el operador lo desmarca, no genera alertas (igual que el resto del módulo). |
| R6 | Lock `ShouldBeUnique` cache key cambia entre versiones Laravel | Baja | D4 + fallback. Documentado en tasks.md. |

## Rollback

**Trivial, sin deploy de emergencia**:

```bash
# Opción A: quitar el checkbox de la UI (1 línea en la vista Blade)
# Opción B: ignorar el flag en el controller (1 línea: no propagar --include-done)
# Opción C: ignorar el flag en el comando (no llamar collectDoneCandidates)
```

Cero migración que revertir. Cero deploy de `migrate:rollback`. El cambio es puramente aditivo: si algo se rompe, basta con ignorar la flag en cualquiera de los tres puntos y todo lo demás sigue funcionando idéntico al estado previo.

Si se quisiera reversión quirúrgica de un commit único:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash>
systemctl reload php84-php-fpm   # liberar opcode cache del trait si entró
```

No hay workers que reiniciar (cambio NO toca `ConvertAndTranscribeJob` ni `TranscriptorTickCommand`). El regulador de cola Redis y el cron automático siguen funcionando idéntico.

## Open questions

Ninguna para este cambio. El admin confirmó:
- Alcance: "todo lo de hoy" o "todo el histórico" vía el scope existente (Hoy/Rango/Histórico)
- Destino del viejo `srt_content`: se sobreescribe al confirmar el nuevo resultado
- Avisos: se actualizan según el flag `generate_alerts` que ya existe
- Sin datos huérfanos: si el reproceso falla, la fila queda en `error` con el `srt_content` viejo como fallback (patrón actual de `--include-failed`)
