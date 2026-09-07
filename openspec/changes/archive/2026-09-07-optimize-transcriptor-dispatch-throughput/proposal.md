# Optimizar el rendimiento del envío automático de API Transcriptor

## Why

El módulo `api-transcriptor` envía las grabaciones del día a la API externa (`192.168.0.138:9000`) con un regulador basado en la profundidad de la cola Redis local (`target_redis_queue=140`), que no refleja la carga real de la GPU remota. Cuando la cola local supera el objetivo se frena el despacho aunque la API esté al 30% de uso, generando drift acumulado entre el cierre del archivo en disco y su envío. A esto se suma la ausencia de telemetría por etapa: hoy no se puede distinguir si el retraso viene del scan, del regulador o del polling.

Por eso hace falta (a) instrumentar cuánto tarda cada tramo del pipeline por fila y (b) rebalancear el regulador para que use señales honestas — saturación real de GPU/vía upstream o `/dev/shm` local — en lugar de la cola Redis.

## What Changes

- Persistir por cada `Transcription` cuatro marcas temporales: `discovered_at` (cuándo el scanner la vio en disco), `dispatched_at` (cuándo se encoló a Redis), `submission_committed_at` (cuándo `TranscriptionSubmitService::submit()` recibió `job_id` externo) y mantener `started_at`/`finished_at` como están.
- Exponer en `/ia/api-transcriptor` un panel de diagnóstico con p50/p95 por etapa (`mtime → discovered`, `discovered → dispatched`, `dispatched → committed`, `committed → finished`), conteos por estado y la causa del último freno del regulador.
- Sustituir la señal única `Redis::llen('queues:transcription') >= target_redis_queue` por una combinación configurable: porcentaje de uso de la API remota (`/api/stats`) **OR** `/dev/shm` libre **OR** `inflight_active` contra `inflight_max`. El umbral y el modo (`local_only` / `remote_aware` / `hybrid`) se eligen en caliente desde la UI de settings.
- Añadir endpoint `GET /ia/api-transcriptor/latency` que devuelve las distribuciones y un `GET /ia/api-transcriptor/regulator-cause` que explica por qué el último tick frenó o no.
- Persistir en `transcriptions` la causa del último freno (`regulator_skip_reason`) cuando el tick omite despacho, para diagnóstico histórico.

**BREAKING**: la columna `target_redis_queue` deja de ser la única señal de freno; pasa a ser un techo superior cuando el modo es `local_only`.

## Capabilities

### New Capabilities
- `transcriptor-pipeline-latency`: instrumenta y expone la latencia por etapa del pipeline `mtime_archivo → discovered → dispatched → committed → finished`.
- `transcriptor-regulator-signals`: redefine el freno del despacho para usar señales reales (GPU remota, tmpfs local, inflight) en lugar de solo la profundidad de cola Redis.

### Modified Capabilities
- `transcription-orchestrator-runtime`: el requisito del regulador pasa de "frenar cuando `Redis::llen >= target_redis_queue`" a "frenar cuando alguna señal configurada indique saturación".
- `transcription-disk-scanner`: la fase 1 persiste `discovered_at` cuando crea la fila `Transcription` por primera vez.

## Impact

- **Migración**: añadir columnas `discovered_at`, `dispatched_at`, `submission_committed_at`, `regulator_skip_reason` a `transcriptions`.
- **Código afectado**:
  - `app/app/Services/Ia/DiskScannerService.php` (escribir `discovered_at`)
  - `app/app/Services/Ia/TranscriptionSubmitService.php` (escribir `submission_committed_at`)
  - `app/app/Console/Commands/TranscriptionTickCommand.php` (nuevas señales + persistir causa del skip)
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (endpoints `latency` y `regulator-cause`)
  - `app/app/Services/Ia/TranscriptorApiClient.php` (añadir `getRemoteGpuUsage()`)
  - `app/app/Services/Ia/TranscriptorSettings.php` (claves `regulator_mode`, `regulator_signals`)
  - `app/resources/views/ia/api-transcriptor/index.blade.php` (panel de diagnóstico)
  - `app/config/transcriptor.php` (defaults para los nuevos ajustes)
- **APIs externas**: nuevos métodos sobre `TranscriptorApiClient` para sondear `/api/stats` con caché de N segundos para no castigar al nodo GPU.
- **No afecta** la API de poller ni la de settings existente.

## Non-goals

- No se cambia el algoritmo ASR ni la calidad de la transcripción; el cambio es de orquestación y observabilidad.
- No se introduce una segunda cola Redis ni se migra a otro broker.
- No se reescribe `TranscriptionSubmitService`; solo se añaden marcas temporales.
- No se modifica el polling en sí (`poll_limit`, `poll_max_age_hours`); solo se añade un endpoint de lectura.
- No se escala la flota de workers GPU; eso queda fuera del alcance de TCloud v2.
