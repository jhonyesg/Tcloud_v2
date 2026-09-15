## Why

El `<select>` nativo de "Todas mis keywords" en Mis Avisos (pestañas En vivo e Histórico) tiene el mismo problema de escalabilidad que el dropdown de medios: clientes con docenas de keywords registradas tienen que scrollear una lista larga para encontrar la correcta. Ahora que el dropdown de medios ya tiene búsqueda client-side (`mis-avisos-storages-search` archivado), extender el mismo patrón al selector de keywords brinda consistencia UX y resuelve el mismo dolor en una pieza equivalente.

## What Changes

- Crear un nuevo partial Blade `app/resources/views/mis-avisos/_filter-keywords.blade.php` que reemplaza el `<select>` nativo por un dropdown multi-línea con búsqueda client-side, espejado del partial `_filter-storages.blade.php` (archivado en `mis-avisos-storages-search`).
- A diferencia del dropdown de medios (multi-select con checkboxes), este es **single-select**: usa radio buttons; la opción "Todas mis keywords" representa "sin filtro".
- Matching insensible a acentos y case (mismo `String.prototype.normalize('NFD')` ya usado en storages).
- Trigger button:
  - Cuando `keyword_id === 0`: muestra `"Todas mis keywords"`.
  - Cuando hay una keyword seleccionada: muestra el **texto** de la keyword (no un contador, porque siempre es 0 o 1).
- Autofocus, reset al cerrar (Escape / click outside / re-selección), sticky top del input — todo igual al partial de medios.
- Auto-apply opcional: si el partial recibe `autoApply=<method>`, invoca ese método del padre tras cada selección. En vivo usa `applyLiveFilters()` (auto-apply igual que hoy); Histórico NO auto-aplica (sigue esperando al botón "Buscar").
- Sustitución de los `<select>` actuales en `app/resources/views/mis-avisos/index.blade.php`:
  - **En vivo** (líneas ~418-424) → `@include('mis-avisos._filter-keywords', ['scope' => 'liveFilters', 'autoApply' => 'applyLiveFilters'])`.
  - **Histórico** (líneas ~524-533) → `@include('mis-avisos._filter-keywords', ['scope' => 'historyFilters', 'autoApply' => null])`.
- Contrato HTTP intacto: `keyword_id` sigue serializándose como un único entero en la URL (`searchHistory()` línea ~1410, `pollLive()` línea ~1350). El input de búsqueda sigue siendo 100% client-side, sin nuevos params ni endpoints.

## Capabilities

### New Capabilities

- `mis-avisos-keywords-filter`: Filtro client-side de búsqueda dentro del dropdown single-select de keywords en Mis Avisos.

### Modified Capabilities

_Ninguna._ El contrato HTTP (`keyword_id` como entero) no cambia. Se reusa el patrón y la spec del archivado `mis-avisos-storages-filter` como referencia, pero no se modifica ningún spec existente.

## Impact

- **Archivos creados**:
  - `app/resources/views/mis-avisos/_filter-keywords.blade.php` (nuevo partial, ~80 líneas).
- **Archivos modificados**:
  - `app/resources/views/mis-avisos/index.blade.php` — dos reemplazos de `<select>` por `@include` (~15 líneas tocadas).
- **Backend / API**: cero. `keyword_id` sigue siendo el único canal al backend para selección de keyword.
- **BD / migraciones**: ninguna.
- **Alpine.js / assets**: ninguno.
- **Compatibilidad**: backward-compatible. Si Alpine no carga el scope local, el dropdown degrada a un fallback visible (los radio buttons siguen funcionando con Alpine plano).

## Non-goals

- No se agrega multi-select de keywords (sigue siendo single; los keyword_id son índices únicos).
- No se agrega "Seleccionar visibles" (single-select no aplica).
- No se agrega debounce (lista chica en cliente; el matching O(n) es sub-ms).
- No se cambia el orden de las keywords (sigue siendo el orden del payload PHP, actualmente por categoría + alfabético según el servidor).
- No se modifica el componente `<select>` real en otra parte del módulo "Palabras clave" (la pestaña dedicada para gestionar keywords sigue igual).
