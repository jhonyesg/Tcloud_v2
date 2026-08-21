# Tasks: Trabajos completados visibles y transcripción en modal

## 1. Backend — paginación por scope

- [x] Constantes en `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`:
  `JOBS_PER_PAGE_DEFAULT = 50`, `JOBS_PER_PAGE_MAX = 100`, `JOBS_WINDOW_MAX = 500`,
  y el mapa `JOB_SCOPES` (`pending` / `completed` / `failed` / `all`).
- [x] Reescribir `indexData()`: lee `scope`, `page`, `per_page`; aplica `whereIn('state', …)`;
  ordena por `finished_at DESC NULLS LAST` en scopes terminales.
- [x] Búsqueda sobre `original_name` OR `files.name`.
- [x] Intersectar el filtro `state` con el scope activo.
- [x] Conteo acotado con `reorder()->select(DB::raw('1'))->limit(501)`.
- [x] Bloque `pagination` en el payload (`page`, `per_page`, `total`, `total_pages`,
  `capped`, `window_max`) y `scope` dentro de `filters`.
- [x] `?only=jobs` corta antes del bloque de storages.
- [x] Renombrar `$scope` del bucle de storages a `$inheritedScope` (colisionaba con el scope
  de la sub-tab).
- [x] `php -l` sin errores.

## 2. Backend — endpoint de transcripción

- [x] `TRANSCRIPT_SEGMENTS_MAX = 5000` y método `transcript(int $id)`.
- [x] Devuelve `plain_text`, `segments[]` con `start_label`/`end_label` vía
  `TranscriptionSegment::getStartLabel()`, `srt_content` y metadatos.
- [x] Bandera `segments_truncated`.
- [x] Ruta `GET /ia/api-transcriptor/jobs/{id}/transcript` en `routes/web.php`, junto a
  `jobs/{id}/status`.

## 3. Frontend — sub-tabs y paginación

- [x] Tercer botón **Fallidos**; `jobsSubTab` pasa a `pending | completed | failed`.
- [x] Eliminar el `x-show` por fila que repartía las 200 filas entre sub-tabs.
- [x] `statCount()` + getters `jobsPendingCount` / `jobsCompletedCount` / `jobsFailedCount`
  leyendo `stats.local`; retirar `filteredJobsCount()`.
- [x] `setJobsSubTab()`, `reload()`, `goToPage()`, `pageRangeStart()`, `pageRangeEnd()`.
- [x] `scopeStates()` alimenta el `<select>` de estado (antes le faltaban `queued` y
  `processing`) y limpia el filtro si no pertenece al nuevo scope.
- [x] `load(opts)` envía `scope`/`page`/`per_page` y `only=jobs` al paginar.
- [x] Controles de paginación bajo la tabla, con aviso cuando `capped`.
- [x] Estados vacíos por sub-tab (`emptyStateTitle()` / `emptyStateHint()`).

## 4. Frontend — modal "Ver transcripción"

- [x] Estado `transcript` en el componente Alpine.
- [x] Markup del modal: cabecera, pestañas Texto/Segmentos, buscador, cuerpo con estados
  loading/error/vacío, pie con Copiar / Descargar .srt / Abrir detalle.
- [x] Responsive: `inset-0` en móvil, `sm:max-w-4xl sm:max-h-[85vh] sm:rounded-2xl`.
- [x] Cierre con `Escape` y clic fuera.
- [x] `openTranscript()`, `closeTranscript()`, `visibleTranscriptSegments()`, `highlight()`
  (escapa antes de inyectar en `x-html`), `copyTranscript()`, `downloadTranscriptSrt()`,
  `formatDuration()`.
- [x] Botón "Ver transcripción" por fila cuando `state === 'done'`.

## 5. Frontend — regresión del dispatch masivo

- [x] `bulkDispatchPending()` envía `{}` sin modo selección (el servidor autoselecciona
  hasta 2000) y `{ ids }` con selección explícita.
- [x] La barra de acción y su etiqueta usan `jobsPendingCount` (total de BD) en vez de
  `dispatchableJobsCount()` (filas de la página).

## 6. Página de detalle

- [x] `downloadSrt()` movido dentro del componente Alpine; enlace a `@click.prevent`.
- [x] Renderizar la lista de segmentos con marcas de tiempo (ya venían eager-loaded).

## 7. Tour interactivo

- [x] Paso nuevo describiendo las tres sub-tabs y la paginación.
- [x] Paso "Tabla de Trabajos" menciona "Ver transcripción" y la paginación.

## 8. Verificación

- [x] Distribución de estados en BD confirma el diagnóstico (88.514 `done`, 0 en la ventana
  de 200).
- [x] `scope=completed|failed|pending|all` devuelven solo sus estados.
- [x] `page=11` se recorta a la última página válida; `per_page=9999` se recorta a 100.
- [x] `scope=completed&state=dead` ignora el estado ajeno al scope.
- [x] Orden por `finished_at DESC` verificado en la página 1.
- [x] `only=jobs` omite storages: 697ms → 67ms.
- [x] `transcript()` sobre un job real: 91 segmentos, `plain_text` de 9.685 chars,
  `srt_content` de 12.801 chars, etiquetas `HH:MM:SS` correctas.
- [x] Ambas vistas compilan; los bloques `<script>` pasan `node --check`.
- [ ] **Pendiente de validación manual en navegador** (requiere sesión admin real): las tres
  sub-tabs, la paginación, el modal en escritorio y en ancho móvil, y un dispatch masivo
  comprobando que `enqueued` supera el tamaño de página.
