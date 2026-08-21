# Tasks: botón Ver del banner abre vista de progreso (no otro launch)

## 1. UI: nuevo método `openApplyView()`

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Agregar `openApplyView()`: setea `showApply = true` SIN resetear el estado de run.

## 2. UI: cambio en el botón `Ver` del banner

- [ ] Cambiar `@click="openApply();"` por `@click="openApplyView();"` (line ~88).

## 3. UI: modal dual-mode

- [ ] En el modal `x-show="showApply"`, agregar un bloque condicional:
  - Vista launch existente cuando `!runId`.
  - Vista progreso cuando `runId && !runFinished`:
    - Texto "Progreso en vivo".
    - Status text (`runStatusText`).
    - Barra de progreso (`runProgressPct`) + texto (`runProgress`).
    - Stuck warning si `runStuck`.
    - Botón "Refrescar estado" → `await this.pollRun()` (poll inmediata).
    - Botón "Cerrar" → `showApply = false`.
    - Texto pequeño: "Esta corriendo en background; podés cerrar este modal y seguir navegando — la barra del header sigue activa."
- [ ] La vista launch (selector de scope + button confirmar) **NO debe mostrarse** si hay `runId`.

## 4. Verificación

- [ ] Recargar /ia/correcciones con un run en curso.
- [ ] Banner aparece con % + barra.
- [ ] Click "Ver" → modal se abre mostrando la barra, NO muestra dropdown de scope.
- [ ] Click "Refrescar estado" → el campo `last_progress_at` se actualiza al instante (verificable en Redis si hay run vivo).
- [ ] Modal se cierra con "Cerrar" sin alterar el estado del run.
- [ ] "Re-aplicar" del header sigue abriendo el modal de launch (no se rompió `openApply`).

## 5. Archivar

- [ ] Mover a `archive/2026-08-01-2026-08-01-corrections-retroactive-progress-modal/`.
