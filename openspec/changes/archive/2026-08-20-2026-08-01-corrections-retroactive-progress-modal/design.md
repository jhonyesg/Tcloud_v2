# Design: botón "Ver" del banner abre modal de progreso (no otro launch)

## Context

El banner de "Re-aplicar en curso" (`runId && !runFinished`) expone un botón `Ver` que hoy llama a `openApply()`. Ese handler resetea el estado del run (`runId = null`, `runProgressPct = 0`, etc.) y abre el modal genérico de NUEVO launch (dropdown de scope + "Confirmar y aplicar"). El admin que quiere *ver* el progreso termina frente a un form de lanzar otra corrida — contradice la UX del banner.

El estado del run vive en Alpine dentro de `index.blade.php` y se alimenta por polling a `/ia/correcciones/apply-retroactive/{runId}` (intervalo 2s). El backend ya protege contra duplicados (409 anti-duplicados), así que el frontend no necesita re-implementar esa lógica.

## Goals / Non-Goals

**Goals:**
- Que el botón `Ver` del banner abra un modal de **detalle de progreso** del run vivo, sin resetear su estado.
- Reutilizar el modal existente (`showApply`) con render condicional (dual-mode), respetando el patrón actual.
- Exponer un "Refrescar estado" que dispare un poll inmediato reutilizando `pollRun()`.
- Mantener intacto el flujo de nuevo launch desde el botón "Re-aplicar" del header (`openApply`).

**Non-Goals:**
- No eliminar `openApply()` ni el modal de launch.
- No agregar historial/paginación de runs viejos (viven en `correction_apply.active` con TTL 4h).
- No implementar cancelación de run desde la UI (sigue siendo kill por ssh).
- No mostrar logs de artisan en el modal.

## Decisions

### D1. Un solo modal `showApply` con render condicional (no un modal separado)

Se mantiene la entrada `showApply` y se condiciona el contenido:
- `runId && !runFinished` → vista de progreso.
- `!runId` → vista de launch existente.

**Por qué:** respeta el patrón existente, evita duplicar markup de overlay/backdrop, y el estado `showApply` ya controla apertura/cierre. Alternativa descartada: un segundo modal dedicado — más código, dos overlays que pueden desincronizarse.

### D2. Nuevo método `openApplyView()` que NO toca el estado de run

`openApplyView()` solo hace `showApply = true`. A diferencia de `openApply()`, **no** resetea `runId`, `runProgressPct`, `runProgress`, `runStatusText`, `runStuck`, ni detiene el polling.

**Por qué:** el polling del banner debe seguir vivo mientras el modal está abierto, para que "Refrescar estado" y el cierre no rompan la barra del header. Alternativa descartada: reutilizar `openApply()` con un flag — arriesga resetear estado por un camino compartido.

### D3. "Refrescar estado" reutiliza `pollRun()`

El botón llama `await this.pollRun()` directamente. `pollRun()` ya hace el fetch a `/apply-retroactive/{runId}` y actualiza el estado Alpine; no hace falta un endpoint nuevo.

**Por qué:** cero backend nuevo, cero duplicación de lógica de fetch. El poll inmediato es solo una invocación manual del mismo método que corre en el intervalo.

### D4. Cierre del modal no altera el run

`Cerrar` hace `showApply = false` únicamente. El polling del banner sigue corriendo (no se detiene al cerrar). Cuando el poll detecta `status='done'`, el polling se detiene, el banner se oculta y el botón "Refrescar" desaparece del modal (condición `!runFinished`).

**Por qué:** el run es un proceso de background independiente del modal; el modal es solo una ventana de observación.

## Risks / Trade-offs

- **Riesgo bajo (solo frontend).** Sin cambios backend, sin migraciones.
- **Condición de carrera en el render:** si el run termina mientras el modal está abierto, la vista de progreso pasa a la vista de launch (porque `runFinished` se vuelve true). Mitigación: la spec exige que el botón "Refrescar" desaparezca y el modal pueda quedar con su último estado; el banner se oculta. Aceptable y coherente con la spec.
- **Doble fuente de verdad del estado de run:** el modal y el banner leen el mismo estado Alpine, así que no hay divergencia posible — es el mismo objeto.
- **Trade-off de dual-mode:** el modal mezcla dos propósitos (launch vs. observación). Se acepta por simplicidad y porque el estado `runId` desambigua sin ambigüedad.
