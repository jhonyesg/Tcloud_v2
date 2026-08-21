## MODIFIED Requirements

### Requirement: Tablas de pendientes y aprobadas soportan búsqueda libre y filtro por origen
Las pestañas **Pendientes** y **Aprobadas** del módulo `/ia/correcciones` SHALL exponer: (1) un input `<input type="search">` que filtra las filas cuyo `wrong_text` o `correct_text` contenga el texto (case-insensitive), (2) un dropdown de filtro por `source` con conteo por source y opción "Todos", (3) un indicador "X visibles / Y totales" cuando hay filtro activo. La pestaña Aprobadas SHALL cargarse vía AJAX (`GET /correcciones/approved`) con paginación server-side: la búsqueda y el filtro por source se envían como query params y la tabla muestra solo la página solicitada (default 50 ítems), con controles de paginación y selección en lote que se acumula a través de páginas.

#### Scenario: Admin busca "Open English" en la tabla de aprobadas
- **WHEN** el admin escribe "open english" en el campo de búsqueda de la pestaña Aprobadas
- **THEN** el cliente pide `GET /correcciones/approved?search=open+english` y las filas cuyo `wrong_text` o `correct_text` contengan "open english" se muestran paginadas; las demás se ocultan
- **THEN** el indicador muestra "X visibles / Y totales"

#### Scenario: Admin filtra por source=ai-suggest en Pendientes
- **WHEN** el admin selecciona `source='ai-suggest-YYYY-MM-DD'` en el dropdown
- **THEN** solo las correcciones de ese lote se muestran
- **THEN** el indicador muestra "M visibles / N totales"

#### Scenario: Pestaña Aprobadas carga vía AJAX
- **WHEN** el admin hace click en la pestaña "Aprobadas"
- **THEN** se dispara `GET /correcciones/approved` y la tabla se puebla con la primera página desde la respuesta JSON (sin recargar la página)
- **WHEN** hay 0 aprobadas
- **THEN** se muestra "No hay correcciones aprobadas"

### Requirement: Sub-tab "AI Suggest Results" muestra historial de auto-aprobaciones
El módulo `/ia/correcciones` SHALL exponer una sub-tab "AI Suggest Results" accesible desde el sidebar, alimentada por `GET /correcciones/ai-suggest-results`, que retorna: (1) el resumen de las últimas 5 corridas AI Suggest (`source`, `last_run_at`, `count_auto_approved`, `count_pending`, `count_rejected`), (2) la lista de correcciones auto-aprobadas por AI Suggest paginada (default 50 por página) con búsqueda libre y filtro por source aplicados en el servidor. La sub-tab SHALL mantener la misma estética de tabla y soportar filtro por fecha del lote.

#### Scenario: Admin revisa historial de auto-aprobaciones
- **WHEN** el admin hace click en "AI Suggest Results"
- **THEN** la página muestra dos secciones: "Resumen de corridas" (5 últimas) y "Auto-aprobadas" (tabla paginada con búsqueda libre)
- **THEN** el admin puede buscar por texto (`wrong_text` o `correct_text`) y filtrar por `source` (fecha del lote), paginando el resultado
- **THEN** las correcciones auto-aprobadas incorrectas pueden eliminarse con el mismo botón "Eliminar" que las demás

