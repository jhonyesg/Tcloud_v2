## Context

The current `CorreccionesController::applyRetroactive()` (líneas 609-760) launches the async worker via the trait `RunsBackgroundCommands::execBackground()`. The trait builds a command like:

```
$phpBin = PHP_BINARY;                                 // (1) bajo php-fpm: /www/server/php/84/sbin/php-fpm
if (!is_executable($phpBin)) $phpBin = '/usr/bin/php'; // (2) fallback nunca se dispara, php-fpm tiene +x
$cmd = "$phpBin $artisanPath corrections:apply-run --run-id=... ...";
execBackground($cmd);                                 // setsid bash -c "$cmd > /tmp/kilo_artisan_bg.log 2>&1" &
```

`PHP_BINARY` under PHP-FPM resolves to the FPM SAPI binary, which interprets `artisan` as a positional argument and exits after printing its usage banner. The artisan command never runs; the cache entry stays in `status='queued'` with `last_progress_at = null`. The UI's stuck detector (`index.blade.php` ~línea 4364) requires `status='running'` and a string `last_progress_at`, so it never fires either. The admin sees an inert progress bar for the full TTL (4h).

The trait is reused by `ApiTranscriptorController` (escanear carpetas) y `TranscriptorSettingsController` (procesar settings), so any fix MUST be centralized.

The cache lifecycle in `CorreccionesController::applyRetroactive()` already follows a clear pattern: `Cache::put(...)` with `status='queued'` → worker overwrites to `status='running'` → overwrites to `status='done'` or `status='error'` → `Cache::forget('corrections_apply:active')` on completion. The liveness check slots in between the `execBackground()` call and the `return 202`.

## Goals / Non-Goals

**Goals:**
- Centralizar la resolución del binario CLI en `RunsBackgroundCommands` para que los 3 callers hereden el fix sin tocar sus archivos.
- Hacer que la UI detecte un worker muerto en vez de quedar inmóvil: liveness ping post-launch + extensión del detector de stuck a `queued` huérfano.
- Hacer el log compartido diagnosticable por caller.
- Mantener compatibilidad con `applyRetroactive`, `runStatus`, `activeApplyRun` y el resto de la UI.

**Non-Goals:**
- No rediseñar el módulo correcciones como deep module (queda para un change posterior).
- No reemplazar `setsid bash -c` por `Process` de Symfony/Laravel (funciona, sólo arreglamos el binario y la observabilidad).
- No introducir un sistema de colas Laravel (Redis queue + horizon): el alcance es cirujano, no arquitectónico.
- No cambiar el TTL del cache, ni el intervalo de polling, ni el formato del response del endpoint.

## Decisions

### 1. Detección de SAPI dentro del trait

```php
trait RunsBackgroundCommands
{
    protected function resolvePhpCli(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;            // ya somos CLI, usar lo mismo
        }
        foreach (['/usr/bin/php', '/usr/bin/php84'] as $candidate) {
            if (is_executable($candidate)) return $candidate;
        }
        throw new \RuntimeException(
            'No se encontró un binario CLI de PHP para lanzar comandos en background. '.
            'Probá instalar php-cli o exponer el binario en /usr/bin/php.'
        );
    }
}
```

**Rationale:** `PHP_SAPI === 'cli'` cubre `php artisan` directo y crons. La lista de fallback prioriza las rutas documentadas en el proyecto (el symlink `/usr/bin/php -> /www/server/php/84/bin/php` ya existe). Lanzar excepción en lugar de continuar con `php-fpm` evita el bug actual.

**Alternatives considered:**
- Detectar `basename(PHP_BINARY) === 'php-fpm'` → frágil (cambia con la distro).
- Comprobar `php_sapi_name()` en runtime → redundante con `PHP_SAPI`.
- Variable de entorno `TCLI_PHP_BIN` → operador-depenciente, no es defensa-en-profundidad.

### 2. Liveness ping como verificación post-dispatch

```php
// dentro de applyRetroactive(), después de execBackground():
usleep(2_000_000);  // 2s para que artisan escriba 'running'
$state = Cache::get($cacheKey);
$status = $state['status'] ?? null;

if ($status === null) {
    // el comando nunca llegó a tocar cache — worker muerto
    Cache::put($cacheKey, [...$state, 'status' => 'error', 'error_message' => '...'], TTL);
    Cache::forget('corrections_apply:active');
    return response()->json(['error' => '...', 'log' => '/tmp/kilo_artisan_bg.log'], 500);
}
if ($status === 'queued') {
    // arrancó pero no escribió running — mismo síntoma, mismo error
    Cache::put($cacheKey, [...$state, 'status' => 'error', 'error_message' => '...'], TTL);
    Cache::forget('corrections_apply:active');
    return response()->json(['error' => '...', 'log' => '/tmp/kilo_artisan_bg.log'], 500);
}
// status === 'running' || 'done' || 'error' → aceptamos
return response()->json(['runId' => $runId, ...], 202);
```

**Rationale:** 2s es suficiente para que `CorrectionsApplyRunCommand::handle()` haga `Cache::put(... 'status' => 'running')` (línea 91-95 del comando). Si la corrida es tan corta que termina antes del sleep, `status='done'` también es válido — el if ya lo acepta. El `usleep` agrega 2s a la latencia del POST: aceptable porque la UI ya espera varios segundos para abrir el modal de progreso, y la alternativa (polling sin ping) deja al admin 30+ segundos mirando una barra inmóvil antes de cualquier señal.

**Alternatives considered:**
- Polling asíncrono del lado del cliente (sin ping en el request) → la UI sigue ciega durante el primer poll cycle (2s) Y no tiene cómo distinguir "queued porque arrancó" de "queued porque murió".
- Liveness via socket UNIX entre worker y web → sobre-ingeniería para 1 caso de uso.
- Hacer el ping 5s → duplica la latencia sin beneficio observable.

### 3. Detector de stuck extendido a `queued` huérfano

```js
// index.blade.php, dentro de pollRun()
const queuedAt = Date.parse(d.queued_at || '');
const ageQueuedMs = isNaN(queuedAt) ? 0 : (Date.now() - queuedAt);
const orphanQueued = d.status === 'queued'
    && !d.started_at
    && ageQueuedMs > 60_000;
const staleRunning = d.status === 'running'
    && typeof d.last_progress_at === 'string'
    && (Date.now() - Date.parse(d.last_progress_at)) > 180_000;

if (orphanQueued || staleRunning) {
    if (!this.runStuck) {
        this.runStuck = true;
        this.runStuckSinceText = new Date(...).toLocaleTimeString('es-CO', ...);
    }
} else if (this.runStuck) { /* clear */ }
```

**Rationale:** 60s es 30× el tiempo de ping (2s) y < latencia de php-fpm cold start: deja margen suficiente sin hacer esperar al admin. La condición `!started_at` evita falsos positivos en el primer poll (cuando la cache acaba de ser escrita con `started_at=null`).

**Alternatives considered:**
- Mostrar stuck desde el primer poll si `status='queued'` → demasiado agresivo: en corrida normal la transición `queued→running` puede tardar 1-3 segundos.
- Hacer la UI auto-cancelar runs stuck → peligroso (puede matar una corrida real que sólo avanza lento).

### 4. Prefijo de log por caller

`RunsBackgroundCommands::execBackground()` recibe un parámetro opcional `$logTag = 'unknown'`:

```php
protected function execBackground(string $cmd, string $logTag = 'unknown'): void
{
    $shellCmd = 'setsid bash -c ' . escapeshellarg(
        "(echo '[{$logTag}] start ' \"\$(date -Is)\"; {$cmd}; echo '[{$logTag}] end ' \"\$(date -Is)\") ".
        '>> /tmp/kilo_artisan_bg.log 2>&1'
    ) . ' &';
    exec($shellCmd);
}
```

**Rationale:** El log compartido se vuelve trivial de filtrar por `grep '\[corrections:apply\]'`. Append (`>>`) en lugar de truncate (`>`) preserva el historial de runs. Los 3 callers pasan su tag al llamar.

**Alternatives considered:**
- Log por caller en archivos separados → más limpieza pero pierde la vista agregada que ya tiene valor diagnóstico.
- Prefijo via `tee` o `logger -t` → una capa más de shell sin ganancia real.

## Risks / Trade-offs

- **[Latencia del POST]** → `usleep(2s)` agrega 2 segundos al POST `apply-retroactive`. La UI ya abre el modal antes del polling; impacto UX mínimo. Si en el futuro se quiere eliminar, considerar fire-and-forget + ping WebSocket.
- **[Falsos negativos del ping]** → Si el worker tarda >2s en escribir `running` (BD lenta, autoloader frío), el ping marcará error. Mitigación: 2s es 4× el tiempo observado en logs previos para `handle()`. Si se observa drift, subir a 3s.
- **[Race condition: 409 falso]** → Si dos admins clickean Re-aplicar simultáneamente, el `Cache::add` del active pointer ya bloquea con 409. Sin cambio.
- **[Cache cleanup en caso de crash]** → El TTL de 4h sigue siendo la red de seguridad. Si el worker muere a la mitad (después de escribir `running`), el stuck detector de 3 min dispara y el admin puede inspeccionar el log.

## Migration Plan

Sin migración de BD. Sin cambio de API. El rollout es:

1. **Pre-deploy**: auditar manualmente los 3 callers del trait con un dry-run (`php artisan list`) y confirmar que bajo FPM el binario equivocado se sigue eligiendo (reproducir el bug).
2. **Deploy**: mergear y desplegar. Sin pasos manuales.
3. **Verificación post-deploy**:
   - En `/ia/correcciones`, dar "Re-aplicar" con alcance "Último día". Esperar ver barra con progreso real.
   - Si la UI muestra error "El worker no arrancó", revisar `/tmp/kilo_artisan_bg.log` filtrando por `[corrections:apply]`.
   - Probar el otro flujo afectado por el bug latente: `transcripciones/escaneo` debería también mejorar.
4. **Rollback**: revert del merge. Sin impacto en BD. Las runs en curso previas al deploy siguen su ciclo (cache independiente por runId).

## Open Questions

- ¿Vale la pena aplicar el mismo fix a `ApiTranscriptorController` y `TranscriptorSettingsController` que también usan el trait? **Decisión: sí**, porque el fix va en el trait y se hereda automáticamente. No requiere tareas separadas. Verificar con un dry-run de cada flujo durante la implementación.
- ¿Mostrar el contenido del log directamente en la UI (read-only) o sólo el path? **Decisión deferida**: por ahora sólo el path en el mensaje de error; un visor de logs en la UI es scope de otro change.
