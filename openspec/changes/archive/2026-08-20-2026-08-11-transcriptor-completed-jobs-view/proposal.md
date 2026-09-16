# Change: Trabajos completados visibles y transcripción en modal (API Transcriptor)

## Why

En `/ia/api-transcriptor` → pestaña **Trabajos**, la sub-tab **Completados** aparecía
prácticamente vacía: solo unas pocas filas en estado `dead`. El admin no tenía forma de ver
las transcripciones terminadas desde el listado.

**Medición en producción (2026-08-11):**

| Estado | Filas en BD | Filas en la ventana que cargaba la UI |
|---|---|---|
| `done` | 88.514 | **0** |
| `queued` | 84.763 | 192 |
| `dead` | 4.134 | 8 |
| `error` | 19 | 0 |

**Causa raíz.** Toda la tabla se alimentaba de una única consulta sin paginar en
`ApiTranscriptorController::indexData()`:

```php
$jobs = $query->orderByDesc('created_at')->limit(200)->get();
```

Las sub-tabs no filtraban en servidor: eran un `x-show` de Alpine sobre esas mismas 200
filas, y los contadores de badge eran `this.jobs.filter(...).length`. Con un backlog de
~85.000 `pending`/`queued`, las 200 filas más recientes eran casi todas pendientes, así que
en Completados solo sobrevivían los `dead` que casualmente eran recientes. Las 88.514
transcripciones `done` existían en Postgres pero **nunca llegaban al navegador**.

Además, la única forma de leer una transcripción era navegar fuera del listado a
`/ia/api-transcriptor/jobs/{id}`, perdiendo la posición en la tabla.

## What Changes

### 1. Paginación y scope resueltos en servidor

`indexData()` acepta `scope` (`pending` | `completed` | `failed` | `all`), `page` y
`per_page` (default 50, tope 100). El listado es navegable hasta 500 filas por scope
(10 páginas); más atrás se busca por nombre, no se pagina.

- Los terminales se ordenan por `finished_at DESC NULLS LAST`, no por `created_at`.
- La búsqueda cubre `transcriptions.original_name` además de `files.name` (antes solo el
  segundo, pese a que la tabla muestra el primero).
- El filtro `state` se intersecta con el scope: `?scope=failed&state=done` no devuelve `done`.
- El conteo escanea como máximo 501 filas (`LIMIT` sobre un `SELECT 1` sin `ORDER BY`) en
  vez de contar la tabla entera.
- `?only=jobs` omite el bloque de storages del payload. Ese bloque cuesta ~430ms
  (`resolveInheritedTranscriptionScope` sobre 175 storages) y paginar no lo necesita:
  la carga baja de ~700ms a ~70ms por clic.

### 2. Tercera sub-tab: Fallidos

**Completados** pasa a ser solo `done`. `error` y `dead` se mueven a **Fallidos**. Mezclarlos
hacía que los fallos se leyeran como éxitos, que es precisamente lo que el admin veía.

Los contadores de badge pasan a leer `stats.local` (totales reales de BD) en vez de contar
la página cargada, que como mucho podía dar `per_page`.

### 3. Modal "Ver transcripción"

Nuevo endpoint `GET /ia/api-transcriptor/jobs/{id}/transcript` que devuelve el texto plano,
los segmentos con etiquetas `HH:MM:SS` ya resueltas (reutilizando `TranscriptionSegment::getStartLabel()`),
el SRT y los metadatos.

La acción por fila abre un modal con pestañas Texto/Segmentos, buscador con resaltado,
copiar y descargar `.srt`, sin salir del listado. Es responsive: pantalla completa en móvil,
panel centrado en escritorio.

### 4. Regresión evitada en el dispatch masivo

`bulkDispatchPending()` enviaba siempre los `ids` de las filas cargadas. Con 200 filas
despachaba hasta 200; al pasar a páginas de 50 habría empezado a despachar solo 50 en
silencio. Ahora, sin modo selección, envía el body sin `ids` y deja que `bulkDispatch()`
autoseleccione hasta 2000 (comportamiento que el backend ya soportaba). Con modo selección
sigue enviando los `ids` marcados.

### 5. Arreglos en la página de detalle

- `downloadSrt()` leía `window.jobDetail?.srtContent`, que resuelve a una propiedad de la
  *función* `jobDetail` y siempre era `undefined`: descargaba un fichero vacío. Se mueve
  dentro del componente Alpine.
- `show()` hacía `with('segments')` pero la vista solo usaba `->count()`. Ahora se renderiza
  la lista de segmentos con sus marcas de tiempo.

## Impact

- **Sin migraciones.** El esquema ya soportaba todo; el índice `(state, created_at)` cubre
  el filtro por scope.
- **Sin cambios en el pipeline de transcripción.** Solo lectura y presentación.
- Specs afectadas: `transcriptor-state-visibility` (Completados dejaba de ser
  `done|error|dead`), `transcription-api-orchestrator` (el listado ya no son "los últimos
  100 jobs"), `jobs-stuck-refresh-bulk` (las filas que terminan en `error`/`dead` ahora van
  a Fallidos, no a Completados).
