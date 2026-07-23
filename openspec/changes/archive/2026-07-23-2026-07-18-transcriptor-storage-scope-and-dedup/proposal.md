## Why

El scanner automático del módulo API Transcriptor (`transcription:scan-and-submit`) tiene tres problemas que se manifiestan juntos cuando se operan storages heterogéneos:

1. **Layout fijo `flat`** — El scanner asume `base_path/dmY/*` (correcto para canales de TV como Telemedellin, RCN, Caracol TV). Pero los storages de emisoras consolidadas (`01 Emisoras 01`, `02 Emisoras 01 Reg`) tienen archivos en `base_path/<emisora>/dmY/*`. Resultado: el scanner automático nunca descubre archivos nuevos en esos storages. Solo el flag `--all` manual rescata archivos, pero requiere intervención humana.

2. **Sin deduplicación entre storages anidados** — Los storages se configuran con rutas independientes, pero físicamente pueden anidarse. Ejemplo concreto en producción: `01 Emisoras 01` (base=`.../Radios/Bogota/`) y `03 La W Bogota` (base=`.../Radios/Bogota/LA_W/`) apuntan a archivos físicos comunes. Si ambos tienen `transcription_enabled=true`, el archivo `LA_W/18072026/wradio_18072026_*.mp3` se registra DOS veces (con `path` distinto en cada storage), generando DOS `Transcription` rows, DOS POSTs al API Transcriptor y DOS alertas downstream. Hoy ya hay 4,580 archivos LA_W registrados bajo storage 47 sin transcripción, y habilitar storage 63 los duplicaría.

3. **`transcription_priority` no llega al API Transcriptor** — El campo existe en BD, en el UI (`<select>` editable en `index.blade.php` línea 159), en el cálculo de cola Redis (`ConvertAndTranscribeJob::calculatePriority`), y se persiste vía `ApiTranscriptorController`. Pero la prioridad real se asigna desde el panel del API Transcriptor directamente. Mantener el campo aquí genera redundancia y el efecto esperado (que los archivos de prioridad alta se procesen primero) no se observa.

El impacto operativo es claro: en Emisoras 01 el scanner automático está inerte (no descubre archivos nuevos), y en cualquier par parent/child habilitado se duplica trabajo y se duplican alertas downstream (donde el módulo de alertas consulta archivos transcritos y aplica keywords por cliente).

## What Changes

- **Layout-aware scanner** — Agregar columna `folder_layout` (`flat` | `grouped_by_subfolder`) en `storage_providers`. El scanner elige la estrategia de descubrimiento según el layout del storage. Para storages emisoras consolidados se usa `grouped_by_subfolder` (`base_path/*/dmY/*`).

- **Scope-aware dedup (owner único)** — Agregar columna `allow_parent_overlap` (boolean, default `false`). El scanner automático computa, para cada storage, los subpaths excluidos (storages hijos con `transcription_enabled=true` cuyo `base_path` es descendiente) y los omite del scan. Además, antes de crear un nuevo `File`, consulta si el `absolute_path` ya está registrado bajo otro storage; si lo está, lo omite sin crear duplicado. El storage más específico gana la propiedad del archivo; el otro storage no procesa ese archivo. Esto se alinea con el modelo downstream: el módulo de alertas externas lee `File.storage_provider_id` y aplica las keywords del cliente asociado — un archivo = un dueño = un set de alertas.

- **Eliminar `transcription_priority`** — DROP COLUMN, limpieza de UI (`<select>` en blade línea 159, badge "P" línea 1036-1037, métodos `savePriority()`), limpieza de validación y endpoints en `ApiTranscriptorController`, simplificación de `ConvertAndTranscribeJob::calculatePriority()` (todos los jobs van a `transcription-low` queue), limpieza en `ScanAndSubmitCommand` y `DiagnosePendingTranscriptionsCommand`. Supervisor pasa a consumir una sola cola.

- **Buscadores manuales también se benefician** — `ApiTranscriptorController::scanStorage`, `processFolder`, `processDay`, `processBatch` llaman todos a `DiskScannerService::scanStorage()`. Como el fix vive en el servicio central, esos endpoints heredan la corrección automáticamente. **Este change los deja documentados en design.md aunque no requiera tocarlos.**

## Capabilities

### Modified Capabilities
- `transcription-disk-scanner`: agrega requisitos de layout-awareness y scope-aware dedup.
- `transcription-api-orchestrator`: remueve requisitos relacionados con `transcription_priority` y selección de cola por prioridad.

## Impact

- **DB schema** — `storage_providers`:
  - `+folder_layout VARCHAR(20) NOT NULL DEFAULT 'flat' CHECK (folder_layout IN ('flat','grouped_by_subfolder'))`
  - `+allow_parent_overlap BOOLEAN NOT NULL DEFAULT false`
  - `-transcription_priority INTEGER` (DROP COLUMN)

- **Seed data** — Setear manualmente `folder_layout='grouped_by_subfolder'` para storage 47 (`01 Emisoras 01`) y storage 49 (`02 Emisoras 01 Reg`). El resto queda en `flat` por default (backward-compatible).

- **Backend scanner**:
  - `app/app/Services/Ia/DiskScannerService.php`:
    - `+computeExcludedSubpaths(StorageProvider $storage): array` — lista de primeros segmentos de path a excluir (descendientes enabled).
    - `+findOwnerByAbsolutePath(string $absolutePath): ?StorageProvider` — JOIN contra `files` + `storage_providers`.
    - `+dayFoldersGrouped(string $basePath, int $daysBack, bool $all): array` — usa `Glob`/`scandir` para encontrar `*/dmY/`.
    - `scanStorage()` refactorizado: elige `dayFolders()` vs `dayFoldersGrouped()` según layout, excluye subpaths, valida `absolute_path` antes de crear `File`.

- **Backend jobs/commands/controllers**:
  - `app/app/Jobs/ConvertAndTranscribeJob.php`: remover `calculatePriority()`, simplificar `dispatchWithPriority()` para siempre usar `transcription-low`.
  - `app/app/Console/Commands/ScanAndSubmitCommand.php`: remover lectura de `$storage->transcription_priority`.
  - `app/app/Console/Commands/DiagnosePendingTranscriptionsCommand.php`: remover `pluck('transcription_priority')`.
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`: remover `transcription_priority` de `select()`, `orderByDesc()`, validación (`'transcription_priority' => 'nullable|integer|min:0'`), endpoint PATCH, fillable.

- **Frontend UI**:
  - `app/resources/views/ia/api-transcriptor/index.blade.php`:
    - `-Línea 159: <select x-model.number="s.transcription_priority" @change="savePriority(s)">`
    - `-Líneas 1036-1037: badge "P" + priority`
    - `-Métodos Alpine.js: savePriority(s)` y referencias en `updateStorageField()` (línea 2046).

- **Supervisor config**:
  - `/etc/supervisor/conf.d/tcloud-transcription-worker.conf`: cambiar `--queue=transcription-high,transcription-medium,transcription-low` → `--queue=transcription` (o mantener solo `transcription-low` y actualizar el dispatch).

- **No requiere** cambios al API externo (192.168.0.138:9000), ni al Redis, ni al sistema de colas.

## Non-goals

- **NO** se migran los 4,580 archivos LA_W ya registrados bajo storage 47 hacia storage 63. El usuario prefiere control manual vía enable/disable. Los archivos históricos mantienen su dueño actual (storage 47) y sus transcripciones/alertas existentes no se re-procesan.
- **NO** se reasignan archivos automáticamente cuando se habilita un storage hijo. La reasignación debe ser manual o se propondrá en un change aparte si surge la necesidad.
- **NO** se toca la latencia del scanner (predictive scan, inotify, reducción de skip de 60s). Eso es un change aparte.
- **NO** se elimina la tabla `transcriptions` ni los archivos `.mp4/.mp3` ya en disco.
- **NO** se modifican las keywords ni la lógica de alertas downstream (`KeywordMatcher`, `AlertDispatcher`).
- **NO** se cambian los endpoints manuales `processFolder`, `processDay`, `processBatch` — heredan el fix automáticamente vía `DiskScannerService::scanStorage()`.