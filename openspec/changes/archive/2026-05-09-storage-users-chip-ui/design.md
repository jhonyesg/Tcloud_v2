## Context

El modal de usuarios del storage (`showUsersModal`) existe en `storages.blade.php`. Usa Alpine.js para estado reactivo. El typeahead llama a `/admin/users/search?q=` y el bug es que el dropdown no aparece en el evento `@focus` porque `@click.away` en el input cierra el dropdown antes de que el usuario vea los resultados — además el evento `@focus` no tiene debounce y puede dispararse antes de que Alpine termine de montar el componente.

La UI actual muestra los usuarios asignados en una `<table>` con botones "Editar" y "Remover". Se reemplaza por chips.

## Goals / Non-Goals

**Goals:**
- Chips visuales para usuarios asignados con botón "×" para remover.
- Dropdown de búsqueda que muestra todos los usuarios al hacer click/focus en el input (sin escribir).
- Edición de permisos al hacer click en el chip (popover inline pequeño).
- Checkbox "Todas las personas" que asigna todos los usuarios del sistema al storage.

**Non-Goals:**
- Cambiar la API REST subyacente (endpoints existentes se mantienen).
- Paginación de resultados de búsqueda.
- Drag-and-drop para reordenar usuarios.

## Decisions

### Dropdown de búsqueda: reemplazar @click.away por @mousedown.prevent

**Problema**: `@click.away="userSearchOpen = false"` en el input cierra el dropdown antes de que el `@click` en el resultado se ejecute. El orden de eventos es: mousedown → blur/click.away → click. Para el momento que se ejecuta el click en el resultado, el dropdown ya está cerrado.

**Decisión**: Usar `@mousedown.prevent` en cada ítem del dropdown (en lugar de @click). `mousedown` se ejecuta antes de `blur`, así que el input no pierde el foco y el dropdown no se cierra antes de tiempo. Alternativamente, envolver el dropdown en un `@mousedown.prevent` en el contenedor.

**Para el @focus vacío**: Cambiar la lógica a `@click="if(!userSearchOpen) { searchUsers(''); userSearchOpen = true; }"` para abrir el dropdown al primer click en el input, sin depender de focus timing.

### Vista de chips para usuarios asignados

Los chips reemplazan la tabla. Estructura de cada chip:

```
┌─────────────────────────────────────────────────┐
│  MODAL - Usuarios del Storage: NombreStorage    │
├─────────────────────────────────────────────────┤
│                                                 │
│  Usuarios asignados:                            │
│  ┌──────────┐ ┌───────────┐ ┌──────────────┐   │
│  │@acr  ×  │ │@massmedio ×│ │@multiarchivo ×│  │
│  └──────────┘ └───────────┘ └──────────────┘   │
│  (click en chip → mini-form de permisos)        │
│                                                 │
│  ☐ Todas las personas                           │
│                                                 │
│  ─────────────────────────────────────────────  │
│  Agregar usuario:                               │
│  [ @username o email...  ▼ ]  [Permisos ▼]     │
│  [ Asignar ]                                    │
│                                                 │
└─────────────────────────────────────────────────┘
```

Cada chip muestra `@username` con badge de color por nivel de permisos (read=gris, write=azul, upload=amarillo, full=verde).

### Edición de permisos desde chip

Al hacer click en un chip (no en el ×), se abre un pequeño formulario inline debajo del área de chips (igual al `showEditAssignment` actual, solo se mueve visualmente).

### "Todas las personas" checkbox

- Al marcar: llama al endpoint existente `POST /admin/storages/{id}/users` por cada usuario del sistema que no esté ya asignado. Se hace en serie con un loop en JS, o se añade un nuevo endpoint `POST /admin/storages/{id}/users/bulk` que acepta un array de user_ids.
- Al desmarcar: elimina todos los usuarios del storage con un endpoint nuevo `DELETE /admin/storages/{id}/users/all` o llamadas individuales.
- **Decisión**: Añadir endpoint `POST /admin/storages/{id}/users/assign-all` en el controller para no saturar la red con N requests.

## Risks / Trade-offs

- **"Todas las personas" puede asignar muchos usuarios** → se muestra un confirm() antes de ejecutar.
- **Chips pueden overflow si hay muchos usuarios** → el contenedor usa `flex-wrap` con scroll vertical limitado.
- **El endpoint bulk es nuevo** → requiere ruta nueva en web.php y método en el controller.
