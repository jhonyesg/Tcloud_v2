## Context

El botón "Procesar N pendientes ahora" del módulo API Transcriptor dispara N POSTs HTTP simultáneos al endpoint `/ia/api-transcriptor/jobs/{id}/dispatch-now`. Cada POST arranca PHP-FPM, abre conexión Postgres, corre `ffmpeg` (30-60s) y mantiene la conexión todo ese tiempo. Con 1356 filas pending actuales, esto satura el pool de Postgres (`SQLSTATE: sorry, too many clients already` — 234 errores en 10 días en `laravel.log`).

La infraestructura Redis + workers supervisord **ya existe** (`/etc/supervisor/conf.d/tcloud-transcription-worker.conf`, 10 procs, queue `transcription-high,medium,low`). El comando `transcription:scan-and-submit` ya la usa en `app/app/Console/Commands/ScanAndSubmitCommand.php:75` con `ConvertAndTranscribeJob::dispatchWithPriority()`. Solo falta exponer este mismo pipeline al click del admin desde el frontend.

## Goals / Non-Goals

**Goals:**
- Un único endpoint backend `POST /ia/api-transcriptor/jobs/bulk-dispatch` que encola N `ConvertAndTranscribeJob` a Redis en <500ms.
- El frontend hace UN solo `fetch` (no N+ Promise.allSettled).
- El admin ve progreso en vivo: `stats.queued` baja, `stats.done` sube, conforme los workers procesan (polling ya existente cada 2s).
- Idempotencia: si una fila ya tiene `job_id` o no es `pending|queued|processing`, se omite con contador separado `skipped_queued`.
- Si Redis no responde, 503 explícito (no 500 ambiguo).

**Non-Goals:**
- NO se modifica `ConvertAndTranscribeJob`, `TranscriptionSubmitService`, ni el config de supervisord.
- NO se arregla `scan-and-submit` (otro change).
- NO se cambian endpoints individuales `dispatch-now`, `refresh-status`, `reprocess`, `cancel`.
- NO se eliminan los botones individuales.

## Decisions

### Decisión 1: Endpoint nuevo en controlador existente, ruta en web.php

`POST /ia/api-transcriptor/jobs/bulk-dispatch` registrado en `app/routes/web.php:167` (justo después de `dispatch-now`), llamando a `App\Http\Controllers\Ia\ApiTranscriptorController::bulkDispatch`.

**Por qué**: aprovecha que el controlador ya tiene toda la lógica de transcripción, modelos cargados, `TranscriptorApiClient` configurado. Evita crear un controlador nuevo y re-instanciar dependencias.

**Firma**:
```php
public function bulkDispatch(Request $request)
```

### Decisión 2: Validación de input mínima, dos modos

```php
$validated = $request->validate([
    'ids' => 'nullable|array|max:2000',
    'ids.*' => 'integer|min:1',
]);
```

- Si `ids` está presente → encolar SOLO esos.
- Si `ids` está vacío/ausente → auto-seleccionar todos los `pending|queued|processing` con `job_id IS NULL`, capped a 2000 (protección).

**Por qué**: el frontend ya tiene un selector múltiple con checkboxes. El admin puede o no usarlos; ambos caminos deben funcionar.

### Decisión 3: Cálculo de prioridad idéntico al de `ScanAndSubmitCommand`

```php
$storage = StorageProvider::find($tx->file->storage_provider_id);
$priority = ConvertAndTranscribeJob::calculatePriority(
    (int) ($storage->transcription_priority ?? 0),
    true,   // generateAlerts
    false   // bulkOperation = false (manual, no es loop del scheduler)
);
```

**Por qué**: reusar exactamente la función existente garantiza consistencia. Un cambio futuro en `calculatePriority` (si se rebalancea la fórmula) fluye a ambos caminos automáticamente.

### Decisión 4: Query en bulk con `whereIn`, no loop con findOrFail

```php
$rows = Transcription::whereIn('id', $ids)
    ->with('file:id,storage_provider_id')
    ->whereIn('state', ['pending','queued','processing'])
    ->whereNull('job_id')
    ->get();
```

Una sola query a Postgres. Más rápido y reduce presión sobre el pool (drain a 1 conexión por request en vez de N).

### Decisión 5: Cada dispatch en try/catch, acumular errores

```php
foreach ($rows as $tx) {
    try {
        ConvertAndTranscribeJob::dispatchWithPriority(
            $tx->file_id,
            (bool) $tx->generate_alerts,
            $priority
        );
        $enqueued++;
    } catch (\Throwable $e) {
        $errors++;
        Log::warning("bulkDispatch tx={$tx->id}: {$e->getMessage()}");
    }
}
```

**Por qué**: si Redis falla en la fila 137 de 1356, no abortar todo el batch. Continuar y devolver contadores parciales.

### Decisión 6: Detección de Redis caído → 503 explícito

Si Redis lanza `Predis\Connection\ConnectionException` o `RedisException` en el dispatch, detectar y devolver `503` con mensaje claro:

```php
catch (\RedisException | \Predis\Connection\ConnectionException $e) {
    return response()->json([
        'error' => 'Redis no disponible: ' . $e->getMessage(),
        'enqueued' => $enqueued,
        'partial' => true,
    ], 503);
}
```

**Por qué**: 500 ambiguo ("Internal Server Error") no le dice al admin qué pasó. 503 con mensaje permite decidir si reintentar. Distinguir de errores de validación (que son 422).

### Decisión 7: Cambios mínimos en frontend

Solo dos puntos a tocar en `index.blade.php`:

1. Método `bulkDispatchPending()` (línea 1659): reemplazar todo el `Promise.allSettled(targets.map(fetch))` por un único `fetch('/ia/api-transcriptor/jobs/bulk-dispatch', { method: 'POST', body: JSON.stringify({ ids: targets.map(j=>j.id) }) })`.

2. El render del botón de "Procesar N pendientes ahora" (línea 625): el `x-text` puede quedar casi igual; lo que cambia es el icono durante `bulkDispatching` (de `fa-spin` a `fa-spin` mientras "Encolando..."). El mensaje al terminar (`bulkDispatchResult`) ya muestra contadores, solo adaptar texto al nuevo shape (`enqueued`/`skipped_queued`/`errors`).

**No tocar**: filas de la tabla, modales, cancelación, filtros.

### Decisión 8: NO rate-limit en este endpoint

La protección de 2000 ids + necesidad explícita de clic admin son suficientes. Un atacante no conocido (sesión autenticada + rol admin) no debería sufrir rate-limit agresivo sin métricas previas. Documentar como follow-up si se observan abuse patterns.

## Risks / Trade-offs

- **[Riesgo] Ventana de carrera con `scan-and-submit` y con `dispatch-now` individual** → El admin puede hacer clic al bulk dispatch mientras un worker o el scheduler también está procesando la misma fila. Mitigación: el endpoint ya filtra `job_id IS NULL` y la transición a `queued|processing` la hace el worker tras tomar el job (no el dispatch). Si dos dispatchers encolan el mismo job, Redis lo ejecutará dos veces. **Aceptable**: `TranscriptionSubmitService` ya maneja duplicados actualizando en lugar de creando.

- **[Riesgo] Si el admin selecciona 1356 ids, el endpoint devuelve `{ enqueued: 1356 }` en 500ms pero el procesamiento real toma ~30 min** → El admin puede pensar "ya está" y cerrar el navegador. Mitigación: el `bulkDispatchResult` muestra claramente "Encolados para procesamiento en background" + el panel de stats muestra `queued: 1356` → `processing: 50` → `done: 200` en vivo. **Aceptable**: mismo UX que `Escanear storages`.

- **[Trade-off] Una query grande a Postgres (`whereIn` con 2000 ids) puede tardar 100-300ms** → Mitigación: el `indexData()` del index ya hace un `limit(200)` sin problemas; 2000 es 10x pero sigue bajo el threshold de saturación. Si se observa lentitud, agregar chunking `chunk(500)`.

- **[Riesgo] Si Redis se cae justo después del dispatch parcial, las filas encoladas se pierden** → `ConvertAndTranscribeJob` persiste en Redis, no en BD. Si Redis reinicia sin persistencia, jobs en vuelo se pierden. Mitigación: el worker de supervisord re-dispatcha automáticamente por el mecanismo `tries=1, timeout=120`. **Acceptable** (pre-existente, no introducido por este change).

- **[Riesgo] Botón "Procesar N pendientes ahora" no muestra progreso granular (X de N procesados)** → Primer MVP solo muestra "Encolando 1356..." → "Encolados 1356, errores 0". El progreso real lo da `stats`. **Aceptable** (suficiente para destrabar Postgres).

## Migration Plan

Sin migración BD. Deploy estándar:

1. Pull código (controller + blade).
2. `php artisan route:clear` (Laravel cachea la lista de rutas).
3. Verificar con `php artisan route:list | grep bulk-dispatch` que aparece.
4. Verificar manualmente: clic en "Procesar N pendientes ahora" con 5-10 filas pendientes → respuesta <1s, contadores decrementan en panel colapsable.
5. Rollback: `git revert`. Sin limpieza necesaria (no se tocaron filas).

## Open Questions

- **P1**: ¿El endpoint debe aceptar también `priority` override (`{ ids, priorityOverride: 20 }`)? Decisión actual: NO, mantener consistencia con scheduler. Decidir si admin lo necesita después.
- **P2**: ¿Soportar `?dryRun=true` que solo cuente cuántas se encolarían? Útil pero inflaría scope. Seguir sin él; si se necesita, follow-up.
- **P3**: ¿Logging estructurado por request id para correlación con logs de workers? El `tries=1` del worker ya loguea por job_id, así que correlación es trivial sin request-id. Decidir si se agrega después.
