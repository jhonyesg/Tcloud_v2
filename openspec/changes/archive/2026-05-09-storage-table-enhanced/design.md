## Context

La vista `/admin/storages` usa Alpine.js para cargar y mostrar todos los storages desde la API (`GET /admin/storages`) en un array `storages[]`. Actualmente la tabla renderiza los registros con `x-for` sin ningún procesamiento previo. El stack no incluye Vue, React ni librerías de tablas — todo el comportamiento interactivo se implementa con Alpine.js puro y Tailwind CSS, respetando la arquitectura existente del proyecto.

## Goals / Non-Goals

**Goals:**
- Ordenamiento asc/desc por cualquier columna directamente en el cliente.
- Búsqueda de texto libre en tiempo real sobre el array cargado.
- Filtros de tipo (local, s3, todos) y estado (activo, inactivo, todos).
- Paginación del lado del cliente (10 / 25 / 50 registros por página).
- Contador de "mostrando X de Y registros".
- Sin llamadas adicionales al servidor al filtrar/ordenar.

**Non-Goals:**
- Paginación del lado del servidor.
- Exportación de datos (CSV, Excel).
- Columnas configurables o reordenables por el usuario.
- Persistencia de filtros entre sesiones.

## Decisions

### 1. Toda la lógica en Alpine.js (sin librerías externas)
**Decisión**: Implementar ordenamiento, búsqueda, filtros y paginación como propiedades computadas dentro del componente Alpine.js existente.

**Por qué**: El proyecto usa Alpine.js en todas las vistas de admin. Añadir una librería como DataTables o AG Grid introduciría dependencias innecesarias, conflictos de estilos con Tailwind, y rompería la coherencia del proyecto.

**Alternativas consideradas**:
- *Alpine.js + DataTables*: Sobrecarga innecesaria, conflictos con Tailwind.
- *Tabla renderizada desde el servidor con Livewire*: Requiere cambiar la arquitectura actual que usa fetch+Alpine.

### 2. Computed getter `filteredAndSorted` como fuente de verdad
**Decisión**: Añadir un getter Alpine `filteredAndSorted` que aplica en cadena: filtro de tipo → filtro de estado → búsqueda → ordenamiento → paginación. El `x-for` de la tabla itera sobre `paginatedStorages` (slice del resultado).

**Por qué**: Mantiene el array `storages` sin mutar, facilita el reset de filtros, y el código es predecible.

### 3. Estado de ordenamiento como `{ column, direction }` 
**Decisión**: Usar un objeto `sortBy = { column: 'id', direction: 'asc' }`. Los encabezados llaman a `toggleSort('nombre_columna')` que invierte la dirección si la columna ya está activa, o establece asc si es nueva columna.

**Por qué**: Patrón estándar, simple de implementar y extender.

### 4. Paginación del lado del cliente con `.slice()`
**Decisión**: Calcular `currentPage` y `perPage` (default 25). Usar `Array.slice()` sobre el array filtrado/ordenado.

**Por qué**: Los storages son pocos en general (decenas a cientos). Cargar todos de una vez y paginar en cliente es aceptable y evita complejidad en el backend.

## Risks / Trade-offs

- **Rendimiento con muchos registros** → Si se registran miles de storages, el filtrado/sort en cliente puede ser lento. Mitigación: la paginación limita el DOM; el filtrado con `Array.filter` en JS es eficiente hasta varios miles de registros.
- **Estado no persistido** → Al recargar la página, los filtros se resetean. Mitigación: fuera de alcance por ahora; si se necesita, se puede añadir `localStorage` en una iteración futura.
- **Sincronización con modales** → Los modales de edición/borrado siguen referenciando el objeto del array `storages`. El getter `filteredAndSorted` no muta el array original, por lo que la referencia se mantiene válida.
