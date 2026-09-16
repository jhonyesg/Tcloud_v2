# fix-storages-tab-cards-and-dead-retry-button

## Why

El operador del tab Storages (`/ia/api-transcriptor`) reportó (2026-09-15) que las tarjetas resumen son ruido o engaño: "Pendientes hoy" mezcla `error|dead` con `pending` real (no es la cola), "Listos hoy" no acciona nada, y el botón "Reintentar fallidos (upstream batch)" está **roto** — hace `POST /ia/api-transcriptor/retry-batch` a una ruta eliminada en el change `simplify-api-transcriptor-to-storage-and-config` (commit `1c9d690`), así que responde 404 en silencio (abre pestaña nueva con error). El dato real de errores ya existe: `transcription_storage_snapshots.error_count` se captura cada 15 min y hoy no se muestra en ningún lado de la UI.

## What Changes

- **REMOVED** tarjetas "Pendientes hoy" y "Listos hoy" (grid de 4 tarjetas → 2): sus datos (funnel diario de `StorageFunnelService::countsForScope`) eran engañosos/inútiles para el operador. La columna per-storage "Pendientes (hoy)" / "Listos (hoy)" de la tabla y su orden se mantienen intactos (especificadas en `transcriptor-storage-funnel`).
- **REMOVED** botón muerto "Reintentar fallidos (upstream batch)" del header de la tabla. La funcionalidad real vive en el cron semanal `transcription:retry-batch-upstream` (lunes 04:00, `routes/console.php:111`) y en el CLI directo; la UI rota solo confunde.
- **ADDED** columna "Errores" por storage en la tabla, alimentada por `transcription_storage_snapshots.error_count` (mismo endpoint `GET /storages/{id}/snapshot` que ya se llama para "Snapshot transcriptor": cero endpoints nuevos, cero queries nuevas).
- **ADDED** contador global "Errores hoy" en el header de la tabla, sumando los snapshots visibles — reemplaza funcionalmente el "Pendientes hoy" irreal por el número que sí revisa el operador.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `transcriptor-storage-funnel`: la spec ya no exige tarjetas resumen con funnel diario; los conteos viven SOLO en las columnas por storage de la tabla (requerimiento de "Pestaña Storages muestra conteos Pendientes, En curso y Listos" y "Badge de alerta en columna Pendientes" se ajustan a la realidad post-`simplify`), y se agrega el requerimiento de la columna "Errores" desde snapshots.

## Impact

- **Solo UI**: `app/resources/views/ia/api-transcriptor/index.blade.php` (tarjetas, botón del form, columna y JS de agregación de errores). 
- **Sin migración, sin rutas nuevas, sin cambios de controller**: `storageSnapshot()` ya devuelve `error_count` (y `inflight_count`) en el payload `current`.
- El cron semanal de retry y el comando `transcription:retry-batch-upstream` no se tocan.
- `StorageFunnelService::countsForScope()` sigue existiendo: la tabla (columnas Pendientes/Listos) sigue consumiéndolo.

## Non-goals

- No se reviven las pestañas Trabajos/Consumo (retiradas en `simplify-api-transcriptor-to-storage-and-config`).
- No se cambia el cron semanal ni se agregan botones de reintento (la revisión de fallidos del día se hace desde la columna de errores + CLI).
- No se retira `StorageFunnelService` (lo usa la tabla; el desacoplamiento completo es otro cambio).