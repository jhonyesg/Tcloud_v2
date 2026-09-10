## Why

La pestaña `Clientes` de Avisos Inteligentes (`/ia/avisos-inteligentes`) lista los
clientes con su módulo, cantidad de keywords y storages con acceso. Hoy la tabla
no permite ordenar por columna, solo paginación Anterior/Siguiente, y el
controller ordena por `username` hardcoded. El operador pierde tiempo buscando
clientes concretos o identificando de un vistazo quién tiene el módulo activo.
El estándar de tablas del proyecto (decisión `standard_tabla_paginacion_sort_mis_avisos`)
exige el patrón de Mis Avisos (sort por columna + paginación top/bottom con
elipsis ±2 + per-page) en todos los módulos.

## What Changes

- Hacer clic en los encabezados `Usuario`, `Email`, `Módulo`, `Keywords` y
  `Acceso` ordena la tabla asc/desc server-side vía `?sort=&direction=`.
- Por defecto, al cargar la pestaña sin `sort`, los clientes con módulo activo
  aparecen primero (luego por `username` ASC). Hacer clic en cualquier columna
  descarta ese default (modelo Excel-like).
- Paginación superior e inferior con números de página y elipsis ventana ±2,
  selector de tamaño (25/50/100, whitelist server-side) y estado deshabilitado
  coherente. Reutiliza el partial `mis-avisos/_pagination.blade.php`
  generalizándolo para que reciba un nombre de scope Alpine.
- Numeración `#` por página a la izquierda, calculada como
  `(currentPage - 1) * perPage + index + 1`.
- Iconos de orden (FontAwesome `fa-sort` / `fa-arrow-up` / `fa-arrow-down`)
  idénticos a Mis Avisos.
- Semántica de `Módulo`: `asc` = activos primero, `desc` = inactivos primero.

Sin cambios breaking. La respuesta JSON ya exponía `current_page`,
`last_page` y `next_page_url`; se mantiene la forma y se añaden los params
de query.

## Capabilities

### New Capabilities

- `avisos-admin-clients-table`: define el comportamiento de orden,
  paginación, per-page y numeración de la pestaña `Clientes` de
  `/ia/avisos-inteligentes`.

### Modified Capabilities

- Ninguno. La generalización del partial `_pagination.blade.php` no cambia el
  comportamiento observable de Mis Avisos (mismo scope `'live'` / `'history'`,
  misma API `goPage` / `setPerPage`).

## Impact

- **Backend**: `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`
  método `index()` (líneas 31-71) acepta `sort`, `direction`, `per_page`;
  añade `leftJoin` + `selectRaw` para `module_enabled`; aplica whitelist y
  orden secundario estable.
- **Frontend**: `app/resources/views/ia/avisos-inteligentes/index.blade.php`
  (thead en líneas 75-80, paginación en 123-131, Alpine en 982+).
- **Partial compartido**: `app/resources/views/mis-avisos/_pagination.blade.php`
  pasa de hardcodear `live`/`history` a aceptar un parámetro `$scope`
  genérico. Los callers existentes no se modifican.
- **Sin migraciones**, sin cambios de modelo, sin breaking de API.
- **Riesgo de performance**: el `leftJoin` a `user_alerts_inteligentes`
  (`user_id` UNIQUE) no degrada la consulta.
