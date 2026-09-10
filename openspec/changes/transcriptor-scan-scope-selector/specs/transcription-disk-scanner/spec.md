# Delta — transcription-disk-scanner

## MODIFIED Requirements

### Requirement: Scanner supports backlog recovery

El sistema SHALL soportar un parámetro `--days=N` para escanear también las carpetas de los N días anteriores al actual, `--from=DDMMYYYY --to=DDMMYYYY` para escanear las carpetas `dmY` explícitas de un rango, y `--all` para escanear todas las carpetas existentes bajo `base_path`. El alcance SHALL controlar únicamente el descubrimiento: la fase de envío SHALL seguir respetando el regulador de cola (`scan_max_dispatch_per_cycle`) sin importar el alcance.

#### Scenario: Recover yesterday recordings
- **WHEN** se ejecuta el scanner con `--days=1`
- **THEN** el sistema escanea la carpeta de hoy y la de ayer, procesando los `.mp4` sin transcripción de ambas

#### Scenario: Recover date range explicitly
- **WHEN** se ejecuta el scanner con `--from=01092026 --to=05092026`
- **THEN** el sistema escanea únicamente las carpetas `01092026`, `02092026`, `03092026`, `04092026`, `05092026` (hoy no incluida salvo que esté en el rango)

#### Scenario: Recover all historical backlog
- **WHEN** se ejecuta el scanner con `--all`
- **THEN** el sistema escanea recursivamente todas las carpetas bajo `base_path` que contengan `.mp4` sin transcripción, respetando `scan_batch` por ciclo

#### Scenario: El alcance no bypassa el regulador
- **WHEN** el escaneo con `--all` descubre 5,000 archivos nuevos en una corrida
- **THEN** el envío a cola de esa corrida sigue limitado por `scan_max_dispatch_per_cycle` y los `pending` restantes quedan para el regulador del cron