# Auditoría de superficie API Transcriptor vs API externa + remediación

## Why

El módulo API Transcriptor expone en TCloud un cliente contra el upstream
(`/v1/transcribe`, `/v1/jobs/...`, `/health`, etc.) que en su mayoría está bien
implementado, pero la auditoría del 2026-09-14 encontró cinco tipos de
desalineamiento entre lo que la API upstream ofrece, lo que TCloud usa, y lo
que el operador termina sufriendo en la práctica:

**1. Saturación e idempotencia ausentes**
Cuando la API upstream responde `429 Rate limit` o `503 Ramdisk full`, TCloud
aborta el job (lo marca `error`/`dead`) en vez de esperar. Tampoco envía
`idempotency_key`, así que cualquier reintento crea un job nuevo en el upstream
y duplica cómputo. La doc upstream lo dice explícitamente ("backoff
exponencial con jitter, leer header Retry-After") y TCloud no lo hace.

**2. Endpoints implementados pero no cableados**
`TranscriptorApiClient` define `unstickUpstream()`, `changePriority()`,
`deleteUpstream()` pero ningún caller los usa. Es código muerto que invita a
creer que el wiring funciona cuando en realidad no — algunos de esos métodos
(respecialmente `unstick`) fueron diseñados justamente para los ~1.158
"processing" zombies que el sistema arrastró durante horas el 2026-09-14.

**3. Operador sin herramientas de recuperación masiva**
`/v1/jobs/retry-batch` existe en el upstream y re-encola en bloque todos los
job fallidos. TCloud no lo expone ni como comando ni como botón en UI. Cuando
el upstream se recupera después de una caída, el operador tiene que clickear
N veces el botón "Reintentar".

**4. Webhook descartado con justificación heredada**
La spec `transcription-api-orchestrator` dice textual: "el sistema SHALL NOT
exponer una ruta de webhook entrante ni enviar `callback_url`". La razón
histórica (un 33.571-filas-acumuladas de 2026) ya fue mitigada: hay polling
confiable + horizonte de 48h. Mantenerlo cerrado solo impide beneficiarse del
mecanismo que el upstream quiere optimizar — el polling cae a la mitad cuando
los jobs rápidos notifican en vez de esperar el ciclo de 30 s.

**5. Corrector async invisible en la UI**
El upstream distingue `corrected: 0|1|-1` (corregido en proceso / hecho con
corrección / corrector falló). TCloud lo persiste, pero la UI muestra "done"
genérico, indistinguible de un `corrected=0` (corrector todavía no pasó). En
los `done` de hoy: 3.969 están `corrected=0` esperando corrector, 1.735
están `corrected=-1` (corrector reventó) y solo 383 son `corrected=1` (de
5.555 done). El operador no sabe qué SRT está "listo para mostrar" y cuál
está incompleto.

> El usuario pidió cubrir las cinco brechas en un solo change "que no rompa el
> actual sistema sino que se complemente y lo haga sostenible en el tiempo".
> El diseño es **aditivo y reversible** en cada fase: cada fase puede
> rollbackarse independientemente porque no cambia contratos existentes, solo
> añade capacidad opcional.

## What Changes

### Fase A — Cumplimiento: cablear lo que ya está implementado

- Conectar `unstickUpstream()` con un nuevo endpoint
  `POST /ia/api-transcriptor/jobs/{id}/unstick` que lo invoque.
  Render: botón "Re-encolar upstream" en `job-detail.blade.php`.
  Caso de uso: el operador ve un `processing` zombie (hilo muerto) y lo
  resucita con un click.
- Conectar `deleteUpstream()` con un nuevo endpoint
  `POST /ia/api-transcriptor/jobs/{id}/delete-upstream` que llama a
  `DELETE /v1/jobs/{job_id}` y luego borra la fila local. Restringido a
  estados terminales.
- Reemplazar el `Http::post(...)` crudo de `cancelJob()` por una llamada al
  cliente `TranscriptorApiClient::cancelUpstream()` (nuevo método para
  uniformidad, mismo endpoint `/v1/jobs/{id}/cancel`).
- Marcar los métodos `changePriority()` y `unstickUpstream()` con
  `@codeCoverageIgnore` solo si quedan sin caller después de cablear, o
  usarlos en alguna acción masiva de la UI.

Resultado: cero código muerto en `TranscriptorApiClient`. Toda capacidad del
cliente se llama desde al menos un lugar.

### Fase B — Saturación: leer `Retry-After` y propagar `idempotency_key`

- En `TranscriptorApiClient::submitRequest()` agregar interceptor de respuesta
  que:
  - Para 429: lanza `RateLimitException` con `retryAfter` (parseado del header)
  - Para 503: lanza `UpstreamUnavailableException` con `retryAfter`
  - Para `Retry-After: <segundos>` numérico Y HTTP-Date: normaliza a
    `\DateTimeImmutable` UTC.
- En `TranscriptionSubmitService::submit()` capturar `RateLimitException` y
  marcar la fila con `requeue_after_at = now() + retryAfter`. La fila queda
  en `state=pending` y NO se reintenta antes del plazo; el filtro de requeue
  del `tick` ya respeta este campo.
- Para `UpstreamException` (503 genérico) usar un `max_backoff_seconds`
  configurable (default 300 s) y reducir el batch del siguiente tick con
  `target_redis_queue` temporalmente si supera 3 strikes consecutivos en una
  ventana de 5 min (`circuit_breaker`).
- En `TranscriptorApiClient::submitNoCallback()` calcular
  `idempotency_key = hash_file('sha256', $audioPath)[:64]` si la API lo
  requiere (config con banderilla `submit_with_idempotency_key`, default
  true). Esto previene duplicación accidental al reenviar el mismo archivo
  (ej. tras resubmit manual).

Resultado: 429 y 503 no matan jobs y la API puede escalar con seguridad.

### Fase C — Recuperación masiva: `POST /v1/jobs/retry-batch`

- Nuevo método `TranscriptorApiClient::retryBatchUpstream(int $maxAgeHours = 48)`:
  POST `/v1/jobs/retry-batch`.
- Nuevo artisan command `transcription:retry-batch-upstream [--dry-run]
  [--max-age-hours=48] [--limit=500]` que:
  - Lista locales `state IN (error, dead)` con `job_id` no nulo y
    `created_at > now() - maxAgeHours`
  - En `--dry-run`: solo cuenta y muestra el desglose por `error_message`
  - En real: invoca `retryBatchUpstream`, muestra los counters
    devueltos por la API (`requeued`, `skipped`, `failed`).
- Botón "Reintentar todos los fallidos" en `/ia/api-transcriptor` con
  confirm modal (criterio de falla: 0 rate-limit, 1-3 reintentos son seguros).
- Wire en schedule: lunes 04:00 `transcription:retry-batch-upstream
  --max-age-hours=168` (semana) en modo dry-run primero, operativo segundo
  semana.

Resultado: recuperación post-mortalidad del upstream en 1 click.

### Fase D — Webhook opcional con token simétrico

- Ruta nueva `POST /webhooks/transcription` en `routes/api.php` (fuera del
  middleware CSRF, dentro de throttle 60/min).
- Verificación: header `X-Tcloud-Webhook-Token = HMAC-SHA256(secret, body)`;
  secret leído de `env('TCLOUD_WEBHOOK_SECRET')`. Rechaza 401 si no coincide.
- Payload esperado: `{"job_id":..., "state":..., "corrected":..., "srt_url":...}`
  mapea 1:1 a lo que ya consume `TranscriptionPollingService::pollOne()`.
- Refactor: extraer la lógica "procesar respuesta upstream de un job" a
  `TranscriptionPollingService::applyRemoteState(Transcription $tx, array $remote)`
  para que tanto polling como webhook lo llamen.
- En `TranscriptorApiClient::submitNoCallback()` agregar banderilla
  `submit_with_callback` (default false para no activar hasta validar) que
  pasa `callback_url = env('TCLOUD_CALLBACK_URL')` cuando se activa.
- Schedule: ningún cambio automático — el polling sigue corriendo como
  respaldo. Webhook es optimización.

Resultado: latencia de cierre de jobs baja de 30 s (ciclo de poll) a <5 s
cuando upstream termina. Polling sigue como red de seguridad.

### Fase E — Estado `corrected` visible en UI

- En `job-detail.blade.php` añadir panel lateral con tres badges:
  - `corrected=0` → "Pendiente de corrector async" (azul, animado)
  - `corrected=1` → "Corrector aplicado: X reemplazos (parakeet), Y (mms)"
    leyendo `corr_pass` y `corr_mms` del último poll
  - `corrected=-1` → "Corrector falló — SRT usable sin post-proceso"
- En la tabla principal de `index.blade.php` añadir columna "Corrector"
  con dot color: pending (azul) / done (verde) / failed (rojo).
- Backfill: comando `transcription:backfill-corrected-audit` que re-poll los
  jobs `done` sin `corrected` seteado usando `getJob()` y actualiza la columna.
  Idempotente, sin daño.

Resultado: el operador ve de un vistazo qué SRT es "listo para mostrar" y
cuál está todavía siendo post-procesado por la API.

## Non-goals

- **No** se cambia el flujo de polling ni el horizonte de 48 h. Polling
  sigue siendo la fuente de verdad; webhook y `retry-batch` son aditivos.
- **No** se introducen cambios en la BD (sin migraciones). Solo columnas
  `corrected`, `requeue_after_at`, `dispatched_at`, `callback_url`,
  `retry_after` que ya existen en `transcriptions` o se añaden como
  `SystemSetting` (Redis).
- **No** se sube ni se baja `target_redis_queue`, `inflight_max` ni
  `regulator_mode` desde este change. Solo se añade una "puerta" para que
  esos valores sean respetados correctamente cuando llega 429/503.
- **No** se reemplaza `lang_fix`. La config actual permanece.
- **No** se elimina código por eliminar: los métodos cableados quedan; los
  que queden sin caller tras Fase A se marcan como "available" en PHPDoc.

## Sequencing & dependencies

Las cinco fases están **secuenciadas**, no paralelas. Razones:

```
A (cumplimiento) → B (saturación) → C (recuperación masiva)
                                    ↓
                          D (webhook opcional)
                                    ↓
                          E (UI visibility)
```

- **A** es prerrequisito de B, porque introduce el método
  `cancelUpstream()` que B va a usar en el path de error.
- **B** es prerrequisito de C, porque retry-batch re-envía a un upstream que
  podría estar saturado; sin backoff respeto el upstream queda peor.
- **D** puede ir paralelo a C, pero se pone después de C por orden lógico.
- **E** no depende de D. Puede ir en cualquier momento, se hace último por
  ser puramente UI.

Cada fase es **rollbackeable independientemente**: revert del merge solo de
esa fase devuelve al estado anterior.

## Affected Files

**Nuevo:**
- `app/app/Console/Commands/RetryBatchUpstreamCommand.php`
- `app/app/Console/Commands/BackfillCorrectedAuditCommand.php`
- `app/app/Http/Controllers/Webhooks/TranscriptionWebhookController.php`
- `app/app/Exceptions/UpstreamRateLimitException.php`
- `app/app/Exceptions/UpstreamUnavailableException.php`
- `app/app/Services/Ia/UpstreamCircuitBreaker.php`
- `app/tests/Harnesses/harness_transcriptor_upstream_backoff.php`
- `app/tests/Harnesses/harness_transcriptor_webhook_signature.php`

**Modificado:**
- `app/app/Services/Ia/TranscriptorApiClient.php` — agregar
  `cancelUpstream()`, `retryBatchUpstream()`, parser de `Retry-After`,
  generación de `idempotency_key`, soporte opcional `callback_url`
- `app/app/Services/Ia/TranscriptionSubmitService.php` — catch de
  excepciones upstream, propagar `requeue_after_at`
- `app/app/Services/Ia/TranscriptionPollingService.php` — extraer
  `applyRemoteState()` reusable
- `app/app/Console/Commands/TranscriptionTickCommand.php` — integrar
  circuit breaker con el regulador local
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — nuevos
  endpoints `unstick`, `deleteUpstream`, `retryBatch`
- `app/resources/views/ia/api-transcriptor/job-detail.blade.php` —
  badges de `corrected`, botón "Re-encolar upstream"
- `app/resources/views/ia/api-transcriptor/index.blade.php` — columna
  "Corrector", botón "Reintentar todos los fallidos"
- `app/routes/web.php` — wiring nuevos endpoints API Transcriptor
- `app/routes/api.php` — webhook entrante
- `app/app/Services/TranscriptorSettings.php` — nuevas claves
  `submit_with_idempotency_key`, `submit_with_callback`, `webhook_secret`,
  `max_backoff_seconds`, `circuit_breaker_threshold`, etc.

**Specs modificadas (deltas):**
- `openspec/specs/transcription-api-orchestrator/spec.md`
  (re-encolar upstream, retry-batch, idempotency_key)
- `openspec/specs/transcription-result-polling/spec.md`
  (corregir visibilidad `corrected`)
- `openspec/specs/transcriptor-regulator-signals/spec.md`
  (Retry-After / circuit breaker)

## Sizing estimado

| Fase | Esfuerzo | Riesgo | Reversible |
|---|---|---|---|
| A — cumplimiento | 4 h | bajo | sí |
| B — saturación | 6 h | medio (circuit breaker) | sí |
| C — recuperación masiva | 3 h | bajo | sí |
| D — webhook opcional | 5 h | medio (HMAC, double-firing) | sí |
| E — visibilidad `corrected` | 2 h | muy bajo | sí |
| **Total** | **20 h** | bajo | sí |

No hay migraciones de BD. No hay despliegues de supervisor nuevos. Workers
existentes siguen sirviendo (la lógica nueva vive en cliente y comandos).
