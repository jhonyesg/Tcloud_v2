## 1. Estado Alpine

- [x] 1.1 Agregar `shareActiveTab: 'all'` al estado inicial de Alpine en `index.blade.php`
- [x] 1.2 Agregar `selectedShareIds: []` al estado inicial
- [x] 1.3 Agregar `bulkDeleteLoading: false` al estado inicial
- [x] 1.4 Resetear `shareActiveTab`, `selectedShareIds` al abrir el modal de detalle (`openDetailModal`)

## 2. Helpers Alpine

- [x] 2.1 Agregar `filteredShares()` que filtra `fileShares` por `shareActiveTab` (`'all'` devuelve todos)
- [x] 2.2 Agregar `shareTabCount(tab)` que devuelve el conteo de `fileShares` para ese permiso (o total si `'all'`)
- [x] 2.3 Agregar `toggleShareSelect(id)` que añade/quita el ID de `selectedShareIds`
- [x] 2.4 Agregar `toggleSelectAllShares()` que selecciona todos los de `filteredShares()` si no están todos, o deselecciona si están todos
- [x] 2.5 Agregar `allFilteredSharesSelected()` que devuelve true si todos los visibles están seleccionados
- [x] 2.6 Agregar `someFilteredSharesSelected()` que devuelve true si hay seleccionados pero no todos

## 3. Método bulk delete

- [x] 3.1 Agregar `bulkDeleteShares()` que ejecuta `Promise.all` de DELETE sobre `selectedShareIds`, actualiza `fileShares` filtrando los eliminados, limpia `selectedShareIds`, muestra toast de éxito o error parcial

## 4. UI — Pestañas

- [x] 4.1 Reemplazar la cabecera de la sección de compartidos por un grupo de pestañas: Todos, Lectura, Escritura, Subida, Completo; cada una con `@click="shareActiveTab = '...'; selectedShareIds = []"` y `:class` activo
- [x] 4.2 Mostrar contador en cada pestaña usando `shareTabCount(tab)`

## 5. UI — Lista con checkboxes

- [x] 5.1 Añadir fila de cabecera con checkbox "Seleccionar todos" usando `toggleSelectAllShares()` e indicador visual indeterminado cuando `someFilteredSharesSelected() && !allFilteredSharesSelected()`
- [x] 5.2 Cambiar el `x-for` para iterar sobre `filteredShares()` en lugar de `fileShares`
- [x] 5.3 Agregar checkbox a cada item del `x-for`: `:checked="selectedShareIds.includes(share.id)"`, `@change="toggleShareSelect(share.id)"`, deshabilitado cuando `bulkDeleteLoading`

## 6. UI — Barra de acciones bulk

- [x] 6.1 Agregar barra `x-show="selectedShareIds.length > 0"` con texto "X seleccionados" y botón "Eliminar seleccionados" que llama `bulkDeleteShares()`
- [x] 6.2 Mostrar loading en el botón cuando `bulkDeleteLoading` y deshabilitar el botón durante la operación
