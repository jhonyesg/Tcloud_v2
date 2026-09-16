## Why

La pestaña **Storages** de `/ia/api-transcriptor` tiene dos columnas que muestran exactamente el mismo dato con cabeceras contradictorias:

- Una cabecera "**Snapshot transcriptor**" cuya celda imprime `s.funnel?.pending` (conteo en vivo del funnel, no del snapshot cada 15 min).
- Una cabecera "**Pendientes (hoy)**" justo al lado, que también imprime un conteo del funnel (el mismo número cuando ordenás por `pending`).

La info real del snapshot (delta vs 15 min, **cola remota**, **errores hoy**) sí está disponible, pero vive en la celda que el usuario ve al final, pegada al botón "Ver archivos". El usuario confirmó que esa parte (cola remota + errores hoy) le resulta útil para diagnóstico.

Hoy la tabla es ruidosa y la cabecera "Snapshot transcriptor" es engañosa porque su contenido NO es del snapshot.

## What Changes

- Renombrar la cabecera de la columna 5 de **"Snapshot transcriptor"** a **"Pendientes (live)"**, dejando intacta la celda (`s.funnel?.pending ?? 0` + ⚠ si `shouldWarnPending`). Es un cambio de etiqueta, el dato que muestra sigue siendo el mismo.
- Eliminar la columna **"Pendientes (hoy)"** (cabecera + `td` + `setStoragesSort('pending')` si nadie más la usa). Era duplicado exacto de la columna anterior bajo otra etiqueta.
- Conservar intacta la celda de snapshot detallada al final de la fila (líneas 380-425 de `index.blade.php`): `pending_count`, `delta vs 15min`, **cola remota**, **errores hoy**. Aquí sí se preserva toda la utilidad que el usuario validó.
- Mantener `snapshotErrorsTotal()` (suma reactiva del badge "Errores hoy: N" en el header) tal cual — sigue funcionando porque la celda detallada sigue poblando `$data.snapshotErrors[s.id]`.

**No se toca**: tabla `transcription_storage_snapshots`, cron `transcriptor:storage-snapshot`, endpoint `GET /ia/api-transcriptor/storages/{id}/snapshot`, lógica del pipeline PG (`transcriptions`, worker, watchdog), ni nada del flujo downstream hacia Correcciones / Avisos Inteligentes / Mis Avisos.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- (ninguna — la columna "Snapshot transcriptor" sigue mostrando `s.funnel?.pending`; el cambio es solo de etiqueta y eliminación de duplicado. No hay cambio de comportamiento a nivel de spec.)

Este change es refactor puro de UI (renombrar + eliminar duplicado). Marcar `.openspec.yaml` con `skip_specs: true`.

## Impact

- **Vistas**: `app/resources/views/ia/api-transcriptor/index.blade.php` (cabeceras en líneas ~256-273, td en líneas ~338-345, sort key `'pending'`).
- **JS Alpine inline**: `shouldWarnPending(s)` y `pendingWarningTitle(s)` se mantienen (viven en la columna renombrada, no en la eliminada).
- **Endpoint**: ninguno se modifica. `GET /ia/api-transcriptor/storages/{id}/snapshot` sigue activo para alimentar la celda detallada.
- **BD**: sin migración. Tabla `transcription_storage_snapshots` se sigue poblando cada 15 min.
- **Cron**: sin cambios.
- **Flujo downstream**: intacto. Correcciones, Avisos Inteligentes y Mis Avisos no consumen ni estas cabeceras ni estas celdas, solo el resultado del pipeline PG.
- **Riesgo de UI**: bajo — se eliminan 1 cabecera + 1 td; la paginación/ordenación del resto de columnas (Storage, Cantidad, Tipo, Listos hoy, Prioridad, Transcripción, Acciones) no se ve afectada.
- **Sin migración, sin deploy disruptivo**: cambio de Blade + retoque Alpine, recuperable con `git revert`.

## Non-goals

- No se elimina la tabla `transcription_storage_snapshots`, ni el cron, ni el endpoint `/snapshot`. La celda detallada sigue dependiendo de ellos.
- No se cambia la lógica de warnings (`shouldWarnPending`), ni el badge "Errores hoy" del header.
- No se reorganiza la celda detallada a otro lugar de la fila (esa sería la Opción B del exploration; queda fuera de scope).
- No se aplica el estándar de paginación Mis Avisos — la paginación actual (líneas 195-227) ya cumple con elipsis ±2 al estar basada en `storagesPageList`, así que solo verificamos que no se rompe.
