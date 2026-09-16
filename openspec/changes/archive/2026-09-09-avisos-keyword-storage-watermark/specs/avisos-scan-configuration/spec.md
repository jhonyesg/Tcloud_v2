## MODIFIED Requirements

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

La sub-ventana SHALL ofrecer "Escanear ahora" con filtros puntuales: storage específico, keyword específica, ventana temporal mediante presets (8 h, 24 h, 3 días, 7 días, hoy) o rango personalizado con fecha y hora opcional, modo "full scan" (barra todas las keywords atrasadas en su storage), y opción forzar re-escaneo (borra los hits previos de las transcripciones objetivo antes de escanear). Los límites `from`/`to` del rango personalizado SHALL respetar el componente de hora cuando se proporciona; si solo viene fecha, se interpreta como día completo (startOfDay/endOfDay). El estimado mostrado en la confirmación SHALL reflejar los filtros aplicados. La corrida manual SHALL registrar su resumen en `avisos_scan_runs` con origen manual o `full` y SHALL respetar el límite de lote por corrida (el admin puede encadenar corridas).

#### Scenario: Re-escaneo forzado de un storage del día
- **WHEN** el admin lanza "Escanear ahora" con el storage "Negocios Ditu", rango de hoy y opción forzar
- **THEN** se borran los hits previos de las transcripciones terminadas hoy de ese storage y se re-escanean, registrando la corrida como manual con su resumen

#### Scenario: Rango masivo requiere confirmación
- **WHEN** el admin solicita un rango cuya estimación de transcripciones supera el límite de lote
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

#### Scenario: Full scan masivo requiere confirmación
- **WHEN** el admin solicita un "escaneo completo" cuya estimación de pares pendientes supera el umbral configurable
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

#### Scenario: Rango con solo fecha mantiene semántica de día completo
- **WHEN** el admin envía un rango personalizado solo con fecha (sin hora) en Desde
- **THEN** el escaneo aplica desde el inicio de ese día, igual que el comportamiento previo

## ADDED Requirements

### Requirement: La sub-ventana expone el estado de cobertura por keyword

La sub-ventana SHALL mostrar, además del estado del escaneo, una tabla de cobertura por keyword con: nombre, total de storages con acceso, número de storages con `scanned_until = NULL` (catch-up pendiente), último `scanned_until` observado, último hit detectado y total de hits acumulados. El admin SHALL poder filtrar por storage y SHALL poder lanzar un catch-up explícito por keyword desde la UI (REWIND a `NULL` del par).

#### Scenario: Admin consulta cobertura global
- **WHEN** el admin abre la sub-ventana de escaneo
- **THEN** ve la tabla de cobertura de todas las keywords del sistema con sus watermarks por storage y puede identificar cuáles requieren catch-up

#### Scenario: Admin fuerza catch-up de una keyword para un cliente
- **WHEN** el admin hace clic en "Activar histórico" sobre la keyword KW-X del cliente Y
- **THEN** el watermark del par `(KW-X, storage del cliente Y)` se pone en `NULL` y la próxima corrida lo procesa desde el inicio
