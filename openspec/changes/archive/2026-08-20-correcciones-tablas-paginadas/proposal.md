## Why

Las tablas "Aprobadas" y "AI Suggest Results" de `/ia/correcciones` cargan el diccionario completo de una sola vez (2.467 correcciones aprobadas, 2.263 de ellas auto-aprobadas por AI Suggest). El navegador recibe un JSON de ~500KB y Alpine filtra en cliente, lo que hace la página lenta y difícil de navegar. Además no hay forma de seleccionar en lote más allá de la página visible.

## What Changes

- **Paginación server-side** en las tablas "Aprobadas" y "AI Suggest Results" de `/ia/correcciones`: los endpoints `GET /ia/correcciones/approved` y `GET /ia/correcciones/ai-suggest-results` aceptan `page`, `per_page` (default 50), `search` y `source`, y devuelven `{ items, total, page, last_page, sources }`.
- **Controles de paginación** en ambas tablas (anterior/siguiente, indicador "x de N páginas").
- **Filtros movidos al servidor**: búsqueda libre y filtro por `source` con debounce, paginando sobre el conjunto filtrado (no sobre todo el diccionario).
- **Selección en lote a través de páginas**: el selector acumula ids en un `Set` a través de las páginas y ofrece "Seleccionar hasta 500", respetando `config('corrections.bulk_max_ids') = 500`.
- El dropdown de orígenes (`sources`) se alimenta del payload paginado, no de barrer el array completo en cliente.
- **BREAKING**: el shape de la respuesta JSON de ambos endpoints cambia de `array` plano a `{ items, total, page, last_page, sources }`.

## Capabilities

### New Capabilities

- `corrections-tablas-paginadas`: paginación server-side, filtros y selección lote multi-página para las tablas de correcciones aprobadas y de resultados AI Suggest en `/ia/correcciones`.

### Modified Capabilities

- `transcription-corrections`: las tablas "Aprobadas" (requirement de búsqueda/filtro por origen) y la sub-tab "AI Suggest Results" (requirement de historial de auto-aprobaciones) pasan de carga completa a paginación server-side con selección de hasta 500 ítems.

## Impact

- `app/app/Http/Controllers/Ia/CorreccionesController.php` — `approved()` (línea ~172) y `aiSuggestResults()` (línea ~191): firma de respuesta y filtros.
- `app/resources/views/ia/correcciones/index.blade.php` — estado Alpine (`approved`, `aiSuggestResults`), tablas, controles de paginación y lógica de selección.
- `app/config/corrections.php` — se reutiliza `bulk_max_ids` (500) como tope de selección.
- No requiere migración.
