## Context

La gestión de usuarios en storages admin se divide en dos páginas: `storages.blade.php` (lista de storages) y `storage-users.blade.php` (gestión de usuarios de un storage). La selección de usuarios usa un `<select>` con `@foreach($allUsers)` mostrando emails. El User model tiene `username` y `email`. La app usa Alpine.js + Blade + TailwindCSS, sin build step.

## Goals / Non-Goals

**Goals:**
- Modal in-place en la lista de storages para gestionar usuarios sin navegar a página separada.
- Typeahead de búsqueda de usuarios por username con endpoint AJAX.
- Mostrar username como identificador principal, email como subtítulo.
- Mantener la página `storage-users.blade.php` funcional para compatibilidad con URLs directas.

**Non-Goals:**
- No cambiar la gestión de storages desde la vista de usuarios (`user-storages.blade.php`).
- No paginar la lista de usuarios asignados (por ahora son pocos por storage).
- No cambiar la lógica de permisos o el modelo de datos.

## Decisions

### 1. Modal inline vs página separada

**Decisión**: Añadir un modal completo en `storages.blade.php` que contenga la tabla de usuarios asignados + formulario de asignación con typeahead.

**Alternativa considerada**: Usar un iframe o cargar `storage-users.blade.php` dinámicamente. **Rechazada**: complejidad innecesaria, el contenido es pequeño y cabe en un modal.

### 2. Endpoint de búsqueda AJAX vs pre-carga

**Decisión**: Crear endpoint `GET /admin/users/search?q=...` que retorne hasta 20 resultados con `id`, `username`, `email`.

**Alternativa considerada**: Pre-cargar todos los usuarios en un array de JS y filtrar client-side. **Rechazada**: no escala con cientos de usuarios, y el dropdown actual ya es difícil de navegar con muchos usuarios.

### 3. Componente typeahead

**Decisión**: Implementar un typeahead inline con Alpine.js: input de texto + dropdown de resultados filtrados. Sin dependencias externas.

**Por qué**: La app no tiene build step ni usa librerías de componentes. Alpine.js tiene toda la reactividad necesaria para un typeahead simple (input → fetch → mostrar resultados → seleccionar).

### 4. Formato de visualización de usuario

**Decisión**: Mostrar `@username` como texto principal y `email` como texto secundario en gris.

```
┌──────────────────────────────────────┐
│ @juan_dev          juan@email.com    │
│ @maria_ops         maria@corp.co     │
└──────────────────────────────────────┘
```

**Por qué**: El username es más corto y reconocible. El email sigue visible para distinguir usernames similares.

### 5. Mantener storage-users.blade.php

**Decisión**: Mantener la página separada, pero también actualizarla para usar typeahead y mostrar username.

**Por qué**: Si algún admin tiene bookmarked la URL directa, no debe romperse. Además, la página separada puede ser útil para gestión bulk en el futuro.

## Risks / Trade-offs

- **[Riesgo] Duplicación de lógica** → El modal en storages.blade.php y la página storage-users.blade.php harán cosas similares. Mitigación: Extraer funciones comunes a un script compartido o usar los mismos endpoints AJAX.
- **[Riesgo] Typeahead sin debounce** → Si el usuario escribe rápido, muchas requests. Mitigación: Implementar debounce de 300ms.
- **[Trade-off] Modal vs página** → Un modal tiene espacio limitado. Con muchos usuarios asignados, la tabla puede ser larga. Mitigación: Scroll interno en el modal, max-height.
