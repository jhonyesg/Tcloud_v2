## Why

La pestaña "Storages" del módulo API Transcriptor (`/ia/api-transcriptor`, blade `resources/views/ia/api-transcriptor/index.blade.php:275-319`) muestra actualmente solo cuatro columnas — Nombre, Tipo, Toggle de Transcripción y Acciones — sin ningún conteo. El operador no puede saber cuántos archivos tiene cada storage pendientes de transcribir hoy, cuántos ya están listos hoy, ni cuántos medios (storages hijos) cuelgan de un padre regional. Para medios como "Emisoras 01 Reg" que heredan hijos como Antioquia/Caracol/Atlántico, la ausencia de números impide detectar omisiones del scanner o del dispatcher. La columna `transcription_priority` fue re-añadida en `2026_09_08_000000_restore_transcription_priority_to_storage_providers` (la migración `2026_07_18_120000` la había eliminado en su `up()`).

## What Changes

- Añadir tres columnas a la tabla Storages: **Cantidad** (1 + descendientes por prefijo de `base_path`, sin importar `transcription_enabled`), **Pendientes (hoy)** y **Listos (hoy)** — conteos derivados de `transcriptions` filtrados por `created_at` en timezone Bogota del día actual.
- Mostrar **Emisoras 01** (parent heredado por prefijo de `base_path`) con la suma agregada de los miembros de su scope (`StorageProvider::resolveInheritedTranscriptionScope`), y permitir plegar/desplegar las filas hijas con sangría.
- Exponer `transcription_priority` como columna visible solo-lectura en la tabla (no editable en esta entrega; muerta en BD hasta esta entrega).
- Agregar un badge ⚠ en la celda Pendientes cuando el conteo supere el umbral configurable (default 5) vía `system_settings.transcriptor_pending_alert_threshold`.
- Cachear los conteos con TTL de 60 segundos por scope (`Cache::remember("transcriptor.funnel.scope.{id}", 60, ...)`), invalidando al togglear `transcription_enabled`. Uso del operador `+` (no `array_merge`) para preservar las claves numéricas de storage_id.

## Capabilities

### New Capabilities
- `transcriptor-storage-funnel`: conteos por storage (Pendientes / En curso / Listos) en la pestaña Storages del módulo API Transcriptor, con agregación correcta para padres heredados y cache de 60 s.

### Modified Capabilities
- (ninguno) — `transcriptor-state-visibility` cubre conteos globales por estado en la pestaña Trabajos, no por storage en Storages.

## Impact

- Controllers: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (métodos `indexData` líneas 175-198 y `stats` líneas 1577-1586).
- Views: `app/resources/views/ia/api-transcriptor/index.blade.php` (cabecera líneas 277-282, cuerpo 285-319, Alpine component `apiTranscriptor()` línea 1773).
- Model: `app/app/Models/StorageProvider.php` (reuso de `resolveInheritedTranscriptionScope`, helper nuevo `funnelCountsForScope(int $rootId): array`).
- Service nuevo opcional: `app/app/Services/Ia/StorageFunnelService.php` con la query agregada y el cache.
- Settings: lectura de `transcriptor.storages.pending_alert_threshold` desde `system_settings` (default 5, sin migración nueva — se crea con `Cache::rememberForever` la primera vez).
- Sin migración de BD. Sin cambios al dispatcher (`TranscriptionTickCommand`) ni al scanner (`DiskScannerService`). Sin endpoints nuevos en esta entrega.

## Non-goals

- Editar `transcription_priority` desde la UI (futuro).
- Mostrar la posición FIFO global por storage (futuro).
- Botón "reenviar omitidos" o "forzar escaneo" desde la fila (futuro).
- Modificar `ORDER BY` del tick o del scan.
- Filtros por `mtime` cutoff en los conteos: se muestran números crudos de la BD tal cual.
