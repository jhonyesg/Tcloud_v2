## ADDED Requirements

### Requirement: Toda transcripción nueva entra al matching global de menciones por default

El sistema SHALL crear cada nueva `Transcription` con `generate_alerts=true`
salvo opt-out explícito del operador. El matching global (`KeywordMatcher`
vía `avisos:scan`) SHALL procesar toda transcripción terminada con
`generate_alerts=true` que aún no tenga hits, dentro de la ventana de
re-escaneo configurada, con el límite de lote por corrida vigente. El
filtrado por cliente (qué `user_keyword` recibe qué `alert_delivery`) SHALL
ocurrir exclusivamente en la capa de entrega (`avisos:deliver-alerts`) y
nunca en la capa de ingesta.

#### Scenario: Transcripción creada por el tick automático

- **WHEN** `transcription:tick` corre y descubre archivos nuevos que aún no
  tienen transcripción
- **THEN** las filas `Transcription` creadas por `DiskScannerService`
  quedan con `generate_alerts=true` y entran al matching global en el
  siguiente ciclo de `avisos:scan`

#### Scenario: Opt-out explícito desde la UI

- **WHEN** el operador lanza un lote desde la UI con el checkbox
  "generar avisos" desmarcado
- **THEN** las transcripciones nuevas se crean con `generate_alerts=false`
  Y la decisión queda registrada en los parámetros de la corrida para
  auditoría

#### Scenario: Sub-comando invocado sin flag --alerts

- **WHEN** `transcription:scan-and-submit` se invoca sin `--alerts`
  (omisión del flag) por cualquier caller — incluido `transcription:tick`,
  un script externo o un job asíncrono
- **THEN** el comando trata el flag como activo (`generate_alerts=true`)
  y las transcripciones creadas entran al matching global

#### Scenario: Sub-comando invocado con opt-out explícito

- **WHEN** `transcription:scan-and-submit` se invoca con `--alerts=0`
  (opt-out explícito)
- **THEN** las transcripciones creadas quedan con `generate_alerts=false`
  y NO entran al matching global

### Requirement: El flag --alerts del sub-comando de escaneo respeta default-true

El comando `transcription:scan-and-submit` SHALL aceptar el flag `--alerts`
como parámetro opcional con valor 0 o 1. Cuando el caller omite el flag, el
comando SHALL tratarlo como `1` (matching activo). El comando SHALL
documentar en su descripción que la omisión del flag equivale a activarlo,
para evitar que callers futuros asuman un default conservador.

#### Scenario: Caller omite el flag

- **WHEN** un caller invoca `transcription:scan-and-submit --days=0` sin
  `--alerts`
- **THEN** las transcripciones creadas tienen `generate_alerts=true` y la
  invocación queda registrada en el log del sub-comando

#### Scenario: Caller pasa opt-out explícito

- **WHEN** un caller invoca `transcription:scan-and-submit --alerts=0`
- **THEN** las transcripciones creadas tienen `generate_alerts=false` y
  la decisión es trazable en el log con el valor pasado

### Requirement: El modelo Transcription declara el invariante

El modelo `App\Models\Transcription` SHALL documentar en su docblock el
significado del campo `generate_alerts` (entra al matching global de
menciones, NO "genera avisos"), el default obligatorio (true), la forma de
opt-out (explícita desde la UI) y los callers responsables de respetar el
invariante (`TranscriptionTickCommand`, `ScanAndSubmitCommand`,
`ApiTranscriptorController`).

#### Scenario: Desarrollador revisa el modelo antes de agregar un nuevo caller

- **WHEN** un desarrollador busca dónde agregar un nuevo punto de creación
  de `Transcription`
- **THEN** el docblock de `generate_alerts` le indica explícitamente que el
  default debe ser `true` y qué capa hace el filtrado per-user

### Requirement: Backfill re-escanea transcripciones afectadas por la regresión

Tras aplicar el fix, el admin SHALL poder lanzar una corrida de
`avisos:scan --no-window` (o `avisos:scan-run` background runner) que
re-procese las transcripciones con `generate_alerts=false` que deberían
haber sido escaneadas por el matching global durante la regresión. El
runner SHALL ser idempotente: re-escanear transcripciones que ya tengan
hits no duplica filas (`KeywordMatcher` ya es idempotente por UNIQUE
triple + insertOrIgnore).

#### Scenario: Admin lanza backfill tras el deploy

- **WHEN** el admin corre `php artisan avisos:scan-run
  --run-id=backfill-2026-09-07 --no-window --limit=200`
- **THEN** el runner drena las transcripciones pendientes en tandas de 200,
  crea hits donde corresponde, y la UI de polling del run muestra
  `status=done` con `scanned` y `hits_new` totales al finalizar

#### Scenario: Re-escaneo de una transcripción ya escaneada

- **WHEN** el backfill re-escanea una transcripción que ya tenía hits por
  el camino del pipeline (porque llegó primero a `KeywordMatcher` desde
  `TranscriptionProcessor`)
- **THEN** el matcher omite la transcripción por la guardia de
  idempotencia y el conteo de hits nuevos no se incrementa
