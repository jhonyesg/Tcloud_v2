## Why

En el dropdown "Todos los medios" del módulo Mis Avisos, los clientes con acceso a muchos medios (decenas de storage providers) tienen que scrollear para encontrar el que quieren seleccionar. Un cliente típico de medios de comunicación tiene entre 10 y 80+ proveedores contratados, lo que hace la selección tediosa y propensa a error (activar/desactivar el checkbox equivocado). Agregar un cuadro de búsqueda dentro del dropdown permite filtrar la lista visible mientras se tipea, reduciendo el scroll a cero en la mayoría de los casos.

## What Changes

- Agregar un input de búsqueda (`type="search"`) dentro del popup del dropdown "Todos los medios", en `app/resources/views/mis-avisos/_filter-storages.blade.php`.
- El filtro opera client-side sobre el array `storages` ya cargado en Alpine.js (sin requests al servidor, sin nuevos endpoints).
- Matching insensible a acentos y case-insensitive: "rcn" matchea "RCN Tv", "television" matchea "Televisión".
- El input recibe autofocus al abrir el popup; se resetea al cerrar (sea por click outside, Escape, o re-selección de "Todas").
- Empty state diferenciado: "Sin medios con acceso." (sin medios) vs "Sin coincidencias para «X»" (filtro sin matches).
- El contador del botón trigger ("N medio(s)") sigue reflejando la **selección** (no los visibles).
- Cambio aplica simultáneamente a las pestañas En vivo e Histórico (partial compartido).

## Capabilities

### New Capabilities

- `mis-avisos-storages-filter`: Filtro client-side de búsqueda dentro del dropdown multi-selección de medios en Mis Avisos.

### Modified Capabilities

_Ninguna._ No se modifican requirements de capabilities existentes: no cambia el contrato HTTP (`storage_ids[]`), no cambia el controlador, no cambian los datos persistidos. Solo se agrega comportamiento UX al dropdown existente.

## Impact

- **Código**:
  - `app/resources/views/mis-avisos/_filter-storages.blade.php` (único archivo modificado; ~33 líneas → ~55 líneas).
- **Backend / API**: ninguno. Cero cambios en `MisAvisosController.php`, rutas, ni queries SQL.
- **BD / migraciones**: ninguna.
- **Alpine.js / assets**: ninguno (ya usa Alpine, no se agregan dependencias).
- **Compatibilidad**: backward-compatible — el dropdown sigue funcionando idéntico para quien no use el filtro.
- **Riesgo**: bajo. Es una mejora visual acotada al dropdown; no toca la lógica de submit ni el contrato de query params.

## Non-goals

- No se agrega un botón "Seleccionar todos los visibles" (la selección masiva sigue siendo via "Todas (sin filtro)" + quitar lo no deseado).
- No se agrega debounce (lista chica, match O(n) sub-ms en el navegador).
- No se persiste la query de búsqueda entre pestañas, reloads, ni sesiones (es estado efímero del popup).
- No se cambia el comportamiento del trigger button (label, counter, ícono).
- No se modifica el orden de los medios (siguen ordenados alfabéticamente como hoy).
