# Spec — Transcription API Orchestrator

## Purpose

Define cómo el módulo de transcripción decide qué storages se transcriben, envía los archivos a la API externa, recoge los resultados por polling y expone los trabajos al operador en `/ia/api-transcriptor`.

## Requirements

### Requirement: Admin puede habilitar transcripción por StorageProvider

El sistema SHALL permitir al admin marcar cada `StorageProvider` con la bandera `transcription_enabled = true` para indicar que sus archivos deben enviarse a la API de transcripción.

Esa bandera SHALL ser **autoritativa y de un solo escritor**: se cambia únicamente desde API Transcriptor, vía `POST /ia/api-transcriptor/storages/{id}/toggle` (`ApiTranscriptorController::toggleStorage()`). El sistema NO SHALL derivarla de ninguna otra tabla ni permitir que otro módulo la escriba.

API Transcriptor SHALL ser independiente de Avisos Inteligentes y de Correcciones: esos módulos **consumen** el contenido que la transcripción produce, y por tanto no deciden qué se transcribe. La pantalla de un módulo consumidor NO SHALL ofrecer el control de habilitación ni redirigir a él para cambiarlo.

Contexto histórico (no repetir): entre el 2026-08-18 y el 2026-08-20 esta bandera fue un valor derivado de `user_storages.transcription_enabled` y el control se mudó a la ficha del cliente en Avisos Inteligentes. La indirección dejó el pipeline apagado 44 horas cuando el pivote quedó vacío, y obligaba a salir del módulo para encender un canal.

Todo cambio de la bandera SHALL registrarse en `laravel.log` con el storage, el valor anterior, el nuevo y el usuario que lo hizo: apagar un storage detiene su descubrimiento por completo, y ese silencio es indistinguible de un fallo.

#### Scenario: Admin habilita un storage para transcripción
- **WHEN** el admin pulsa el interruptor de la columna "Transcripción" en `/ia/api-transcriptor` sobre un storage inactivo
- **THEN** el sistema persiste `transcription_enabled = true`, deja constancia en el log, y el scanner comienza a considerar archivos de ese storage en el siguiente ciclo del tick

#### Scenario: Admin deshabilita un storage
- **WHEN** el admin confirma el apagado desde el mismo interruptor
- **THEN** el scanner deja de crear nuevos `ConvertAndTranscribeJob`s para ese storage, los jobs ya encolados o en proceso continúan, y lo ya transcrito se conserva

#### Scenario: Petición sin el campo esperado
- **WHEN** llega un `POST` al endpoint de toggle sin `transcription_enabled`
- **THEN** el sistema responde 422 y no modifica ninguna fila

#### Scenario: El pool de workers se reajusta solo
- **WHEN** cambia el número de storages habilitados
- **THEN** `transcription:tune --apply` recalcula los medios equivalentes en su siguiente corrida (cada 5 min) y ajusta las units systemd sin intervención manual

### Requirement: Job envía archivo a la API sin callback
El sistema SHALL hacer `POST /v1/transcribe` en `TRANSCRIPTOR_BASE_URL` con multipart `file`, `language` y `lang_fix`, **sin `callback_url`**.

El resultado se recupera exclusivamente por consulta (ver la capability
`transcription-result-polling`). El sistema SHALL NOT exponer una ruta de
webhook entrante ni enviar `callback_url`: el transcriptor nunca inicia una
conexión hacia Tcloud.

> Historia: la versión anterior de esta spec describía un `callback_url` hacia
> `/webhooks/transcription` y un receptor de webhooks. Esa ruta nunca existió, y
> el texto se copió al panel de ayuda y al tour de la UI. En agosto de 2026,
> 33.571 transcripciones dejaron de cerrarse durante una semana y nadie miró el
> polling, porque la documentación decía que los resultados llegaban solos.

#### Scenario: Envío exitoso crea Transcription
- **WHEN** la API responde 200 con `{"job_id":"...","priority":N,"state":"queued"}`
- **THEN** se persiste una `Transcription` con `state=queued`, el `job_id` devuelto, el `node_url` usado y `started_at = NOW()`

#### Scenario: API responde 401 sin Authorization
- **WHEN** `TRANSCRIPTOR_API_KEY` no está configurado y la API requiere token
- **THEN** el job falla con mensaje "API auth required" y se loguea en `error_message`

---

### Requirement: El polling es el único camino de retorno de resultados
El sistema SHALL correr `transcription:poll-results` cada minuto vía Laravel scheduler, consultando `GET /v1/jobs/{job_id}` para las `Transcription` en `state IN (queued, processing)` con `job_id`, hasta `poll_limit` por ciclo.

La selección SHALL repartirse de forma que ninguna fila pueda quedar excluida
indefinidamente: primero las nunca sondeadas, luego las de `last_polled_at` más
antiguo, desempatando por envío más reciente. Una ventana fija sobre la cabeza
de la tabla (p. ej. `ORDER BY id DESC LIMIT n`) SHALL NOT usarse: una fila que
nunca resuelve se queda dentro para siempre y tapa a las demás.

#### Scenario: Job terminado se recoge y se procesa
- **WHEN** la consulta devuelve `state=done` con `srt_url`
- **THEN** el sistema descarga el SRT, lo guarda en `transcriptions.srt_content`, lo parsea en `TranscriptionSegment` con `start_seconds`, `end_seconds` y `text`, y actualiza `state=done`, `finished_at=NOW()`, `duration_seconds`

#### Scenario: Job está dead según la API externa
- **WHEN** el polling detecta que la API externa marcó el job como `dead`
- **THEN** la `Transcription` se actualiza con `state=dead` y el `error_message` del payload

---

### Requirement: Una pérdida de resultado upstream se cierra, no se reintenta para siempre
El sistema SHALL distinguir un fallo transitorio (nodo caído, 5xx al consultar el job) de una pérdida definitiva del resultado, y cerrar la fila en el segundo caso.

Se considera pérdida definitiva: `404` al consultar el job, o `404`/`5xx` al
descargar el SRT de un job que la API reporta como `done`. El fallo SHALL
registrarse a nivel `WARNING` con el status y la operación — nunca `debug`, que
`LOG_LEVEL=warning` descarta en producción.

#### Scenario: El SRT ya no existe en el transcriptor
- **WHEN** `GET /v1/jobs/{id}` devuelve `state=done` pero `GET /v1/jobs/{id}/srt` devuelve 500
- **THEN** la `Transcription` pasa a `state=dead` con un `error_message` que empieza por el marcador de pérdida, y deja de consumir slots de polling

#### Scenario: Una fila lleva demasiado tiempo sin resolverse
- **WHEN** una `Transcription` en `queued`/`processing` supera `poll_max_age_hours` desde su `started_at`
- **THEN** pasa a `state=dead` indicando el plazo agotado, sea cual sea la causa

---

### Requirement: Las transcripciones con resultado perdido se pueden reenviar
El sistema SHALL ofrecer `transcription:backfill-lost` para reencolar las filas cerradas por pérdida upstream cuyo audio original siga en disco.

El comando SHALL respetar el mismo regulador que el envío automático
(`computeDispatchBatch`), de modo que solo consuma la capacidad libre por debajo
del objetivo de cola y no desplace a las grabaciones del día. SHALL resetear a
`pending` únicamente las filas que despacha en esa corrida. SHALL ofrecer un
modo `--audit` sin escrituras.

#### Scenario: Auditoría antes de reprocesar
- **WHEN** se ejecuta con `--audit`
- **THEN** informa cuántas filas son recuperables y cuánto audio implica, por día y por storage, sin modificar nada

#### Scenario: El audio original ya no está
- **WHEN** una candidata no tiene su archivo accesible en disco
- **THEN** se cierra como `dead` indicando que la transcripción es irrecuperable, en vez de reencolarse

---

### Requirement: Admin puede ver transcriptions recientes y sus detalles

El sistema SHALL listar las transcripciones en `/ia/api-transcriptor` de forma **paginada y
filtrada en servidor** por sub-tab, y permitir ver el detalle de una en
`/ia/api-transcriptor/jobs/{id}` con el SRT, los segmentos y el SRT viewer (texto plano).

Además SHALL permitir leer la transcripción sin abandonar el listado, mediante la acción
"Ver transcripción" que consume `GET /ia/api-transcriptor/jobs/{id}/transcript` (ver
capability `transcriptor-jobs-listing`).

> **Historia.** El requisito original decía "los últimos 100 jobs ordenados por
> `created_at DESC`", implementado como `limit(200)` sin paginación y con las sub-tabs
> repartiendo esas filas en el cliente. Con 84.763 filas en `queued`, la ventana no contenía
> ni una sola de las 88.514 transcripciones en `done`.

#### Scenario: Listado paginado de jobs
- **WHEN** el admin abre `/ia/api-transcriptor`
- **THEN** ve la primera página de 50 trabajos del scope activo, con columnas filename,
  file_id, state, duration_seconds, started_at, finished_at
- **AND** dispone de controles Anterior/Siguiente hasta 500 registros por scope

#### Scenario: Detalle muestra SRT y segmentos
- **WHEN** el admin abre el detalle de un job en estado `done`
- **THEN** ve la lista de segmentos con sus marcas de tiempo, el `srt_content` formateado en
  un `<pre>` con scroll, y un botón "Descargar .srt" que descarga el contenido real

---

### Requirement: Admin puede re-encolar un job fallido manualmente
El sistema SHALL exponer acción "Reintentar" en el detalle de un job en estado `error` o `dead` que re-encola un `ConvertAndTranscribeJob` borrando la `Transcription` previa.

#### Scenario: Reintentar un job error
- **WHEN** el admin hace POST a `/ia/api-transcriptor/jobs/{id}/retry` con state ∈ {error, dead}
- **THEN** se elimina la `Transcription` anterior y se encola un nuevo job (la API externa puede haber purgado el `.bin` tras 7 días, en cuyo caso el nuevo job fallará con "file not found in upstream")

#### Scenario: Reintentar un job en estado terminal inválido
- **WHEN** el job está en `done`, `queued` o `processing`
- **THEN** el sistema responde 409 "solo se reintentan jobs en error/dead"

### Requirement: Scanner detecta archivos nuevos accesibles en storages habilitados
El sistema SHALL ejecutar el command `transcription:scan-new` cada 2-3 minutos vía Laravel scheduler, identificando archivos válidos sin transcripción previa en cada StorageProvider con `transcription_enabled = true`.

#### Scenario: Archivo elegible detectado
- **WHEN** existe un `File` con `modified_at < NOW() - 60s` (archivo "completo" hace >60s) y NO existe una `Transcription` con su `file_id`
- **THEN** el scanner encola un `ConvertAndTranscribeJob` para ese File y registra la fecha de despacho

#### Scenario: Archivo recién modificado se ignora
- **WHEN** un File tiene `modified_at >= NOW() - 60s` (aún se está escribiendo)
- **THEN** el scanner NO lo encola en este ciclo; aparecerá en el siguiente ciclo una vez que pasen los 60s sin modificación

#### Scenario: Archivo ya transcrito se ignora
- **WHEN** ya existe una `Transcription` (en cualquier estado) para un File
- **THEN** el scanner NO encola otro job para ese File

---

### Requirement: ConvertAndTranscribeJob convierte a Opus 64k mono 16kHz
El sistema SHALL convertir el archivo de entrada a Opus 64 kbps, mono, 16 kHz usando ffmpeg local antes de enviarlo a la API, cumpliendo con el formato recomendado por la documentación del transcriptor.

#### Scenario: Conversión exitosa
- **WHEN** el job recibe un File path válido y ffmpeg está disponible en el sistema
- **THEN** se genera un archivo `.opus` temporal de ~7 MB para 15 min de audio (vs ~205 MB del MP4 original) y se elimina al terminar

#### Scenario: ffmpeg falla o no está instalado
- **WHEN** el proceso ffmpeg retorna exit code != 0 o el binario no existe
- **THEN** el job lanza `RuntimeException`, Laravel marca el job como `failed`, y se registra `error_message` en la `Transcription`

---

### Requirement: Jobs se encolan en Redis y los procesan 10 workers paralelos
El sistema SHALL encolar `ConvertAndTranscribeJob` en Redis en la cola única `transcription` y SHALL mantener 10 queue workers procesando esa cola en paralelo via supervisor. El orden de procesamiento queda determinado por el orden de dispatch (FIFO) y la concurrencia del supervisor.

#### Scenario: 10 workers procesan en paralelo
- **WHEN** hay 50 jobs en cola y 10 workers activos
- **THEN** hasta 10 jobs se procesan simultáneamente (10 ffmpeg en paralelo, 25% de 40 cores)

#### Scenario: Worker muere y job vuelve a la cola
- **WHEN** un worker muere mientras procesa un job (OOM, kill)
- **THEN** tras el timeout (120s), Redis devuelve el job a la cola y otro worker lo retoma; `Transcription::firstOrCreate` reutiliza la fila existente con `state=pending`

---

### Requirement: Recuperación de jobs colgados (estado pending)
El sistema SHALL crear `Transcription` con `state=pending` (no `queued`) hasta que la API externa acepte el job y devuelva `job_id`. `scan-stale` SHALL recuperar `Transcription` con `state=pending` y `job_id IS NULL` con más de 5 minutos de antigüedad, reencolando el job.

#### Scenario: Transcription creada como pending
- **WHEN** el job crea la Transcription antes del POST a la API
- **THEN** se persiste con `state=pending` y `job_id=null`

#### Scenario: Transcription pasa a queued tras aceptación de API
- **WHEN** la API externa responde 200 con `job_id`
- **THEN** la Transcription se actualiza a `state=queued` con el `job_id` recibido

#### Scenario: scan-stale recupera pending colgado
- **WHEN** scan-stale encuentra una Transcription con `state=pending`, `job_id=null`, `created_at < NOW() - 5 min`
- **THEN** dispatchea un nuevo `ConvertAndTranscribeJob` para ese `file_id`; el job reutiliza la Transcription existente via `firstOrCreate`

---

### Requirement: Procesamiento por lote en background con alertas opcionales
El sistema SHALL permitir al admin iniciar un lote global de hasta 200 archivos que se ejecuta en background (proceso separado via `nohup`), distribuyendo el lote entre storages habilitados según prioridad. El admin SHALL poder elegir si el lote genera alertas (checkbox, default OFF).

#### Scenario: Lote iniciado en background
- **WHEN** el admin selecciona batch=50 y hace clic en "Iniciar procesamiento"
- **THEN** el sistema lanza `transcription:process-batch --batch=50 --run-id=xxx` en background, devuelve inmediatamente un `run_id`, y el frontend hace polling cada 2s del progreso

#### Scenario: Lote con alertas deshabilitadas
- **WHEN** el admin inicia un lote con "Generar alertas" desmarcado
- **THEN** todos los jobs del lote se crean con `generateAlerts=false` y al completar no se disparan alertas

#### Scenario: Lote con alertas habilitadas
- **WHEN** el admin marca "Generar alertas" e inicia el lote
- **THEN** los jobs se crean con `generateAlerts=true` y al completar se disparan alertas normalmente

#### Scenario: Lote distribuido por prioridad
- **WHEN** hay 2 storages habilitados: Caracol (priority=10, 100 candidatos) y Radio (priority=0, 50 candidatos) y batch=50
- **THEN** el lote asigna más archivos a Caracol que a Radio, proporcional al peso `candidatos * (1 + priority/10)`

#### Scenario: Cerrar/recargar no detiene el lote
- **WHEN** el admin cierra el modal o recarga la página mientras el lote corre
- **THEN** el lote continúa en background; al reabrir el modal, el polling retoma el progreso si el `run_id` sigue en cache

---

### Requirement: Procesamiento manual por carpeta o día
El sistema SHALL permitir al admin procesar todos los archivos de una carpeta específica o de un día (HOY/AYER) desde el navegador de archivos, encolando jobs con prioridad manual y alertas opcionales.

#### Scenario: Procesar carpeta actual
- **WHEN** el admin está navegando una carpeta y hace clic en "Procesar carpeta"
- **THEN** el sistema encola `ConvertAndTranscribeJob` para cada archivo sin transcripción de la carpeta actual (parent_id), con `generateAlerts` según checkbox

#### Scenario: Procesar día
- **WHEN** el admin está en modo HOY o AYER y hace clic en "Procesar día"
- **THEN** el sistema encola jobs para todos los archivos visibles sin transcripción, con `generateAlerts` según checkbox

#### Scenario: Confirmación antes de encolar
- **WHEN** el admin hace clic en "Procesar carpeta" o "Procesar día"
- **THEN** se muestra confirmación con el número de archivos a encolar antes de proceder

### Requirement: Todo ajuste expuesto tiene un consumidor real

Cada clave del `SCHEMA` de `TranscriptorSettings` SHALL tener al menos un consumidor que la lea **a través de la capa de settings**. Una clave que solo se menciona en comentarios, o que solo se lee con `config('transcriptor.…')`, NO SHALL exponerse en la pantalla de configuración.

La razón es de diagnóstico, no de estética: un panel con palancas que no mueven nada invita a diagnosticar con ellas. Durante la auditoría del 2026-08-20, `ai_coherence_threshold` mostraba 0,4 mientras el pase de coherencia corría con un corte fijo de 0.5, y `ai_coherence_model` no lo leía nadie.

Cuando un criterio deba fijarse en código y no en la UI, SHALL vivir como constante nombrada junto a la lógica que gobierna, con el motivo escrito.

Cada clave del `SCHEMA` SHALL existir también en `config/transcriptor.php` con el mismo default. Sin esa correspondencia el valor efectivo sigue saliendo del esquema, pero la pantalla informa un origen ("archivo") que no es cierto.

#### Scenario: Se propone un ajuste nuevo
- **WHEN** se añade una clave al `SCHEMA`
- **THEN** existe un consumidor que la lee vía `TranscriptorSettings`, y la clave está declarada en `config/transcriptor.php` con el mismo default

#### Scenario: Un ajuste se queda sin consumidor
- **WHEN** un cambio deja una clave sin nadie que la lea
- **THEN** la clave se retira del `SCHEMA` en el mismo cambio, y si el valor sigue haciendo falta pasa a ser una constante junto a su lógica

#### Scenario: Verificación automática de la correspondencia
- **WHEN** se ejecuta la suite de tests
- **THEN** `TranscriptorSettingsTest` comprueba que toda clave del esquema tiene respaldo en `config/transcriptor.php` y falla si alguna no lo tiene

### Requirement: Los topes de interfaz se sirven desde la capa de settings

Los límites que la interfaz aplica del lado del navegador —tope del deslizador de lote, máximo de envíos en paralelo y tamaño de lote por defecto— SHALL entregarse a la vista desde `TranscriptorSettings`, en el mismo payload que el resto de datos de la página, y NO SHALL leerse de `config()` al renderizar.

El servidor clampea `processBatch` con `ui_batch_max` de la capa de settings. Si la vista pinta un tope distinto, el usuario puede pedir lotes que el servidor recorta sin decírselo: las dos mitades tienen que salir de la misma fuente.

#### Scenario: Override guardado sin abrir la pestaña de configuración
- **WHEN** un admin guarda `ui_batch_max = 75` y otro admin abre `/ia/api-transcriptor` sin entrar en la pestaña Configuración
- **THEN** la página pinta 75 como tope del deslizador desde la primera carga, el mismo valor con el que el servidor clampeará

#### Scenario: Sin override
- **WHEN** no hay override guardado
- **THEN** la vista recibe el valor de `config/transcriptor.php`, sin cambio de comportamiento respecto a antes

### Requirement: api-transcriptor es un módulo de frontera cerrada para ediciones cross-module

El sistema SHALL mantener el módulo `/ia/api-transcriptor` — vistas, rutas, controlador, servicios asociados, configuración y migraciones propias — como un **módulo de frontera cerrada para modificaciones cross-module**.

Un change cuyo nombre NO siga el patrón `YYYY-MM-DD-*-api-transcriptor-*` NO SHALL modificar archivos del módulo. La dependencia desde otros módulos hacia api-transcriptor SHALL ser solo de lectura (consumo de modelos Eloquent, redirección a su URL para acciones operativas). En particular, otro módulo NO SHALL importar el `ApiTranscriptorController` ni llamar a sus endpoints de escritura (`toggleStorage`, `retry`, `cancelJob`, `reprocess`, `bulkDispatch`, `scanStorage`, `processFolder`, `processDay`, `processBatch`, `syncStorage`) por ruta: si necesita el mismo efecto, abre su propio change con prefijo api-transcriptor o propone al operador usar la UI del módulo.

Fixes de bug y parches de seguridad SHALL abrir su propio change con el prefijo `YYYY-MM-DD-*-api-transcriptor-*` y seguir el flujo normal de OpenSpec (proposal + tasks + archive). Esta regla NO SHALL interpretarse como congelación total del módulo: ediciones internas bien documentadas siguen siendo válidas.

#### Scenario: Una propuesta cross-module intenta editar api-transcriptor

- **WHEN** un change cuyo nombre NO empieza por `YYYY-MM-DD-*-api-transcriptor-*` tiene un diff que toca uno o más de los siguientes paths:
  - `app/resources/views/ia/api-transcriptor/**`
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`
  - `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`
  - `app/app/Services/Ia/TranscriptorSettings.php`
  - `app/app/Services/Ia/TranscriptionCoherencePass.php`
  - `app/app/Services/Ia/TranscriptorApiClient.php`
  - `app/app/Services/Ia/TranscriptionProcessor.php`
  - `app/app/Services/Ia/TranscriptionPollingService.php`
  - `app/app/Services/Ia/DiskScannerService.php`
  - `app/app/Services/Ia/AudioConverter.php`
  - rutas con prefijo `/ia/api-transcriptor/` en `app/routes/web.php`
  - `app/config/transcriptor.php`
  - migraciones con `transcription` en el nombre del archivo
- **THEN** el PR se rechaza con referencia a este requisito, y la edición se traslada a un change dedicado con el prefijo correcto o se elimina del diff

#### Scenario: Consumo desde otro módulo

- **WHEN** el módulo Avisos Inteligentes o el módulo Correcciones necesita mostrar un enlace a `/ia/api-transcriptor`, leer una `Transcription` o un `TranscriptionSegment`
- **THEN** lo hace por URL (enlace en la vista) o por modelo Eloquent (lectura), nunca importando un controlador o servicio de api-transcriptor para escribir en él ni llamando a sus endpoints de escritura

#### Scenario: Fix interno del módulo

- **WHEN** se detecta un bug o vulnerabilidad dentro de api-transcriptor
- **THEN** se abre un nuevo change con nombre `YYYY-MM-DD-*-api-transcriptor-*` que sigue el flujo normal de OpenSpec, y ese change SÍ puede modificar libremente los archivos del módulo
