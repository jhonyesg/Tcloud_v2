## Context

La tarjeta "Tarea programada" vive en `resources/views/ia/api-transcriptor/_settings-tab.blade.php`, dentro del tab Configuración del módulo `/ia/api-transcriptor`. Su bloque de acciones es un `<div data-tour="cfg-run">` que hoy contiene tres botones: "Simular", "Ejecutar ahora" y "Procesar históricos" (este último agregado en trabajo no commiteado del working tree).

El estado en vivo que alimenta esa tarjeta viene de `GET /ia/api-transcriptor/settings`, que la vista consume vía `loadConfig()` y `refreshConfigRuntime()` con un `setInterval` de 10 s (`startConfigPoll`). Ese polling **no** se ve afectado por este cambio.

Restricciones que moldean el approach:

- **Auth por sesión**: el controller toma el actor de `session('user_id')`, nunca `auth()->user()`. Al eliminar `runTick()` desaparece uno de los dos únicos sitios del controller que leían esa sesión para log; el otro (`update`/`reset`) se conserva.
- **Sin build step**: Alpine.js por CDN. Quitar el método `runTick()` del `x-data` no requiere compilar nada.
- **El trait `RunsBackgroundCommands` es compartido**: lo usan `AvisosInteligentesController`, `CorreccionesController` y `ApiTranscriptorController`. El cambio solo lo desengancha de `TranscriptorSettingsController`.
- **Trabajo no commiteado en el mismo archivo**: el botón "Procesar históricos" se agregó a `_settings-tab.blade.php` sin commitear, junto con `ApiTranscriptorController`, `routes/web.php`, `AGENTS.md` y el nuevo `TranscriptorWorkEstimator.php`.

## Goals / Non-Goals

**Goals:**

- Que la tarjeta "Tarea programada" deje de ofrecer dos caminos de lanzamiento manual redundantes ("Simular", "Ejecutar ahora").
- Que desaparezca la ruta, el método de controller, el método Alpine y el log que solo existían para servir a esos dos botones.
- Que el cambio sea reversible con un `git revert` de un commit único, sin migración ni estado persistente que limpiar.

**Non-Goals:**

- No arreglar el bloqueo de `execBackground()` por stdout heredado que afecta a los otros tres callers. Se documenta como riesgo residual.
- No tocar el scheduler ni la frecuencia del tick, ni la discrepancia entre el `tick_interval_minutes` anunciado y la cadencia real (~10-12 min observados). Eso es otro cambio.
- No tocar el modal "Procesar históricos" ni sus tres endpoints.
- No alterar las tarjetas de estado de la tarjeta "Tarea programada".

## Decisions

### Decisión 1: eliminar en vez de arreglar los dos botones

Alternativas consideradas:

| Opción | Por qué no |
|---|---|
| Arreglar "Ejecutar ahora" (candado compartido con el cron + lanzar en background + 202 + panel de progreso) | Deja un **tercer** camino para descubrir "hoy", redundante con el tick automático y con "Procesar históricos". El costo de construir un panel de progreso nuevo no compra ninguna capacidad que el módulo no tenga ya. |
| Arreglar la propagación de `--dry-run` y quedarse con "Simular" como preview real | Un preview real de solo lectura ya existe: `POST /ia/api-transcriptor/scan/estimate` devuelve el conteo de trabajo sin mutar nada, y es lo que usa el modal. Mantener un segundo mecanismo de estimación sería duplicarlo peor (vía log, no vía JSON). |
| Mantener ambos y quitar solo el candado del freeze | El freeze por `exec()` es del trait compartido: tocarlo afecta a Correcciones y Avisos. Arreglar un botón que igual se quiere eliminar es trabajo con riesgo cruzado. |

Se elimina. El trabajo manual por alcance queda en "Procesar históricos", que ya tiene las tres cosas que a estos botones les faltaban: estimación previa, candado y progreso visible.

### Decisión 2: el endpoint se elimina por completo, no se deja como API headless

No hay consumidor externo. El único caller era el método Alpine `runTick()` de la propia vista (verificado con grep global). Dejar la ruta viva "por si acaso" mantendría abierta una superficie que lanza un `transcription:tick` sin candado y congela el worker de PHP-FPM que la atienda — el problema que este cambio remueve.

### Decisión 3: el flag `--dry-run` del comando se conserva

`TranscriptionTickCommand` mantiene su `{--dry-run}`. Es útil por CLI y está cubierto por el criterio de aceptación 8 de `openspec/specs/transcription-orchestrator-runtime/spec.md`. Lo que se elimina es su exposición por HTTP, no la capacidad. La vía documentada pasa a ser:

```bash
cd app && php artisan transcription:tick --dry-run
```

### Decisión 4: el contenedor `data-tour="cfg-run"` NO se elimina

El botón "Procesar históricos" vive dentro de ese `<div>`. Se quitan dos `<button>` y se conserva el wrapper, así el anclaje del tour sigue siendo válido y no hay que tocar ninguna definición de tour (verificado: `cfg-run` solo aparece en ese archivo).

### Cambios concretos por archivo

**Backend**

| Archivo | Cambio |
|---|---|
| `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` | Eliminar el método `runTick(Request $request)` (líneas 128-172) y el `use App\Http\Controllers\Concerns\RunsBackgroundCommands;` (línea 5) junto con `use RunsBackgroundCommands;` (línea 29) |
| `app/routes/web.php` | Eliminar la línea 211: `Route::post('/api-transcriptor/settings/run-tick', ...)` |

**Frontend (Blade + Alpine)**

| Archivo | Cambio |
|---|---|
| `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` | En el `<div data-tour="cfg-run">` (línea 49), eliminar los dos `<button>` de "Simular" (`@click="runTick(true)"`) y "Ejecutar ahora" (`@click="runTick(false)"`). Conservar el botón "Procesar históricos" (`@click="openPz()"`) |
| `app/resources/views/ia/api-transcriptor/index.blade.php` | Eliminar el método Alpine `async runTick(dryRun)` del objeto `x-data`. Ningún otro método lo invoca |

**Estado Alpine resultante**: el bloque `cfgSaving` sigue existiendo (lo usan `togglePause()`, `saveConfig()` y `loadConfig()`). No se introduce estado nuevo ni se elimina ninguno. El evento `@click` del botón eliminado era el único disparador de `runTick()`.

**Limpieza operativa**: borrar `storage/logs/transcription-tick-manual.log` (log huérfano; ningún componente lo lee).

## Risks / Trade-offs

### Riesgo 1: se pierde el "empujón" cuando el pipeline parece atascado

Ese es el uso real que el operador le daba al botón. Trade-off aceptado: el tick automático es continuo (los gaps observados de 10-12 min son la duración del scan, no un parón), y si de verdad está atascado, la respuesta correcta no es disparar una corrida paralela sino diagnosticar. Señales disponibles sin este botón: `cfgRuntime.tick_last_run` en la tarjeta, el semáforo de la cola remota, y `storage/logs/transcription-tick.log`.

### Riesgo 2: el bloqueo por stdout heredado sigue presente en los otros callers

`execBackground()` conserva el defecto: `exec("setsid bash -c '<inner>' &")` sin redirigir el stdout del wrapper, y el `echo "[tag] start"` de la línea 81 escribe al stdout heredado de PHP-FPM. Los afectados son `POST /scan/run` (ApiTranscriptorController), los dos endpoints de escaneo de avisos y `corrections:apply`. Medido en el servidor: la forma del trait bloquea 4 s con un `sleep 4` de fondo. En el caso de `/scan/run` el `$logFile` está pasado, pero eso no redirige el `echo` del marcador — el bloqueo persiste. **Fuera de alcance de este cambio**; se documenta aquí porque al quitar `runTick` el síntoma más visible desaparece y sería fácil creer que el defecto se fue con él.

### Riesgo 3: el working tree tiene trabajo sin commitear en los mismos archivos

`_settings-tab.blade.php`, `routes/web.php` y `ApiTranscriptorController.php` tienen cambios sin commitear de la feature "Procesar históricos". Commitear ese trabajo **antes** de implementar este cambio, o el diff de la eliminación se mezclará con el de la feature y el `git revert` dejará de ser limpio.

### Rollback

Sin migración y sin estado persistente. Un commit único, revertible:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash>
systemctl reload php84-php-fpm   # liberar el opcode cache del controller
```

No hay workers que reiniciar: el cambio no toca `TranscriptionTickCommand`, el scheduler ni la cola PG. Si el revert se aplica y el operador quiere volver a lanzar el tick a mano, el endpoint y los botones vuelven tal cual (incluido su freeze, que es la señal de que el revert funcionó).
