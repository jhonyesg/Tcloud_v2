# transcriptor-jobs-listing Specification

## Purpose
Gobernar cómo se lista, pagina y filtra la pestaña Trabajos del módulo API
Transcriptor (`/ia/api-transcriptor`), y cómo el admin lee el contenido de una
transcripción sin abandonar el listado. El filtrado por sub-tab y la paginación se
resuelven en `ApiTranscriptorController::indexData()` (servidor), no en el cliente.

## Requirements

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
