## Context

El módulo `/ia/api-transcriptor` actualmente ejecuta cuatro tareas distintas con una sola superficie de UI:

1. **Selección de storages** (StorageProvider.transcription_enabled) — único escritor real
2. **Regulador de despacho** (TranscriptorSettings + tick command) — backend
3. **Observabilidad** (latencia, consumo, decisión del regulador) — UI sobre `/latency`, `/live-consumption`, `/regulator-cause`
4. **Operación manual** (escanear, reintentar, cancelar, procesar carpeta/día, transcribir un archivo) — UI sobre muchos endpoints

Las tareas 3 y 4 son las que se eliminan. La motivación (3.7h de delay en despacho, sin upstream respondiendo) está en `proposal.md`.

## Goals / Non-Goals

**Goals:**

- Dejar el módulo navegable con solo dos pestañas: Storages (lista + toggle) y Configuración.
- **Mantener intacto el contrato cross-module** con Correcciones y Avisos Inteligentes: el modelo `Transcription`, `TranscriptionSegment` y `TranscriptionReview` siguen escribiéndose desde el tick + polling + processor; Correcciones los lee vía `TranscriptionReviewService` y `CorrectionService`; Avisos los lee vía `MentionsSearchService`, `MentionBackfillService`, `AvisosScanService`. Esta superficie es **no negociable**.
- Mantener intacto el backend: `transcription:tick`, `transcription:poll-results`, `transcription:scan-and-submit`, los systemd workers Redis y la tabla `transcriptions` siguen siendo la fuente de verdad.
- Borrar endpoints que nadie consume una vez cortada la UI (≥20 rutas en `routes/web.php`).
- Retirar las 10+ specs que documentaban únicamente la superficie eliminada.
- Sin migración de BD ni cambios de schema.

**Non-Goals:**

- No tocar `TranscriptorApiClient`, `TranscriptionPollingService`, `TranscriptionSubmitService`, `TranscriptorSettings`, `StorageFunnelService`, `TranscriptionProcessor`, `TranscriptionCoherencePass`, `TranscriptionReviewService`, `MentionsSearchService`, `MentionBackfillService`, `AvisosScanService`, `CorrectionService` ni los modelos `Transcription`, `TranscriptionSegment`, `TranscriptionReview`.
- No tocar el tick ni el regulador (esa investigación corre en otra conversación).
- No reintroducir observabilidad de otra forma.
- No borrar datos de `transcriptions` (los 8,284 `pending` y 1,904 `queued` históricos siguen siendo backlog válido cuando el upstream vuelva).
- No romper el visor de Mis Avisos, el módulo de Correcciones, ni el deep-link desde estos hacia el contenido transcrito.

## Decisions

### Decisión 1 — Cut en un solo PR, no incremental

**Por qué:** Los 24 endpoints a eliminar comparten un mismo Alpine component (`apiTranscriptor({...})`) y un mismo controller (`ApiTranscriptorController`). Cortar incrementalmente dejaría `indexData()` calculando campos huérfanos (`stats.local`, `emptyFolders`, etc.) que ningún consumidor lee. Un solo PR mantiene la coherencia: routes + controller + vista se mueven juntos.

**Alternativa considerada:** Borrar rutas primero (deploy), luego métodos (otro deploy). Descartada por el trabajo de mantener un controller parcialmente muerto durante varios días.

### Decisión 2 — `indexData()` simplificado: solo storages + scope heredado

La versión actual de `indexData()` (líneas 123-327 del controller) calcula:

```
- Jobs paginados (scope, filtros, búsqueda)     ← REMOVE
- Storages habilitados + scope heredado        ← KEEP
- Funnel por scope (pending/done agregados)     ← KEEP (es la "señal" mínima que muestra qué se ha transcrito)
- pending_alert_threshold                       ← KEEP pero ya no se usa en UI; revisar si quitar del payload
```

La nueva forma es ~120 líneas: solo lo necesario para que la pestaña Storages pinte la lista y el toggle funcione. El bloque `ui_limits` se va del payload (no se usa en ningún lado una vez que se quitan los modales).

### Decisión 3 — Endpoints de diagnóstico: borrado total, no保留

`/ia/api-transcriptor/latency`, `/ia/api-transcriptor/regulator-cause`, `/ia/api-transcriptor/live-consumption`, `/ia/api-transcriptor/health`, `/ia/api-transcriptor/stats`, `/ia/api-transcriptor/shm-status`, `/ia/api-transcriptor/empty-folders` se eliminan todos. Razón: ya hay equivalentes vía log (`storage/logs/laravel.log` con `transcriptor.consumption.snapshot`, `transcriptor:tick:last_decision` en cache, `transcription:check-shm-health`). Mantenerlos "por si acaso" significa mantener controller, ruta, cache key y vista de Alpine para algo que nadie va a llamar.

**Excepción:** el endpoint `/ia/api-transcriptor/jobs/{id}` (job-detail) NO se reemplaza por redirect — se borra. Si alguien tiene un deep-link guardado, recibe 404 limpio, no un redirect confuso.

### Decisión 4 — Cache keys de Redis: limpiar en deploy, no en código

Las 5 cache keys a retirar (`transcriptor:stats:combined`, `transcriptor:health:combined`, `transcriptor:empty_folders:*`, `transcriptor:consumption:series:*`, `transcriptor:tick:last_decision`) tienen TTL entre 60s y 70min, así que se purgan solas tras el deploy. **No** se agrega un comando de cleanup porque las claves huérfanas no afectan nada y expiran en <2h.

**Excepción:** si la cache `transcriptor:tick:last_decision` la lee algún servicio backend (no solo la UI), mantenerla. **A confirmar durante implementación** — por ahora se asume que solo `/regulator-cause` la consume.

### Decisión 5 — `TranscriptionConsumptionSnapshot` command: borrar archivo y su cron

Este comando (`app/app/Console/Commands/TranscriptionConsumptionSnapshot.php`) escribe un punto por minuto al anillo `transcriptor:consumption:series:*` que solo lee `/live-consumption`. Si se quita el endpoint, el comando es ruido puro (1 query/min a `/api/info` + 1 write a Redis sin consumidor). Se borra el archivo y se elimina la línea de `routes/console.php` línea 156.

### Decisión 6 — `_pipeline-diagnostics.blade.php`: borrar el partial completo

Este partial se incluye en la línea 416 de `index.blade.php`. No se usa en ningún otro lugar. Borrar archivo + quitar el `@include`.

### Decisión 7 — `job-detail.blade.php`: borrar sin redirect

Página standalone con su ruta propia. No tiene sentido mantener una página de detalle si no hay lista desde donde llegar. 404 limpio > redirect que confunde.

### Decisión 8 — `startApiTranscriptorTour()` y la JS del tour: borrar todo

El tour referencia `button[\@click="loadHealth()"]`, `button[\@click="openBatchModal()"]`, `data-tour="cfg-pause"`, etc. Si quitamos esos botones, el tour intenta seleccionar nodos que ya no existen y se rompe. Solución limpia: borrar el botón "Guía" + la función JS completa + los `data-tour="..."` que ya no tienen destino.

### Decisión 9 — Test harnesses: actualizar o retirar

| Harness | Acción |
|---|---|
| `tests/harness_api_transcriptor_pending_perf.php` | RETIRAR — verifica endpoints `/stats`, `/health`, `/empty-folders` que ya no existen |
| `tests/harness_api_transcriptor_modal_scope.php` | RETIRAR — verifica el modal de "Escanear storages" |
| `tests/harness_api_transcriptor_index_perf.php` | MANTENER y actualizar — verifica el cache del scope heredado, sigue vivo |

### Decisión 10 — Specs retiradas vs modificadas

OpenSpec no tiene "retire spec" como operación de primera clase. La práctica del proyecto es: para specs que el cambio invalida por completo, **borrar el directorio `specs/<name>/`** y listar la acción en el proposal.md y en tasks.md. Para specs que se modifican parcialmente, **crear delta spec** en `changes/<name>/specs/<name>/spec.md`.

**Estrategia aquí**: la mayoría son 100% del feature eliminado → borrar directorio. Solo `transcription-api-orchestrator` y `transcription-orchestrator-runtime` reciben delta (algunos requirements sobreviven).

## Risks / Trade-offs

- **[Risk][CRÍTICO] Romper el contrato con Correcciones y Avisos Inteligentes** — Estos módulos leen `Transcription`, `TranscriptionSegment` y `TranscriptionReview` directamente, y consumen los servicios `TranscriptionProcessor`, `TranscriptionPollingService`, `TranscriptionCoherencePass`, `TranscriptionReviewService`, `CorrectionService`, `MentionsSearchService`, `MentionBackfillService`, `AvisosScanService`. Si alguno de estos se rompe, los dos módulos que más valor aportan al operador dejan de ver contenido nuevo → [Mitigación]
  - **El contrato preservado está documentado en `proposal.md → Contrato con módulos dependientes`** y se verifica con grep como gate de pre-implementación (tarea 1.7-1.10).
  - **Test harnesses de regresión** antes y después del deploy: `harness_mis_avisos_viewer.php`, `verify_mis_avisos_flow.php`, `harness_mis_avisos_menciones.php`, `harness_avisos_scan_time_presets.php`, `harness_mis_avisos_clip_limit.php`. Todos pasan con código preservado intacto.
  - **No se importa `ApiTranscriptorController` desde fuera del propio módulo** — verificado por grep en tarea 1.3, así que eliminar métodos del controller no rompe nada cross-module.
- **[Risk] Alguien tiene un deep-link guardado a `/jobs/{id}`** → [Mitigación] 404 limpio es aceptable; el módulo ya no ofrece navegación a jobs. Si en el futuro se quiere un visor de transcripciones, será otro change (`mis-archivos-transcript-viewer` ya existe).
- **[Risk] El operador pierde la capacidad de reintentar manualmente un job `error`/`dead`** → [Mitigación] Aceptado por el usuario en la conversación; el camino sigue siendo CLI: `php artisan transcription:retry-batch-upstream --max-age-hours=168`. Documentar en AGENTS.md.
- **[Risk] Tras el cut, los 8,284 `pending` quedan sin UI para verse** → [Mitigación] Aceptado. El usuario tiene visibilidad del estado por canal/rango a través del dashboard (`dashboard-modular-partials` ya cubre estado del módulo).
- **[Risk] El cache `transcriptor:tick:last_decision` la lea algo inesperado durante implementación** → [Mitigación] grep completo durante implementación (tarea 1.6); si encuentra consumidor, dejar el cache key vivo aunque sin endpoint que lo sirva.
- **[Risk] El partial `_pipeline-diagnostics` se incluya desde otro view** → [Mitigación] grep antes de borrar (tarea 1.4); debería ser único en `index.blade.php`.
- **[Trade-off] Simplificación elimina UX que un día podrá querer reincorporarse** → [Mitigación] el código se guarda en git; un `git revert` o cherry-pick devuelve el módulo completo. La propuesta archivada `2026-09-15-api-transcriptor-consumption-aware-dispatch` queda como referencia del diseño original.

## Migration Plan

**Pre-deploy:**

1. Confirmar que el upstream de transcripción no esté en medio de un despacho (query `pending_count` y `queued_count` para tener línea base).
2. `git status` limpio en branch dedicado.

**Deploy (un solo PR con merge → main):**

1. Aplicar cambios de routes (borrar 24 rutas).
2. Aplicar cambios de controller (borrar 22 métodos + 3 constantes).
3. Aplicar cambios de vista (reescribir `index.blade.php` reducido, borrar `_pipeline-diagnostics.blade.php` y `job-detail.blade.php`).
4. Borrar `TranscriptionConsumptionSnapshot.php` y su entrada en `routes/console.php`.
5. Borrar test harnesses retirados.
6. Actualizar `harness_api_transcriptor_index_perf.php` si lo necesita.
7. `composer dump-autoload` (por la clase borrada).
8. `php artisan route:cache`.
9. `php artisan view:cache`.

**Post-deploy:**

- `redis-cli -n 1 --scan --pattern 'tcloud_tcloud_cache_transcriptor:*' | xargs redis-cli DEL` — limpia las 5 cache keys huérfanas (opcional, expiran solas).
- Verificar `GET /ia/api-transcriptor` carga con solo dos pestañas.
- Verificar `POST /ia/api-transcriptor/storages/{id}/toggle` sigue funcionando (re-correr `tests/harness_api_transcriptor_index_perf.php`).
- Verificar `GET /ia/api-transcriptor/settings` sigue renderizando.

**Rollback:**

`git revert <merge-commit>` restaura el estado anterior completo (no hay migración de BD). Las cache keys vuelven a tener consumidores al instante. Riesgo: ninguno.

## Open Questions

Ninguna que cambie specs o tasks. Una duda operacional menor que se resuelve en implementación:

- ¿El setting `transcriptor_pending_alert_threshold` debe vivir en el payload de `indexData()` si ya no se usa? **Decisión provisional:** mantener el campo en payload pero sin uso en UI; si tras el primer PR nadie lo pide, retirar en un follow-up de limpieza.

## Contrato cross-module preservado (mapa)

```
┌────────────────────────────────────────────────────────────────────────┐
│                      KEEP — NO TOCAR EN ESTE CHANGE                     │
└────────────────────────────────────────────────────────────────────────┘

                             ┌──────────────────────┐
                             │ Recorder (Disco_B)   │
                             └──────────┬───────────┘
                                        │ archivos nuevos
                                        ▼
                  ┌──────────────────────────────────────┐
                  │ TranscriptionTickCommand (cron)     │
                  │  Phase 1: scan-and-submit discovery  │
                  │  Phase 2: regulator dispatch        │
                  │  → inserta Transcription(state=pend)  │
                  └──────────┬───────────────────────────┘
                             │
                             ▼
        ┌────────────────────────────────────────────────┐
        │ TranscriptionSubmitService                     │
        │  POST /v1/transcribe (upstream)                 │
        │  → update state=queued, job_id, dispatched_at   │
        └──────────┬─────────────────────────────────────┘
                   │
                   ▼
   ┌──────────────────────────────────────────────────────┐
   │ TranscriptionPollingService (cron cada minuto)      │
   │  GET /v1/jobs/{id} hasta state in (done, error,     │
   │  dead)                                              │
   │  → si done: TranscriptionProcessor::processDone()    │
   └──────────┬───────────────────────────────────────────┘
              │
              ▼
   ┌──────────────────────────────────────────────────────┐
   │ TranscriptionProcessor::processDone()                │
   │  1. descarga SRT, persiste srt_content               │
   │  2. SrtParser → TranscriptionSegment[] insert        │
   │  3. TranscriptionCoherencePass::run()                 │
   │     → hidrata source_segment_id por dictionary       │
   │  4. KeywordMatcher::run()                            │
   │     → segment_keyword_hits (alimenta Avisos)         │
   │  5. state=done                                       │
   └──────────────────────────────────────────────────────┘
              │
              ▼
   ╔══════════════════════════════════════════════════════╗
   ║              TABLA transcriptions                    ║
   ║              TABLA transcription_segments             ║
   ║              TABLA transcription_reviews              ║
   ╚════════════════╤═════════════════════════════════════╝
                    │
        ┌───────────┴────────────┐
        ▼                        ▼
   ┌─────────────────┐  ┌──────────────────────┐
   │ CORRECCIONES    │  │ AVISOS INTELIGENTES   │
   │                 │  │                       │
   │ Transcription   │  │ AvisosScanService    │
   │ ReviewService   │  │  → scanPair()        │
   │                 │  │  → KeywordMatcher    │
   │ CorrectionService│  │                       │
   │  → chunkById()  │  │ MentionsSearchService │
   │  → aplica       │  │  → visibleTranscrip…  │
   │    diccionario  │  │  → joins transcription│
   │                 │  │    _segments, user_   │
   │ CorrectionCoher.│  │    storages.transc…   │
   │  (lee source_   │  │                       │
   │   segment_id)   │  │ MentionBackfillService│
   └─────────────────┘  └──────────────────────┘
                              ▲
                              │
            ┌─────────────────┴─────────────────┐
            │ CONSUMIDORES SECUNDARIOS          │
            │ - dashboard-modular-partials      │
            │ - storage-health-and-reconcile    │
            │ - mis-avisos-storages-filter      │
            │ - keyword-storage-scope           │
            │ - transcript-segment-integrity    │
            └───────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│                       CUT — ELIMINAR EN ESTE CHANGE                    │
└────────────────────────────────────────────────────────────────────────┘

   /ia/api-transcriptor/jobs/{id} (show)          ─┐
   /ia/api-transcriptor/jobs/{id}/retry           │
   /ia/api-transcriptor/jobs/{id}/dispatch-now    │
   /ia/api-transcriptor/jobs/bulk-dispatch        │
   /ia/api-transcriptor/jobs/{id}/refresh-status  │
   /ia/api-transcriptor/jobs/{id}/reprocess      │
   /ia/api-transcriptor/jobs/{id}/cancel         │
   DELETE /ia/api-transcriptor/jobs/{id}          │
   /ia/api-transcriptor/storages/{id}/files      │
   /ia/api-transcriptor/storages/{id}/scan       │
   /ia/api-transcriptor/storages/{id}/process-*  │
   /ia/api-transcriptor/process-batch            │
   /ia/api-transcriptor/scan/estimate            │
   /ia/api-transcriptor/batch-status/{runId}     │
   POST /ia/api-transcriptor/transcribe/{fileId} │
   /ia/api-transcriptor/jobs/{id}/status         │  TODOS reemplazan o
   /ia/api-transcriptor/jobs/{id}/transcript     │  eran exclusivos del
   /ia/api-transcriptor/transcribe/progress/{k}  │  módulo API Transcriptor;
   POST /ia/api-transcriptor/storages/{id}/sync  │  ningún consumidor fuera
   /ia/api-transcriptor/health                   │  del módulo los invoca
   /ia/api-transcriptor/stats                    │  (verificado por grep en
   /ia/api-transcriptor/empty-folders            │  tarea 1.3).
   /ia/api-transcriptor/shm-status               │
   /ia/api-transcriptor/latency                  │
   /ia/api-transcriptor/regulator-cause          │
   /ia/api-transcriptor/live-consumption        ─┘

   + 22 métodos en ApiTranscriptorController
   + 2 vistas completas (job-detail, _pipeline-diagnostics)
   + 1 comando consola (TranscriptionConsumptionSnapshot)
   + su cron (`transcription:consumption-snapshot`)
   + Alpine state y métodos JS asociados
   + función `startApiTranscriptorTour()`

   KEEP: /ia/api-transcriptor                  (vista con 2 tabs)
         /ia/api-transcriptor/settings        (GET)
         /ia/api-transcriptor/settings        (POST)
         /ia/api-transcriptor/settings/reset
         /ia/api-transcriptor/settings/run-tick
         /ia/api-transcriptor/storages/{id}/toggle   ← ÚNICA escritura
```

**Cómo se valida este contrato**:

1. **Pre-implementación**: grep `ApiTranscriptorController::` fuera del directorio `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` debe devolver 0 hits (tarea 1.3).
2. **Pre-merge**: re-correr los harnesses `harness_mis_avisos_viewer.php`, `verify_mis_avisos_flow.php`, `harness_avisos_scan_time_presets.php` (tareas 9.12-9.14).
3. **Post-deploy**: smoke tests curl a `/ia/correcciones` y `/ia/avisos-inteligentes` con sesión admin — debenrenderizar igual que antes.