## Context

El módulo API transcriptor de Tcloud orquesta la transcripción de grabaciones de TV (cortes `.mp4` de ~15 min que un grabador escribe cada ~15 min en storages locales). Hoy el flujo es:

```
grabador → MP4 en disco
storage:sync (c/15min) → tabla files
scan-new (c/2min) → consulta tabla files → encola Redis
queue:work → ffmpeg opus + POST /v1/transcribe (con callback_url roto)
transcriptor externo (192.168.0.138:9000) → done en ~5s
  ↳ webhook POST callback_url → 404 nginx (callback_host cae a vhost stopped)
scan-stale (c/5min, stale_after=30min) → GET /v1/jobs/{id} → recupera a los 30min
```

Validado contra el nodo en producción:
- `POST /v1/transcribe` devuelve `{job_id, priority, state:"queued"}` (async, sin modo bloqueante).
- `GET /v1/jobs/{id}` → `state` (queued/processing/done/error/dead/cancelled) + `srt_url` + `corrected`.
- `GET /v1/jobs/{id}/srt` → SRT completo (409 si no done).
- ASR real: 3s (`lang_fix=off`), 5s (`lang_fix=async` con corrección ortográfica).
- `/api/info` y `/api/stats` exponen `node_id="transcriptor-138"`.
- SRTs disponibles ~7 días en el nodo.

Volumen real: 2 storages habilitados, ~6 cortes/hora, ASR 5s. El diseño actual (Redis + 3 colas + workers + webhook) está sobredimensionado y roto en su punto más frágil (webhook entrante).

Stakeholders: operadores que revisan transcripciones en `/ia/api-transcriptor`, y el sistema de keywords/alertas que depende de `TranscriptionProcessor`.

## Goals / Non-Goals

**Goals:**
- Reducir la latencia total (descubrimiento → transcripción persistida) de ~32-45 min a ~1-3 min.
- Eliminar la dependencia del webhook entrante (frágil, roto por enrutamiento nginx).
- Descubrir archivos nuevos leyendo el disco directamente, sin depender de `storage:sync`.
- Garantizar la relación `File`↔`Transcription` (crear la fila faltante si el archivo no está en DB).
- Recuperar el backlog de 4196 archivos históricos sin transcribir.
- Mantener el funcionamiento de los endpoints manuales de la UI (`/ia/api-transcriptor/*`).

**Non-Goals:**
- No arreglar nginx ni el `callback_host` (webhook fuera del diseño).
- No balanceo multi-nodo (solo se cataloga el nodo único).
- No rediseñar la UI de transcripciones.
- No tocar `CorrectionService`/`KeywordMatcher` (se reutilizan al persistir).
- No eliminar `storage:sync` (sigue para la UI de archivos, solo deja de alimentar al scanner).

## Decisions

### D1: Polling como vía principal de recepción (sin webhook)
**Decisión:** Eliminar el webhook entrante. Un comando `transcription:poll-results` consulta `GET /v1/jobs/{job_id}` cada 30s para las `Transcription` en `queued`/`processing` con `job_id`, y al ver `done` descarga el SRT y persiste.

**Rationale:** El webhook ya se rompió una vez por enrutamiento nginx (fragilidad de red entrante). La documentación del nodo advierte que el mapeo job_id↔nodo se pierde ante reinicios del orquestador, mientras que Tcloud ya persiste `node_url` por transcripción. Con ASR de 5s, el polling cada 30s entrega el SRT en ~30s sin depender de infraestructura externa.

**Alternativas descartadas:**
- *Arreglar el webhook (cambiar callback_host al dominio o añadir server_name IP al vhost):* reintroduce la dependencia de red entrante y nginx. Requiere coordinación de infraestructura. Menos robusto.
- *Webhook + polling de respaldo:* doble vía, más código, la misma fragilidad del webhook como punto único de fallo inicial.

### D2: Escaneo directo del filesystem (no tabla `files`)
**Decisión:** El scanner hace `scandir(storage.base_path . '/' . date('dmY'))` para la carpeta del día, con `filemtime() < now-60s` como filtro de estabilidad. Para cada `.mp4`:
1. Si no existe `File` con `path='dmY/name'` → crearlo (mtime real, sin depender de `storage:sync`).
2. Si no existe `Transcription` con `file_id` → crearla en `state=pending`.

**Rationale:** `storage:sync` corre cada 15 min, añadiendo hasta 15 min de latencia de descubrimiento. El escaneo directo del disco reduce eso a 0-2 min. La carpeta del día sigue el patrón observado (`16072026`, `15072026`) con nombre `date('dmY')`.

**Alternativas descartadas:**
- *Mantener la tabla `files` como fuente:* latencia de descubrimiento hasta 15 min.
- *Ambos (disco + sincronizar tabla):* más complejidad; el scanner ya crea el `File` si falta, así que la tabla queda consistente sin doble escritura.

### D3: Envío síncrono sin colas Redis
**Decisión:** Reemplazar `ConvertAndTranscribeJob::dispatchWithPriority` + 3 colas + workers por un comando `transcription:scan-and-submit` que ejecuta el pipeline (ffmpeg → POST) síncronamente para cada `Transcription` en `pending` sin `job_id`.

**Rationale:** El volumen actual (2 storages, ~6 cortes/hora, ffmpeg ~30s por archivo) no justifica Redis + workers. Un proceso síncrono cada 2 min procesa los pendientes acumulados. Si ffmpeg tarda en un archivo grande, retrasa el ciclo pero el siguiente ciclo lo compensa. Los endpoints manuales de la UI ya ejecutan el job síncrono (vía `ReflectionMethod`), así que el patrón está probado.

**Alternativas descartadas:**
- *Mantener Redis + workers:* complejidad innecesaria para el volumen. Los 10 workers corriendo hoy consumen ~1.5GB RAM sin valor agregado.
- *Dos colas separadas (scan vs polling):* el polling es solo GET HTTP (liviano), no necesita cola.

### D4: `lang_fix=async` fijo
**Decisión:** El POST siempre envía `lang_fix=async`. El SRT llega corregido (Spanglish→español) en ~5s. El polling descarga el SRT cuando `state=done` (el nodo marca `done` tras la corrección).

**Rationale:** Medido en producción: `async` da SRT corregido en 5s, `off` da crudo en 3s. Los 2s extra valen la corrección automática. `CorrectionService` local queda como capa adicional opcional.

**Alternativas descartadas:**
- *`lang_fix=off` + corrección local:* delega la corrección a Tcloud, requiere mantener diccionario. Menos valor.
- *Configurable por storage:* flexibilidad innecesaria para 2 storages.

### D5: Recuperación de backlog con `--days`
**Decisión:** El scanner soporta `--days=N` (procesa carpetas de hoy y los N días anteriores) y `--all` (todas las carpetas existentes). Default: `--days=0` (solo hoy, para el flujo automático).

**Rationale:** Hay 4196 archivos sin transcribir de días anteriores que el scanner automático (solo "hoy") jamás tocará. Un comando manual `transcription:scan-and-submit --days=30` permite recuperar el backlog sin tocar el flujo diario.

### D6: Persistencia de `node_id` y `node_url`
**Decisión:** Cada `Transcription` guarda `node_url` (URL base del nodo, ya existe el campo) y `node_id` (nuevo campo nullable string) al recibir la respuesta del POST. El polling usa `node_url` para construir el endpoint de consulta.

**Rationale:** Prepara el terreno para multi-nodo futuro y persiste el mapeo job_id↔nodo ante reinicios (justo lo que el webhook no garantizaba). Migración opcional para añadir `node_id`.

## Risks / Trade-offs

- **[ffmpeg bloquea el ciclo de scan]** → El scanner síncrono procesa un archivo a la vez. Si un MP4 grande tarda 60s en ffmpeg, el ciclo de 2 min se retrasa. **Mitigación:** el siguiente ciclo compensa; con ~6 cortes/hora hay margen amplio. Monitorear con logs de duración.
- **[Pérdida de paralelismo]** → Sin Redis no hay paralelismo de envío. **Mitigación:** el volumen no lo necesita; el ASR (5s) no es el cuello, ffmpeg (~30s) lo es, y es inherentemente secuencial por archivo.
- **[Webhook eliminado es BREAKING]** → Si otro sistema depende del endpoint `/webhooks/transcription`, se rompe. **Mitigación:** verificado que solo `TranscriptionCallbackController` lo usa; la ruta se elimina junto con el controlador.
- **[Scanner directo al disco asume estructura de carpetas `dmY`]** → Si un storage no sigue el patrón `carpeta_fecha`, el scanner no encuentra archivos. **Mitigación:** fallback a escaneo recursivo del `base_path` si la carpeta del día no existe; configurable.
- **[SRTs expiran en 7 días en el nodo]** → Si el polling se cae más de 7 días, los jobs done se pierden. **Mitigación:** el `poll-results` es liviano y corre cada 30s; además el comando puede re-enviar jobs en `queued` sin `job_id` que excedan un timeout.
- **[Backlog de 4196 archivos puede saturar el nodo]** → Un `--all` envía 4196 archivos de golpe. **Mitigación:** el scanner respeta `scan_batch` (default 5) por ciclo; el backlog se recupera gradualmente, no de golpe.

## Migration Plan

1. **Añadir migración** `transcriptions.node_id` (nullable string) — sin downtime.
2. **Desplegar nuevos comandos** `transcription:scan-and-submit` y `transcription:poll-results`.
3. **Actualizar `routes/console.php`**: reemplazar `scan-new`/`scan-stale` por los nuevos schedules.
4. **Marcar jobs `queued` existentes como recuperables**: el `poll-results` los rescatará automáticamente vía polling.
5. **Detener workers Redis** de transcripción (`queue:work ... transcription-*`).
6. **Eliminar ruta webhook** y `TranscriptionCallbackController`.
7. **Rollback**: revertir `routes/console.php`, reiniciar workers, restaurar ruta. Los jobs en `queued` vuelven a depender de `scan-stale`.

## Open Questions

- ¿El patrón de carpeta `dmY` es universal para todos los storages futuros, o algunos grabadores usan otra estructura? (Hoy solo Caracol y NTN24, ambos `dmY`.)
- ¿Se quiere mantener `transcription:process-batch` para el lote manual de la UI, o el nuevo `scan-and-submit --days=N` lo reemplaza completamente?
- ¿Conviven los endpoints manuales de la UI (`transcribeFile`, `reprocess`, `dispatchNow`) con el nuevo flujo, o se refactorizan para usar el scanner directo?