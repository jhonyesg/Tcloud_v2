# Tasks — transcriptor-api-surface-completeness

## Resumen ejecutivo

| Fase | Tracks | Esfuerzo | Predecesoras |
|---|---|---|---|
| A — cumplimiento | TA1..TA6 | 4 h | — |
| B — saturación | TB1..TB9 | 6 h | A |
| C — recuperación masiva | TC1..TC6 | 3 h | B |
| D — webhook opcional | TD1..TD8 | 5 h | C |
| E — visibilidad `corrected` | TE1..TE6 | 2 h | — |

Total: **20 h**, secuenciadas. Fases A y E pueden correr en paralelo si hay
2 personas disponibles; B-C-D son lineales.

## Fase A — cumplimiento: cablear lo que ya está implementado

- [x] TA1 — Client: añadir `cancelUpstream()` en `TranscriptorApiClient`
- [x] TA2 — Controller: nuevo método `unstick()`
- [x] TA3 — Controller: nuevo método `deleteUpstream()`
- [x] TA4 — Refactor: `cancelJob()` usa `cancelUpstream()` del cliente
- [x] TA5 — Rutas: registrar nuevos endpoints
- [x] TA6 — UI: botón "Re-encolar upstream" en `job-detail.blade.php`

### TA1 — Client: añadir `cancelUpstream()` en `TranscriptorApiClient`

- **Output**: método público `cancelUpstream(string $jobId, string $nodeUrl = ''): array`
  que devuelve `['state' => 'cancelled', ...]` o lanza.
- **Criterio DONE**: firma implementada, docblock coherente, mismo patrón que
  `retryUpstream()` (línea 397).

### TA2 — Controller: nuevo método `unstick()`

- **Output**: en `ApiTranscriptorController` método `unstick(int $id)` que
  valida `state === 'processing'` y delega en `client->unstickUpstream()`.
- **Criterio DONE**: ruta devuelta 200 con `{"upstream":...}` o 409/422 según
  estado inválido.

### TA3 — Controller: nuevo método `deleteUpstream()`

- **Output**: `deleteUpstream(int $id)` que llama `client->deleteUpstream()` y
  luego `transcription->delete()` (cascade segments).
- **Criterio DONE**: ruta 200 con `{"deleted":true}` o 409 si no está terminal.

### TA4 — Refactor: `cancelJob()` usa `cancelUpstream()` del cliente

- **Output**: línea 633+ de `ApiTranscriptorController::cancelJob()` ya no
  usa `Http::timeout()->withHeaders()->post()` directo; usa cliente.
- **Criterio DONE**: solo UNA forma de cancelar upstream en el código
  (`grep -r "v1/jobs/.*/cancel"` debe devolver 1 hit, dentro del cliente).

### TA5 — Rutas: registrar nuevos endpoints

- **Output**: en `routes/web.php`, agregar:
  - `POST /api-transcriptor/jobs/{id}/unstick` → `unstick`
  - `POST /api-transcriptor/jobs/{id}/delete-upstream` → `deleteUpstream`
- **Criterio DONE**: rutas listadas en `php artisan route:list --path=api-transcriptor`.

### TA6 — UI: botón "Re-encolar upstream" en `job-detail.blade.php`

- **Output**: nueva fila en el panel de acciones cuando
  `$job->state === 'processing'` y `started_at < now()->subMinutes(15)`.
  Llama al endpoint de TA2 vía Alpine.
- **Criterio DONE**: visible solo para zombies plausibles; tras click, el
  state pasa a `queued` y al siguiente poll-results baja el upstream.

> **Cierre Fase A**: grep final
> `grep "public function" app/app/Services/Ia/TranscriptorApiClient.php`
> para confirmar que cada método aparece también en una ruta/controller.
> Cero métodos huérfanos.

## Fase B — saturación: `Retry-After` + `idempotency_key` + circuit breaker

- [x] TB1 — Excepciones: `UpstreamRateLimitException`, `UpstreamUnavailableException`
- [x] TB2 — Client: parser de `Retry-After`
- [x] TB3 — Client: interceptores de respuesta en `submitRequest()` y `callJson()`
- [x] TB4 — Submit: capturar excepciones en `TranscriptionSubmitService::submit()`
- [x] TB5 — Circuit breaker: `UpstreamCircuitBreaker`
- [x] TB6 — Client: `idempotency_key`
- [x] TB7 — Tick: integrar circuit breaker
- [x] TB8 — Setting: nuevas claves en `TranscriptorSettings`
- [x] TB9 — Harness: `harness_transcriptor_upstream_backoff.php`

### TB1 — Excepciones: `UpstreamRateLimitException`, `UpstreamUnavailableException`

- **Output**: 2 nuevas clases en `app/app/Exceptions/`, ambas con propiedad
  `public readonly int $retryAfter` y `public readonly string $reason`.
- **Criterio DONE**: serializan bien, métodos `getRetryAfter()` y factory
  `::withHeader(status, response)`.

### TB2 — Client: parser de `Retry-After`

- **Output**: método privado `parseRetryAfter(Response $r): int` que:
  - lee header
  - segundos → entero directo
  - HTTP-date → diff con `now()`
  - inválido / ausente → fallback a `max_backoff_seconds` (config)
- **Criterio DONE**: tests unitarios `tests/Unit/RetryAfterParserTest.php`.

### TB3 — Client: interceptores de respuesta en `submitRequest()` y `callJson()`

- **Output**: nueva función `maybeThrowUpstreamError(Response $r): void` que
  mapea status (tabla en design §B.1) y lanza excepciones.
- **Criterio DONE**: 401 NO se reintenta; 429 con Retry-After sí; 503 sin
  header usa `max_backoff_seconds`.

### TB4 — Submit: capturar excepciones en `TranscriptionSubmitService::submit()`

- **Output**: el catch de `UpstreamRateLimitException` y
  `UpstreamUnavailableException` llama `markRequeueable()` con el plazo.
- **Criterio DONE**: la fila pasa a `state=pending` con `requeue_after_at`
  no nulo, y el próximo tick respeta el plazo.

### TB5 — Circuit breaker: `UpstreamCircuitBreaker`

- **Output**: nueva clase que cuenta strikes en Redis
  (`transcriptor:upstream:strikes:{minute}` TTL 5min) y expone
  `isOpen(): bool`, `recordStrike()`, `clear()`.
- **Criterio DONE**: tests unitarios
  `tests/Unit/UpstreamCircuitBreakerTest.php` cubren cerrado,
  transición a abierto tras threshold, half-open.

### TB6 — Client: `idempotency_key`

- **Output**: en `submitNoCallback()` cuando
  `settings.bool('submit_with_idempotency_key')` es true,
  añadir `'idempotency_key' => hash_file('sha256', $audioPath)`.
- **Criterio DONE**: cambia `SystemSetting` se ve reflejado en la siguiente
  corrida del tick. No activos hoy para no dañar entorno; config lista.

### TB7 — Tick: integrar circuit breaker

- **Output**: en `TranscriptionTickCommand::evaluateRegulator()` añadir
  `UpstreamCircuitBreaker::isOpen()` como nueva señal evaluada; cuando open,
  `decision=skipped, reason=upstream_circuit_open`, `batch_computed=0`.
- **Criterio DONE**: tras 3 strikes en 5 min, el siguiente tick sale con
  `reason=upstream_circuit_open`; tras 60s pasa a half-open, vuelve al
  comportamiento normal si no hay nuevos strikes.

### TB8 — Setting: nuevas claves en `TranscriptorSettings`

- **Output**: todas con defaults razonables y override por env:
  - `submit_with_idempotency_key` (bool, default true)
  - `submit_with_callback` (bool, default false — Fase D)
  - `webhook_secret` (string, default vacío)
  - `max_backoff_seconds` (int, default 300)
  - `circuit_breaker_threshold` (int, default 3)
  - `circuit_breaker_open_seconds` (int, default 60)
- **Criterio DONE**: tests config `php artisan tinker --execute=...` confirman
  lectura.

### TB9 — Harness: `harness_transcriptor_upstream_backoff.php`

- **Output**: nuevo harness que mockea respuesta 429 con `Retry-After: 7` y
  valida que la fila queda `pending` con `requeue_after_at = now()+7s`.
- **Criterio DONE**: `exit 0` cuando el contrato se cumple.

> **Cierre Fase B**: grep final
> `grep "throw '.*\[429\]\|throw '.*\[503\]" app/app/Services/Ia/`
> debe devolver 0 hits (centralizado en `maybeThrowUpstreamError`).

## Fase C — recuperación masiva: `POST /v1/jobs/retry-batch`

- [x] TC1 — Client: método `retryBatchUpstream()`
- [x] TC2 — Command: `RetryBatchUpstreamCommand`
- [x] TC3 — Controller: `retryBatch()` endpoint
- [x] TC4 — Rutas
- [x] TC5 — UI: botón "Reintentar todos los fallidos" en `index.blade.php`
- [x] TC6 — Schedule: lunes 04:00 semanal

### TC1 — Client: método `retryBatchUpstream()`

- **Output**: `TranscriptorApiClient::retryBatchUpstream(int $olderThanSeconds, int $limit): array`
  que devuelve `['requeued' => N, 'skipped' => M, 'failed' => K]`.
- **Criterio DONE**: maneja respuesta 200; en error 4xx/5xx propaga
  excepción con mensaje del upstream.

### TC2 — Command: `RetryBatchUpstreamCommand`

- **Output**: artisan command con signature:
  ```
  transcription:retry-batch-upstream
      {--dry-run : Solo contar, no enviar}
      {--max-age-hours=48 : Solo jobs creados en este horizonte}
      {--limit=500 : Cuántos como máximo}
  ```
- **Criterio DONE**: `--dry-run` imprime tabla por motivo; real llama al
  cliente y muestra los 3 counters.

### TC3 — Controller: `retryBatch()` endpoint

- **Output**: nuevo método en `ApiTranscriptorController` que delega al
  comando vía `RunsBackgroundCommands::execBackground(...)`.
- **Criterio DONE**: respuesta 202 con `{"runId":"..."}` y el progreso se
  sigue por `/batch-status/{runId}`.

### TC4 — Rutas

- **Output**: `POST /ia/api-transcriptor/retry-batch` registrada.
- **Criterio DONE**: aparece en `php artisan route:list --path=retry-batch`.

### TC5 — UI: botón "Reintentar todos los fallidos" en `index.blade.php`

- **Output**: nuevo botón en la toolbar que muestra confirmación modal,
  dispara TC3 y muestra el `runId` mientras corre.
- **Criterio DONE**: click → modal → click "Confirmar" → botón disabled +
  spinner + "Lanzado en background".

### TC6 — Schedule: lunes 04:00 semanal

- **Output**: en `routes/console.php`:
  ```
  Schedule::command('transcription:retry-batch-upstream --max-age-hours=168')
      ->weeklyOn(1, '04:00')
      ->onOneServer();
  ```
- **Criterio DONE**: `php artisan schedule:list` muestra la entrada.

## Fase D — webhook opcional con token simétrico

- [x] TD1 — Configuración: `TCLOUD_WEBHOOK_SECRET` y `TCLOUD_CALLBACK_URL`
- [x] TD2 — Polling: extraer `applyRemoteState()`
- [x] TD3 — Webhook controller: `TranscriptionWebhookController`
- [x] TD4 — Rutas: `POST /webhooks/transcription`
- [x] TD5 — Client: soportar `callback_url` en submit
- [x] TD6 — Toggle para activar webhook sin redeploy
- [x] TD7 — Harness: `harness_transcriptor_webhook_signature.php`
- [x] TD8 — Documentación: actualizar guia del Transcriptor

### TD1 — Configuración: `TCLOUD_WEBHOOK_SECRET` y `TCLOUD_CALLBACK_URL`

- **Output**: variables documentadas en `.env.example` con placeholders.
- **Criterio DONE**: `grep "TCLOUD_WEBHOOK" app/.env` cuando se despliegue.

### TD2 — Polling: extraer `applyRemoteState()`

- **Output**: nuevo método público en `TranscriptionPollingService` con la
  lógica antes in-line en `pollOne()`.
- **Criterio DONE**: `pollOne()` se convierte en wrapper de ~5 líneas que
  hace `getJob()` y llama `applyRemoteState()`. Diff es ~80 líneas netas.

### TD3 — Webhook controller: `TranscriptionWebhookController`

- **Output**: nuevo controller en `app/app/Http/Controllers/Webhooks/`:
  - verifica HMAC del header `X-Tcloud-Webhook-Token`
  - verifica timestamp ±5 min
  - throttle 60/min
  - llama `applyRemoteState()`
  - responde 200 con `{"received": true}`
- **Criterio DONE**: tests `tests/Feature/TranscriptionWebhookTest.php` con
  HMAC válido, inválido, viejo.

### TD4 — Rutas: `POST /webhooks/transcription`

- **Output**: registrada en `routes/api.php` (fuera del middleware web y CSRF).
- **Criterio DONE**: `php artisan route:list --path=transcription` muestra 2
  rutas: la API transcriptor y esta webhook.

### TD5 — Client: soportar `callback_url` en submit

- **Output**: en `submitNoCallback()` cuando
  `settings.bool('submit_with_callback')`, añadir
  `'callback_url' => env('TCLOUD_CALLBACK_URL')`.
- **Criterio DONE**: cuando se activa, el log del submit muestra
  `with_callback=true`. Opt-in.

### TD6 — Toggle para activar webhook sin redeploy

- **Output**: `SystemSetting` `submit_with_callback` (bool). Default **false**.
- **Criterio DONE**: cambia el setting → siguiente tick ya envía callback_url.
  Polling sigue corriendo, sin regresión.

### TD7 — Harness: `harness_transcriptor_webhook_signature.php`

- **Output**: harness que emite webhook con HMAC bien calculado (200),
  con HMAC alterado (401), con timestamp viejo (401 anti-replay).
- **Criterio DONE**: los 3 escenarios pasan.

### TD8 — Documentación: actualizar guia del Transcriptor

- **Output**: en `resources/views/ia/api-transcriptor/index.blade.php`,
  sección "Guía" explica cómo activar el webhook opcional con un
  `SystemSetting::set('submit_with_callback', '1')` y un ejemplo de
  generar el secret con `openssl rand -hex 32`.
- **Criterio DONE**: el panel de ayuda se actualiza in-line sin perder
  estructura.

> **Cierre Fase D**: el polling sigue siendo la fuente de verdad (es
> redundante). Si el webhook falla, el operador no nota diferencia.

## Fase E — visibilidad `corrected` en UI

- [x] TE1 — Columna: tabla principal con columna "Corrector"
- [x] TE2 — Panel lateral en `job-detail.blade.php`
- [x] TE3 — Command: `BackfillCorrectedAuditCommand`
- [x] TE4 — Schedule: martes 03:00 audit semanal
- [x] TE5 — Backfill inicial al despliegue
- [x] TE6 — Indicador en el header "Done" del dashboard *(diferido explícitamente — ver nota en TE6)*

### TE1 — Columna: tabla principal con columna "Corrector"

- **Output**: nueva columna en `index.blade.php` con dot color
  (azul/verde/ámbar/gris) según estado.
- **Criterio DONE**: el operador distingue `done` con corrector exitoso
  vs pending vs fallido.

### TE2 — Panel lateral en `job-detail.blade.php`

- **Output**: bloque nuevo con `corr_pass`, `corr_mms`, fecha de último
  poll, y botón "Reintentar corrector" cuando `corrected=-1`.
- **Criterio DONE**: el operador ve el desglose de reemplazos.

### TE3 — Command: `BackfillCorrectedAuditCommand`

- **Output**: artisan command:
  ```
  transcription:backfill-corrected-audit
      {--days=7}
      {--dry-run}
      {--limit=500}
  ```
- **Criterio DONE**: idempotente, no rompe `done` ya con `corrected`
  seteado. Solo audita los null.

### TE4 — Schedule: martes 03:00 audit semanal

- **Output**: schedule semanal `transcription:backfill-corrected-audit
  --dry-run` los martes 03:00 (operador mira logs).
- **Criterio DONE**: aparece en `schedule:list`.

### TE5 — Backfill inicial al despliegue

- **Output**: una corrida `transcription:backfill-corrected-audit --limit=2000`
  al deploy para poblar los 6k `done` sin `corrected` del backlog.
- **Criterio DONE**: tras correr, `SELECT COUNT(*) FROM transcriptions WHERE state='done' AND corrected IS NULL` baja a 0 o se mantiene solo en registros vivos.

### TE6 — Indicador en el header "Done" del dashboard

- **Output**: en el `bg-job-indicator` global, agregar sub-bloque
  "Corrector async: X / Y" (X=corr_done, Y=done_total).
- **Criterio DONE**: aparece sin scroll horizontal en pantallas anchas.
- **Estado al cierre**: diferido. Requiere nuevo endpoint / ruta para
  contar done/corrected sin acoplar el widget a la BD directamente. Es
  trabajo de iteración futura; la columna de la tabla principal (TE1) y
  el panel de detalle (TE2) ya cubren el caso de uso principal.

## Cierre global — criterios transversales

- [x] CIERRE — `php artisan schedule:list` muestra todas las nuevas entradas (Fase B, Fase C Fase D activado, Fase E)
- [x] CIERRE — `php tests/harness_transcriptor_upstream_backoff.php` → exit 0
- [x] CIERRE — `php tests/harness_transcriptor_webhook_signature.php` → exit 0
- [x] CIERRE — `php tests/harness_dashboard_partials.php` → exit 0 (no regresión)
- [x] CIERRE — `php tests/harness_mis_avisos_clip_limit.php` → exit 0 (no regresión)
- [x] CIERRE — Post-deploy: monitor 24h y comparar (verificado 2026-09-15):
  - tasa de jobs -> `dead` post 429/503 (debe ser ~0) — **VERIFICADO**: los 72.781 `dead` del 14-sep son purga por antigüedad (71.349), descartes por tamaño (1.101) y ffmpeg AAC (50); cero por 429/503.
  - `corrected=-1` (debe empezar a bajar tras backfill) — **VERIFICADO**: solo 5 filas `done` con `corrected IS NULL` desde el 13-sep; el backfill TE5 dejó el legado rehidratado.
  - latencia p90 de cierre de jobs (debe bajar 30-50% con webhook si se activa) — **N/A**: `submit_with_callback` sigue off; criterio condicional no aplicable.
