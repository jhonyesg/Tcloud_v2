# Design: Visibilidad real de progreso en Re-aplicar correcciones

## 1. Arquitectura general

Flujo actual con los puntos de ruptura corregidos (marcados ★):

```
┌──────────────┐  POST /apply-retroactive   ┌────────────────────────────────┐
│  UI Alpine   │ ──────────────────────────▶│ CorreccionesController         │
│              │                            │                                │
│              │ ◀────── 202 {runId} ───────│ ★ 409 si ya hay corrida activa │
│              │         o 409 {runId}      │    (lee corrections_apply:     │
│              │                            │     active; setea puntero)     │
└──────┬───────┘                            └───────────────┬────────────────┘
       │                                                    │ setsid artisan
       │                                                    ▼
       │                                    ┌────────────────────────────────┐
       │                                    │ CorrectionsApplyRunCommand     │
       │                                    │  └─ CorrectionService::        │
       │                                    │     applyRetroactively()       │
       │                                    │     por chunk de 500:          │
       │                                    │     ★ Cache: processed += n    │
       │                                    │     ★ Cache: updated (parcial) │
       │                                    │     ★ Cache: last_progress_at  │
       │                                    │     ★ Pares pre-procesados     │
       │                                    │       1 vez (hoist)            │
       │                                    └───────────────┬────────────────┘
       │                                                    │ Cache::put por chunk
       │  GET /apply-retroactive/{runId} (poll 2s)          │
       │ ◀──────────────────────────────────────────────────┘
       │  {status, processed, total, updated, last_progress_at, ...}
       │
       │  ★ GET /apply-retroactive-active (al cargar la página)
       │    → re-attach automático si hay corrida queued/running
       ▼
┌──────────────────────────────────────────────────────────────┐
│  Barra: pct = processed/total*100  (antes: updated/total ☠)  │
│  Stuck:  now - last_progress_at > 3min → aviso visible       │
└──────────────────────────────────────────────────────────────┘
```

## 2. Contrato del estado en cache

Key: `corrections_apply:{runId}` (Redis DB 1, TTL 4h — sin cambios).

| Campo              | Tipo        | Cuándo se escribe            | Notas                                    |
|--------------------|-------------|------------------------------|------------------------------------------|
| `status`           | string      | queued → running → done/error| Sin cambios                              |
| `total`            | int         | al lanzar (pre-count)        | Sin cambios                              |
| `processed` ★      | int         | **cada chunk**               | Conteo real acumulado de segments leídos |
| `updated`          | int         | **cada chunk** (parcial) ★   | Antes solo al final; ahora acumulado     |
| `progress`         | int         | cada chunk                   | Se mantiene (lastId, solo diagnóstico)   |
| `last_progress_at` ★ | ISO8601   | cada chunk                   | Heartbeat para stuck detection           |
| `started_at`/`finished_at`/`error_message`/`dry_run`/`chunk`/`days_back` | — | como hoy | Sin cambios              |

Key nueva: `corrections_apply:active` → `{runId: string}` con el mismo TTL del run.

- Se **setea** en `applyRetroactive()` al lanzar.
- Se **borra** en el comando cuando status pasa a `done` o `error` (y el controller la re-valida leyendo el estado real del run: si el run dice `done`/`error`, el puntero se considera inválido aunque exista — defensa ante muerte abrupta donde el comando no alcanzó a limpiarla).
- Anti-stuck del puntero: si el puntero existe pero el run apunta a `queued` con `started_at=null` y antigüedad > 5 min, el controller lo considera huérfano y permite lanzar una nueva (esto cubre el caso real observado de la corrida 06:35 que quedó `queued` eterna).

## 3. Cambios por archivo

### 3.1 `CorrectionService::applyRetroactively()`

```php
// ANTES (por chunk):
$progressCb($chunk->last()->id, $total);

// DESPUÉS:
$processed = 0;
$base->chunkById($chunkSize, function ($chunk) use (..., &$processed, &$updated, $total) {
    // ... lógica idéntica de aplicación ...
    $processed += $chunk->count();
    if ($progressCb) {
        $progressCb($processed, $total, $updated, $chunk->last()->id);
    }
});
```

Firma del callback: `fn(int $processed, int $total, int $updatedSoFar, int $lastId)`. Es un contrato interno servicio↔comando (único caller con callback es `CorrectionsApplyRunCommand`); verificar con grep que no haya otros callers antes de cambiar la forma.

**Hoist de pares (perf):** hoy `applyText($raw, $corrections)` ejecuta `array_map` + `array_filter` + `usort` por segmento. Extraer a un método `preparePairs(Collection $corrections): array` llamado UNA vez en `applyRetroactively()` antes de `chunkById`, y agregar una variante `applyTextWithPairs(string $text, array $pairs): string` (o cambiar `applyText` a aceptar pares si los otros 2 callers — líneas 57 y 352 — también pueden pre-procesar; el de la línea 57 está en un loop de preview, mismo hoist aplica). Decisión en implementación: preferir **no** romper la firma pública de `applyText`; agregar la variante de pares y dejar `applyText` como wrapper que prepara y delega (compat total).

### 3.2 `CorrectionsApplyRunCommand`

En el callback de progreso:

```php
function ($processed, $total, $updatedSoFar, $lastId) use ($cacheKey, &$state) {
    $state['processed']        = $processed;
    $state['total']            = $total;
    $state['updated']          = $updatedSoFar;   // parcial, no solo al final
    $state['progress']         = $lastId;          // diagnóstico
    $state['last_progress_at'] = now()->toIso8601String();
    Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
}
```

Al terminar (done o error): `Cache::forget('corrections_apply:active')`.

Coste del `Cache::put` por chunk: ~429 puts para una corrida de 214k/500 — negligible contra el trabajo por chunk.

### 3.3 `CorreccionesController`

```php
public function applyRetroactive(Request $request)
{
    // ...validaciones como hoy...

    // ★ Anti-duplicado con re-attach amistoso
    $activeId = Cache::get('corrections_apply:active');
    if ($activeId) {
        $active = Cache::get("corrections_apply:{$activeId}");
        $orphan = !$active
            || in_array($active['status'], ['done', 'error'])
            || ($active['status'] === 'queued' && $active['started_at'] === null
                && now()->diffInMinutes($active['queued_at'] ?? now()) > 5);
        if (!$orphan) {
            return response()->json([
                'error'  => 'Ya hay una corrida en curso.',
                'runId'  => $activeId,
                'status' => $active['status'],
            ], 409);
        }
    }
    // ...crear run como hoy + $state['queued_at'] = now()->toIso8601String()...
    Cache::put('corrections_apply:active', ['runId' => $runId], now()->addHours(self::CACHE_TTL_HOURS));
    // ...
}

// ★ Nuevo: para re-attach al cargar la página
public function activeApplyRun()
{
    $activeId = Cache::get('corrections_apply:active');
    if (!$activeId) return response()->noContent();          // 204
    $state = Cache::get("corrections_apply:{$activeId}");
    if (!$state || in_array($state['status'], ['done','error'])) {
        return response()->noContent();
    }
    return response()->json(array_merge(['runId' => $activeId], $state));
}
```

Ruta: `Route::get('/correcciones/apply-retroactive-active', [..., 'activeApplyRun'])` junto a las existentes (líneas 220-221 de `web.php`).

**Fix redirect:** el `$cmd` del controller hoy incluye `> /tmp/kilo_artisan_apply.log 2>&1` y el wrapper agrega `> /tmp/kilo_artisan_bg.log 2>&1` (el último gana → el primer log queda vacío para siempre). Quitar el redirect del `$cmd` del controller y dejar solo el del wrapper, así el log de diagnóstico tiene salida real.

### 3.4 `index.blade.php`

- `pollRun()`: leer `d.processed` para barra y texto; fallback a `d.updated` si `processed` no existe (degradación amable si el backend viejo respondiera):
  ```js
  const done = d.processed ?? d.updated ?? 0;
  this.runProgress = d.total > 0 ? `${done} / ${d.total} segmentos` : `${done} segmentos`;
  this.runProgressPct = d.total > 0 ? Math.min(100, Math.round(done / d.total * 100)) : (d.status === 'done' ? 100 : 0);
  ```
- Labels ES: `{queued: 'En cola…', running: 'Procesando…', done: 'Terminada', error: 'Falló'}`.
- **Re-attach en `init()`**: llamar `GET apply-retroactive-active`; si 200, poblar `runId` + estado y arrancar el intervalo de polling; mostrar banner en el header del módulo ("Re-aplicar en curso: 22% — ver") que abre el modal. Si 204, nada.
- **Stuck detection**: en cada poll, si `status==='running'` y `now - Date.parse(d.last_progress_at) > 180_000` → mensaje ámbar "Sin avances desde las HH:MM — la corrida pudo haberse detenido" (no mata el polling; el admin decide). Eliminar el `runStuckTimer` muerto.
- **409 handling en `runApply()`**: si 409 con `runId`, re-adjuntar a ese run (mismo camino que el re-attach) en vez de mostrar error.
- `closeApply()` ya hace `window.location.reload()` si `runFinished` — sin cambios.

## 4. Secuencia de estados

```
                 click Re-aplicar
                       │
          ┌────────────┴─────────────┐
          ▼                          ▼
   no hay activa                hay activa sana
          │                          │
          ▼                          ▼
   202 {runId}                  409 {runId} ──▶ UI re-adjunta
   puntero=runId                     (no duplica)
          │
          ▼
   status=queued ──▶ comando arranca ──▶ status=running
        │                                 │ por chunk:
   queued_at seteado                      │   processed ↑, updated ↑,
        │                                 │   last_progress_at ↑
        ▼                                 ▼
   (huérfano si >5min               status=done|error
    sin arrancar →                   puntero borrado
    permite relanzar)                     │
                                          ▼
                                    UI: reload, segmentos
                                    actualizados visibles
```

## 5. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Otro caller del callback de `applyRetroactively` con la firma vieja | grep de callers antes de cambiar; único conocido: `CorrectionsApplyRunCommand` |
| Puntero `active` colgado por muerte abrupta (kill -9) | Validación contra estado real del run + regla de huérfano 5min en queued + `last_progress_at` viejo en running también permite relanzar (misma regla de stuck) |
| Cache flush entre corrida y poll | Mismo riesgo que hoy; TTL 4h sin cambios |
| UI cacheada vieja contra backend nuevo | `processed ?? updated` fallback; degradación a comportamiento actual |
| Doble click rápido (race entre dos POST) | Ventana de ms; aceptable. El peor caso es el comportamiento actual (dos corridas idempotentes). Un lock atómico `Cache::add` lo reduce a cero — usar `Cache::add` (SET NX) para el puntero en vez de `put` |

## 6. Verificación

1. Lanzar corrida `--days=1` desde la UI → la barra sube de forma visible en el primer minuto (antes: 0% eterno).
2. Recargar la página a mitad de corrida → el banner/barra reaparecen solos con el % actual.
3. Intentar lanzar otra corrida durante la primera → 409 y re-attach (no proceso paralelo nuevo: verificar `ps aux | grep apply-run` muestra un solo proceso).
4. Simular muerte: `kill -9 <pid>` a mitad → en ≤ 3min el aviso de estancada aparece.
5. `php artisan corrections:apply-run --run-id=...` manual contra un runId creado por tinker/Cache::put → progreso por chunk en cache (verificable con redis-cli).
6. Log de diagnóstico `/tmp/kilo_artisan_bg.log` contiene la salida del comando (ya no vacío).
