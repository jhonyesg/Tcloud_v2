## Context

La sección de compartidos vive dentro del modal de detalle (`showDetailModal`) en `resources/views/files/index.blade.php`. El estado `fileShares` es un array Alpine con todos los enlaces del archivo abierto. Actualmente se renderiza con un `x-for` plano sin filtrado. La eliminación individual usa `deleteShareLink(shareId)` que llama `DELETE /shares/{id}` y filtra el array local.

## Goals / Non-Goals

**Goals:**
- Pestañas de filtrado por permiso: Todos / Lectura / Escritura / Subida / Completo, calculadas desde `fileShares` sin peticiones extra.
- Selección individual y en bloque con checkbox, con barra de acciones contextual.
- Eliminación en bloque reutilizando el endpoint individual en secuencia.

**Non-Goals:**
- Endpoint bulk en backend.
- Cambios fuera del modal de detalle.

## Decisions

### 1. Filtrado de pestañas: computed desde Alpine, sin fetch adicional

**Decisión:** Una propiedad computada `filteredShares()` filtra `fileShares` por `shareActiveTab`. El valor `'all'` devuelve todos.

**Estado Alpine nuevo:**
- `shareActiveTab: 'all'` — pestaña activa (`'all'|'read'|'write'|'upload'|'full'`)
- `selectedShareIds: []` — IDs seleccionados actualmente
- `bulkDeleteLoading: false` — loading mientras se eliminan en bloque

### 2. Selección: array de IDs, no objetos

**Decisión:** `selectedShareIds` es un array de enteros. El checkbox de cada share usa `:checked="selectedShareIds.includes(share.id)"` y `@change="toggleShareSelect(share.id)"`. El "seleccionar todos" compara con `filteredShares()`.

**Alternativa descartada:** objeto `{id: bool}` — más complejo para el "seleccionar todos" parcial.

### 3. Eliminación en bloque: secuencial con Promise.all

**Decisión:** `bulkDeleteShares()` hace `Promise.all(selectedShareIds.map(id => fetch DELETE /shares/id))`, luego filtra `fileShares` para quitar los eliminados y limpia `selectedShareIds`. Si alguno falla se muestra feedback sin abortar los demás.

### 4. La barra de acciones bulk se muestra condicionalmente

Solo aparece cuando `selectedShareIds.length > 0`. Se oculta al cambiar de pestaña (que limpia la selección).

### 5. Resetear selección al cambiar de pestaña

Al cambiar `shareActiveTab`, `selectedShareIds` se vacía para evitar selecciones fantasma de shares no visibles.

## Risks / Trade-offs

- **[Trade-off] N llamadas DELETE en lugar de una** → Aceptable dado el volumen esperado de shares por archivo (decenas, no miles). Simplifica el backend.
- **[Riesgo] Fallo parcial en bulk delete** → Se informa cuántos fallaron con toast de error. Los exitosos sí se eliminan del array local.
