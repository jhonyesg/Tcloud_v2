## Why

El botón **Re-aplicar correcciones** muestra una barra de progreso que no avanza y nunca termina: el proceso PHP de fondo muere al instante porque `PHP_BINARY` resuelve al binario **php-fpm** (no al CLI) bajo PHP-FPM, y el único guard `is_executable()` no distingue entre SAPI CLI y FPM. El log de la corrida expone la ayuda de `php-fpm` (`Usage: php-fpm [-n] [-e] …`). La UI tampoco detecta el fallo porque el detector de "stuck" sólo dispara cuando `status='running'` y `last_progress_at` es string — un worker muerto deja el estado en `queued` para siempre. El mismo trait `RunsBackgroundCommands` lo usan `ApiTranscriptorController` y `TranscriptorSettingsController`, así que el bug es latente en esos 3 callers.

## What Changes

- **`RunsBackgroundCommands::execBackground()` resuelve el binario CLI correcto** detectando `PHP_SAPI !== 'cli'` y forzando `/usr/bin/php` (CLI) cuando el SAPI es `fpm`. Centralizado: los 3 callers heredan el fix.
- **Liveness ping post-launch**: tras `execBackground()`, el controlador `CorreccionesController::applyRetroactive()` hace `usleep(2s)` y verifica que el cache del run ya pasó a `status='running'`. Si no, marca el run como `error` con `error_message="El worker no arrancó — revisá /tmp/kilo_artisan_bg.log"` y libera el puntero `corrections_apply:active`.
- **Detector de "stuck" extendido en la UI**: además del caso `status='running' + last_progress_at > 3min`, dispara alerta visible cuando `status='queued' AND started_at is null AND age(queued_at) > 60s`. La barra muestra el ícono de warning y un CTA "Ver log" en vez de quedar inmóvil.
- **Prefijo de log por caller**: `RunsBackgroundCommands::execBackground()` ahora registra cada corrida con `[corrections:apply]`, `[transcriptor:scan]` o `[transcriptor:settings]` para que `/tmp/kilo_artisan_bg.log` sea diagnosticable.
- **No-op si la corrida es corta**: si `applyRun` ya terminó antes del ping, `status='done'` y se acepta sin error.

## Capabilities

### New Capabilities
- `corrections-apply-retroactive-runner`: Especifica el ciclo de vida completo del run async de re-aplicación: lanzamiento correcto del binario CLI, liveness ping, transiciones de estado `queued→running→done|error`, detección de worker muerto y contrato del detector de stuck. Cubre los 3 callers que usan `RunsBackgroundCommands`.

### Modified Capabilities
<!-- No hay specs previas que cubran el ciclo de vida del run async; las archives
     `2026-08-01-corrections-apply-progress-visibility` y
     `2026-08-01-corrections-retroactive-progress-modal` describen la UI de la barra
     pero no el contrato de "el worker tiene que arrancar y la UI tiene que enterarse
     si muere". Por eso este cambio introduce spec nuevo, no modifica uno existente. -->

## Impact

- **Código**:
  - `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php` (resolver CLI, log con prefijo)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php::applyRetroactive()` (liveness ping + error path)
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` y `TranscriptorSettingsController.php` (heredan el fix central; sin cambios de línea)
  - `app/resources/views/ia/correcciones/index.blade.php` (extender detector de stuck, copy del warning)
- **APIs/Routes**: sin cambios de contrato — `POST /ia/correcciones/apply-retroactive`, `GET /ia/correcciones/apply-retroactive/{runId}`, `GET /ia/correcciones/apply-retroactive-active` mantienen su forma.
- **Migraciones**: ninguna.
- **Compatibilidad**: breaking para `RunsBackgroundCommands` si otro caller lo extiende — revisar `grep` antes de mergear (no se encontraron extends en el código actual).
- **Tests**: nuevo test de regresión `tests/Feature/CorrectionsApplyRetroactiveDeadWorkerTest.php` que simula worker muerto (envuelve `execBackground` con uno que solo `exit 1`) y verifica que la UI recibe `status='error'` en vez de quedarse en `queued`.

## Non-goals

- Rediseñar el módulo `correcciones` como deep module (`codebase-design`); eso queda para un change posterior si la Opción C resulta deseable.
- Cambiar el mecanismo de polling (intervalo de 2s) o el TTL del cache (4h).
- Tocar el modelo `Correction`, las migraciones de la papelera u otros módulos no relacionados con el lanzamiento del background.
- Cambiar la API de `corrections:apply-run` (sólo se ajusta el comportamiento al fallar el launch).
