# Design: Regulación en caliente del pipeline de transcripción

## Contexto actual

Cadena real hoy: `routes/console.php:36` (`transcription:tick` cada 2 min) → Fase 1 `transcription:scan-and-submit --no-dispatch` (descubre, crea filas `Transcription`) → Fase 2 regulador (`Redis::llen` vs `target_redis_queue`) → `ConvertAndTranscribeJob::dispatch()` a `queues:transcription` → 21 procesos `queue:work` corren `TranscriptionSubmitService::submit()` (ffmpeg → opus → POST multipart) contra un transcriptor con **2 workers GPU**.

Los 17 knobs de `config/transcriptor.php` son `env()` puro, ninguno está en `.env`, y no hay ni una tabla ni un endpoint que permita cambiarlos. Verificado en vivo: 11 `tcloud-transcription-batch-*` + 10 `tcloud-transcription-worker@*` activos = 21.

---

## D1: El déficit antes del clamp

`TranscriptionTickCommand.php:80-82`:

```php
// Antes — el clamp hace inalcanzable el freno
$batch = max($min, min($max, $target - $current + $runway));
if ($batch <= 0) { /* código muerto: con min_batch=10 nunca entra */ }
```

`max($min, ...)` se aplica **antes** de evaluar el freno, así que con `min_batch=10` el resultado es ≥ 10 siempre. Con `current=300, target=140, runway=5` la fórmula da `-155` y se eleva a `10`: el tick sigue inyectando 10 jobs cada 2 minutos sobre una cola ya saturada. La prueba está en `storage/logs/transcription-tick.log`, donde todas las entradas muestran `batch_computed=145` constante.

```php
// Después — el déficit manda; min_batch es piso solo cuando hay margen real
$deficit = $target - $current + $runway;
if ($deficit <= 0) {
    Log::info('TranscriptionTick: skip dispatch, queue at target', [
        'current' => $current, 'target' => $target, 'deficit' => $deficit,
    ]);
    return Command::SUCCESS;
}
$batch = max($min, min($max, $deficit));
```

Este cálculo se extrae a `TranscriptorSettings::computeDispatchBatch(int $current): int` para que `ScanAndSubmitCommand` use exactamente la misma aritmética y no puedan volver a divergir.

## D2: `retry_after` vs `$timeout` — el multiplicador silencioso

`config/queue.php:26` declara `retry_after = 90`; `ConvertAndTranscribeJob.php:28` declara `$timeout = 600`. Redis reserva el job durante 90 s; pasado ese plazo lo devuelve a la cola aunque el worker original siga en ffmpeg. Un archivo de 40 min de audio produce dos ffmpeg y dos POST simultáneos.

La guarda de idempotencia por `job_id` (`ConvertAndTranscribeJob.php:59-62`) **no cubre esta ventana**: solo se activa después de que la primera copia haya escrito `job_id`, y entre los 90 s y los 600 s ambas copias están en vuelo. Que esa guarda aparezca en los logs es el síntoma del bug, no su solución.

`retry_after` pasa a **900** (defensa: debe superar `$timeout` con margen). `ConvertAndTranscribeJob` es el único `ShouldQueue` de la app, así que el radio de impacto es nulo y no hace falta tocar las units systemd. Se añade un assert en `TranscriptionConfigCommand` que falla ruidosamente si `retry_after <= $timeout`, para que la relación no vuelva a romperse.

## D3: `TranscriptorSettings` — el accessor único

**Almacenamiento: reutilizar `system_settings`** (`2026_05_13_100001_create_system_settings_table.php`, modelo `App\Models\SystemSetting`) con prefijo `transcriptor.`. Sin migración. Es el mismo patrón que ya usa `SessionService::getEffectiveMaxSessions()` con `global_max_sessions`. Decisivo: "restaurar default" = **borrar la fila**, lo que reexpone limpiamente el valor de `config/transcriptor.php` y permite distinguir tres orígenes (`bd` / `env` / `archivo`). Una tabla nueva con columnas `NOT NULL` perdería esa distinción, que es justo lo que la UI debe mostrar.

**Esquema como constante de clase.** Una sola `private const SCHEMA` define por clave: `type`, `group`, `min`, `max`, `options`, `label`, `help`, `env_key`. Esa constante alimenta el accessor, `validationRules()` y el formulario Blade. Es la corrección estructural del defecto "slider hasta 500 vs tope 200 en el servidor": ya no hay dos sitios donde escribir el rango.

**Lectura con TTL, no memoización única.** Los `queue:work` viven horas. Un singleton que lea en el constructor congelaría los valores hasta reiniciar systemd — que es exactamente el bug que ya tiene `TranscriptorApiClient` (lee `submit_timeout`/`get_timeout`/`base_url` en las líneas 25-28 y está bindeado como singleton). Diseño:

```php
private array $map = [];
private float $loadedAt = 0.0;

private function map(): array
{
    if ($this->map && (microtime(true) - $this->loadedAt) < 30) {
        return $this->map;
    }
    $this->map = Cache::remember('transcriptor:settings', 60, fn () =>
        SystemSetting::where('key', 'like', 'transcriptor.%')->pluck('value', 'key')->all()
    );
    $this->loadedAt = microtime(true);
    return $this->map;
}
```

El store de caché por defecto es Redis DB 1, compartido entre php-fpm y los workers CLI, así que `Cache::forget('transcriptor:settings')` desde la UI propaga a todos los procesos. Resolución por clave: fila en BD → `config("transcriptor.$key")` → default del esquema; con clamp a `min`/`max` **también en lectura**, para que una fila corrupta nunca produzca un valor fuera de rango en runtime.

**No** se hace overlay de la BD sobre el repositorio de config desde un ServiceProvider: añadiría un hit a BD en cada boot, rompería `artisan` con la BD caída, y ocultaría el origen del valor.

**Escritura.** `set(array $values)` valida contra el esquema más invariantes cruzadas (`min_batch ≤ max_batch`, `worker_min ≤ worker_max`, `runway ≤ target_redis_queue`, `submit_timeout < ConvertAndTranscribeJob::$timeout`), persiste con `SystemSetting::set()`, invalida caché y devuelve el mapa efectivo. `reset(array $keys)` borra filas. `effective()` devuelve por clave `{value, default, source, min, max, label, group, help}`, donde `source` distingue `env` (la variable existe en `.env`) de `archivo` (literal del config).

## D4: El goteo — convertir el golpe en ritmo

Tres knobs nuevos atacan el "de golpe" directamente:

- **`tick_interval_minutes`** (default 2, rango 1–60). El schedule pasa de `everyTwoMinutes()` a `everyMinute()` y el comando se auto-limita contra un timestamp en caché (`transcriptor:tick:last_run`). Así la frecuencia es ajustable en caliente sin editar `routes/console.php` ni recargar el cron.
- **`dispatch_stagger_ms`** (default 0, rango 0–5000). En el bucle de dispatch del tick, cuando es > 0 se aplica `->delay(now()->addMilliseconds($i * $stagger))` sobre el `PendingDispatch`. Los jobs entran a Redis escalonados en vez de todos en el mismo instante. Es la diferencia entre 145 arranques simultáneos de ffmpeg y 145 repartidos en el intervalo.
- **`dispatch_paused`** (bool). Freno de emergencia. Puntos de aplicación: `TranscriptionTickCommand` (**después** de la Fase 1 de descubrimiento — nada se pierde, solo se deja de encolar), `ScanAndSubmitCommand` antes del bucle, y `ApiTranscriptorController::bulkDispatch/processBatch/dispatchNow/transcribeFile` devolviendo **HTTP 423** con mensaje. **No** se aplica dentro de `ConvertAndTranscribeJob::handle()`: lo ya encolado debe drenar.

## D5: El multiplicador de `ScanAndSubmitCommand`

`ScanAndSubmitCommand.php:178` calcula `$submitBatch = scan_batch * count($storages)` — con 31 storages, 3100 jobs en el bucle apretado de las líneas 187-199, sin consultar el regulador. Es la ruta que usa el botón "Escanear storages" de la UI (`processBatch`), así que también inunda cuando el disparo es manual.

Se sustituye por:

```php
$cap = $this->settings->int('scan_max_dispatch_per_cycle');       // default 200
$cap = min($cap, $this->settings->computeDispatchBatch(
    (int) Redis::llen('queues:transcription')
));
if ($cap <= 0) { /* log skip, return SUCCESS */ }
```

El mismo helper de D1, de modo que el camino manual respeta el mismo techo que el automático.

## D6: Pestaña "Configuración" (Blade + Alpine)

**Archivo**: `app/resources/views/ia/api-transcriptor/index.blade.php` (2533 líneas).

- **Botón de pestaña**: se añade tras el bloque de "Trabajos" (líneas 105-113), con el mismo markup — `@click="tab='config'"`, ternario en `:class`, icono `fas fa-sliders-h`. Punto amber cuando `cfg.dispatch_paused` es true.
- **Panel**: nuevo `<div x-show="tab === 'config'" x-transition:enter.opacity.duration.150ms>` hermano de los de las líneas 131 y 557. Tarjetas `bg-white rounded-xl shadow-sm border border-slate-200`, agrupadas: Ritmo / Descubrimiento / Confiabilidad / API / Workers / UI.
- **Estado Alpine** (en el objeto `apiTranscriptor()` de la línea 1210): `cfg: {}`, `cfgMeta: {}`, `cfgRuntime: null`, `cfgErrors: {}`, `cfgSaving: false`, `cfgDirty: false`, `cfgPoll: null`. Actualizar el comentario `tab: 'storages', // storages | jobs` a incluir `config`.
- **Eventos**: `$watch('tab', v => v === 'config' ? this.loadConfig() : this.stopConfigPoll())` — carga diferida, la petición no corre en cada page load. `saveConfig()` (POST, pinta `cfgErrors` por clave si vuelve 422), `resetKey(k)`, `togglePause()` (confirm + POST inmediato), `runTickNow(dry)`. Éxito y error vía el helper `showToast()` ya existente.
- **`batchSize`** (línea 1256) deja de renderizarse con `{{ config('transcriptor.scan_batch', 100) }}` y pasa a inicializarse desde `cfg.scan_batch`. El slider de la línea 965 y sus botones de atajo enlazan `:max` a `cfgMeta.ui_batch_max.max`, eliminando el truncado silencioso contra el `min(200, …)` de `ApiTranscriptorController.php:785`.
- **Tarjeta "Tarea programada"**: qué es `transcription:tick`, cada cuánto corre (`tick_interval_minutes`), última ejecución, cuántos encoló, y **el lote que calcularía ahora mismo** con los valores actuales. Botones "Ejecutar ahora" y "Simular (dry-run)".
- **Franja de estado en vivo** (poll cada 10 s solo con la pestaña visible): profundidad de cola vs objetivo como barra, reutilizando el estilo de la barra de progreso de las líneas 1024-1027; workers activos vs objetivo; conteos por estado reutilizando la query de `ApiTranscriptorController::stats()` (línea 1173).
- **Tour**: un paso nuevo en el array de `startApiTranscriptorTour` (~línea 2300) para que el recorrido guiado siga siendo coherente.

## D7: Controller y rutas

Nuevo `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` — `ApiTranscriptorController` ya tiene 1249 líneas y esto es una preocupación distinta.

- `GET /ia/api-transcriptor/settings` → `{groups, runtime}`. `runtime` incluye `Redis::llen('queues:transcription')`, conteos por estado, workers activos (mismo estilo de helper que `TranscriptionTuneCommand::getActiveState`), última línea de `transcription-tune.log` y el lote que calcularía el tick ahora.
- `POST /ia/api-transcriptor/settings` → valida con `$settings->validationRules()` (derivadas del esquema, imposible que se desincronicen del formulario), aplica, y registra en log el diff con el `id` del usuario de `session('user')` — este proyecto usa auth por sesión, **no** `auth()->user()`.
- `POST /ia/api-transcriptor/settings/reset` → `{keys: []}` o todas.

Se registran en el grupo ya existente `Route::middleware(['auth','admin'])->prefix('ia')` de `routes/web.php:162`, junto al resto de rutas `api-transcriptor/*`. Los POST llevan `throttle:30,1`. La ruta `transcribe/{fileId}` (línea 181) recibe `throttle:10,1` como defensa en profundidad contra la ráfaga del navegador.

## D8: Ráfaga del navegador

`index.blade.php:1756` dispara `Promise.allSettled(pending.map(f => fetch(...)))` sin tope. Cada request entra por `ApiTranscriptorController::transcribeFile` (línea 859, `set_time_limit(600)` en la 881), que corre ffmpeg + POST **síncronos dentro de php-fpm**. Seleccionar 200 archivos son 200 procesos php-fpm, 200 ffmpeg y 200 conexiones a BD.

Se reemplaza por un pool acotado a `ui_max_parallel_sends` (default 3), conservando los contadores incrementales que el modal de resultados ya consume:

```js
const limit = Number(this.cfg.ui_max_parallel_sends || 3);
let idx = 0;
const runners = Array.from({ length: Math.min(limit, pending.length) }, async () => {
    while (idx < pending.length) {
        const f = pending[idx++];
        // await fetch(...); actualizar sent/errors y f.has_transcription
    }
});
await Promise.all(runners);
```

A medio plazo lo correcto es que "enviar seleccionados" use el endpoint `jobs/bulk-dispatch` ya existente, que hace justo este trabajo a través de la cola regulada. Se deja anotado; el cambio de default queda fuera de alcance aquí.

## D9: Semáforo de concurrencia — el dial que sirve de madrugada

Ajustar números regula el **ritmo de encolado**, no el **ffmpeg concurrente**. Mientras N procesos corran ffmpeg contra 2 GPUs, el cuello es local: la evidencia del 24-jul (15 `ffmpeg falló` en 4 s, stderr truncado a offsets distintos) es contención de CPU/IO, no un rechazo de la API — no hay un solo 429 en los logs.

Nuevo `app/app/Jobs/Middleware/LimitTranscriptionConcurrency.php` con `Redis::funnel()` (`Illuminate\Redis\Limiters\ConcurrencyLimiter`, nativo del framework, sin paquetes):

```php
$limit = $this->settings->int('inflight_max');
if ($limit <= 0) { return $next($job); }          // feature apagada

Redis::funnel('transcriptor:inflight')
    ->limit($limit)
    ->releaseAfter(650)                            // > $timeout: un worker muerto libera su cupo
    ->then(fn () => $next($job), fn () => $job->release(rand(5, 20)));
```

Esto **desacopla el número de workers del ffmpeg concurrente**: se pueden dejar los 11 workers y poner `inflight_max=4`; los sobrantes se aparcan en el semáforo sin tocar systemd, y el valor es ajustable en vivo desde la pestaña nueva.

**Cambio obligatorio en el job.** `release()` incrementa `attempts()`. Con `public int $tries = 1` el primer job bloqueado por el semáforo fallaría de forma permanente. Se pasa a:

```php
public int $tries = 0;                                    // sin límite por conteo
public function retryUntil(): \DateTime { return now()->addHours(2); }
```

Acotar por reloj es la semántica correcta para una espera de semáforo, y la propiedad de clase gana sobre el `--tries=3` de las units systemd. La guarda de idempotencia por `job_id` se mantiene y gana importancia.

## D10: `ShouldBeUnique` y resiliencia HTTP

El mismo `file_id` llega a `queues:transcription` por cuatro caminos: el tick, `scan-and-submit`, `bulk-dispatch` y el envío manual. Se añade:

```php
class ConvertAndTranscribeJob implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 900;                          // pareado con retry_after
    public function uniqueId(): string { return (string) $this->fileId; }
}
```

Verificado que el `dispatch()` estático de las líneas 40-45 delega en el helper global `\dispatch()` → `PendingDispatch`, donde `ShouldBeUnique` sí se honra. El lock usa el store Redis ya configurado.

`TranscriptorApiClient::submit()` y `submitNoCallback()` no tienen retry: un 502 transitorio manda la transcripción directo a `markError()`, y a los 3 a `dead`. Se envuelve el POST con `Http::retry($settings->int('submit_max_attempts'), $settings->int('submit_retry_base_ms'), throw: false)`, reintentando **solo** en excepciones de conexión y 5xx — nunca en 4xx/401, que son permanentes.

`TranscriptorApiClient.php:46` hace `file_get_contents($opusPath)`: el opus entero en un string PHP, multiplicado por N workers con `memory_limit=512M`. Se cambia a `->attach('file', fopen($opusPath, 'r'), basename($opusPath))` para que Guzzle lo transmita en streaming, con `fclose` defensivo en `finally` (el `@unlink` de `TranscriptionSubmitService` sigue funcionando igual).

## D11: Workers huérfanos y el dial del tuner

`TranscriptionTuneCommand` solo conoce `tcloud-transcription-batch-N` (líneas ~107, 124-136, 180). Los 10 `tcloud-transcription-worker@{1..10}` activos son invisibles para él: cree que el pool es 11 cuando la concurrencia real es 21. `transcription-orchestrator-runtime` §7 los prohíbe explícitamente.

Nuevo paso `reconcileForbiddenPools()` dentro de `--apply`: enumera instancias con `systemctl list-units 'tcloud-transcription-worker@*.service' --all --plain --no-legend`, hace `systemctl disable --now` de las activas, las reporta en el JSON bajo `stopped_orphans[]` y emite `Log::warning` para que quede visible en `transcription-tune.log` si reaparecen. Auto-sanable.

Las constantes `MIN_WORKERS`/`MAX_WORKERS`/`RATIO_MEDIOS_POR_WORKER` (líneas 37-39) pasan a lecturas del servicio, quedando como defaults del esquema. `worker_max` efectivo se topa al número de units realmente instaladas (`glob('/etc/systemd/system/tcloud-transcription-batch-*.service')`) y ese tope se expone a la UI, para que el slider no pueda pedir workers inexistentes. Se añade `worker_override` (0 = automático): en saturación lo que se necesita es "ponlo en 4 ahora", no deducir el ratio. `stopAllWorkers()` también pasa a usar el conteo de units instaladas en vez de `self::MAX_WORKERS`, para no dejar rezagados.

## D12: Limpieza de inconsistencias

Aprovechando que ya se tocan estos archivos:

| Defecto | Fix |
|---|---|
| `config('transcriptor.callback_host')` pintado en `index.blade.php:85`, clave inexistente | Mapear la clave a `TRANSCRIPTOR_CALLBACK_HOST` (ya está en `.env`). Solo display; §9 sigue prohibiendo cablear webhooks |
| `ApiTranscriptorController.php:550` default inline `5` vs config `100` | Leer del servicio, sin default inline |
| `batchAlerts` validado en `:786` pero nunca pasado al artisan en `:813-820` | Añadir `{--alerts}` a `ScanAndSubmitCommand` y propagarlo a `generate_alerts` de las filas creadas |
| `CorrectionsApplyRunCommand.php:12` `{--run-id=required}` (literal, no validación) | `{--run-id=}` + chequeo explícito con `Command::FAILURE` |
| `CorrectionService.php:218,286` hardcodean `500` | Leer `corrections_chunk`, que existe y estaba muerta |
| `TranscriptionPollingService.php:34` `limit(100)` < objetivo 140 | `poll_limit`, default 140 |
| `PollResultsCommand.php:24` `limit(50)` hardcodeado | `stale_resend_limit` |
| `scan_days_back` definida y nunca leída | Usarla como default de `--days` en `ScanAndSubmitCommand` |

## D13: Riesgos y rollback

- **Riesgo**: `$tries = 0` + `retryUntil()` podría reintentar en bucle un archivo intrínsecamente corrupto durante 2 h. Mitigado porque `TranscriptionSubmitService::markError()` promueve a `dead` según `max_retries` y la guarda de `job_id` corta los reenvíos.
- **Riesgo**: `tick_interval_minutes` con el schedule en `everyMinute()` depende del timestamp en caché; si Redis se cae, el tick correría cada minuto. Aceptable: el regulador y `dispatch_paused` siguen acotando, y `withoutOverlapping` se mantiene.
- **Riesgo**: `ShouldBeUnique` podría bloquear un reenvío legítimo dentro de la ventana de 900 s. Aceptable: `uniqueFor` está pareado con `retry_after`, y un reenvío antes de ese plazo es justamente lo que se quiere evitar.
- **Rollback**: cada fase es reversible por separado. Las Fases 2-4 tienen componente de datos: `DELETE FROM system_settings WHERE key LIKE 'transcriptor.%'` devuelve todo a los valores de `config/transcriptor.php`, idénticos a hoy.

## Archivos

### Modificados

- `app/config/queue.php` — `retry_after` 90 → 900
- `app/config/transcriptor.php` — añadir `callback_host` y los defaults de los knobs nuevos
- `app/app/Console/Commands/TranscriptionTickCommand.php` — déficit antes del clamp, `dispatch_paused`, `tick_interval_minutes`, `dispatch_stagger_ms`, settings
- `app/app/Console/Commands/ScanAndSubmitCommand.php` — quitar `* count($storages)`, tope propio, `--alerts`, `dispatch_paused`
- `app/app/Console/Commands/TranscriptionTuneCommand.php` — `reconcileForbiddenPools()`, consts → settings, `worker_override`, tope por units instaladas
- `app/app/Console/Commands/PollResultsCommand.php` — `stale_resend_limit`
- `app/app/Console/Commands/CorrectionsApplyRunCommand.php` — `--run-id=` + validación
- `app/app/Services/Ia/TranscriptorApiClient.php` — timeouts por llamada, `Http::retry`, streaming del opus
- `app/app/Services/Ia/TranscriptionPollingService.php` — `poll_limit`
- `app/app/Services/Ia/DiskScannerService.php` — settings
- `app/app/Services/Ia/TranscriptionSubmitService.php` — `max_retries` vía settings
- `app/app/Services/Ia/CorrectionService.php` — `corrections_chunk`
- `app/app/Jobs/ConvertAndTranscribeJob.php` — middleware, `$tries=0` + `retryUntil()`, `ShouldBeUnique`
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `dispatch_paused` (423), clamps vía settings, quitar default inline `5`
- `app/resources/views/ia/api-transcriptor/index.blade.php` — pestaña Configuración, pool acotado, slider enlazado, tour
- `app/routes/web.php` — rutas de settings, `throttle` en `transcribe/{fileId}`
- `app/routes/console.php` — tick a `everyMinute()` (auto-limitado por setting)
- `app/app/Providers/AppServiceProvider.php` — binding singleton de `TranscriptorSettings`

### Nuevos

- `app/app/Services/Ia/TranscriptorSettings.php`
- `app/app/Console/Commands/TranscriptionConfigCommand.php`
- `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`
- `app/app/Jobs/Middleware/LimitTranscriptionConcurrency.php`
- `app/tests/Unit/TranscriptorSettingsTest.php`
- `app/tests/Feature/TranscriptionTickRegulatorTest.php`
