## ADDED Requirements

### Requirement: UI batch launcher runs the scanner process in a verifiable background task

El botón "Escanear storages" del modal del API Transcriptor SHALL lanzar el comando `transcription:scan-and-submit` en background de forma verificable. La ruta HTTP responde con `200 OK` y un `run_id` ANTES de que el proceso artisan arranque. El sistema SHALL entonces garantizar que, dentro de los 5 segundos posteriores al envío del `200`, el cache `transcription_batch:{runId}` transicione de `status: starting` a `status: running` o `status: queued` (señal de que el artisan arrancó y ejecutó su primera acción de cache). Si el wrapper de lanzamiento falla por sintaxis o por cualquier otro motivo que impida arrancar el artisan, el sistema SHALL escribir el cache con `status: error` y un mensaje accionable que apunte al log compartido, de modo que el modal frontend pueda mostrar el error al operador en vez de quedarse colgado en "Iniciando proceso en background..." durante el TTL completo (2h).

El contrato entre el trait de lanzamiento (`RunsBackgroundCommands::execBackground`) y los callers SHALL ser: el trait es el único responsable de agregar el `&` final y la redirección de salida; los callers SHALL construir comandos artisan puros sin redirección y sin `&` final. Si un caller histórico le pasa al trait un `$cmd` que ya termina en `&`, el trait SHALL detectar el patrón y eliminarlo antes de envolver, como defensa contra regresiones.

#### Scenario: Manual click launches the artisan and the cache transitions to running
- **WHEN** el operador hace clic en "Iniciar procesamiento" en el modal "Escanear storages" con al menos un storage habilitado
- **THEN** el frontend recibe un `200 OK` con un `run_id` válido
- **AND** dentro de los 5 segundos posteriores, el cache `transcription_batch:{runId}` muestra `status: running` o `status: queued`
- **AND** se crea el archivo de log `storage/logs/transcription-batch-{runId}.log` con al menos una línea de salida del comando artisan (PHP warnings, info del scanner, etc.)

#### Scenario: Launcher wrapper fails and the cache reports error with actionable message
- **WHEN** por cualquier motivo el wrapper bash que lanza el artisan no puede ejecutarlo (sintaxis inválida, binario PHP CLI no encontrado, permisos, etc.)
- **THEN** el cache `transcription_batch:{runId}` se actualiza con `status: error` y un mensaje que incluye la ruta al log compartido (`/tmp/kilo_artisan_bg.log`) filtrable por el `[logTag]` correspondiente
- **AND** el frontend muestra el mensaje de error en el panel de resultados del modal en lugar de quedarse en "Iniciando proceso en background..."

#### Scenario: Caller passes a $cmd with trailing ampersand and the trait strips it
- **WHEN** un caller pasa al trait `execBackground($cmd, $tag)` con `$cmd` que ya termina en ` &` (patrón histórico del controller de transcripción)
- **THEN** el trait elimina el `&` final antes de envolver el comando
- **AND** el artisan arranca normalmente sin producir `bash: -c: syntax error`

#### Scenario: Trait redirects per-run output to a dedicated log file when logFile is provided
- **WHEN** un caller pasa al trait un tercer argumento `?string $logFile` no nulo
- **THEN** toda la salida del artisan (stdout + stderr) se redirige tanto al log por-corrida como al log compartido `/tmp/kilo_artisan_bg.log` con los marcadores `[logTag] start` y `[logTag] end`

#### Scenario: No background process leaks when artisan exits immediately
- **WHEN** el artisan arranca pero falla antes de la primera escritura al cache (por ejemplo, `boot --no-dispatch` con todos los storages ya procesados, o un error fatal antes del primer `Cache::put`)
- **THEN** el subshell bash `setsid` cierra limpiamente
- **AND** el proceso artisan no queda zombie (el handler de SIGCHLD reaps el PID reparentado a init)

### Requirement: Frontend runBatch() polls the cache with the real run_id returned by the server

El frontend del modal "Escanear storages" SHALL garantizar que el polling de `/batch-status/{runId}` use el `run_id` REAL devuelto por el servidor en la respuesta HTTP del POST `/process-batch`. Si por motivos de latencia el `Promise.race` con el watchdog del frontend rechaza el fetch antes de que llegue la respuesta, el catch SHALL esperar a la promesa original del fetch para extraer el `run_id` real en vez de inventar uno sintético. Solo si el fetch original también falla (timeout de red total, error del servidor), el frontend SHALL mostrar un error de conexión accionable.

#### Scenario: Server is slow but responds eventually and frontend uses the real run_id
- **WHEN** el operador hace clic en "Iniciar procesamiento" y el servidor tarda entre 5s y 20s en responder
- **THEN** el frontend espera la respuesta real del fetch (más allá del watchdog inicial)
- **AND** el polling arranca con el `run_id` real devuelto por el servidor (`batch_xxx`)
- **AND** el modal muestra el progreso real del batch (cache `starting → running → queued`)

#### Scenario: Server never responds and frontend surfaces a connection error
- **WHEN** el operador hace clic en "Iniciar procesamiento" y el fetch original falla completamente (timeout de red total, 5xx no recuperable)
- **THEN** el frontend NO inventa un `run_id` sintético para pollear contra cache inexistente
- **AND** muestra un mensaje accionable de "sin respuesta del servidor" en el panel de resultados del modal
- **AND** el cache no queda "huérfano" con un `run_id` que no corresponde a un proceso real
