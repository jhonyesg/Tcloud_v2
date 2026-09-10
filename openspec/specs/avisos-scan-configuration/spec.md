# avisos-scan-configuration Specification

## Purpose
TBD - created by archiving change 2026-09-07-avisos-scan-configuration. Update Purpose after archive.

## Requirements

### Requirement: El admin configura el escaneo automático de menciones desde una sub-ventana dedicada

El módulo admin `/ia/avisos-inteligentes` SHALL ofrecer una sub-ventana "Escaneo" (pestaña propia, separada de la gestión de clientes) donde el admin configura: escaneo automático activado/desactivado, intervalo en minutos entre corridas automáticas (mínimo 5) y ventana de re-escaneo en horas (transcripciones terminadas dentro de la ventana y sin hits). Los valores SHALL persistir en `SystemSetting` y SHALL aplicarse al siguiente tick sin despliegue.

#### Scenario: El admin activa el escaneo automático con intervalo
- **WHEN** el admin activa el escaneo automático y fija el intervalo en 15 minutos
- **THEN** la configuración persiste y el siguiente tick del scheduler (≥15 min desde la última corrida) ejecuta el escaneo

#### Scenario: Intervalo por debajo del mínimo se corrige
- **WHEN** el admin intenta fijar un intervalo menor a 5 minutos
- **THEN** la sub-ventana lo corrige al mínimo aceptado (5) con un mensaje visible

### Requirement: El escaneo automático corre como cron con tick fijo y decisión por settings

El scheduler SHALL tener un tick fijo (cada 5 minutos, `withoutOverlapping`) que llama al comando de escaneo. El comando SHALL decidir dentro de sí mismo si toca correr: solo escanea si el escaneo automático está activado Y han transcurrido al menos `avisos_scan_interval_minutes` desde la última corrida exitosa registrada. Este patrón SHALL documentarse igual que `sessions_cleanup_interval_minutes` (la expresión cron es fija porque Laravel la cachea al boot; la frecuencia real vive en settings).

#### Scenario: Tick fuera de horario no escanea dos veces
- **WHEN** el tick corre a los 2 minutos de la última corrida con intervalo 15
- **THEN** el comando termina sin escanear y registra el motivo (fuera de ventana)

#### Scenario: Escaneo automático desactivado
- **WHEN** el escaneo automático está desactivado y el tick corre
- **THEN** el comando termina sin escanear y sin crear registros de corrida fallida

### Requirement: El escaneo es idempotente, acotado y no envía correos

El escaneo SHALL reutilizar el motor existente (`KeywordMatcher::run`, idempotente por UNIQUE triple + insertOrIgnore) y SHALL procesar únicamente transcripciones `done` con `generate_alerts=true` cuyo par `(transcription, keyword)` no tenga hit previo Y cuyo `transcription.finished_at` sea posterior al `scanned_until` del par `(keyword_id, storage_provider_id)` en `keyword_scan_watermarks` (o donde `scanned_until` sea NULL — catch-up). El procesamiento SHALL respetar la ventana de re-escaneo configurada salvo en modo catch-up (`noWindow`) o full scan, SHALL respetar el alcance keyword→store del usuario, SHALL respetar el acceso por storage del usuario, y SHALL acotarse por un lote máximo por corrida (default 50, configurable). El escaneo SHALL NOT enviar correos ni invocar al dispatcher de envío: la generación de entregas pendientes (fanout relacional del motor) y su envío siguen siendo trabajo del pipeline existente y del scheduler de avisos respectivamente. Cada corrida SHALL registrar un resumen en `avisos_scan_runs` (origen cron/manual/full, transcripciones escaneadas, hits nuevos, duración, estado, error) y SHALL avanzar de forma atómica y monotónica el `scanned_until` de cada par procesado.

#### Scenario: Backfill de transcripciones terminadas sin hits
- **WHEN** el escaneo corre y existen 12 transcripciones terminadas en las últimas 72 horas sin ningún hit y con `generate_alerts=true`
- **THEN** las escanea en lote, registra hits nuevos y la corrida reporta 12 escaneadas con su conteo de hits

#### Scenario: Backfill de transcripciones terminadas con keyword sin watermark
- **WHEN** el escaneo corre y existen 12 transcripciones terminadas en las últimas 72 horas para una keyword sin watermark previo (nunca escaneada)
- **THEN** las escanea en lote, registra hits nuevos, crea el watermark con `scanned_until = MAX(finished_at)` y la corrida reporta 12 escaneadas con su conteo de hits

#### Scenario: Transcripción ya escaneada no se re-procesa
- **WHEN** una transcripción de la ventana ya tiene hits de la mención
- **THEN** el escaneo la omite (idempotencia del motor) y no duplica hits ni entregas

#### Scenario: Par (transc, keyword) ya cubierto no se re-procesa
- **WHEN** una transcripción del storage 7 fue escaneada contra la keyword KW-A y el `scanned_until` del par `(KW-A, storage 7)` es posterior al `finished_at` de la transcripción
- **THEN** el escaneo la omite (idempotencia del motor + watermark) y no duplica hits ni entregas

#### Scenario: Keyword nueva escanea histórico completo
- **WHEN** un cliente agrega una keyword KW-B nueva y existe un storage con 2000 transcripciones terminadas con `generate_alerts=true`
- **THEN** el watermark `(KW-B, storage)` arranca con `scanned_until = NULL` y el siguiente cron procesa las 2000 transcripciones contra KW-B sin afectar a otras keywords ya cubiertas en ese storage

#### Scenario: El escaneo no dispara correos
- **WHEN** el escaneo genera hits nuevos para un cliente con cadencia activa
- **THEN** no se invoca al dispatcher de correo durante el escaneo; el envío ocurre solo cuando avisos:deliver-alerts procese la entrega pendiente según su cadencia

### Requirement: Disparo manual acotado desde la sub-ventana

La sub-ventana SHALL ofrecer "Escanear ahora" con filtros puntuales: storage específico, keyword específica, ventana temporal mediante presets (8 h, 24 h, 3 días, 7 días, hoy) o rango personalizado con fecha y hora opcional, modo "full scan" (barra todas las keywords atrasadas en su storage), y opción forzar re-escaneo (borra los hits previos de las transcripciones objetivo antes de escanear). Los límites `from`/`to` del rango personalizado SHALL respetar el componente de hora cuando se proporciona; si solo viene fecha, se interpreta como día completo (startOfDay/endOfDay). El estimado mostrado en la confirmación SHALL reflejar los filtros aplicados. La corrida manual SHALL registrar su resumen en `avisos_scan_runs` con origen manual o `full` y SHALL respetar el límite de lote por corrida (el admin puede encadenar corridas). El botón "Escaneo completo" SHALL correr en background con un `runId` y la UI SHALL hacer polling del estado (iteración, pares, hits, tiempo) hasta `done`/`error`. El botón "Activar histórico" SHALL invocar primero un preview sin mutación y mostrar cuántos pares se verán afectados antes del confirm final. Toda mutación SHALL registrar en `watermark_audit_log` con `actor_user_id=session('user_id')`.

#### Scenario: Re-escaneo forzado de un storage del día
- **WHEN** el admin lanza "Escanear ahora" con el storage "Negocios Ditu", rango de hoy y opción forzar
- **THEN** se borran los hits previos de las transcripciones terminadas hoy de ese storage y se re-escanean, registrando la corrida como manual con su resumen

#### Scenario: Full scan masivo requiere confirmación
- **WHEN** el admin solicita un "escaneo completo" cuya estimación de pares pendientes supera el umbral configurable
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

#### Scenario: Full scan reporta progreso en vivo
- **WHEN** el admin lanza un "escaneo completo"
- **THEN** la UI hace polling cada 2s al endpoint del runId y muestra iteración / pares / hits / tiempo hasta `done`/`error`

#### Scenario: Rango con solo fecha mantiene semántica de día completo
- **WHEN** el admin envía un rango personalizado solo con fecha (sin hora) en Desde
- **THEN** el escaneo aplica desde el inicio de ese día, igual que el comportamiento previo

#### Scenario: Rewind con preview antes de confirmar
- **WHEN** el admin hace clic en "Activar histórico" sobre un par
- **THEN** la UI consulta `POST /rewind?preview=true` y muestra "Esto procesará N transcripciones" antes de pedir confirmación; la mutación final registra en `watermark_audit_log`

#### Scenario: Rango masivo requiere confirmación
- **WHEN** el admin solicita un rango cuya estimación de transcripciones supera el límite de lote
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

### Requirement: Estado visible de las corridas de escaneo

La sub-ventana SHALL mostrar el estado actual del escaneo: automático activado/desactivado, intervalo y ventana vigentes, última corrida exitosa (fecha, duración, conteos) y las últimas corridas registradas con su resultado. Las corridas fallidas SHALL mostrar el error para diagnóstico.

#### Scenario: El admin ve por qué no se escaneó
- **WHEN** el escaneo automático está activo pero la última corrida falló por error de BD
- **THEN** la tabla de corridas muestra la corrida fallida con su mensaje de error y fecha

#### Scenario: Vista del estado cuando nunca ha corrido
- **WHEN** nadie ha configurado ni corrido el escaneo aún
- **THEN** la sub-ventana muestra el estado "nunca ha corrido" con los valores por defecto propuestos

### Requirement: La sub-ventana expone el estado de cobertura por keyword

La sub-ventana SHALL mostrar, además del estado del escaneo, una tabla de cobertura por keyword con: nombre, total de storages con acceso, número de storages con `scanned_until = NULL` (catch-up pendiente), último `scanned_until` observado, último hit detectado y total de hits acumulados. El admin SHALL poder filtrar por storage y SHALL poder lanzar un catch-up explícito por keyword desde la UI (REWIND a `NULL` del par).

#### Scenario: Admin consulta cobertura global
- **WHEN** el admin abre la sub-ventana de escaneo
- **THEN** ve la tabla de cobertura de todas las keywords del sistema con sus watermarks por storage y puede identificar cuáles requieren catch-up

#### Scenario: Admin fuerza catch-up de una keyword para un cliente
- **WHEN** el admin hace clic en "Activar histórico" sobre la keyword KW-X del cliente Y
- **THEN** el watermark del par `(KW-X, storage del cliente Y)` se pone en `NULL` y la próxima corrida lo procesa desde el inicio

### Requirement: Disparo manual con "Histórico completo" no falla por claves de ventana ausentes

El endpoint `POST /ia/avisos-inteligentes/scan/run-bg` SHALL iniciar el escaneo correctamente cuando el operador elige la opción "Histórico completo (sin límite de fechas)" en combinación con "Forzar re-escaneo" o sin él. Aunque el body del request NO incluya los campos `from` ni `to` (porque solo aplican a "Rango personalizado"), SHALL responder HTTP 200 o 202 con un JSON que contenga `runId`, sin lanzar `Undefined array key` ni devolver 500. El operador SHALL ver el modal transicionar a `phase: 'running'` con el progreso real del escaneo.

#### Scenario: Histórico completo + force sin from/to inicia correctamente
- **WHEN** el operador POST a `/scan/run-bg` con `{"noWindow": true, "force": true, "limit": 50}` (sin `from`, sin `to`, sin `preset`)
- **THEN** el endpoint responde 200/202 con `{runId: "...", status: "queued" | "running"}`
- **AND** el modal frontend transiciona a `phase: 'running'`
- **AND** el log NO contiene la excepción `Undefined array key "from"`

#### Scenario: Rango personalizado sigue funcionando (regresión)
- **WHEN** el operador POST a `/scan/run-bg` con `{"preset": "24h", "noWindow": false}`
- **THEN** el endpoint responde 200/202 con `{runId: "...", window_label: "Último día (24 h)"}`
- **AND** el comportamiento existente se preserva

### Requirement: Endpoints del módulo de avisos devuelven errores accionables ante excepciones internas

Los endpoints mutacionales del módulo Avisos Inteligentes (`runScanBackground`, `runFullScan`, `rewindWatermark`, `saveAssign`, y sus equivalentes de preview) SHALL estar envueltos en try/catch. Cuando una excepción interna ocurra (Undefined array key, error de BD, timeout de Redis, etc.), SHALL responder con HTTP 5xx y un JSON `{error: "<mensaje legible>"}` que el frontend puede mostrar al operador. Adicionalmente SHALL loguear con `Log::error()` incluyendo `exception`, `file`, `line`, y un resumen del request.

El comportamiento observable SHALL ser:
- HTTP 5xx con `{"error": "..."}` legible en vez del genérico `{"message":"Server Error"}` de Laravel.
- Log en `storage/logs/laravel.log` con todos los campos para diagnóstico.
- Sin filtrar la traza completa al cliente (mantener el mensaje legible pero NO exponer paths internos del servidor).

#### Scenario: Excepción interna genera 5xx con mensaje útil
- **WHEN** cualquier excepción interna ocurre dentro del endpoint (e.g. error de BD, undefined key, timeout)
- **THEN** el endpoint responde con un código 5xx y un JSON `{error: "Error interno al <verbo>: <motivo>"}`
- **AND** el log de Laravel contiene `Log::error` con la traza y los datos de request (sanitizados)
- **AND** el operador ve el mensaje en el modal en vez de "Server Error" opaco

#### Scenario: Flujo feliz sigue devolviendo 200/202 sin cambios
- **WHEN** un request legítimo al endpoint (por ejemplo, lanzar un escaneo con preset válido) procesa sin excepción
- **THEN** el endpoint responde con el código de éxito (200/202) y el JSON esperado
- **AND** no se agrega overhead observable (no try/catch cambia latencia en el caso exitoso)

### Requirement: Catch-up histórico manual usa modo mensual cuando no hay ventana temporal

Cuando el operador elige la combinación `noWindow=true && force=true && !from && !to && !preset` en `/ia/avisos-inteligentes`, el endpoint `POST /scan/run-bg` SHALL activar el modo de iteración mensual en lugar del path clásico. La respuesta 202 SHALL incluir `mode: 'monthly'` y los campos del plan (`month_count`, `first_month`, `last_month`). El comportamiento del path clásico (presets, from/to, force sin noWindow) SHALL seguir intacto.

#### Scenario: Histórico completo + force usa modo mensual
- **WHEN** el operador POST a `/scan/run-bg` con `{"noWindow": true, "force": true, "limit": 50}` y la BD tiene datos de marzo 2024 a septiembre 2026
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'monthly', month_count: 30, first_month: '2024-03', last_month: '2026-09'}`
- **AND** el worker itera mes por mes secuencialmente en lugar de un solo scan monolítico
- **AND** el cache del run contiene `month_plan` y `current_month`

#### Scenario: Otros flujos siguen usando modo clásico
- **WHEN** el operador POST a `/scan/run-bg` con cualquier otra combinación (`preset`, `from/to`, `force` sin `noWindow`)
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'classic', window_label: '...'}`
- **AND** el comportamiento existente no cambia (el path clásico sigue vigente)

### Requirement: El modo mensual tiene un interruptor operativo

El modo mensual SHALL estar detrás de un feature flag `avisos_scan_monthly_mode.enabled` en `system_settings` (o `.env`) para poder desactivarlo sin revertir el despliegue. Si el flag está desactivado, el path `noWindow+force` sigue usando el código clásico (un solo scan monolítico). Esto permite al operador del servidor "frenar" el modo mensual si la query plan mensual o la iteración presentan problemas en producción sin hacer rollback del cambio.

#### Scenario: Flag activado usa el path mensual
- **WHEN** `avisos_scan_monthly_mode.enabled = true` (default)
- **THEN** el endpoint detecta `noWindow+force+sin rango` y activa el modo mensual
- **AND** el worker itera por meses con yielding

#### Scenario: Flag desactivado vuelve al path clásico
- **WHEN** el admin del sistema cambia `avisos_scan_monthly_mode.enabled = false`
- **AND** el operador intenta lanzar un scan histórico
- **THEN** el endpoint NO activa el modo mensual (devuelve `mode: 'classic'`)
- **AND** el worker corre el scan monolítico (estado anterior al fix)
- **AND** el comportamiento conocido de saturación de BD puede volver (aceptable como rollback operativo)

### Requirement: Bulk INSERT de keyword_scan_watermarks deduplica antes de ejecutar

Cuando el matching construye el array `$bumpSet` para escribir en `keyword_scan_watermarks`, SHALL deduplicar las filas que comparten el mismo `(keyword_id, storage_provider_id)` antes de ejecutar el INSERT. La deduplicación SHALL consolidar:

- `scanned_until = MAX(scanned_until)` de las filas duplicadas (la más reciente).
- `candidates_total = SUM(candidates_total)` de las filas duplicadas (acumulado).
- `hits_total = SUM(hits_total)` de las filas duplicadas (acumulado).
- `last_scanned_at = MAX(last_scanned_at)` de las filas duplicadas.
- El `last_scan_run_id` puede ser cualquiera de las filas (no importa cuál; el UPDATE posterior sobrescribe con el runId actual).

Después de deduplicar, el INSERT SHALL ejecutarse UNA sola vez por par `(keyword_id, storage_provider_id)` sin causar `Cardinality violation`.

#### Scenario: 8 transcripciones del mismo par se consolidan en 1 fila
- **WHEN** `$bumpSet` tiene 8 filas con `keyword_id=94, storage_provider_id=12` y distintos `scanned_until` (de `2026-07-11 05:50:18` a `2026-07-11 08:15:49`)
- **THEN** después de dedupe hay exactamente 1 fila con `keyword_id=94, storage_provider_id=12`, `scanned_until = '2026-07-11 08:15:49'` (MAX), `candidates_total = 8` (SUM), `hits_total = SUM de hits`
- **AND** el INSERT no genera `Cardinality violation`

#### Scenario: Sin duplicados el INSERT no cambia
- **WHEN** `$bumpSet` tiene 50 filas con pares `(keyword_id, storage_provider_id)` todos distintos
- **THEN** el INSERT contiene las 50 filas tal cual (cero deduplicación)
- **AND** el comportamiento es idéntico al estado actual (backward compat con el camino feliz)

#### Scenario: Hits se suman correctamente
- **WHEN** `$bumpSet` tiene 3 filas con el mismo `(keyword_id=10, storage_provider_id=5)` y `hits_total = [2, 0, 5]`
- **THEN** la fila deduplicada tiene `hits_total = 7` (suma de los 3)
- **AND** el contador `hits_new` en el cache state suma correctamente los hits originales (no se pierden)

#### Scenario: Modo clásico no se afecta
- **WHEN** un scan clásico (con `preset='24h'`) procesa 50 candidatos todos con pares únicos
- **THEN** el path funciona idéntico al estado actual (sin overhead, sin cambios visibles)
- **AND** un scan clásico con duplicados (caso raro antes) ahora funciona correctamente sin romper

### Requirement: Recarga con escaneo activo no fuerza la pestaña ni abre el modal automáticamente

Cuando el operador recarga `/ia/avisos-inteligentes` mientras hay un escaneo activo (cache key `avisos_scan_bg:active` presente), el módulo SHALL seguir attachando el polling del progreso pero SHALL NO forzar `activeTab = 'escaneo'` ni abrir el modal `scanModal` automáticamente. La página SHALL mantener la pestaña que el operador tenía activa antes de la recarga. El modal SHALL abrirse solo cuando el operador (a) hace click explícito en "Escanear ahora" desde el módulo o (b) navega a la URL `/ia/avisos-inteligentes?focus=bg-avisos-scan-{runId}` desde el indicador global de background jobs.

#### Scenario: Recarga con escaneo activo no fuerza la pestaña ni abre el modal
- **WHEN** el operador recarga `/ia/avisos-inteligentes` mientras hay un escaneo activo
- **THEN** la página carga en la pestaña que el operador tenía activa antes de la recarga
- **AND** el modal `scanModal` NO se abre automáticamente
- **AND** el indicador global del layout muestra la card del escaneo en curso

#### Scenario: Lanzar un escaneo nuevo desde el módulo sigue abriendo el modal en fase confirm
- **WHEN** el operador hace click en "Escanear ahora" en `/ia/avisos-inteligentes`
- **THEN** se abre el modal en `phase: 'confirm'` (comportamiento existente, sin cambios)

### Requirement: El filtro "Storage" del escaneo solo lista storages transcribiendo

El dropdown "Storage (opcional)" de la sub-ventana "Escaneo" SHALL listar únicamente los `StorageProvider` asignados al usuario de la sesión que tengan `storage_providers.transcription_enabled = true`. El endpoint que alimenta el dropdown SHALL ser dedicado del módulo Avisos Inteligentes (no SHALL reutilizar `/user/storages`, que sirve al módulo de Files y debe seguir devolviendo todos los storages del usuario). El filtro SHALL cubrir también el dropdown equivalente del modal de confirmación del escaneo. El criterio de inclusión SHALL ser la bandera global `transcription_enabled` (escrita únicamente desde API Transcriptor); la bandera por cliente `user_storages.transcription_access` SHALL NOT ser criterio de exclusión del dropdown.

#### Scenario: Dropdown muestra solo storages con transcription_enabled=true
- **WHEN** el admin abre la sub-ventana "Escaneo" y despliega el dropdown "Storage (opcional)"
- **AND** el usuario tiene 8 storages asignados, 5 con `transcription_enabled=true` y 3 con `transcription_enabled=false`
- **THEN** el dropdown lista exactamente los 5 storages con `transcription_enabled=true`
- **AND** la opción por defecto sigue siendo "Todos los storages"

#### Scenario: Dropdown se actualiza tras toggle en API Transcriptor
- **WHEN** el admin apaga un storage en API Transcriptor (`transcription_enabled: true → false`)
- **AND** recarga `/ia/avisos-inteligentes` o espera al siguiente refresh
- **THEN** el dropdown ya no muestra ese storage

#### Scenario: Files no se ve afectado
- **WHEN** el admin navega al módulo de Files
- **THEN** el árbol de archivos sigue mostrando todos los storages asignados al usuario (incluidos los que tienen `transcription_enabled=false`)

#### Scenario: Modal de confirmación usa el mismo conjunto filtrado
- **WHEN** el admin hace clic en "Escanear ahora" y abre el modal de confirmación
- **THEN** su dropdown "Storage (opcional)" lista exactamente los mismos storages que el dropdown del panel, no un conjunto más amplio

### Requirement: La pestaña Cobertura se acota a storages transcribiendo

La pestaña "Cobertura" (filtro dropdown, tabla de pares `(keyword, storage)` y botón "Activar histórico" / rewind) SHALL operar únicamente sobre los `StorageProvider` con `storage_providers.transcription_enabled = true` que estén asignados al usuario. La respuesta del endpoint de cobertura SHALL incluir el campo `storages` con la lista filtrada para alimentar el dropdown del filtro. Las filas de `keyword_scan_watermarks` cuyos `storage_provider_id` correspondan a storages apagados SHALL NO aparecer en la tabla ni ser objetivo del rewind. NO SHALL eliminarse las filas de `keyword_scan_watermarks` de storages apagados: solo dejan de incluirse en la respuesta paginada; si el storage vuelve a activarse, las filas reaparecen con su `scanned_until` previo.

#### Scenario: Dropdown del filtro Cobertura se puebla con storages transcribiendo
- **WHEN** el admin abre la pestaña "Cobertura"
- **THEN** el dropdown "Storage" del filtro muestra la lista de storages con `transcription_enabled=true`
- **AND** la opción por defecto "Todos los storages" sigue presente

#### Scenario: Tabla excluye pares de storages apagados
- **WHEN** la tabla Cobertura se carga y existe un par `(keyword_id=10, storage_provider_id=7)` en `keyword_scan_watermarks`
- **AND** el storage 7 tiene `transcription_enabled=false`
- **THEN** ese par NO aparece en la respuesta paginada

#### Scenario: Rewind no es invocable sobre un storage apagado
- **WHEN** la tabla Cobertura no muestra ningún par del storage 7 (porque está apagado)
- **THEN** el botón "Activar histórico" no es alcanzable para pares de ese storage desde la UI
- **AND** un POST directo a `/scan/rewind` con `storage_provider_id=7` sigue siendo aceptado por el endpoint si el par existe en BD (la UI no lo invoca; el backend no es responsable de este guardrail en esta entrega)

#### Scenario: Reactivar un storage hace reaparecer sus pares
- **WHEN** el admin reactiva un storage en API Transcriptor (`transcription_enabled: false → true`)
- **AND** recarga la pestaña Cobertura
- **THEN** los pares de `keyword_scan_watermarks` para ese storage vuelven a aparecer en la tabla con su `scanned_until` previo
