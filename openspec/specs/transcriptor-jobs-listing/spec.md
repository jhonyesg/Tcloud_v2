# transcriptor-jobs-listing Specification

## Purpose
Gobernar cómo se lista, pagina y filtra la pestaña Trabajos del módulo API
Transcriptor (`/ia/api-transcriptor`), y cómo el admin lee el contenido de una
transcripción sin abandonar el listado. El filtrado por sub-tab y la paginación se
resuelven en `ApiTranscriptorController::indexData()` (servidor), no en el cliente.

## Requirements

### Requirement: El listado de trabajos se pagina y filtra en servidor

El sistema SHALL resolver el filtrado por sub-tab y la paginación en
`ApiTranscriptorController::indexData()`, no en el cliente. El endpoint
`GET /ia/api-transcriptor` SHALL aceptar `scope`, `page` y `per_page`, y devolver un bloque
`pagination` con `page`, `per_page`, `total`, `total_pages`, `capped` y `window_max`.

`scope` SHALL mapear a estados así: `pending` → `pending|queued|processing`;
`completed` → `done`; `failed` → `error|dead`; `all` → sin filtro. El valor por defecto es
`pending`, y un `scope` desconocido SHALL tratarse como `pending`.

`per_page` por defecto es 50 y SHALL recortarse a un máximo de 100. La navegación SHALL
topar en `JOBS_WINDOW_MAX` (500) filas por scope.

> **Historia.** El listado cargaba `limit(200)` ordenado por `created_at DESC` y repartía
> esas filas entre sub-tabs con `x-show` de Alpine. Con 84.763 filas en `queued`, las 200
> más recientes eran 192 `queued` + 8 `dead`: la sub-tab Completados mostraba 8 filas
> muertas y ninguna de las **88.514** transcripciones en `done`. Un filtro client-side sobre
> una ventana truncada hereda el sesgo de la ventana.

#### Scenario: Completados devuelve solo transcripciones terminadas
- **WHEN** se pide `GET /ia/api-transcriptor?scope=completed` con `Accept: application/json`
- **THEN** todas las filas devueltas tienen `state = "done"`
- **AND** su número es `per_page` (50 por defecto) mientras existan suficientes

#### Scenario: Página fuera de rango se recorta
- **WHEN** se pide `page=11` con el tope de 500 filas y `per_page=50` (10 páginas)
- **THEN** el servidor devuelve la página 10 y `pagination.page = 10`, sin error

#### Scenario: per_page excesivo se recorta
- **WHEN** se pide `per_page=9999`
- **THEN** se devuelven 100 filas y `pagination.per_page = 100`

#### Scenario: Se indica que hay más filas de las navegables
- **WHEN** el scope tiene más de 500 filas
- **THEN** `pagination.capped` es `true` y `pagination.total` es 500

### Requirement: Los scopes terminales se ordenan por fecha de finalización

El sistema SHALL ordenar los scopes `completed` y `failed` por
`finished_at DESC NULLS LAST` y, como desempate, `created_at DESC`. Los scopes `pending` y
`all` SHALL mantener el orden `created_at DESC`.

#### Scenario: Completados ordenados por finished_at
- **WHEN** se pide `scope=completed`
- **THEN** la primera fila es la de `finished_at` más reciente
- **AND** las filas con `finished_at` nulo aparecen al final

### Requirement: El conteo del total no recorre la tabla completa

El sistema SHALL calcular el total escaneando como máximo `JOBS_WINDOW_MAX + 1` filas,
mediante un `SELECT 1` sin `ORDER BY` y con `LIMIT`. El total exacto por estado se obtiene
de `GET /ia/api-transcriptor/stats`, que agrupa con el índice `(state, created_at)`.

> El `ORDER BY` debe retirarse explícitamente (`reorder()`): con el orden por `finished_at`
> puesto, Postgres ordenaría el conjunto entero solo para contar.

#### Scenario: Conteo acotado
- **WHEN** el scope `completed` tiene 88.514 filas
- **THEN** la consulta de conteo lee 501 filas como máximo
- **AND** devuelve `total = 500` y `capped = true`

### Requirement: El parámetro `only=jobs` omite el bloque de storages

El sistema SHALL devolver el payload sin la clave `storages` cuando la petición incluya
`only=jobs`. El cliente SHALL usarlo al cambiar de página o de sub-tab, y SHALL omitirlo en
la carga inicial y tras acciones que alteren storages.

> El bloque de storages ejecuta `resolveInheritedTranscriptionScope()` sobre 175 storages y
> cuesta ~430ms de los ~700ms de la respuesta. Paginar no lo modifica.

#### Scenario: Paginar no recalcula storages
- **WHEN** se pide `GET /ia/api-transcriptor?scope=completed&page=2&only=jobs`
- **THEN** el payload contiene `jobs`, `filters` y `pagination`, y NO contiene `storages`

#### Scenario: La carga inicial sí trae storages
- **WHEN** se pide sin `only`
- **THEN** el payload incluye `storages` con `descendant_count` y `descendant_names`

### Requirement: El filtro de estado se intersecta con el scope

El sistema SHALL ignorar el parámetro `state` cuando el estado pedido no pertenezca al scope
activo, y SHALL devolverlo vacío en `filters.state`. El `<select>` de estados de la UI SHALL
ofrecer solo los estados del scope activo.

#### Scenario: Estado ajeno al scope se ignora
- **WHEN** se pide `scope=failed&state=done`
- **THEN** todas las filas devueltas están en `error` o `dead`
- **AND** `filters.state` es `""`

#### Scenario: Estado propio del scope se aplica
- **WHEN** se pide `scope=failed&state=dead`
- **THEN** todas las filas devueltas están en `dead`

### Requirement: La búsqueda cubre `original_name`

El sistema SHALL buscar el término en `transcriptions.original_name` **o** en `files.name`.

> La tabla muestra `job.original_name || job.file?.name`, pero la búsqueda solo miraba
> `files.name`: había coincidencias visibles en pantalla que el filtro descartaba.

#### Scenario: Coincidencia por original_name
- **WHEN** una `Transcription` tiene `original_name = "wradio_31072026.mp3"` y su `File`
  asociado se llama distinto
- **THEN** buscar `wradio` devuelve esa fila

### Requirement: Sub-tab Fallidos separada de Completados

El sistema SHALL presentar tres sub-tabs en la pestaña Trabajos: **Pendientes**
(`pending|queued|processing`), **Completados** (`done`) y **Fallidos** (`error|dead`).

Los contadores de badge SHALL leerse de `stats.local` (totales de BD), no del array de
filas cargado.

> Agrupar `done` con `error|dead` hacía que 4.134 fallos compartieran pestaña con los
> éxitos. Contar la página cargada daba como mucho `per_page`, así que el badge mentía
> sobre el tamaño real de la cola.

#### Scenario: Fallidos no contiene done
- **WHEN** el usuario activa la sub-tab Fallidos
- **THEN** ninguna fila tiene `state = "done"`

#### Scenario: Badges reflejan el total de BD
- **WHEN** `stats.local = { pending: 47, queued: 84763, processing: 23, done: 88514, error: 19, dead: 4134 }`
- **THEN** el badge de Pendientes muestra 84.833, el de Completados 88.514 y el de Fallidos 4.153

#### Scenario: Cambiar de sub-tab vuelve a la página 1
- **WHEN** el usuario está en la página 5 de Pendientes y pulsa Completados
- **THEN** se pide `scope=completed&page=1` y la tabla muestra la primera página

### Requirement: Endpoint de contenido de una transcripción

El sistema SHALL exponer `GET /ia/api-transcriptor/jobs/{id}/transcript`, que devuelve
`id`, `state`, `file_name`, `language`, `duration_seconds`, `word_count`, `finished_at`,
`error_message`, `srt_content`, `plain_text`, `segments[]` y `segments_truncated`.

Cada segmento SHALL incluir `segment_index`, `start_seconds`, `end_seconds`, `text` y las
etiquetas `start_label` / `end_label` en formato `HH:MM:SS`, resueltas en servidor con
`TranscriptionSegment::getStartLabel()` y `getEndLabel()`.

Los segmentos SHALL ordenarse por `segment_index` y limitarse a
`TRANSCRIPT_SEGMENTS_MAX` (5000), marcando `segments_truncated = true` al recortar.

#### Scenario: Job completado devuelve texto y segmentos
- **WHEN** se pide el transcript de una `Transcription` en `done` con 91 segmentos
- **THEN** `segments` tiene 91 entradas ordenadas, `plain_text` no está vacío,
  `srt_content` contiene el SRT y `segments_truncated` es `false`

#### Scenario: Transcripción muy larga se recorta
- **WHEN** la transcripción tiene más de 5000 segmentos
- **THEN** se devuelven 5000 y `segments_truncated` es `true`
- **AND** `srt_content` sigue conteniendo el SRT completo

### Requirement: Modal "Ver transcripción" desde el listado

El sistema SHALL ofrecer, en cada fila con `state = "done"`, una acción "Ver transcripción"
que abre un modal sin abandonar el listado. El modal SHALL tener pestañas **Texto** y
**Segmentos**, un buscador que filtra segmentos y resalta coincidencias, y acciones Copiar,
Descargar `.srt` y Abrir detalle.

El modal SHALL cerrarse con `Escape` y con clic fuera, y SHALL ser responsive: pantalla
completa por debajo del breakpoint `sm`, panel centrado con `max-height` por encima.

El resaltado SHALL escapar el texto antes de inyectarlo en `x-html`, y SHALL escapar el
término buscado como expresión regular.

#### Scenario: Abrir la transcripción desde una fila completada
- **WHEN** el usuario pulsa "Ver transcripción" en una fila `done`
- **THEN** se hace `GET /ia/api-transcriptor/jobs/{id}/transcript` y el modal muestra el
  texto plano; el listado permanece detrás sin recargarse

#### Scenario: Buscar dentro de la transcripción
- **WHEN** el usuario escribe un término con la pestaña Segmentos activa
- **THEN** solo se muestran los segmentos que lo contienen, con la coincidencia resaltada
- **AND** se indica el número de coincidencias

#### Scenario: Descargar el SRT desde el modal
- **WHEN** el usuario pulsa "Descargar .srt"
- **THEN** se descarga un fichero `transcripcion_{id}.srt` con el contenido de `srt_content`

#### Scenario: Fallo al cargar
- **WHEN** el endpoint responde con error
- **THEN** el modal muestra el mensaje de error en lugar del contenido, sin cerrarse

### Requirement: El dispatch masivo no queda limitado por el tamaño de página

El sistema SHALL enviar `POST /ia/api-transcriptor/jobs/bulk-dispatch` **sin** el array
`ids` cuando no haya modo selección activo, delegando en la autoselección del servidor
(hasta 2000 pendientes). Con modo selección activo SHALL enviar los `ids` marcados.

La etiqueta de la barra de acción SHALL mostrar el total de pendientes de BD
(`stats.local`), no el número de filas cargadas en la página.

> Antes se enviaban siempre los `ids` de las filas cargadas. Al reducir la página de 200 a
> 50, el lote habría pasado a 50 en silencio y el banner de resultado habría parecido
> correcto.

#### Scenario: Dispatch masivo sin selección usa la autoselección del servidor
- **WHEN** hay 84.763 pendientes y el usuario pulsa "Procesar pendientes ahora" sin
  activar el modo selección
- **THEN** el body de la petición no incluye `ids`
- **AND** el número encolado supera el tamaño de página

#### Scenario: Dispatch masivo con selección respeta lo marcado
- **WHEN** el usuario activa el modo selección y marca 3 filas
- **THEN** el body incluye exactamente esos 3 `ids` y solo se encolan 3

### Requirement: La página de detalle descarga el SRT y muestra los segmentos

El sistema SHALL definir `downloadSrt()` dentro del componente Alpine `jobDetail`, y SHALL
renderizar la lista de segmentos con sus marcas de tiempo en
`resources/views/ia/api-transcriptor/job-detail.blade.php`.

> `downloadSrt()` era una función global que leía `window.jobDetail?.srtContent`, lo que
> resuelve a una propiedad de la *función* `jobDetail` — siempre `undefined`. La descarga
> producía un fichero vacío. Por otro lado, `show()` hacía `with('segments')` pero la vista
> solo llamaba a `->count()`: se cargaban todas las filas para mostrar un número.

#### Scenario: Descarga con contenido real
- **WHEN** el usuario pulsa "Descargar .srt" en el detalle de un job `done`
- **THEN** el fichero descargado contiene el `srt_content` de la transcripción

#### Scenario: Segmentos visibles en el detalle
- **WHEN** el job tiene segmentos
- **THEN** se listan ordenados por `segment_index` con `HH:MM:SS → HH:MM:SS` y su texto