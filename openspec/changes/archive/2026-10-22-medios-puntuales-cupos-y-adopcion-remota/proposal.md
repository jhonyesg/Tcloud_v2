## Why

El límite de canales editado en Grabadores **no se aplica**. `CanalController::store()` (línea 94) obtiene un modelo `Grabador` y lee `$acceso->limite_canales`; `grabadores` no tiene esa columna, Eloquent devuelve `null` y `4 >= null` evalúa **siempre true**. Medido: par `(grabador 1, user 11)` con `limite_canales=5` y 4 canales → la UI muestra "1 de 5" pero el `POST` rebota. Ningún no-admin puede crear canales, con cualquier límite. El rechazo es invisible: ninguna vista del módulo renderiza `session('error')` y el alta es POST clásico, fuera del `mostrarToast` de AJAX.

Segundo problema: `Solicitudes_05` (`api_canal_id=202`), creado a mano en el grabador, no existe en `canales`: es invisible en Medios Puntuales, no consume cupo y rompe la equivalencia admin↔equipo. `sincronizar()` solo rellena `api_canal_id` de filas locales; nunca importa huérfanos. Tercero: `asignarUsuario` hardcodea `limite_canales => 10` (línea 188) y `actualizarAsignacion` borra canales al bajar el límite sin confirmar (líneas 241-250).

## What Changes

- **`CupoCanalesService`** (`limite`, `uso`, `resumen`, `puedeCrear`): único lector de `grabador_usuario`; lo consumen `create`, `store` y `actualizarAsignacion`.
- **Guard atómico** en `store()`: `uso >= limite` dentro de transacción con `lockForUpdate` del pivote.
- **Feedback visible**: partial `partials/flash-toast.blade.php` en las 5 vistas del módulo + `old()` en el formulario.
- **`asignarUsuario` honra el límite pedido** (default 10) en pivote y slots.
- **Bajar el límite no destruye**: bloquea creación futura y reporta excedentes; la poda exige `prune=true` explícito y aborta sin borrar local si falla el `DELETE` remoto.
- **`AdopcionRemotaService`**: clasifica remotos sin fila local (`adoptable` / `sugerido` / `fuera_de_alcance`). "Sincronizar" detecta y propone; adoptar = solo `INSERT` espejo, nunca escritura al grabador, y **no se bloquea por cupo** (el canal ya graba; ocultarlo no lo detiene).
- **Panel de cupos** en Grabadores (`usados/límite`, detectados sin registrar) y comando `canales:detectar-remotos`.
- **`withPivot('ruta_base')`** en `User::grabadores()` / `Grabador::usuarios()`; se elimina la tarjeta "Medios Puntuales" duplicada en `dashboard/user.blade.php:74-98`.

## Capabilities

### New Capabilities

- `medios-puntuales-adopcion-remota`: detección, clasificación e integración no destructiva de canales que existen en el grabador y no en `canales`.

### Modified Capabilities

- `grabaciones-puntuales`: cupo por par aplicado correctamente, baja de límite no destructiva, asignación que respeta el límite pedido y rechazo visible.

## Impact

**Backend**: `CanalController` (`create`, `store`, `sincronizar`), `GrabadorController` (`asignarUsuario`, `actualizarAsignacion`), `CupoCanalesService` y `AdopcionRemotaService` (nuevos), `CanalesDetectarRemotosCommand` (nuevo), `User`, `Grabador`.

**Frontend**: `partials/flash-toast.blade.php` (nuevo), `canales/{index,create,edit}`, `grabadores/index`, `dashboard/user`.

**Datos**: sin migración. `Solicitudes_05` se materializa vía adopción.

**Non-goals**: no cambia el contrato HTTP del grabador ni el esquema de `canales`/`grabador_usuario`; no se adopta nada fuera de la regla estricta; no se reescribe el módulo como SPA. **Rollback**: `git revert`; la adopción solo INSERTa filas con `api_canal_id`, que quedan válidas.
