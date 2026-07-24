## Context

El módulo **Grabaciones Puntuales** gestiona canales de grabación remotos (`Canal` ↔ `Grabador`). El admin configura una **ruta base** al asignar un usuario al grabador (`GrabadorController::asignarUsuario`), y esa ruta se concatena con el `slot_nombre` para poblar `canales.ruta_destino` (BD local) y `ruta_descarga` (remoto).

Hoy la "ruta base" del par `usuario × grabador` **no está persistida** como atributo. Solo se materializa al asignar/re-asignar, escribiéndose en cada `canal.ruta_destino`. Si un admin hace clic en **Limpiar** sobre un canal, `CanalController::destroy` pone `ruta_destino = NULL`. Al re-agregar `link_origen`, `TcloudApiService::crearCanal` recibe `ruta_destino = NULL` y cae al fallback hardcodeado `generarRutaDescarga()` (`/www/.../Disco_I/<tipo>/<slug>`). El remoto termina grabando en una ruta distinta a la configurada por el admin.

```
   ┌──────────┐   ruta_base (en form)   ┌────────────────────┐
   │ Admin    │ ──────────────────────▶ │ GrabadorController │
   └──────────┘                          │   .asignarUsuario │
                                         └─────────┬──────────┘
                                                   │ escribe en cada canal
                                                   ▼
                                         ┌────────────────────┐
                                         │ canales            │
                                         │  ruta_destino =    │
                                         │  base + slot       │
                                         └────────────────────┘
```

## Goals / Non-Goals

**Goals:**

1. Persistir `ruta_base` en `grabador_usuario(ruta_base)` como **fuente de verdad** del par `usuario × grabador`.
2. **Limpiar** deja de tocar `ruta_destino` (solo resetea el registro remoto: `api_canal_id`, `link_origen`, `detalle`).
3. Re-crear un canal (limpiar → editar → guardar con `link_origen`) **siempre** envía al remoto la ruta que el admin configuró.
4. Defensa en profundidad: el servicio que arma el payload del remoto también resuelve la ruta si el controller no lo hizo.
5. Saneamiento de los 4 canales hoy rotos (`Puntual_03, 06, 08, 09`).

**Non-Goals:**

- Cambiar la API HTTP del grabador remoto.
- Rediseñar el modelo `Canal` o introducir `UserGrabadorSetting`.
- Cambios visibles de UI (el input `ruta_base` del modal de asignación ya existe).
- Migrar `ruta_destino` histórico más allá del script de remediación.

## Decisions

### D1 — Persistir `ruta_base` en `grabador_usuario` (no nueva tabla)

**Por qué:** la pivote ya existe, ya tiene `limite_canales` que es otro setting del mismo par. Agregar una columna es más liviano que una tabla nueva y mantiene la locality (un JOIN en lugar de un extra lookup).

**Alternativa descartada:** crear tabla `user_grabador_settings(id, user_id, grabador_id, ruta_base, ...)` para futuro extensibilidad. Hoy `limite_canales` ya vive en la pivote, así que añadir `ruta_base` ahí es el patrón consistente. Si en el futuro se necesitan más settings, se migra.

### D2 — Backfill al ejecutar la migración

La migración hace `UPDATE grabador_usuario SET ruta_base = <derivada de canales.ruta_destino>` para los pares que ya tienen canales. Reglas:

- Si hay al menos un canal del par con `ruta_destino` no nulo: tomar el primero y derivar `ruta_base = ruta_destino con el último segmento (slot_nombre) removido`.
- Si no hay canales con `ruta_destino`: dejar `NULL`. La remediación posterior los actualizará.

**Por qué in-line en la migración:** es idempotente, corre una sola vez al deploy, no requiere script aparte antes del cambio de código.

### D3 — `CanalController::destroy` ya no limpia `ruta_destino`

**Por qué:** "Limpiar" significa resetear el registro en la API (`api_canal_id`, `link_origen`, `detalle`). La ruta de guardado es independiente de la configuración del stream. Mantenerla preservada garantiza que el próximo re-add usa la misma ruta.

**Riesgo aceptado:** si el admin quiere un reset total incluyendo ruta, debe hacerlo borrando y reasignando el usuario. La UI actual no expone un "reset total" así que no hay regresión de UX.

### D4 — Resolución de `ruta_destino` en 3 capas (defense in depth)

```
   Capa 1 — CanalController::update / store  (controller)
            "si está vacío, derivo del pivote y persisto"
   Capa 2 — TcloudApiService::crearCanal     (servicio)
            "si llega vacío, derivo del pivote antes que del fallback"
   Capa 3 — generarRutaDescarga()            (último recurso)
            (sigue existiendo pero ya no debería dispararse)
```

**Por qué 3 capas:** el controller puede fallar en persistir el `save()` y aún así llegar al servicio; el servicio es el último punto antes del HTTP. Cubrir ambos puntos es barato y elimina la categoría entera de bugs "ruta vacía enviada al remoto". `generarRutaDescarga()` se conserva solo como último recurso defensivo.

**Alternativa descartada:** solo arreglarlo en el controller (capa 1). Más simple pero deja un punto único de falla. Dado el historial del bug, vale la redundancia.

### D5 — Helper estático vs método de modelo

Decisión: lógica de derivación dentro de los métodos que la usan (controller y servicio) usando consultas directas a `DB::table('grabador_usuario')`. No crear un método en `Canal` o `Grabador` todavía.

**Por qué:** son 4 líneas de lectura + concatenación en cada lugar. Un helper de modelo agregaría indirección sin valor hasta que se repita en más sitios. YAGNI.

**Cuándo reconsiderar:** si una 5ta llamada necesita la misma lógica. Ahí sí factor.

### D6 — Script de remediación como tarea aparte, no parte de la migración

**Por qué:** la remediación hace `PUT /canales/{id}` al remoto, lo cual es un side-effect externo fuera del dominio de una migración (que debe ser transaccional y reversible). Vive como `php artisan one-shot` ejecutable bajo demanda, después del deploy del código.

## Risks / Trade-offs

- **Riesgo**: si la columna `ruta_base` se queda `NULL` (par sin canales previos), un nuevo canal creado vía `store` no tendrá ruta → caerá a `generarRutaDescarga`. → **Mitigación**: el spec de la capability exige que el helper NUNCA devuelva vacío; si el pivote no tiene `ruta_base`, lanzar o loggear explícitamente (no caer silenciosamente al fallback en el controller). El servicio mantiene el fallback como red de seguridad.

- **Riesgo**: backfill puede sobreescribir un `ruta_base` intencionadamente NULL con un valor derivado. → **Mitigación**: el backfill solo escribe donde `ruta_base IS NULL`. Si un admin ya puso un valor intencional, no se toca.

- **Riesgo**: orden de deploy. Si se ejecuta la remediación antes que el código que la soporta, el `PUT` al remoto sobrescribe `ruta_descarga` con la local (que para los 4 canales es NULL). → **Mitigación**: la tarea de remediación corre **después** del deploy del código, en orden explícito de `tasks.md`. Incluye paso "asignar local primero, luego PUT remoto" dentro del mismo flujo.

- **Riesgo**: cambios en blanco entre deploy de código y el momento en que el admin re-edita canales existentes. Los canales ya api_canal_id-set no se reenvían al remoto hasta el próximo edit. → **Mitigación**: el script de remediación también hace `PUT` explícito a los 4 canales afectados.

- **Trade-off**: 3 capas de resolución agregan ~30 líneas de código duplicadas (controller y servicio). El costo es bajo vs el costo histórico de un bug recurrente cada vez que se limpia un canal.

## Migration Plan

1. **Pre-deploy**: nada (la migración es aditiva, no rompe).
2. **Deploy**: correr `php artisan migrate`. La migración crea `grabador_usuario.ruta_base` y hace backfill para pares con canales existentes.
3. **Post-deploy**:
   - Ejecutar script de remediación (`php artisan tinker --execute=...` o comando ad-hoc) para los 4 canales rotos: setea `local.ruta_destino` y hace `PUT /canales/{api_id}` con `ruta_descarga` correcta.
4. **Rollback**: `php artisan migrate:rollback` elimina la columna. Los datos derivados se pierden pero la app sigue funcionando (cae al fallback `generarRutaDescarga`, estado pre-fix).

## Open Questions

- ¿La remediación debe ejecutarse en orden topológico `Puntual_03, 06, 08, 09` por `id` o todos en paralelo? (propuesta: secuencial, en el orden que aparecen, para no saturar al grabador).
- Para `Puntual_06` el remoto ya tiene `.../puntual_06` (minúscula). ¿El `PUT` de remediación debe respetar esa minúscula (no tocar) o normalizar a `Puntual_06` mayúscula (consistencia con el patrón)? **Decisión por defecto propuesta**: respetar minúscula (no introduce cambios fuera del scope del fix). Confirmar.
