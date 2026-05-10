## 1. Estado Alpine.js — ordenamiento, búsqueda y filtros

- [x] 1.1 Añadir al componente Alpine.js las propiedades de estado: `searchQuery`, `filterType`, `filterStatus`, `sortBy = { column: 'id', direction: 'asc' }`, `currentPage = 1`, `perPage = 25`
- [x] 1.2 Implementar el getter `filteredAndSorted` que aplica en cadena: filtro de tipo → filtro de estado → búsqueda por texto → ordenamiento
- [x] 1.3 Implementar el getter `paginatedStorages` que aplica `.slice()` sobre `filteredAndSorted` según `currentPage` y `perPage`
- [x] 1.4 Implementar el getter `totalFiltered` (longitud de `filteredAndSorted`) y `totalPages`
- [x] 1.5 Añadir el método `toggleSort(column)` que actualiza `sortBy` e invierte la dirección si la columna ya está activa
- [x] 1.6 Añadir el método `resetFilters()` que limpia `searchQuery`, `filterType`, `filterStatus` y vuelve `currentPage` a 1
- [x] 1.7 Añadir watchers o `x-effect` para resetear `currentPage = 1` automáticamente al cambiar `searchQuery`, `filterType` o `filterStatus`

## 2. Barra de controles (búsqueda, filtros, acciones)

- [x] 2.1 Añadir encima de la tabla un cuadro de texto (`x-model="searchQuery"`) con placeholder "Buscar storage..." y ícono de lupa
- [x] 2.2 Añadir un `<select>` para filtro de tipo con opciones: Todos los tipos / Local / S3 (`x-model="filterType"`)
- [x] 2.3 Añadir un `<select>` para filtro de estado con opciones: Todos / Activo / Inactivo (`x-model="filterStatus"`)
- [x] 2.4 Añadir un selector de registros por página (`<select>` con opciones 10, 25, 50, `x-model="perPage"`)
- [x] 2.5 Añadir el botón "Limpiar filtros" que llama a `resetFilters()`, visible solo cuando algún filtro está activo
- [x] 2.6 Añadir el contador de resultados "Mostrando X–Y de Z storages" usando `totalFiltered` y los índices de la página actual

## 3. Encabezados de tabla con ordenamiento

- [x] 3.1 Convertir los `<th>` de ID, Nombre, Tipo, Archivos y Estado en botones clicables que llamen a `toggleSort('columna')`
- [x] 3.2 Mostrar flecha ↑ (asc) o ↓ (desc) en el encabezado activo usando `x-show` o expresión ternaria en Alpine
- [x] 3.3 Mostrar ícono neutro (↕ o similar) en los encabezados no activos

## 4. Cuerpo de tabla paginado

- [x] 4.1 Cambiar el `x-for` de la tabla para iterar sobre `paginatedStorages` en lugar de `storages`
- [x] 4.2 Añadir mensaje "No se encontraron storages" (`x-show="filteredAndSorted.length === 0"`) cuando no hay resultados

## 5. Controles de paginación

- [x] 5.1 Añadir debajo de la tabla los controles de paginación: botón "Anterior", números de página (o indicador "Página X de Y"), botón "Siguiente"
- [x] 5.2 Deshabilitar el botón "Anterior" cuando `currentPage === 1`
- [x] 5.3 Deshabilitar el botón "Siguiente" cuando `currentPage === totalPages`
- [x] 5.4 Ocultar los controles de paginación completos cuando `totalPages <= 1`

## 6. Verificación visual

- [ ] 6.1 Verificar en el navegador que la búsqueda filtra en tiempo real sin recargar la página
- [ ] 6.2 Verificar que los filtros de tipo y estado funcionan solos y combinados con la búsqueda
- [ ] 6.3 Verificar que el ordenamiento por cada columna funciona asc y desc
- [ ] 6.4 Verificar que la paginación se reinicia al cambiar filtros
- [ ] 6.5 Verificar que los modales de edición, borrado y gestión de usuarios siguen funcionando correctamente
