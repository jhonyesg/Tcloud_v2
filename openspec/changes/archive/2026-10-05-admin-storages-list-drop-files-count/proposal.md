## Why

El módulo `/admin/storages` ("Gestionar Storages") tarda entre **625 ms y 1.8 s** en pintar su listado, y en ocasiones se queda en blanco y obliga al operador a recargar. La causa es que el registro de storage es minúsculo (196 filas), pero el endpoint le adjunta la columna "Archivos" vía `StorageProvider::withCount('files')` (`app/Http/Controllers/StorageProviderController.php:35`). Ese `withCount` dispara **196 conteos correlacionados** contra la tabla `files`, que tiene **1.367.803 filas / 2.5 GB** (`SELECT count(*) FROM files` tarda 176 ms por sí solo; el `SELECT` con los 196 subqueries tarda 1.78 s en frío). El dato "Archivos" no aporta al listado — es puramente informativo — y es el **90% del tiempo de carga**:

| Medición live (2026-10-05) | Tiempo |
|---|---|
| 1) `SELECT * FROM storage_providers` (crudo) | **7 ms** |
| 2) Eloquent `get()` sin count | **44 ms** |
| 3) `withCount('files')` (endpoint HOY) | **625 ms** |
| 4) Cache hit (Redis, TTL 60s) | 1 ms |

El operador confirmó que el estado/columna "Archivos" no es útil en este listado y genera la carga: se elimina.

## What Changes

- **`admin-storages-index-perf`** (MODIFIED): `GET /admin/storages` (AJAX) SHALL devolver la lista de storages **sin** el atributo derivado `files_count`. El endpoint deja de tocar la tabla `files`. Presupuesto de respuesta: `< 100 ms` en caliente de proceso, `< 250 ms` en frío.
- **Backend** — `StorageProviderController::computeAdminStorages()`: cambiar `StorageProvider::withCount('files')->get()->toArray()` por `StorageProvider::get()->toArray()`. El endpoint `show()` (`GET /admin/storages/{id}`) **conserva** `withCount('files')` para que el modal de borrado pida el conteo puntual bajo demanda (1 storage ≈ 40 ms, no 196).
- **Frontend** — `app/resources/views/admin/storages.blade.php`: eliminar la columna "Archivos" de la tabla de escritorio (encabezado ordenable + celda), la mención `· N archivos` de la tarjeta móvil, y el uso de `deletingStorage.files_count` en el modal de confirmación de borrado (reemplazado por un fetch bajo demanda a `GET /admin/storages/{id}`).
- **`storage-table-sortable`** (MODIFIED): el conjunto de columnas ordenables deja de incluir "Archivos".
- **`storage-admin-modal-ui`** (MODIFIED): el aviso de borrado destructivo obtiene el conteo de archivos bajo demanda; mientras no esté disponible muestra un texto genérico ("los archivos asociados a este storage"), nunca un número inventado.
- **Endurecimiento secundario (incluido, mínimo)** — `loadStorages()` en la vista gana un flag `loadingStorages` y un toast de error: si el fetch falla, el operador ve el motivo en vez de un listado vacío silencioso ("No hay storages configurados"). Cierra el síntoma reportado "se queda así y me toca recargar".

## Capabilities

### Modified Capabilities
- `admin-storages-index-perf`: el listado de admin storages deja de computar `files_count`; el conteo se resuelve bajo demanda solo donde se necesita.
- `storage-table-sortable`: la columna "Archivos" sale del conjunto ordenable.
- `storage-admin-modal-ui`: el modal de borrado obtiene el conteo de archivos bajo demanda con fallback textual.
- `admin-storages-loading-state`: el estado de carga del listado inicial (no solo de acciones destructivas) SHALL ser explícito.

## Impact

**Código afectado**:
- `app/app/Http/Controllers/StorageProviderController.php` (~6 líneas: quitar `withCount` en `computeAdminStorages`, actualizar comentario de líneas 19-21; `show()` intacto).
- `app/resources/views/admin/storages.blade.php` (~35 líneas: quitar th/td de Archivos, quitar mención móvil, helper `openDeleteModal` + `loadDeleteCount`, flag `loadingStorages`, toast de error).
- Sin migración de BD, sin cambio de schema, sin tocar workers ni cron.

**Riesgos**:
- (R1) El modal de borrado muestra un instante "Calculando archivos asociados…" antes del número. Mitigación: fallback textual si el fetch falla o tarda; nunca bloquea el botón de borrar.
- (R2) La caché `admin:storages:index` (TTL 60 s) sigue existiendo pero ahora su justificación de rendimiento desapareció (frío = ~44 ms). No se retira en este change para no ampliar el blast radius; queda como follow-up. Mitigación: el comentario del controlador se actualiza para no mentir sobre el motivo.
- (R3) Algún consumidor externo que dependa de `files_count` en el payload del listado. Mitigación: grep verificado — el único consumidor es la propia vista `admin/storages.blade.php` (líneas 502, 572, 599, 780).

**Rollback**: `git revert <commit>` restaura `withCount` y la columna; el listado vuelve al comportamiento previo. Sin estado persistente que limpiar.

## Non-goals

- **No se retira la caché** `admin:storages:index` ni sus puntos de invalidación (`toggleStorage`, `StorageRemapDisk`). Optimización separada; el endpoint ya es rápido sin ella.
- **No se implementa un contador mantenido** en `storage_providers.files_count` (observer/trigger). El conteo exacto se resuelve bajo demanda donde importa.
- **No se pagina/servidor-filtra** el listado: los 196 registros siguen trayéndose completos y filtrados en cliente (44 ms lo permite). Un cambio de paginación server-side es otro workstream.
- **No se toca** `show()` para recortar campos sensibles de `config` (los storages S3 no existen hoy en producción: 0 filas con credenciales). Hardening de payload es follow-up.
