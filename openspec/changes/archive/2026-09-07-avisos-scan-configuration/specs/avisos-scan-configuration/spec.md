## ADDED Requirements

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

El escaneo SHALL reutilizar el motor existente (`KeywordMatcher::run`, idempotente por UNIQUE triple + insertOrIgnore) y SHALL procesar únicamente transcripciones `done` con `generate_alerts=true` que no tengan ningún hit, dentro de la ventana de re-escaneo configurada, con un lote máximo por corrida (default 50, configurable por parámetro). El escaneo SHALL NOT enviar correos ni invocar al dispatcher de envío: la generación de entregas pendientes (fanout relacional del motor) y su envío siguen siendo trabajo del pipeline existente y del scheduler de avisos respectivamente. Cada corrida SHALL registrar un resumen en `avisos_scan_runs` (origen cron/manual, transcripciones escaneadas, hits nuevos, duración, estado, error).

#### Scenario: Backfill de transcripciones terminadas sin hits
- **WHEN** el escaneo corre y existen 12 transcripciones terminadas en las últimas 72 horas sin ningún hit y con `generate_alerts=true`
- **THEN** las escanea en lote, registra hits nuevos y la corrida reporta 12 escaneadas con su conteo de hits

#### Scenario: Transcripción ya escaneada no se re-procesa
- **WHEN** una transcripción de la ventana ya tiene hits de la mención
- **THEN** el escaneo la omite (idempotencia del motor) y no duplica hits ni entregas

#### Scenario: El escaneo no dispara correos
- **WHEN** el escaneo genera hits nuevos para un cliente con cadencia activa
- **THEN** no se invoca al dispatcher de correo durante el escaneo; el envío ocurre solo cuando avisos:deliver-alerts procese la entrega pendiente según su cadencia

### Requirement: Disparo manual acotado desde la sub-ventana

La sub-ventana SHALL ofrecer "Escanear ahora" con filtros puntuales: storage específico, rango de fechas de terminación y opción forzar re-escaneo (borra los hits previos de las transcripciones objetivo antes de escanear). La corrida manual SHALL registrar su resumen en `avisos_scan_runs` con origen manual y SHALL respetar el límite de lote por corrida (el admin puede encadenar corridas).

#### Scenario: Re-escaneo forzado de un storage del día
- **WHEN** el admin lanza "Escanear ahora" con el storage "Negocios Ditu", rango de hoy y opción forzar
- **THEN** se borran los hits previos de las transcripciones terminadas hoy de ese storage y se re-escanean, registrando la corrida como manual con su resumen

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
