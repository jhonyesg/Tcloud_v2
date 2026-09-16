# Proposal — Navegación y pulido visual de las tablas de Mis Avisos

## Why

La paginación actual solo está al final de la tabla ("Anterior/Siguiente" con texto plano) — para pasar de página hay que hacer scroll completo. Las acciones de fila ("Ver", "Editor", "Archivos") son botones grises poco diferenciados entre sí, y los rangos de página no son configurables. Con clientes de miles de hits, navegar es tedioso y la tabla se ve plana.

## What Changes

- **Paginación superior + inferior** en las tablas (En vivo e Histórico): botones Previous/Next con iconos y estado, y selector de **por página (25/50/100/500)** que consulta al servidor con el nuevo tamaño.
- **Navegación rápida por páginas** (números, con elipsis para rangos largos) en la paginación inferior.
- **Acciones con color por identidad**: Ver (brand/sólido), Editor (violeta, color del editor de medios), Archivos (esmeralda, color de la carpeta) — hover con elevación y transiciones; iconos ya presentes.
- **Animaciones sutiles**: hover de filas con transición, badges con estados (menciones ×N ya en violeta), botones con feedback de escala al presionar.
- Backend: `todayHits`/`searchHistory` ya reciben `perPage` — exponer `per_page` en feed/history (validado 25/50/100/500, máx 500) y en el `limit` de export no cambia.

## Capabilities

### New Capabilities
- `mis-avisos-table-navigation`: paginación accesible arriba/abajo con per-page configurable y acciones de fila con identidad de color y micro-animaciones.

## Impact

- Frontend: `_table-hits.blade.php` (paginación superior/inferior + selector), `index.blade.php` (estado perPage por pestaña, parámetros a la API, clases de color de acciones), `_filter-storages` intacto.
- Backend: `MisAvisosController` (feed/history aceptan `per_page` con whitelist) + `MentionsSearchService` (pass-through).
- Sin migraciones. Riesgo: payloads mayores con per-page 500 → aceptable (filas ligeras, JSON ~100KB por página).