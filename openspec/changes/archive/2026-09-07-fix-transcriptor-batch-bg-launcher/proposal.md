## Why

El botón "Escanear storages" del modal del API Transcriptor nunca arranca el proceso en background. El controller devuelve `200 OK` con un `run_id` válido, pero bash aborta el subshell con `syntax error near unexpected token ';'` porque el `$cmd` que arma `ApiTranscriptorController::processBatch()` ya termina en `&` y se vuelve a envolver en otro `&` dentro de `RunsBackgroundCommands::execBackground()`. El cache `transcription_batch:{runId}` queda en `status: starting` durante 2h, el modal se queda pegado en "Iniciando proceso en background...", y el operador termina pensando que el sistema está procesando cuando en realidad no se ejecutó ningún artisan. Las otras rutas que usan el mismo trait (`CorreccionesController::apply`, `AvisosInteligentesController::scan`) no tienen este problema porque no le agregan `&` ni `>>` al `$cmd`. La causa raíz es de contrato: el trait y los callers no se pusieron de acuerdo en quién maneja la redirección y el backgrounding.

## What Changes

- Extender `RunsBackgroundCommands::execBackground()` para aceptar un `$logFile` opcional y que el trait haga la redirección por-corrida además del `>> /tmp/kilo_artisan_bg.log` con marcadores `[logTag] start/end`. La firma pasa a `execBackground(string $cmd, string $logTag = 'unknown', ?string $logFile = null)`.
- Hacer que `execBackground()` sea el único responsable del `&` final: si el `$cmd` que recibe ya termina en `&`, el trait lo detecta y lo elimina (defensa contra regresiones en callers que vuelvan al patrón viejo).
- En `ApiTranscriptorController::processBatch()` (líneas 1098-1111) construir el `$cmd` SIN redirección y SIN `&` final — el trait se encarga de ambos.
- Cuando `execBackground()` detecta que el wrapper bash falló (cualquier `bash: -c:` en stderr o exit != 0), escribir el cache `transcription_batch:{runId}` con `status: error` y un mensaje accionable que apunte al log compartido, para que el modal no se quede mudo en `starting`.
- Migrar `CorreccionesController::apply` y `AvisosInteligentesController::scan` al nuevo contrato del trait (sin cambios funcionales, solo de forma) para que las tres rutas queden idénticas y el bug sea imposible de reintroducir.

## Capabilities

### New Capabilities
_Ninguna._

### Modified Capabilities
- `transcription-disk-scanner`: nuevo requisito "UI batch launcher arranca el proceso en background de forma verificable" que documenta el contrato entre el trait y el controller, exige verificación de arranque (cache pasa de `starting` a `running`/`queued` en ≤ 5s) y exige feedback de error cuando el wrapper bash falla.

## Impact

- `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php` — cambio de firma + helper de validación de sintaxis + manejo de logFile.
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `processBatch()` (líneas ~1095-1129) ya no arma el `>> log &`.
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (línea ~881) y `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (línea ~414) — adoptan la nueva firma.
- Frontend: sin cambios. El modal sigue mostrando el mismo flujo; lo único que cambia es que ahora `status: error` puede aparecer en el panel de resultados en lugar de quedarse colgado en `starting`.
- Tests: nuevo test de integración que ejercita `processBatch()` con un `run_id` y verifica que el cache transiciona a `running`/`queued` en ≤ 5s o aparece `status: error` con mensaje accionable.
- No requiere migración de BD. No requiere reinicio de workers supervisord (los cambios viven en controllers + trait).

## Non-goals

- No se cambia el comando `transcription:scan-and-submit` ni su lógica de scan/dispatch.
- No se reemplaza el patrón `setsid bash -c` por otra estrategia de background (proc_open, fork, etc.). El bug es de sintaxis bash, no de mecanismo de aislamiento.
- No se cambian los timeouts del polling del frontend (2s).
- No se rediseña el modal de progreso.
- No se agrega un nuevo endpoint: el actual `/ia/api-transcriptor/batch-status/{runId}` ya cubre el caso de error si el cache se escribe correctamente.
