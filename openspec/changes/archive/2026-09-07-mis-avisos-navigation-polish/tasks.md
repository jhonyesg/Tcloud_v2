# Tasks — Navegación y pulido visual

## 1. Backend

- [x] 1.1 MisAvisosController: feed/history aceptan `per_page` con whitelist [25,50,100,500] (clamp 25); pass-through a MentionsSearchService
- [x] 1.2 MentionsSearchService::searchHistory(User, filters, perPage) — segundo parámetro opcional

## 2. Frontend

- [x] 2.1 _table-hits: paginación superior e inferior con iconos, números de página con elipsis (helper pageList) y estado deshabilitado — la superior incluye el bloque completo de números (feedback del usuario: así facilita bastante la navegación)
- [x] 2.2 Selector per-page (25/50/100/500) arriba de la tabla; cambios reinician a página 1 y refetch; histórico lo incluye en syncHistoryUrl
- [x] 2.3 Acciones con identidad de color (Ver brand / Editor violeta / Archivos esmeralda) + micro-animaciones (hover elevación, active scale, transiciones)
- [x] 2.4 Filas con hover animado; verificación visual con capturas (tabla clara y legible)

## 3. Validación

- [x] 3.1 php -l / view:clear; E2E: paginación superior funciona, per-page 100 carga 100, colores por acción visibles
- [x] 3.2 openspec validate --strict; archivar si el usuario lo pide