# Design: Trabajos completados visibles y transcripción en modal

## El fallo, en una imagen

```
        transcriptions (177k filas)     ventana cargada       lo que veía el admin
        ORDER BY created_at DESC        limit(200)
        ┌──────────────────────────┐    ┌──────────────┐   Pendientes  → 192 filas
   más  │ queued · queued · …      │───▶│  200 filas   │──▶
 nuevas │ queued · dead   · …      │    │  192 queued  │   Completados → 8 filas
        │ queued · queued · …      │    │    8 dead    │   (solo los dead recientes)
        ├──────────────────────────┤    └──────────────┘
        │ done · done · done · …   │  ✗ 88.514 filas que nunca cruzaban el corte
   más  │ done · done · done · …   │
antiguas└──────────────────────────┘
```

El reparto por sub-tab era `x-show` sobre el array ya cargado. Cualquier filtro
client-side sobre una ventana truncada hereda el sesgo de la ventana: si el corte no
contiene `done`, ninguna cantidad de filtrado en el navegador los hace aparecer.

## Decisión 1: el scope se resuelve en servidor

Se rechaza subir el `limit` (a 1000, 5000…). No arregla la causa — solo mueve el umbral —
y multiplica el payload. Con 177k filas y crecimiento diario, cualquier ventana fija
vuelve a sesgarse en cuanto la cola crece.

`scope` → estados, en una constante del controlador:

| `scope` | estados |
|---|---|
| `pending` (default) | `pending`, `queued`, `processing` |
| `completed` | `done` |
| `failed` | `error`, `dead` |
| `all` | sin filtro |

El filtro explícito `state` se **intersecta** con el scope. Sin eso,
`?scope=failed&state=done` devolvería filas que la sub-tab no debería mostrar, y el
`<select>` de estados quedaría desincronizado de la pestaña activa.

## Decisión 2: `completed` es solo `done`

Antes `done|error|dead` compartían sub-tab. Con 4.134 `dead` y 88.514 `done`, agruparlos
significa que un fallo se lee como un éxito hasta que miras el badge. Se separa en
**Fallidos**, que además da un sitio natural a la acción "Reintentar" (ya existía, pero
convivía con "Reprocesar" bajo la misma pestaña).

## Decisión 3: contar sin contar la tabla

`count(*)` sobre `state = 'done'` recorre 88k filas del índice en cada carga, y el total
exacto no aporta nada: la navegación está topada en 500.

```php
$total = (clone $query)->toBase()
    ->reorder()                       // sin ORDER BY: solo se necesita cardinalidad
    ->select(DB::raw('1'))
    ->limit(self::JOBS_WINDOW_MAX + 1)  // 501 → sabemos si hay "más de 500"
    ->get()->count();
```

`reorder()` es esencial: con el `ORDER BY finished_at` puesto, Postgres ordenaría todo el
conjunto solo para contar. Medido: **51ms**.

Los totales reales por estado los sigue dando `/stats` con un `GROUP BY` indexado, y son
los que alimentan los badges.

## Decisión 4: `?only=jobs`

`indexData()` devolvía siempre el bloque de storages, que recorre 175 storages llamando a
`resolveInheritedTranscriptionScope()`. Medido:

| Parte | Coste |
|---|---|
| Consulta de jobs (página 1, `done`, orden por `finished_at`) | 76ms |
| Conteo acotado | 51ms |
| **Bucle de storages** | **433ms** |

Paginar no cambia los storages. Con `?only=jobs` la respuesta baja de **697ms a 67ms**.
Es aditivo: las llamadas existentes a `load()` sin la opción siguen recibiendo el payload
completo, así que la pestaña Storages no se entera.

El plan de ejecución de la página 1 confirma que la consulta usa el índice existente:

```
Limit → Gather Merge → Sort (top-N heapsort, 52kB)
  → Parallel Index Scan using transcriptions_state_created_at_index
     Index Cond: state = 'done'
Execution Time: 53ms
```

No hace falta índice nuevo. Un índice sobre `(state, finished_at DESC)` evitaría el sort,
pero un top-N heapsort de 52kB sobre 88k filas no justifica el coste de escritura en una
tabla que recibe ~1.000 inserciones al día.

## Decisión 5: el modal no reusa la página de detalle

Se sirve un endpoint JSON aparte (`/jobs/{id}/transcript`) en vez de cargar el HTML del
job-detail en un iframe. El detalle trae el SRT crudo en un `<pre>` y la cabecera con
acciones destructivas (Eliminar, Reintentar); el modal quiere lo contrario — texto legible
y búsqueda, sin acciones de riesgo a un clic.

Las etiquetas `HH:MM:SS` se resuelven en PHP con `TranscriptionSegment::getStartLabel()`,
que ya existía, en vez de reimplementar el formateo en JS.

**Tope de segmentos:** 5.000, con bandera `segments_truncated`. Las grabaciones de radio
son de horas; sin tope una fila patológica devolvería un JSON de decenas de MB. El SRT
completo sigue disponible por descarga.

**Escapado:** el resaltado de búsqueda inyecta en `x-html`, así que el texto se escapa
siempre antes de insertar el `<mark>`, y el término buscado se escapa como regex. El
contenido viene de audio transcrito por un servicio externo — no es entrada de confianza.

## Riesgo asumido: el dispatch masivo

`bulkDispatchPending()` mandaba los `ids` de las filas cargadas. Reducir la página de 200 a
50 lo habría convertido en un lote de 50 sin que nada lo indicara — el banner de resultado
habría dicho "50 encolados" y parecido correcto.

`bulkDispatch()` ya autoselecciona hasta 2000 pendientes cuando el body llega sin `ids`,
así que la corrección es dejar de mandarlos cuando no hay selección explícita. La etiqueta
del botón pasa de "Procesar N pendientes ahora" (N = filas visibles) a "Procesar pendientes
ahora", con el total real de la cola al lado, para no prometer un número que no es el del
lote.
