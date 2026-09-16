# Design — Navegación y pulido visual de tablas

## Decisions

### D1 — Paginación reutilizable con per-page

`_table-hits` gana una paginación completa (arriba y abajo) y un selector de per-page, ambos orientados por `$mode` como el resto del partial:

- Estado Alpine: `livePerPage`/`historyPerPage` (default 25; persistir en settings locales? — v1: solo en estado por pestaña + URL del histórico via syncHistoryUrl).
- API: `per_page` whitelist `[25,50,100,500]` (controller clamp). `MentionsSearchService` ya recibe `$perPage` param en todayHits; searchHistory gana el segundo parámetro.
- Números de página con elipsis: helper JS `pageList(current, last)` → `[1, '…', 4, 5, 6, '…', 12]` (ventana de 2).
- Botones: `‹ 1 … 4 5 6 … 12 ›`, activo con fondo brand; deshabilitados con opacity.

### D2 — Identidad de color de acciones (paleta del sistema)

- Ver: `bg-brand-600 hover:bg-brand-700 text-white` (ya lo es) + `hover:shadow-lg hover:-translate-y-0.5 active:scale-95 transition-all`.
- Editor: `bg-violet-600 hover:bg-violet-700 text-white` (violeta = editor de medios, ya usado en el editor) — misma micro-animación.
- Archivos: `bg-emerald-600 hover:bg-emerald-700 text-white` (carpeta) — idem.
- Filas: `transition-colors` en hover ya existe; se refuerza con `hover:shadow-sm`.

Contraste accesible: textos blancos sobre fondos 600+. El botón "Ver" conserva el color sólido principal; Editor/Archivos solidos de su color — los tres visibles sin leer etiquetas.

### D3 — Sin cambios de contrato

Los per_page fuera de whitelist → clamp a 25. `syncHistoryUrl` incluye per_page (compartible). Export sin cambios.