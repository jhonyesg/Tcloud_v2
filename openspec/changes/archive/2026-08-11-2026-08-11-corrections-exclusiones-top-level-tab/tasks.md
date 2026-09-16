# Tasks: Exclusiones como pestaña top-level en /ia/correcciones

## 1. Reordenar barra de tabs

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`, en el bloque de tabs (líneas 180–202):
  - Agregar un nuevo `<button>` para `tab === 'exclusiones'` entre el botón "Aprobadas" y "Contexto sensible".
  - Usar `fa-ban` como icono (en lugar de `fa-shield-halved`).
  - Usar color `bg-purple-600` cuando activo y `bg-slate-100 text-slate-600` cuando inactivo (paleta consistente con IA Suggest).
  - Incluir badge morado `bg-purple-500` con `exclusionesActiveFiltered.length` que se muestra solo si `> 0`.

## 2. Promover el panel Exclusiones a top-level

- [ ] En el mismo archivo, mover el bloque `<!-- Panel Exclusiones -->` (líneas 1103–1228) a una posición lógica: justo después del cierre del panel IA Suggest y antes del modal "Agregar exclusión".
- [ ] Cambiar `x-show="tab === 'ai-settings'"` por `x-show="tab === 'exclusiones'"` en el `<div>` externo del panel (línea 1104).
- [ ] Cambiar el icono del header interno del panel: `fa-shield-halved text-purple-600` → `fa-ban text-purple-600` (línea 1108).
- [ ] El modal "Agregar exclusión" (líneas 1181–1228) **no se mueve** y conserva `x-show="showExcluirModal"` — ya funciona fuera de cualquier tab.
- [ ] Los modales shortcut de exclusión (líneas 1230+) **no se mueven**.

## 3. Separar la carga en `switchTab()`

- [ ] En el Alpine state `correccionesAdmin()` (líneas 1886–1896):
  - Reemplazar:
    ```js
    if (name === 'ai-settings' && Object.keys(this.aiSettings.list).length === 0) {
        await this.loadAiSettings();
    }
    if (name === 'ai-settings' && this.exclusiones.length === 0) {
        await this.loadExclusiones();
    }
    ```
  - Por:
    ```js
    if (name === 'ai-settings' && Object.keys(this.aiSettings.list).length === 0) {
        await this.loadAiSettings();
    }
    if (name === 'exclusiones' && this.exclusiones.length === 0) {
        await this.loadExclusiones();
    }
    ```

## 4. Asegurar computación de activas

- [ ] Verificar que `exclusionesActiveFiltered` ya existe como `getter` Alpine (búsqueda: `get exclusionesActiveFiltered`). Si no existe, agregarlo:
  ```js
  get exclusionesActiveFiltered() {
      return this.exclusiones.filter(e => !e.archived_at);
  }
  ```
- [ ] El badge del tab debe usar `exclusionesActiveFiltered.length` y mostrarse solo cuando `> 0` (vía `x-show`).

## 5. Spec delta — MODIFIED en transcription-corrections

- [ ] En `openspec/specs/transcription-corrections/spec.md`:
  - Renombrar `## ADDED Requirements` → `## MODIFIED Requirements` en la sección que contiene el requisito "Admin puede gestionar exclusiones dinámicas desde UI" (línea 397).
  - Cambiar el `SHALL exponer` (línea 400): `/ia/correcciones → IA Suggest → Exclusiones` → `/ia/correcciones → Exclusiones`.
  - Actualizar el Scenario "Admin agrega 'Black Friday' desde UI" (línea 403): reemplazar `/ia/correcciones → IA Suggest → Exclusiones → Agregar exclusión` por `/ia/correcciones → Exclusiones → Agregar exclusión`.
  - Scenario "Alta manual desde subpanel Exclusiones NO archiva" (línea 481): reemplazar `IA Suggest → Exclusiones → "Agregar exclusión"` por `Exclusiones → "Agregar exclusión"`.
  - Renombrar `## ADDED Requirements` → `## MODIFIED Requirements` en la sección que contiene el requisito "Atajo 'Excluir' archiva la corrección asociada en la misma operación" (línea 458).
  - Ajustar cualquier otra referencia a "subpanel Exclusiones" o ruta `IA Suggest → Exclusiones` en escenarios posteriores.

## 6. Verificación

- [ ] `php -l app/resources/views/ia/correcciones/index.blade.php` (PHP solo aplica a directivas Blade, la mayor parte es HTML/Alpine).
- [ ] Smoke UI manual:
  - Cargar `/ia/correcciones`. Verificar orden de tabs: Pendientes | Aprobadas | Exclusiones | Contexto sensible | Revisar transcripciones | IA Suggest | AI Suggest Results.
  - Click en "Exclusiones": debe mostrar el panel CRUD completo sin pasar por IA Suggest.
  - Click en "IA Suggest": debe mostrar SOLO el form de configuración (sin sección Exclusiones al final).
  - El badge de "Exclusiones" debe mostrar el número correcto de activas.
  - Los botones "Excluir" de filas en Pendientes y Aprobadas, y el bulk "Excluir N", deben seguir abriendo los mismos modales.
  - El modal "Agregar exclusión" debe seguir funcionando desde el nuevo tab.
- [ ] Verificar que no hay regresiones en modales shortcut: agregar una exclusión desde Pendientes, archivar, restaurar, agregar manualmente.

## 7. Archivar

- [ ] Cuando todo esté validado en producción, mover el change a `openspec/changes/archive/2026-08-11-corrections-exclusiones-top-level-tab/` conservando `proposal.md`, `tasks.md` y `specs/transcription-corrections/spec.md` actualizado.