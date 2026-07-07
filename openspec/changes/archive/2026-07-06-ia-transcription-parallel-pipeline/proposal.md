## Why

El módulo de transcripción está operativo (79/90 tareas del change `ia-transcription-module` completadas) pero tiene cuellos de botella y riesgos de diseño que limitan su escalabilidad:

1. **Procesamiento secuencial**: `scan-new` y `process-batch` ejecutan jobs síncronamente (uno a la vez). Con 40 cores disponibles, solo se usa 1 core por archivo (ffmpeg de audio es single-thread). Un lote de 50 archivos tarda ~8 min; con 10 workers paralelos sería ~50s.

2. **Sin separación auto vs manual**: `scan-new` toma los archivos más recientes sin transcripción de TODO el storage. Si se habilita un storage con 30 días de histórico (~4,320 archivos), el scanner empieza a procesar archivos viejos generando alertas de hace un mes que ya no importan. El usuario quiere que el auto-procesamiento solo toque lo de HOY, y el histórico sea manual y selectivo.

3. **Jobs colgados sin recuperación**: `Transcription::firstOrCreate` crea el registro con `state=queued` antes de que el POST a la API devuelva el `job_id`. Si el proceso muere, el registro queda `queued` sin `job_id` y nadie lo recupera (scan-stale solo mira jobs con `job_id`).

4. **Sin selección granular manual**: El usuario quiere poder procesar por carpeta específica o por día, no solo por lote global.

5. **Conversión escribe en disco**: Los archivos Opus temporales se escriben en disco, desgastando la vida útil. Hay 20GB de RAM disponible via tmpfs (`/dev/shm`).

## What Changes

### Separación auto vs manual
- **`scan-new` (automático, cada 2 min)**: solo procesa archivos de HOY (`file_modified_at >= today 00:00`), batch pequeño (5), genera alertas siempre
- **`process-batch` (manual, background)**: lote global con límite configurable (5-200), distribuido por prioridad de storage, corre en proceso separado, alertas opcionales (checkbox en UI)
- **Procesar carpeta/día (manual, nuevo)**: desde el navegador de archivos, botón "Procesar carpeta" o "Procesar día" que encola solo esos archivos

### Workers paralelos con prioridad
- **10 queue workers** via supervisor procesando la cola Redis
- Jobs encolados con `priority` = `storage_priority * 10 + (hoy ? 100 : 0) + (manual ? 5 : 0)`
  - Archivos de hoy con storage priority alta → se procesan primero
  - Histórico manual → prioridad más baja
- `ConvertAndTranscribeJob` vuelve a usar `dispatch()` (no ejecución síncrona por reflection)
- Supervisor config mantiene los 10 workers vivos

### Conversión en RAM
- `ConvertAndTranscribeJob` y `AudioConverter` escriben archivos Opus temporales en `/dev/shm` (tmpfs, 20GB RAM) con fallback a `sys_get_temp_dir()`

### Recuperación de colgados
- Nuevo estado `pending` en `Transcription` (antes de ffmpeg/POST, sin `job_id`)
- `scan-stale` ampliado: recupera jobs `pending` sin `job_id` con >5 min de antigüedad (reencola)
- Jobs Redis con `timeout` → si un worker muere, el job vuelve a la cola automáticamente

### Alertas opcionales por origen
- `scan-new` (auto): siempre genera alertas (es lo de hoy)
- `process-batch` / carpeta / día (manual): checkbox "Generar alertas" en la UI, default OFF
- `ConvertAndTranscribeJob` acepta parámetro `generateAlerts` (bool); si false, `TranscriptionProcessor` omite `KeywordMatcher::run()`

### UI: selección granular
- Botón "Procesar carpeta" en el navegador de archivos (encola todos los archivos de la carpeta actual)
- Botón "Procesar día" en modo HOY/AYER (encola los archivos visibles)
- Modal de lote con checkbox "Generar alertas" (default desmarcado)

## Capabilities

### Modified Capabilities

- `transcription-api-orchestrator`:
  - scan-new solo procesa archivos de HOY (no histórico)
  - Workers paralelos (10) con prioridad en cola Redis
  - Conversión en RAM (tmpfs)
  - Recuperación de jobs colgados (estado `pending`)
  - Selección granular manual (carpeta/día)
  - Alertas opcionales por origen (auto=siempre, manual=opcional)

## Impact

- **Modelos modificados**: `Transcription` (nuevo estado `pending`, nuevo campo `generate_alerts` boolean)
- **Migración nueva**: añadir `generate_alerts` boolean default true a `transcriptions`
- **Jobs modificados**: `ConvertAndTranscribeJob` (acepta `generateAlerts`, vuelve a `dispatch()`, tmpfs)
- **Commands modificados**: `ScanNewRecordingsCommand` (solo hoy, dispatch), `ScanStaleJobsCommand` (recupera pending sin job_id), `ProcessBatchCommand` (dispatch con prioridad, alertas opcionales)
- **Servicios modificados**: `AudioConverter` (tmpfs), `TranscriptionProcessor` (respeta `generate_alerts`)
- **Controladores modificados**: `ApiTranscriptorController` (processFolder, processDay, toggleAlerts en batch)
- **Frontend modificado**: `index.blade.php` (botones carpeta/día, checkbox alertas en lote)
- **Infra nueva**: supervisor config para 10 queue workers
- **Rutas nuevas**: `POST /api-transcriptor/storages/{id}/process-folder`, `POST /api-transcriptor/storages/{id}/process-day`

## Non-goals

- No se implementa balanceo multi-nodo del transcriptor (sigue single-node, D7 del design original)
- No se cambia el modelo de alertas (coalescing por usuario/transcripción se mantiene)
- No se añade UI para ver progreso de workers individuales (solo progreso de lote)
- No se procesan archivos futuros a HOY (modo "mañana" no aplica)
- No se automatiza el procesamiento de histórico (siempre requiere acción manual del usuario)