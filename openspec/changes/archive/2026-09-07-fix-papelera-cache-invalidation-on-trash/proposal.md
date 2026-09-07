## Why

`FileController@destroy` invalida el cache del listado del **padre original** del archivo trashado, pero no invalida el cache del **root listing** del storage cuando el archivo trashado salta de una subcarpeta a `parent_id=NULL`. Resultado: la query `whereNull('parent_id')` que sirve el root listing puede devolver la fila recién trashed durante toda la ventana TTL del cache (60s para root en `FileController@index:257-258`). El usuario ve items fantasma en `/files` (root del storage) justo después de borrar.

## What Changes

- En `FileController@destroy`, después de la invalidación existente del padre original, agregar una segunda invalidación del root listing (`parent_id = null`) del mismo storage cuando el archivo trashado quedó con `parent_id=NULL`.
- Sin cambios en `PapeleraService`, sin cambios de rutas, sin migraciones, sin cambios en queries.

## Capabilities

### Modified Capabilities
- `trash-module`: el listado del root de un storage (`whereNull('parent_id')`) MUST reflejar el trash inmediatamente (sin esperar al TTL del cache de 60s) una vez que un archivo trashado se mueve a `parent_id=NULL`.

## Impact

- `app/app/Http/Controllers/FileController.php` (modifica `destroy` — agrega segunda invalidación de cache).
- Sin migraciones, sin cambios de servicio.

## Non-goals

- Añadir un filtro explícito `where('is_trashed', false)` a `FileController@index`. Esa sería una defensa más profunda pero más invasiva; este fix cierra la ventana TTL sin tocar queries.
- Mover la invalidación al `PapeleraService::softTrash`. La invalidación cruzada entre controller y service es responsabilidad del controller (que es quien conoce el contexto de UI); el service se mantiene agnóstico al cache de listado.
- Invalidar caches de otros storages distintos al del archivo. Solo el storage afectado.
