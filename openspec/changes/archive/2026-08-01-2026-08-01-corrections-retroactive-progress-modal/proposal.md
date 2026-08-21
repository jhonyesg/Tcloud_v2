# Change: botón "Ver" del banner de proceso retroactivo abre modal de progreso (no otro launch)

## Why

El admin reportó el 2026-08-01:

> *"lo único que no funciona y veo que se queda en el frontend cargando y uno le va a ver es la parte de replicar: tengo ahí un proceso que hice, proceso en curso, lo doy ver, pisar el módulo otra vez como para repetir el proceso pero no veo que haga mayor cosa."*

Investigación: el botón `Ver` del banner `runId && !runFinished` actualmente llama `openApply()`. Pero `openApply()` resetea el estado de run (`runId = null`, `runProgressPct = 0`, etc.) y abre el modal genérico de NUEVO launch (dropdown de scope + botón "Confirmar y aplicar"). El admin espera ver el progreso y termina frente a un modal de "querés lanzar otra corrida?".

Esto contradice la UX del banner que dice "Re-aplicar en curso · X%" — el `Ver` debe abrir un modal con detalle del progreso, no un form de launch.

Diagnóstico técnico (confirmado vía grep + lectura):
- Banner `<div x-show="runId && !runFinished">` (line ~71) — visible solo cuando hay run vivo.
- Button `Ver` (`@click="openApply();"`) — llama al mismo handler que usa el botón principal "Re-aplicar".
- `openApply()` resetea `runId`, `runProgress`, etc. y abre el modal con dropdown para nuevo scope.

## What Changes

### 1. Nuevo método `openApplyView()` (no toca `openApply`)

Diferencia clave con `openApply()`:
- **NO** resetea `runId` ni el estado de progreso.
- **NO** abre el dropdown de scope.
- **NO** muestra el botón "Confirmar y aplicar".
- Abre el modal `showApply` con una **vista alternativa**: solo barra de progreso + estados + botón "Refrescar estado".

### 2. Modal dual-mode en `index.blade.php`

El modal existente `<div x-show="showApply">` gana un bloque condicional:
- Si `runId && !applying && !runFinished` (caso "ver progreso de algo en curso que se re-adjuntó") → vista de progreso: barra, contadores, stuck-warning, botón "Refrescar ahora" (lánza un poll inmediato sin esperar el intervalo), botón "Cerrar".
- Si no hay run vivo (`!runId`) → vista de launch actual (dropdown + "Confirmar y aplicar").

### 3. Botón `Ver` actualizado

`@click="openApplyView();"` en lugar de `openApply()`.

### 4. Re-attach robusto después de reload

Cuando el admin recarga la página mientras un run está vivo:
- `init()` llama `attachToActiveRun()` (ya existe) que setea `runId` y arranca polling.
- El banner aparece con la barra.
- Si el admin hace `Ver`, ahora abre la vista de progreso con la info en vivo.

## Non-goals

- **No eliminamos `openApply()`**: sigue siendo el modal de nuevo launch desde el botón "Re-aplicar" del header.
- **No agregamos paginación/historial de runs viejos**: el banner solo cubre el run actual; los runs viejos ya viven en `correction_apply.active` con TTL 4h.
- **No cancelamos un run desde la UI**: sigue siendo kill al proceso desde ssh (característica posible futura, fuera de alcance).

## Impact

- **Specs affected**: ninguno nuevo; refuerza el MODIFIED Requirement "Comando retroactivo reaplica el diccionario..." con texto UX.
- **Code affected (modificado)**:
  - `app/resources/views/ia/correcciones/index.blade.php`
- **Migrations**: ninguna.
- **Riesgos**: bajo — solo frontend; sin cambios backend.

## Open questions (resueltas)

- **¿Modal dual o modal separado?** Una sola entrada `showApply` con render condicional. Más simple y respeta el patrón existente.
- **¿Botón "Refrescar ahora" dispara una poll inmediata?** Sí — reutiliza `pollRun()` que ya existe.
- **¿Mostrar logs de artisan?** No; eso es un follow-up si hace falta.
