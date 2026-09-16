## 1. Backend — Paginación en endpoint de aprobadas

- [x] 1.1 Modificar `approved()` en `app/app/Http/Controllers/Ia/CorreccionesController.php` para aceptar `page`, `per_page` (default 50, clamp 1..500), `search` (ILIKE case-insensitive sobre `wrong_text` y `correct_text`) y `source` (filtro exacto), aplicando filtros antes de paginar con el orden actual (`applies_count DESC, id DESC`).
- [x] 1.2 Modificar `approved()` para devolver `{ items, total, page, last_page, sources }`, donde `sources` es la lista de orígenes distintos de las aprobadas (no del diccionario completo).

## 2. Backend — Paginación en endpoint de AI Suggest Results

- [x] 2.1 Modificar `aiSuggestResults()` para aceptar `page`, `per_page`, `search` y `source`, paginando `approved_list` y `pending_list` con el mismo shape `{ items, total, page, last_page }` y conservando el resumen `runs` (últimas 5 corridas) con su shape actual.
- [x] 2.2 Aplicar el filtro `source` también al resumen `runs` y devolver `sources` de las aprobadas ai-suggest-*.

## 3. Frontend — Estado Alpine y carga paginada (Aprobadas)

- [x] 3.1 Agregar estado Alpine `approvedPage`, `approvedPerPage` (50), `approvedTotal`, `approvedLastPage`, `approvedSources`, y ajustar `loadApproved()` para enviar `page/per_page/search/source` y almacenar el payload paginado en `approved`.
- [x] 3.2 Actualizar el dropdown de fuentes para poblarse de `approvedSources` (payload) en vez de barrer `approved`.
- [x] 3.3 Agregar controles de paginación (‹ ‹ anterior/siguiente, "Página X de N") a la tabla Aprobadas, con debounce 300 ms en búsqueda/source que reinician a página 1.

## 4. Frontend — Selección multi-página y "Seleccionar hasta 500"

- [x] 4.1 Mantener el check-all limitado a la página visible (página actual paginada) y asegurar que la selección del `Set` sobreviva al cambiar de página.
- [x] 4.2 Agregar botón "Seleccionar hasta 500" que acumula ids de páginas sucesivas (con los filtros activos) hasta `config('corrections.bulk_max_ids')` o agotar resultados; respetar el tope en `bulkDestroy`/excluir.
- [x] 4.3 Actualizar el contador de la barra bulk para mostrar el acumulado (`approvedSelectedIds.size`) y recalcular el badge "X visibles / Y totales" desde `approvedTotal`.

## 5. Frontend — AI Suggest Results paginado

- [x] 5.1 Agregar estado `aiSuggest.approvedPage`, `aiSuggest.approvedTotal`, `aiSuggest.approvedLastPage`, `aiSuggest.approvedSources` y migrar `loadAiSuggestResults()` al shape nuevo (items paginados).
- [x] 5.2 Agregar controles de paginación en la tabla "Auto-aprobadas" y hacer que click en una corrida del resumen filtre por ese `source`.
- [x] 5.3 Actualizar el indicador "visibles / totales" de AI Suggest al total paginado.

## 6. Validación y limpieza

- [x] 6.1 Ejecutar `php artisan` (lint) y revisar la vista con el navegador: cargar `/ia/correcciones`, navegar páginas, filtrar, seleccionar 500 y ejecutar bulk (sin persistir cambios reales).
- [x] 6.2 Verificar que no quedan referencias al shape viejo (`approved.length`, `approved_list`) en la vista ni en el controller.
- [x] 6.3 Actualizar `openspec/changes/correcciones-tablas-paginadas` (marcar tareas completadas) y dejar el change listo para archivar.
