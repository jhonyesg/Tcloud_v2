## 1. Backend — soporte para parámetro `q` en FileController

- [x] 1.1 En `FileController::index()`, detectar si llega el parámetro `q` con al menos 2 caracteres
- [x] 1.2 Cuando `q` está presente, saltar el camino de `syncFolder()` y construir un query DB directo filtrado por `name LIKE %q%` (case-insensitive con `ilike` en PostgreSQL) y `storage_id`
- [x] 1.3 Cuando `q` está presente, omitir el filtro de `parent_id` / `whereNull('parent_id')` para buscar en todo el storage
- [x] 1.4 Mantener las restricciones de permisos de usuario (solo storages asignados) también en el modo búsqueda
- [x] 1.5 Ordenar resultados de búsqueda: carpetas primero (`is_folder DESC`), luego por nombre

## 2. Frontend — eliminar buscador decorativo del navbar

- [x] 2.1 Eliminar de `app/resources/views/layouts/app.blade.php` el bloque `<div class="hidden md:flex ...">` que contiene el input "Buscar archivos..." del header

## 3. Frontend — estado de búsqueda en Alpine.js

- [x] 3.1 Añadir la propiedad `searchQuery: ''` al componente Alpine.js de `files/index.blade.php`
- [x] 3.2 Añadir la propiedad `searchMode: false` para distinguir entre vista de carpeta y vista de búsqueda
- [x] 3.3 Añadir la propiedad `searchTimer: null` para el debounce
- [x] 3.4 Implementar el método `searchFiles()` que llama a `GET /files?q=...&storage_id=...` y asigna el resultado a `this.files`
- [x] 3.5 Añadir `$watch('searchQuery', ...)` con debounce de 350ms: si `searchQuery` tiene ≥2 caracteres llama a `searchFiles()`; si se vacía llama a `clearSearch()`
- [x] 3.6 Implementar el método `clearSearch()` que vacía `searchQuery`, pone `searchMode = false` y llama a `loadFiles()` para restaurar la carpeta actual

## 4. Frontend — cuadro de búsqueda en la barra de herramientas

- [x] 4.1 Añadir el cuadro de búsqueda en la barra de herramientas del módulo de archivos (encima de la tabla/grid de archivos), con `x-model="searchQuery"`, placeholder "Buscar archivos...", ícono de lupa y botón de limpiar (×) visible cuando `searchQuery` no está vacío
- [x] 4.2 Deshabilitar el input visualmente cuando no hay storage seleccionado (`currentStorage === null`)

## 5. Frontend — indicador de modo búsqueda y mensaje sin resultados

- [x] 5.1 Mostrar un banner o etiqueta "Resultados de búsqueda para: «X»" cuando `searchMode === true`, en lugar del breadcrumb normal de carpetas
- [x] 5.2 Añadir el mensaje "No se encontraron archivos para «X»" (`x-show="searchMode && files.length === 0"`) en lugar de la lista vacía
- [x] 5.3 Asegurarse de que el botón "Limpiar" del banner de modo búsqueda también llama a `clearSearch()`

## 6. Verificación

- [ ] 6.1 Verificar en el navegador que al escribir en el cuadro de búsqueda se filtran los archivos con debounce
- [ ] 6.2 Verificar que la búsqueda devuelve resultados de diferentes carpetas del mismo storage
- [ ] 6.3 Verificar que al limpiar la búsqueda se vuelve a la carpeta anterior con su contenido
- [ ] 6.4 Verificar que el navbar ya no muestra el input decorativo
- [ ] 6.5 Verificar que los permisos de usuario se respetan (usuario sin acceso a un storage no ve sus archivos en búsqueda)
