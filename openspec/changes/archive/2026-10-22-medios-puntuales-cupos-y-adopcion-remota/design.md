## Context

El módulo Medios Puntuales tiene **tres escritores** del límite y **tres lectores** distintos del mismo dato, y ninguno coincide.

```
                    grabador_usuario.limite_canales
                    (fuente de verdad, fila id=14 → 5)
                                  │
        ┌─────────────────────────┼──────────────────────────┐
        │                         │                          │
   create()                  store()                  asignarUsuario()
   pivot->limite_canales ✅  $acceso->limite_canales ❌   hardcode 10 ❌
   "1 de 5" en pantalla      4 >= NULL → siempre true     inserta 10
                             (bloquea a TODOS)
```

`$acceso` es un `App\Models\Grabador`. La tabla `grabadores` no tiene `limite_canales`, así que el atributo es `null`. PHP compara `int >= null` como `""` → `true`. El resultado es un deny-by-default universal que no deja rastro: sin excepción, sin log, sin flash.

Medición en vivo (app bootstrapeada contra la BD real):

```
grabador 1: creados=4 limite_ok=5 | store() limite=NULL => bloquea=true | create() disponibles=1
grabador 3: creados=10 limite_ok=10 | store() limite=NULL => bloquea=true | create() disponibles=0
```

El segundo eje del problema es de **identidad**. `canales` es la vista local de lo que existe en el grabador, pero nada garantiza la biyección:

```
  GRABADOR (verdad operativa)              canales (vista local)
  ┌────────────────────────────┐          ┌──────────────────────────┐
  │ 129 canales en 2 equipos   │          │ 24 filas                 │
  │  (ids 1..202, compartidos) │          │  grabador 1 → 14         │
  └────────────┬───────────────┘          │  grabador 3 → 10         │
               │                          └────────────┬─────────────┘
               │   sincronizar() solo LEE→ESCRIBE api_canal_id
               │   nunca INSERTA
               ▼                                       │
        ┌──────────────────────────────────────────────┴───────┐
        │  id=202 Solicitudes_05  ←→  (no existe fila)         │
        │  id=173 solicitudes_01  ←→  id=92  Solicitudes_01   │
        └──────────────────────────────────────────────────────┘
```

Además los identificadores no son comparables en crudo: el remoto usa `codigo` minúscula (`solicitudes_01`) y `nombre` capitalizado (`Solicitudes_01`); el local guarda `slot_nombre='Solicitudes_01'`. Cualquier matching necesita normalización (`trim().lower()`).

## Goals / Non-Goals

**Goals:**

1. Un solo lugar decide el cupo y todos los call sites lo consumen.
2. El rechazo por cupo es visible y accionable (no un `back()` mudo).
3. El límite se puede **bajar** sin destruir datos; la poda es una acción explícita.
4. `limite_canales` enviado al asignar se respeta (ya no se hardcodea 10).
5. Los canales creados a mano en el grabador se **detectan** y se pueden **integrar** sin tocar el remoto ni los datos existentes.
6. Nada de lo que hoy funciona cambia de forma: sin migraciones, sin cambios de esquema, sin reescritura de UI.

**Non-Goals:**

- No se adopta automáticamente nada que no cumpla la regla estricta (ver Decisión 3).
- No se toca el contrato HTTP del grabador (`POST/PUT/DELETE /api/canales`).
- No se introducen colas ni jobs: el volumen es de decenas de filas.
- No se unifica el alta de usuario entre el modal AJAX y la vista `show.blade.php` más allá de corregir el pivote.

## Decisions

### 1. `CupoCanalesService` como único lector del límite

```php
final class CupoCanalesService
{
    public function resumen(int $grabadorId, int $userId): ?array;   // null si no hay asignación
    // ['limite' => int, 'usados' => int, 'disponibles' => int, 'ruta_base' => ?string]
    public function limite(int $grabadorId, int $userId): ?int;
    public function uso(int $grabadorId, int $userId): int;          // count(canales)
    public function puedeCrear(int $grabadorId, int $userId, bool $isAdmin): bool;
}
```

**Por qué**: el bug nació justamente de tener la misma regla copiada en tres sitios con tres lecturas distintas. Un servicio con una sola query (`grabador_usuario` + `count(canales)`) hace imposible repetirlo. `create()`, `store()` y `actualizarAsignacion()` pasan a depender de él.

**Alternativa descartada**: arreglar línea 103 con `$acceso->pivot->limite_canales ?? 0`. Es un parche de una línea que deja los otros dos lectores divergentes y vuelve a romperse con el siguiente cambio.

### 2. Guard atómico y semántica de "bajar el límite"

`store()` ejecuta el chequeo dentro de una transacción con `lockForUpdate()` sobre la fila `grabador_usuario`:

```
BEGIN
  SELECT ... FROM grabador_usuario WHERE (grabador_id,user_id) FOR UPDATE
  usados = count(canales del par)
  if !admin && usados >= limite → rollback + flash error
  INSERT canal
COMMIT
```

**Bajar el límite ya no borra nada.** `actualizarAsignacion` escribe el nuevo valor y, si `usados > nuevoLimite`, responde `warning` con la lista de canales excedentes. La poda requiere `prune=true` explícito desde el modal, con confirmación que enumera los `slot_nombre` a eliminar. Si el `DELETE` remoto de cualquier canal falla, la transacción **aborta entera** y no se borra ninguna fila local (hoy se borra la local aunque el remoto siga vivo → drift silencioso).

**Por qué**: el usuario reportó el caso inverso (subir 4→5), pero la misma función es la que **baja** límites y hoy destruye canales sin preguntar. Un cambio de número en un input no debe ser una operación destructiva.

### 3. Adopción remota: regla estricta, resto es sugerencia

Se midieron tres candidatos de regla contra los 129 remotos reales:

| Regla | Criterio | Candidatos | Veredicto |
|---|---|---|---|
| A — solo `ruta_base` | `dirname(ruta_descarga) == ruta_base` | 12 | **inaceptable**: arrastra 11 canales TV ajenos (`Telemedellin`, `Trece`, `CaracolInternacional`…) |
| B — `ruta_base` + familia numérica | A + `prefijo` compartido con los slots locales (`Solicitudes_`) | **1** (`Solicitudes_05`) | **auto-adoptable** |
| C — cualquier subruta de `ruta_base` | `LIKE 'base/%'` | 46 | **solo sugerencia**, requiere selección manual |

El flujo de `sincronizar()` pasa a devolver tres cubos:

```json
{
  "vinculados": 15, "ya_ok": 5, "sin_match": 9,
  "adoptables":   [{"api_canal_id":202,"codigo":"Solicitudes_05","grabador_id":1,"user_id":11,"slot_nombre":"Solicitudes_05"}],
  "sugeridos":    [{"api_canal_id":91,"codigo":"BluDigital", ...}],
  "fuera_de_alcance": 114
}
```

**Adopción = INSERT local espejando el remoto.** Nunca se hace `PUT` al grabador. Campos:

| Campo local | Origen |
|---|---|
| `grabador_id` | del par cuyo `ruta_base` contiene la ruta remota |
| `usuario_id` | `user_id` del par |
| `slot_nombre` | `remoto.nombre` (capitalización estable, respeta la UNIQUE) |
| `api_canal_id` | `remoto.id` |
| `link_origen`, `detalle`, `ruta_destino` | del remoto |
| `duracion_grabacion`, `formato_salida`, `ffmpeg_args_*` | remoto; si viene `null` → default por `grabadores.tipo` (caso real: `202` trae `formato_salida = null`) |
| `activo` | `remoto.activo` |

**Idempotencia**: `(grabador_id, slot_nombre)` y `(grabador_id, api_canal_id)` ya son UNIQUE, así que un segundo intento choca y se reporta como `ya_ok`.

### 4. El cupo cuenta filas locales, y la adopción lo incrementa

`uso = count(canales WHERE grabador_id, usuario_id)`. Tras adoptar `Solicitudes_05`, el par `(1, 11)` queda en `5/5` — coherente con la realidad del grabador. No se consulta el remoto en cada request (latencia de red en el path crítico); la paridad se mantiene porque el botón de sincronización ahora **también reporta** los detectados.

### 5. Feedback: partial de flash compartido

`resources/views/partials/flash-toast.blade.php` renderiza `session('success'|'warning'|'error')` como toast transitorio con auto-dismiss, reutilizando los tokens visuales que el módulo ya usa. Se incluye en `canales/index|create|edit` y `grabadores/index`. El formulario de creación conserva `old('slot_nombre')` y `old('grabador_id')`.

**Por qué no reusar el toast de AJAX**: ese `mostrarToast` vive dentro del `<script>` de `canales/index.blade.php` y no existe en `create`/`edit` (otro archivo, sin el markup). El partial es la forma barata de cubrir los tres flujos sin duplicar JS.

### 6. `withPivot('ruta_base')`

`getRutaBase()` en `grabadores/index.blade.php:239` deriva la ruta del primer canal del usuario. Si el usuario no tiene canales (caso `user 11 / grabador 1` antes de crear), el campo aparece vacío y guardar sobrescribe la ruta real con `""`. Añadir `ruta_base` al `withPivot` permite leerla del pivote, que es donde el cambio `fix-ruta-destino-persistente-en-limpiar` la puso como fuente de verdad.

## Risks / Trade-offs

- **R1 — Adopción con regla B podría capturar un canal ajeno con el mismo prefijo.** Mitigación: la regla exige además que el canal esté como **hijo directo** del `ruta_base` del par y que el prefijo coincida con un slot local existente. Los 11 falsos positivos de la regla A quedan en `sugeridos`, que requiere selección manual. El comando CLI es dry-run por defecto y el servicio escribe log `canales.adopcion` con el diff.
- **R2 — Bajar el límite sin podar deja `usados > limite`.** Es intencional: el estado es visible en el panel (`5/4` en rojo) y bloquea creación, pero no destruye. Nunca se pierde un canal por un cambio de número.
- **R3 — `lockForUpdate` sobre `grabador_usuario` serializa creación por par.** Es exactamente el alcance deseado (un usuario no se auto-sabotea con doble clic); no afecta a otros pares. La transacción es corta (una fila pivot + count + insert).
- **R4 — Adopción masiva desde "sugeridos" puede importar canales que el admin no quiere en la plataforma.** Mitigación: el modal exige selección explícita casilla por casilla y muestra `codigo`, `id` remoto y ruta; no hay "seleccionar todo".
- **R5 — El remoto puede cambiar entre la detección y la adopción.** Mitigación: la adopción re-verifica el `api_canal_id` por `GET /canales/{id}` antes del INSERT y aborta ese ítem con `warning` si desapareció.
- **R6 — `formato_salida` remoto `null` (caso `202`).** El default se deriva de `grabadores.tipo`; se registra en el log del comando para que el operador pueda corregirlo desde la UI de edición si el archivo no se está generando con la extensión esperada.
