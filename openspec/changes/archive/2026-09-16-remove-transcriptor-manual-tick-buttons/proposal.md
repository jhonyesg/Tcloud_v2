## Why

La tarjeta "Tarea programada" de `/ia/api-transcriptor` expone dos botones que ya no sirven a ningún caso de uso y que hoy dañan al operador:

- **"Ejecutar ahora"** lanza un segundo `transcription:tick` en paralelo al automático, sin candado compartido con el scheduler. Además congela la página: `RunsBackgroundCommands::execBackground()` dispara con `exec()`, que no retorna hasta que todo el árbol de procesos cierra el stdout heredado de PHP-FPM. Reproducido en el servidor: `exec("sleep 4 &")` → 4.00 s; `exec("sleep 4 >/dev/null 2>&1 &")` → 0.00 s. El `echo "[tag] start"` del trait (línea 81) no lleva redirección, así que mantiene el pipe abierto durante los ~10 min del scan. Evidencia: `/tmp/kilo_artisan_bg.log` tiene 12 `[transcriptor:settings] end` y **cero** `start`.
- **"Simular"** no simula. `TranscriptionTickCommand` recibe `--dry-run` pero no lo propaga al `transcription:scan-and-submit` de la Fase 1 (`TranscriptionTickCommand.php:80-85`), así que igual recorre los 70 storages, crea archivos y filas, y corre el matching de menciones. Cuesta el mismo I/O que el real y su salida va a `transcription-tick-manual.log`, que **ningún** componente de UI lee.

El tick automático ya cubre "hoy" de forma continua, y el modal **"Procesar históricos"** (ya en el working tree) cubre hoy/rango/histórico con candado (`transcriptor:scan_run:lock`), estimación de solo lectura (`POST /scan/estimate`) y polling de progreso. Los dos botones son un tercer camino redundante y peor construido.

## What Changes

- Eliminar los botones **"Simular"** y **"Ejecutar ahora"** de `resources/views/ia/api-transcriptor/_settings-tab.blade.php`.
- Eliminar el método Alpine `runTick()` de `resources/views/ia/api-transcriptor/index.blade.php`.
- Eliminar `TranscriptorSettingsController::runTick()` y el `use RunsBackgroundCommands` del controller (el trait queda sin uso ahí).
- Eliminar la ruta `POST /ia/api-transcriptor/settings/run-tick`.
- Eliminar el log muerto `storage/logs/transcription-tick-manual.log`.

**Se conserva**:

- `transcription:tick --dry-run` en el comando: sigue siendo válido por CLI y lo cubre el criterio de aceptación 8 del spec.
- `GET /ia/api-transcriptor/settings` (contexto en vivo) — lo sigue usando el polling de 10 s.
- El contenedor `data-tour="cfg-run"`: el botón "Procesar históricos" vive ahí.
- Las tarjetas de estado de la tarjeta "Tarea programada" (`tick_last_run`, cola, workers, estados).
- Todo el pipeline backend: workers PG, stager, poller, watchdog, snapshots.

### Non-goals

- No se toca `transcription:tick` ni el scheduler (incluida la discrepancia "cada 2 min" vs ~10-12 min reales: es un hilo aparte sobre el costo del discovery).
- No se arregla el bloqueo por stdout heredado de `execBackground()` para los otros callers (`AvisosInteligentesController`, `CorreccionesController`, `ApiTranscriptorController`) — se documenta como riesgo residual.
- No se tocan `POST /scan/estimate`, `POST /scan/run`, `GET /scan/status/{runId}` ni el modal "Procesar históricos".

## Prerrequisito: el working tree trae un hotfix de producción sin commitear

Al abrir el apply, el working tree **no** contenía solo la feature "Procesar históricos". La verificación de estabilidad previa al cambio detectó que el commit `ff29aab` (`chore(timezone): bogota end-to-end via BogotaTime helper`) reemplazó `CarbonImmutable::today()` por `BogotaTime::todayStart()` en tres comandos **sin agregar el `use` correspondiente**. PHP resolvía `BogotaTime` en el namespace `App\Console\Commands` → clase inexistente:

```
ROTO EN HEAD (ff29aab):
  app/app/Console/Commands/TranscriptionTickCommand.php             (crítico: planner del pipeline)
  app/app/Console/Commands/TranscriptionBackfillLostCommand.php
  app/app/Console/Commands/TranscriptionPurgeStalePendingCommand.php
```

Impacto medido: `transcription:tick` abortaba en cada corrida programada. `storage/logs/laravel.log` registró `Class "App\Console\Commands\BogotaTime" not found at TranscriptionTickCommand.php:72` repetidamente; el descubrimiento automático quedó caído hasta que se aplicó el hotfix local (3 líneas `use`, ya presentes en el working tree sin commitear).

Este change **no** causa ese bug ni lo arregla de fondo, pero lo pone en el camino: el grupo 0 del `tasks.md` commitea primero el hotfix (`fix`) y después la feature (`feat`) en commits separados, para que el diff de la eliminación quede aislado y su `git revert` sea limpio. Mezclar el hotfix con la feature ataría el arreglo crítico a una feature de UI que puede revertirse.

Se detectó además basura de depuración sin trackear (`app/scan_one.php`, screenshots y specs `.mjs` de validación) que queda **fuera** de todos los commits.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `transcription-orchestrator-runtime`: el requisito 13 ("Superficie de observacion y control") deja de declarar `POST /ia/api-transcriptor/settings/run-tick` como parte de la superficie expuesta.

## Impact

- `app/routes/web.php` (ruta `run-tick`)
- `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` (método `runTick` + trait)
- `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` (dos botones)
- `app/resources/views/ia/api-transcriptor/index.blade.php` (método Alpine `runTick`)
- `openspec/specs/transcription-orchestrator-runtime/spec.md` (requisito 13)

Sin migración. Sin dependencias nuevas. `RunsBackgroundCommands` sigue en uso por otros tres controllers.

Precede a este change un commit de hotfix ajeno al alcance (`fix(timezone): add missing BogotaTime import`), necesario para devolverle a HEAD la integridad que el tick automático perdió. Ver la sección "Prerrequisito" arriba.
