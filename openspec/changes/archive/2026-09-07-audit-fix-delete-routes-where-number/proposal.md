## Why

Auditando las rutas DELETE que aceptan ids numéricos (mismo patrón que el fix de `/correcciones/{id}`), encontré 4 rutas más vulnerables al mismo TypeError `string → int` que ya se documentó en el log de Laravel 2026-09-06 11:34:40 y 11:37:28. Todas estas rutas pasan el id como `int $id` al controller pero NO declaran `->whereNumber('id')` en la declaración de ruta, así que un cliente que envíe un id no numérico explota con 500 en vez de recibir un 404 limpio.

## What Changes

- `app/routes/web.php` línea 116: `Route::delete('/papelera/{file}', ...)` → agregar `->whereNumber('file')`.
- `app/routes/web.php` línea 187: `Route::delete('/api-transcriptor/jobs/{id}', ...)` → agregar `->whereNumber('id')`.
- `app/routes/web.php` línea 235: `Route::delete('/avisos-inteligentes/{userId}/keywords/{keywordId}', ...)` → agregar `->whereNumber('userId')->whereNumber('keywordId')`.
- `app/routes/web.php` línea 345: `Route::delete('/mis-avisos/keywords/{keywordId}', ...)` → agregar `->whereNumber('keywordId')`.

## Capabilities

### New Capabilities
- `ia-routing`: las rutas DELETE con ids numéricos MUST validar el constraint `whereNumber` en el nivel de ruta para que un id inválido retorne 404 limpio en lugar de 500.

## Impact

- `app/routes/web.php` (4 líneas modificadas).
- Sin migraciones, sin cambios de controller, sin cambios de UI.

## Non-goals

- Auditar rutas PATCH/PUT con el mismo patrón (no hay reportes de TypeError en logs para esas).
- Auditar rutas con route-model binding (`{user}`, `{storage}`, `{session}`) — Laravel las resuelve antes del controller.
- Auditar la ruta `/correo/plantillas/{plantilla}` (línea 62) — usa route-model binding; necesita verificación manual.
