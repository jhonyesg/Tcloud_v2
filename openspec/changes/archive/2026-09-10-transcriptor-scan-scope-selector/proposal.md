# Proposal — transcriptor-scan-scope-selector

## Why

El botón "Escanear storages" del API Transcriptor lanza `transcription:scan-and-submit --days=0` con el alcance HARDCODEADO a la carpeta de HOY. El operador no puede recuperar el backlog histórico desde la UI: 15,703 archivos (más viejos que 2 días, repartidos en ~80 storages habilitados, hasta 62 carpetas `dmY` distintas) nunca serán descubiertos por el botón ni por el cron (`scan_days_back` default 0). Tampoco puede re-procesar de forma dirigida los fallidos (1,243 en `error`, 17,435 en `dead` con audio vivo). El comando ya soporta `--days=N` y `--all` a nivel CLI, pero la UI no los expone y no hay estimación previa del alcance.

## What Changes

- **Selector de alcance en el modal "Escanear storages"**: 3 modos —
  - **Hoy** (comportamiento actual, `--days=0`)
  - **Rango de fechas** (`--from=DDMMYYYY --to=DDMMYYYY` nuevo en el comando; construye la lista de carpetas `dmY` del rango)
  - **Todo el histórico** (`--all`, ya existe en CLI; ahora también desde la UI)
- **Estimador previo**: endpoint nuevo que, para el alcance elegido, devuelve con UNA query barata por storage: archivos sin transcripción en el rango, `error`/`dead` con archivo vivo. El modal muestra el conteo ANTES de lanzar (como el preview del scan mensual de avisos).
- **Reintentar fallidos con alcance**: el checkbox existente pasa a respetar el rango elegido (los `error` con `created_at` dentro del rango; los `dead` solo vía `backfill-lost` para los upstream-lost, con aviso de que los dead por audio ausente NO se recuperan).
- **Batching con runId**: el run en background ya registra progreso en cache (`transcription_batch:{runId}`); para rango/all se añade el plan de storages al cache para que el modal muestre el avance por storage (mismo patrón del full-scan de avisos).

## Non-goals

- NO re-transcribe archivos con transcripción `done` (357k) — el force de re-escaneo de transcripciones ya hechas es otro change.
- NO modifica el orden del tick, el regulador de cola (`scan_max_dispatch_per_cycle`), ni el dispatcher. El alcance solo controla el DESCUBRIMIENTO; el envío sigue regulado.
- NO recupera `dead` por audio ausente/corrupto (esos mueren de nuevo con `backfill-lost`).
- NO cambia el comportamiento del cron automático (solo hoy, como está).

## Capabilities

### New Capabilities

- `transcriptor-scan-scope`: selector de alcance de escaneo (hoy/rango/histórico) con estimación previa y batching con progreso por storage.

### Modified Capabilities

- `transcription-disk-scanner`: el requisito "Scanner supports backlog recovery" se amplía — además de `--days=N` y `--all`, soporta `--from/--to` por carpetas `dmY` explícitas del rango; la elección de alcance desde la UI no bypassa el regulador de dispatch.

## Impact

- **Comando**: `app/app/Console/Commands/ScanAndSubmitCommand.php` — nueva opción `--from/--to` (folders dmY derivados del rango).
- **Servicio**: `app/app/Services/Ia/DiskScannerService.php` — `scanStorage` acepta la lista de carpetas explícita del rango (hoy delega a `dayFolders`; nuevo: `foldersInRange`).
- **Controller**: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `processBatch()` pasa el alcance elegido al comando; endpoint nuevo `POST /ia/transcriptor/scan/estimate` (query ligera con índices sobre `files.transcriptions`).
- **Vista**: `resources/views/ia/api-transcriptor/index.blade.php` — radio buttons de alcance + bloque de estimación en el modal del batch.
- **Migración**: NO requiere. Settings: sin keys nuevas (el rango viene del request).
- **Riesgo**: "todo el histórico" = escaneo recursivo de ~15k+ archivos nuevos a crear; el dispatch sigue regulado (200/ciclo por defecto), pero la creación de `pending` en masa requiere el mismo plan chunked + progress del full-scan de avisos.