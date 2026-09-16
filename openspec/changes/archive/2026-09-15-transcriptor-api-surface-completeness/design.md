# Design — transcriptor-api-surface-completeness

> Diseño por fase. Cada fase es independiente en rollout pero comparte
> contratos detallados aquí.

## Convenciones

- **Lenguaje del upstream**: errores 4xx/5xx NO son excepciones fatales por
  defecto. Solo `Retry-After` y la respuesta del regulador deciden si
  reintentamos.
- **State machine de TCloud**:
  ```
  pending ──┬─▶ queued ──▶ processing ──┬─▶ done
            │                           │
            │                           ├─▶ error ─▶ (requeue_after_at)
            │                           │
            └─▶ dead ◀─────────────────┘
  ```
  `requeue_after_at` (ya existe) es la cita de "puedes re-enviar otra vez a
  partir de".

- **Backwards-compat**: los métodos existentes en `TranscriptorApiClient` no
  cambian su firma ni comportamiento. Cualquier capacidad nueva es opt-in
  mediante banderilla de config.

---

## Fase A — Cumplimiento: cablear lo que ya está implementado

### A.1 Nuevo endpoint: `unstick`

**Ruta**: `POST /ia/api-transcriptor/jobs/{id}/unstick`
(middleware `audit.admin.action` para registrar el actor).

```php
// Controller method stub
public function unstick(int $id)
{
    $job = Transcription::findOrFail($id);
    if ($job->state !== Transcription::STATE_PROCESSING) {
        return response()->json(['error' => 'Solo jobs en processing'], 409);
    }
    if (empty($job->job_id)) {
        return response()->json(['error' => 'Sin job_id upstream'], 422);
    }
    $client = app(TranscriptorApiClient::class);
    $resp = $client->unstickUpstream($job->job_id, $job->node_url ?? '');
    return response()->json(['upstream' => $resp]);
}
```

**UI**: nuevo botón "Re-encolar upstream" en
`resources/views/ia/api-transcriptor/job-detail.blade.php` solo cuando
`$job->state === 'processing'` y `started_at < now()->subMinutes(15)`
(heurística: solo zombies plausibles, evita clicks accidentales sobre
procesos vivos).

### A.2 Nuevo endpoint: `deleteUpstream`

**Ruta**: `POST /ia/api-transcriptor/jobs/{id}/delete-upstream`. Estado
permitido: `done | dead | error`. Llama `DELETE /v1/jobs/{job_id}` y luego
elimina la fila local (cascade segments).

### A.3 Reemplazar `Http::` crudo en `cancelJob()`

`ApiTranscriptorController::cancelJob()` (línea 633+) hoy hace `Http::post(...'/cancel')`
directo, saltando el cliente centralizado. Migrar a
`TranscriptorApiClient::cancelUpstream(string $jobId, string $nodeUrl)` nuevo,
mismo endpoint `/v1/jobs/{job_id}/cancel`, mismo formato de respuesta.

### A.4 Auditoría final

Tras A.1, A.2, A.3, todos los métodos del cliente deben estar conectados.
`changePriority()` se mantiene porque es razonable para un futuro botón
"Subir prioridad de este job". Se documenta en su PHPDoc que es opt-in.

---

## Fase B — Saturación: `Retry-After` + `idempotency_key`

### B.1 Interceptores de respuesta en `TranscriptorApiClient`

```php
// Pseudocódigo para `submitRequest()`
$response = Http::timeout($this->getTimeout())
    ->withHeaders($this->authHeaders())
    ->asMultipart()
    ->post($endpoint, $payload);

$this->maybeThrowUpstreamError($response);
```

`maybeThrowUpstreamError` analiza status + headers:

| Status | Header | Acción |
|---|---|---|
| 401 | — | throw `\RuntimeException` (no reintentar) |
| 429 | `Retry-After: <seg\|date>` | throw `UpstreamRateLimitException($retryAfter)` |
| 503 | `Retry-After: <seg\|date>` | throw `UpstreamUnavailableException($retryAfter)` |
| 503 | sin Retry-After | throw `UpstreamUnavailableException(max_backoff_seconds)` |
| 5xx | — | throw `\RuntimeException` (no retry) |
| otros | — | OK |

`Retry-After` parsing: si es entero → segundos. Si es HTTP-date → diff con
`now()` en segundos. Si está en cualquier formato raro → fallback a
`max_backoff_seconds`.

### B.2 Propagar a `TranscriptionSubmitService::submit()`

```php
try {
    $resp = $client->submitNoCallback(...);
} catch (UpstreamRateLimitException $e) {
    return ['ok' => false, 'requeue_after' => $e->retryAfter];
}
catch (UpstreamUnavailableException $e) {
    $this->circuitBreaker->recordStrike();
    return ['ok' => false, 'requeue_after' => $e->retryAfter];
}
```

`TranscriptionSubmitService` ya tiene método `markRequeueable(Transcription, Carbon)` que setea `requeue_after_at`. Se invoca aquí. El filtro del tick (`whereNull('requeue_after_at')->orWhere('requeue_after_at', '<=', now())`)
ya respeta ese plazo.

### B.3 `idempotency_key`

`TranscriptorApiClient::submitNoCallback()`:

```php
if ($this->settings->bool('submit_with_idempotency_key')) {
    $payload['idempotency_key'] = hash_file('sha256', $audioPath);
}
```

El upstream, cuando recibe esta clave, deduplica envíos: si en los últimos N
segundos alguien más ya envió el mismo contenido, devuelve el `job_id`
existente en lugar de crear uno nuevo. El cliente recibe `{"job_id":"...","deduplicated":true}` (campo opcional, si está, lo logueamos).

### B.4 Circuit Breaker

`UpstreamCircuitBreaker` cuenta strikes en Redis bajo
`transcriptor:upstream:strikes:{minute}` con TTL de 5 min. Si en 5 min
superamos `circuit_breaker_threshold` (default 3), el break está abierto:
el tick setea `batch_computed=0` (skip) durante los próximos 60 s. Pasado
ese tiempo, half-open: 1 strike cierra otra vez. Se expone en `/regulator-cause`
como `signals_evaluated[] = 'upstream_circuit'`.

---

## Fase C — Recuperación masiva: `POST /v1/jobs/retry-batch`

### C.1 Comando nuevo

```
php artisan transcription:retry-batch-upstream --dry-run
  → cuenta candidatos
php artisan transcription:retry-batch-upstream --max-age-hours=168 --limit=500
  → envía POST /v1/jobs/retry-batch con la API devolviendo counters
```

Pseudocódigo:

```php
public function handle(TranscriptorApiClient $client)
{
    $limit = $this->option('limit') ?? 500;
    $maxAge = (int) $this->option('max-age-hours') ?? 48;

    $candidates = Transcription::whereIn('state', ['error', 'dead'])
        ->whereNotNull('job_id')
        ->where('created_at', '>=', now()->subHours($maxAge))
        ->limit($limit)
        ->count();

    if ($this->option('dry-run')) {
        $byReason = Transcription::whereIn('state', ['error', 'dead'])
            ->whereNotNull('job_id')
            ->where('created_at', '>=', now()->subHours($maxAge))
            ->selectRaw('error_message, count(*) as c')
            ->groupBy('error_message')
            ->get();
        $this->table(['Motivo', 'Cantidad'], $byReason);
        $this->info("Dry-run: $candidates candidatos listos");
        return 0;
    }

    $resp = $client->retryBatchUpstream($maxAge * 3600);
    $this->info("Re-encolados: " . ($resp['requeued'] ?? '?'));
    $this->info("Saltados: " . ($resp['skipped'] ?? '?'));
    $this->info("Fallaron: " . ($resp['failed'] ?? '?'));
    return 0;
}
```

### C.2 Botón UI

En `index.blade.php` añadir botón "Reintentar todos los fallidos" en la
toolbar, con confirmación modal. La confirmación dispara una llamada al
endpoint Laravel:

```php
Route::post('/api-transcriptor/retry-batch', [ApiTranscriptorController::class, 'retryBatch']);
```

Que internamente hace `php artisan transcription:retry-batch-upstream`
vía `RunsBackgroundCommands::execBackground(...)` para no bloquear la UI.

### C.3 Schedule

```
// lunes 04:00 — operación semanal de recuperación (post-mortem de fin de semana)
Schedule::command('transcription:retry-batch-upstream --max-age-hours=168')
    ->weeklyOn(1, '04:00')
    ->name('transcription:retry-batch-weekly')
    ->onOneServer();
```

(En operación seca el primer lunes; en `--apply` después de validar logs
de `dry-run`.)

---

## Fase D — Webhook opcional con token simétrico

### D.1 Configuración

```
TCLOUD_WEBHOOK_SECRET=<hex 32 bytes>
TCLOUD_CALLBACK_URL=https://<host>/webhooks/transcription
```

### D.2 Ruta y verificación

```
POST /webhooks/transcription
Headers:
  X-Tcloud-Webhook-Token: HMAC-SHA256(secret, body)
  X-Tcloud-Webhook-Ts: <unix timestamp>   // anti-replay ±5 min
  Content-Type: application/json
Body (del upstream):
  {
    "job_id": "...",
    "state": "done",
    "corrected": 1,
    "srt_url": "..."
  }

→ aplicar TranscriptionPollingService::applyRemoteState($tx, $payload)
→ 200 OK con {"received": true}
```

Rate limit: throttle 60/min. CSRF: excluida vía `VerifyCsrfToken` exception
(la ruta está en `routes/api.php`, fuera del middleware web).

### D.3 Refactor `applyRemoteState()` extraer de `pollOne()`

```php
private function applyRemoteState(Transcription $tx, array $remote, ...): string
{
    // Lógica antes en pollOne(): validar state, llamar ingestDone/transient,
    // setear last_polled_at, etc.
}

public function pollOne(Transcription $tx, ...): string
{
    $remote = $this->client->getJob(...);
    return $this->applyRemoteState($tx, $remote, ...);
}

public function handleWebhook(Transcription $tx, array $payload): string
{
    return $this->applyRemoteState($tx, $payload, source: 'webhook');
}
```

`source` se persiste en `last_polled_at` journal column (o se loguea
diferenciado) para debugging.

### D.4 Backoff de doble firing

Si el webhook llega antes del próximo poll, el poll ya no hace nada
(`state=done` no reaplica). Si el poll llega primero, el webhook llega después
y `applyRemoteState` detecta `state=done` upstream + `state=done` local → no-op.

Si llegan al mismo tiempo (race), la primera transacción gana. La segunda
ve `state=done` upstream con `corrected` ya seteado → idempotente.

### D.5 Banderilla `submit_with_callback`

Off por default (Fase D arranque sin webhooks). Cuando se active:

```php
if ($this->settings->bool('submit_with_callback')) {
    $payload['callback_url'] = env('TCLOUD_CALLBACK_URL');
}
```

El cliente sigue funcionando sin `callback_url` aunque el upstream
configure timeout de callback. Eso es fail-safe.

---

## Fase E — Visibilidad de `corrected` en UI

### E.1 Columna "Corrector" en la tabla principal

```blade
@if($job->corrected === null)
    <span class="dot bg-slate-300"></span> Sin auditar
@elseif($job->corrected === 0)
    <span class="dot bg-blue-400 animate-pulse"></span> Corrector en proceso
@elseif($job->corrected === 1)
    <span class="dot bg-green-500"></span> {{ $job->corr_pass ?? '?' }}/{{ $job->corr_mms ?? '?' }}
@elseif($job->corrected === -1)
    <span class="dot bg-amber-500"></span> Falló — SRT usable
@endif
```

### E.2 Panel lateral en `job-detail.blade.php`

Cuando `state === done && corrected !== null`, mostrar bloque con:

- Cuándo se completó el corrector (de `last_polled_at`)
- Cuántas reemplazos (parakeet, mms)
- Si falló (`-1`), botón "Reintentar corrector" que llama
  `POST /v1/jobs/{job_id}/retry` (Phase A.1 ya da un endpoint equivalente).

### E.3 Backfill command

```
php artisan transcription:backfill-corrected-audit [--days=7] [--dry-run]
```

Pseudocódigo:

```php
$candidates = Transcription::where('state', Transcription::STATE_DONE)
    ->whereNull('corrected')
    ->where('created_at', '>=', now()->subDays($days))
    ->whereNotNull('job_id')
    ->limit($limit)
    ->get();

foreach ($candidates as $tx) {
    $client = app(TranscriptorApiClient::class);
    try {
        $remote = $client->getJob($tx->job_id, $tx->node_url ?? '');
        $tx->update([
            'corrected' => $remote['corrected'] ?? null,
            'corr_pass' => $remote['corr_pass'] ?? null,
            'corr_mms'  => $remote['corr_mms']  ?? null,
        ]);
    } catch (\Throwable $e) {
        // No interrumpir el batch
    }
}
```

Idempotente. Sin daño. Se puede correr en background como `RunsBackgroundCommands`.

---

## Tests

### Harnesses nuevos

- `harness_transcriptor_upstream_backoff.php` — mockea respuesta 429 con
  `Retry-After: 7`, valida que la fila queda `pending` con
  `requeue_after_at = now() + 7s`.
- `harness_transcriptor_webhook_signature.php` — emite webhook con
  HMAC calculado, valida 200; con HMAC alterado valida 401; con timestamp
  viejo valida 401 (anti-replay).

### Tests unitarios

- `tests/Unit/UpstreamCircuitBreakerTest.php` — comportamiento cerrado /
  half-open / abierto
- `tests/Unit/RetryAfterParserTest.php` — segundos, RFC-7231 HTTP-date,
  formatos inválidos

### Regresión

`tests/harness_dashboard_partials.php` y `harness_mis_avisos_clip_limit.php`
mantienen su valor. No se tocan.

---

## Rollback

Cada fase merge se puede revertir individualmente:

| Fase | Cómo revertir |
|---|---|
| A | `git revert` solo de los archivos de Fase A; la API extra no se afecta. |
| B | `git revert` solo de los archivos de Fase B. El `tick` sigue funcionando sin backoff (degradación, no rotura). |
| C | `git revert` solo del comando + endpoint + botón. El sistema sigue teniendo el botón "Reintentar" individual. |
| D | `git revert` solo del webhook controller. Polling ya es la fuente de verdad. |
| E | `git revert` solo de los cambios UI + backfill command. Columna `corrected` ya existe, no se borra. |

---

## Riesgos específicos & mitigantes

| Riesgo | Mitigante |
|---|---|
| `429` y `503` mal manejados rompen jobs | Phase B introduce `requeue_after_at` que ya existe; el flujo "no marca dead" ya tiene cobertura previa |
| `idempotency_key` mal implementado duplica jobs | Backfill dry-run + log explícito + comparación pre/post |
| Webhook doble-firing crea duplicados | `applyRemoteState()` idempotente + check de state |
| Retry-batch dispara avalanche en upstream saturado | Circuit breaker (Fase B) corta antes de Fase C |
| Operador no ve `corrected=-1` y trata como éxito | Fase E badge explícito ámbar + comando reintentar |
