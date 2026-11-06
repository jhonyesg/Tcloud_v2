## ADDED Requirements

### Requirement: El cupo de canales se resuelve desde `grabador_usuario`

El sistema SHALL resolver el límite de canales de un par `(grabador, usuario)` leyendo `grabador_usuario.limite_canales`, y SHALL exponerlo a través de un único servicio `App\Services\GrabacionesPuntuales\CupoCanalesService` con los métodos `limite()`, `uso()` y `resumen()`. El uso SHALL calcularse como `count(canales)` para el par. `CanalController::create`, `CanalController::store` y `GrabadorController::actualizarAsignacion` SHALL consumir ese servicio; NINGUNO SHALL leer el límite como atributo del modelo `Grabador`.

#### Scenario: El límite editado en Grabadores se aplica al crear

- **WHEN** el par `(grabador 1, user_id 11)` tiene `limite_canales = 5` y 4 canales locales
- **AND** el usuario 11 envía `POST /grabaciones-puntuales/canales` con un `slot_nombre` nuevo
- **THEN** el sistema crea el canal (5.º)
- **AND** `CupoCanalesService::resumen(1, 11)` devuelve `['limite' => 5, 'usados' => 5, 'disponibles' => 0]`

#### Scenario: Se rechaza al alcanzar el límite

- **WHEN** el par `(grabador 1, user_id 11)` tiene `limite_canales = 5` y 5 canales locales
- **AND** el usuario envía un `slot_nombre` nuevo
- **THEN** el sistema NO crea el canal
- **AND** redirige de vuelta al formulario con un mensaje de error visible que incluye el límite alcanzado

#### Scenario: Límite inexistente no se interpreta como cero

- **WHEN** `CupoCanalesService::limite($grabadorId, $userId)` no encuentra fila en `grabador_usuario`
- **THEN** retorna `null`
- **AND** cualquier consumidor que reciba `null` SHALL tratar el caso como "sin asignación" y denegar la creación con un mensaje explícito, NUNCA comparar contra el valor nulo

#### Scenario: El administrador no está sujeto al cupo

- **WHEN** un usuario con `role = 'admin'` crea un canal en cualquier grabador
- **THEN** el guard de cupo no se evalúa y la creación procede

#### Scenario: Dos creaciones simultáneas no exceden el cupo

- **WHEN** el par tiene 4 canales con `limite_canales = 5` y llegan dos `POST` concurrentes
- **THEN** exactamente uno de los dos crea el canal
- **AND** el otro recibe el rechazo de cupo, sin dejar el par en 6

### Requirement: El rechazo por cupo es visible para el usuario

Todas las vistas del módulo `grabaciones_puntuales` SHALL renderizar los mensajes flash `success`, `warning` y `error` mediante el partial compartido `resources/views/partials/flash-toast.blade.php`. El formulario de creación SHALL preservar `old('slot_nombre')` y `old('grabador_id')` tras un rechazo. Ningún rechazo de negocio SHALL depender de un `alert()` nativo ni de un `back()` sin mensaje renderizado.

#### Scenario: Rechazo de cupo se muestra como toast

- **WHEN** el `POST /grabaciones-puntuales/canales` es rechazado por cupo
- **THEN** la vista de creación muestra un toast rojo con el motivo
- **AND** el `slot_nombre` escrito por el usuario permanece en el campo

#### Scenario: Alta exitosa se muestra como toast verde

- **WHEN** el `POST /grabaciones-puntuales/canales` crea el canal correctamente
- **THEN** la vista de listado muestra un toast verde de confirmación

### Requirement: La asignación respeta el límite solicitado

`GrabadorController::asignarUsuario` SHALL persistir en `grabador_usuario.limite_canales` el valor recibido en el request (validado como entero entre 1 y 100, default 10) y SHALL provisionar exactamente esa cantidad de slots. NUNCA SHALL escribir un límite distinto al solicitado.

#### Scenario: Asignación con límite 4 crea 4 slots

- **WHEN** un administrador asigna un usuario con `limite_canales = 4` y `nombre_base = Medio`
- **THEN** `grabador_usuario.limite_canales` queda en 4
- **AND** se crean exactamente 4 canales `Medio_01` … `Medio_04`

#### Scenario: Asignación sin límite explícito usa el default

- **WHEN** un administrador asigna un usuario sin enviar `limite_canales`
- **THEN** el pivote queda en 10 y se provisionan 10 slots

### Requirement: Bajar el límite no destruye canales

`GrabadorController::actualizarAsignacion` SHALL escribir el nuevo `limite_canales` sin borrar canales cuando el nuevo valor sea menor que el uso actual. En ese caso SHALL responder indicando el exceso (`usados`, `limite`, lista de `slot_nombre` excedentes) y la creación futura SHALL quedar bloqueada. El borrado de excedentes SHALL requerir la bandera explícita `prune = true`; sin ella, ningún canal local ni remoto SHALL eliminarse.

#### Scenario: Bajar de 10 a 4 con 10 canales no borra nada

- **WHEN** un administrador envía `limite_canales = 4` para un par con 10 canales y sin `prune`
- **THEN** el pivote queda en 4
- **AND** los 10 canales siguen existiendo en `canales` y en el grabador remoto
- **AND** la respuesta incluye `warning` con los 6 `slot_nombre` excedentes

#### Scenario: Poda explícita elimina excedentes en orden

- **WHEN** un administrador envía `limite_canales = 4` con `prune = true` para un par con 10 canales
- **THEN** se eliminan los 6 canales de mayor `id`
- **AND** cada eliminación remota exitosa precede al `DELETE` local de su fila

#### Scenario: Fallo remoto aborta la poda completa

- **WHEN** la poda explícita intenta eliminar un canal y el `DELETE` remoto falla
- **THEN** la transacción aborta
- **AND** ninguna fila local es eliminada
- **AND** la respuesta es un error que identifica el canal problemático

### Requirement: Ruta base legible desde el pivote

`User::grabadores()` y `Grabador::usuarios()` SHALL exponer `ruta_base` en el pivote (`withPivot(['limite_canales', 'ruta_base'])`). La UI del módulo Grabadores SHALL leer la ruta base desde ese pivote y NUNCA derivarla de `canales.ruta_destino`.

#### Scenario: Usuario sin canales muestra su ruta base

- **WHEN** un par tiene `ruta_base = /disco/pruebas` y cero canales
- **THEN** el modal de edición de asignación muestra `/disco/pruebas` en el campo de ruta base

#### Scenario: Editar solo el límite no borra la ruta

- **WHEN** un administrador guarda el modal enviando únicamente `limite_canales`
- **THEN** `grabador_usuario.ruta_base` conserva su valor previo

### Requirement: Los canales remotos huérfanos son detectables

`POST /grabaciones-puntuales/canales/sincronizar` SHALL clasificar los canales del grabador en cuatro cubos y devolverlos en la respuesta: `vinculados` (remoto con fila local actualizada), `ya_ok` (remoto con fila local ya sincronizada), `sin_match` (fila local sin remoto), `remotos_detectados` (remoto sin fila local, con nivel de confianza `adoptable` o `sugerido`). El conteo `remotos_detectados` SHALL mostrarse al operador; no SHALL adoptarse ningún canal de forma automática.

#### Scenario: Canal creado a mano en el grabador aparece como detectado

- **WHEN** existe el remoto `id=202 codigo=Solicitudes_05` bajo `grabador_usuario.ruta_base` del par `(1, 11)` y no existe fila local con ese `slot_nombre`
- **AND** se invoca la sincronización
- **THEN** la respuesta lo incluye en `remotos_detectados` con `confianza = 'adoptable'`
- **AND** `canales` no gana ninguna fila hasta que el operador confirme

## MODIFIED Requirements

### Requirement: Channel creation uses pivot ruta_base

`CanalController::store` SHALL derivar `ruta_destino` from `grabador_usuario.ruta_base` when creating an individual channel, so that channels created via the `crear canal` form also respect the admin-configured base path. Antes de insertar, SHALL evaluar el cupo mediante `CupoCanalesService` dentro de una transacción con bloqueo de la fila pivote.

#### Scenario: store derives ruta_destino from pivot

- **WHEN** a non-admin user submits `store` with `grabador_id = G`, `slot_nombre = S`
- **AND** the pivot `(G, user_id)` has `ruta_base = /disco/grabaciones`
- **THEN** the system creates the canal with `ruta_destino = '/disco/grabaciones/S'`
- **AND** it passes that `ruta_destino` to `crearCanal`

#### Scenario: store consults the pivot limit before creating

- **WHEN** a non-admin user submits `store` and the pivot `(G, user_id)` has `limite_canales = 5` with 4 existing channels
- **THEN** the system counts the existing channels inside the locked transaction
- **AND** it creates the channel only if `usados < limite_canales`
