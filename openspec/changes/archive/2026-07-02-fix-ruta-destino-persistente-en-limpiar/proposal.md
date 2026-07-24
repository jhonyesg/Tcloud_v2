## Why

En el módulo **Medios Puntuales**, cuando un canal pasa por el flujo `Limpiar → re-editar y agregar link_origen`, la `ruta_destino` (BD local) queda `NULL`, y al recrear el canal en la API del grabador se usa el fallback genérico `generarRutaDescarga()` (`Disco_I/radio/<slug>`) en lugar de la ruta real configurada en el módulo Grabadores. Esto hace que los archivos se guarden en una ruta distinta a la que el admin configuró.

Hoy la `ruta_base` configurada por el admin al asignar un usuario solo vive implícita en `canales.ruta_destino`. Como `CanalController::destroy` la pone en `NULL`, se pierde en cuanto el usuario hace clic en **Limpiar**. Los canales 03, 06, 08 y 09 del usuario `siglohallon` ya están en ese estado.

## What Changes

- **Migración nueva**: agregar columna `ruta_base` a la tabla pivote `grabador_usuario`. Fuente de verdad persistente para el par usuario × grabador. Backfill desde `canales.ruta_destino`.
- **`GrabadorController::asignarUsuario`**: persistir `ruta_base` en el pivote al asignar.
- **`GrabadorController::actualizarAsignacion`**: persistir `ruta_base` en el pivote al re-asignar.
- **`CanalController::destroy`** (botón **Limpiar**): dejar de borrar `ruta_destino`. "Limpiar" solo resetea el registro en la API (`api_canal_id`, `link_origen`, `detalle`), no la ruta de guardado.
- **`CanalController::update`**: antes de llamar a `crearCanal`, si `$canal->ruta_destino` está vacío, derivar de `pivote.ruta_base + slot_nombre`, persistir y enviar.
- **`CanalController::store`**: al crear canal individual, derivar `ruta_destino` del pivote.
- **`TcloudApiService::crearCanal`**: defensa en profundidad. Si `$canal->ruta_destino` es null/vacío, leer pivote antes que `generarRutaDescarga()`.
- **Script de remediación**: para `Puntual_03, 06, 08, 09` (y cualquier otro canal con `ruta_destino` NULL) rellenar local + `PUT` remoto con `ruta_descarga` correcta.

No hay cambios de UI visibles. No hay cambios de rutas. No hay breaking changes.

### Non-goals

- No se rediseña el modelo `Canal` ni la API remota del grabador.
- No se cambia la semántica del botón **Limpiar** más allá de no tocar `ruta_destino`.
- No se migran rutas existentes fuera del script de remediación (es uno solo, scoped a canales con `ruta_destino IS NULL` del par `(usuario, grabador)` afectado).
- No se introduce un nuevo modelo `UserGrabadorSetting`. La ruta base vive en la pivote existente `grabador_usuario`.

## Capabilities

### New Capabilities

- `grabaciones-puntuales`: comportamiento de `ruta_destino` consistente con la `ruta_base` configurada en el módulo Grabadores, sobrevive a Limpiar + re-crear, y siempre se envía al grabador remoto.

### Modified Capabilities

_(ninguna. No hay spec previa sobre este módulo)_.

## Impact

- **Modelos**: `Canal`, `Grabador`, pivote `grabador_usuario` (nueva columna).
- **Controladores**:
  - `App\Http\Controllers\GrabacionesPuntuales\GrabadorController` (`asignarUsuario`, `actualizarAsignacion`)
  - `App\Http\Controllers\GrabacionesPuntuales\CanalController` (`update`, `store`, `destroy`)
- **Servicio**: `App\Services\GrabacionesPuntuales\TcloudApiService` (`crearCanal`)
- **Migración**: 1 nueva (`add_ruta_base_to_grabador_usuario_table`)
- **Datos**: 4 canales a remediar (`Puntual_03, 06, 08, 09` del usuario `siglohallon`)
- **Rutas HTTP**: sin cambios
- **UI**: sin cambios visibles
