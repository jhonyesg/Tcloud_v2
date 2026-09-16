## Purpose

Las tablas de correcciones aprobadas y de resultados AI Suggest del módulo `/ia/correcciones` se cargan con paginación server-side y soportan selección en lote a través de páginas, para navegar el diccionario completo sin degradar la carga del navegador.

## ADDED Requirements

### Requirement: Las tablas de aprobadas y AI Suggest Results se cargan paginadas desde el servidor
Los endpoints `GET /ia/correcciones/approved` y `GET /ia/correcciones/ai-suggest-results` SHALL aceptar los query params `page` (default 1), `per_page` (default 50), `search` y `source`, y SHALL devolver un objeto `{ items, total, page, last_page, sources }` donde `items` es la página solicitada ordenada por `applies_count` DESC y `id` DESC (aprobadas) o por `id` DESC (AI suggest), `sources` es la lista de orígenes distintos del conjunto completo, y los filtros se aplican en el servidor antes de paginar. El endpoint de aprobadas SHALL aplicar `search` sobre `wrong_text` y `correct_text` (case-insensitive) y `source` como filtro exacto. El navegador SHALL cargar únicamente la página visible, no el diccionario completo.

#### Scenario: Admin navega a la página 2 de aprobadas
- **WHEN** el admin hace click en "Siguiente" en la pestaña Aprobadas
- **THEN** el cliente pide `GET /ia/correcciones/approved?page=2&per_page=50`
- **THEN** el servidor responde con las 50 correcciones siguientes ordenadas por aplicaciones/id
- **THEN** la tabla muestra esas 50 filas y el indicador "Página 2 de N"

#### Scenario: Búsqueda y filtro paginan sobre el conjunto filtrado
- **WHEN** el admin escribe "open english" en la búsqueda de la pestaña Aprobadas
- **THEN** el cliente pide `GET /ia/correcciones/approved?search=open+english` y el servidor filtra ANTES de paginar
- **THEN** el total y las páginas reflejan solo las filas que matchean
- **THEN** con el mismo filtro activo, pasar de página mantiene la búsqueda

#### Scenario: Filtro por source en AI Results
- **WHEN** el admin hace click en una corrida del resumen "Últimas corridas"
- **THEN** el cliente pide `GET /ia/correcciones/ai-suggest-results?source=ai-suggest-2026-08-19&page=1`
- **THEN** la tabla de auto-aprobadas muestra solo ítems de ese lote, paginados

### Requirement: Selección en lote acumula ítems a través de páginas hasta 500
La pestaña Aprobadas SHALL permitir seleccionar correcciones con checkboxes que se acumulan en un set local a través de las páginas (la selección no se pierde al cambiar de página). El UI SHALL exponer un botón "Seleccionar hasta 500" que acumula ítems de páginas subsiguientes hasta alcanzar el tope `config('corrections.bulk_max_ids')` (500). La barra de acción masiva SHALL mostrar el conteo acumulado y aplicar "Excluir" o "Eliminar" a todos los ítems seleccionados. El check-all del encabezado SHALL seleccionar solo la página visible.

#### Scenario: Admin selecciona en varias páginas y ejecuta bulk
- **WHEN** el admin selecciona 50 ítems en la página 1, pasa a la página 2 y selecciona 20 más
- **THEN** la barra muestra "70 seleccionadas"
- **THEN** al hacer click en "Eliminar", los 70 ids se envían al endpoint de bulk y se cumplen las reglas de límite del backend

#### Scenario: Seleccionar hasta 500
- **WHEN** el admin hace click en "Seleccionar hasta 500" con un filtro que matchea 1.200 correcciones
- **THEN** el set de selección acumula 500 ids
- **THEN** la barra muestra "500 seleccionadas" y el botón queda inactivo hasta deseleccionar

