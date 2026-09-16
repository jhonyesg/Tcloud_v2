## Why

`GET /papelera` siempre responde con JSON crudo (`{"items":[],"pagination":{...}}`) incluso cuando el navegador pide HTML. El usuario lo ve como un dump en pantalla en vez de la vista Blade maqueteada. Causa: `PapeleraController@index` solo tiene `return response()->json(...)` y la vista Blade vive en `app/app/Modules/Papelera/resources/views/` — una ubicación que el auto-discovery de Blade no escanea, así que ni siquiera es renderizable. Necesitamos cablear la vista en su ubicación estándar y darle al controller el patrón dual-mode (HTML al navegador, JSON a Alpine).

## What Changes

- Mover `app/app/Modules/Papelera/resources/views/index.blade.php` → `app/resources/views/papelera/index.blade.php` (ubicación descubrible por Blade, alineada con la convención del proyecto: `files/`, `shares/`, `sites/`, `ia/`, `grabaciones_puntuales/`).
- `PapeleraController@index` adopta el patrón dual-mode: devuelve `view('papelera.index')` cuando el request es navegación normal (sin header `Accept: application/json` ni `X-Requested-With: XMLHttpRequest`); devuelve `response()->json(...)` cuando Alpine pide JSON (los endpoints `restore`, `destroy`, `empty` ya son JSON-only y no cambian).
- Sin migraciones nuevas. Sin cambios a `PapeleraService`. Sin cambios a las rutas (ya están en `app/routes/web.php:113-117`).

## Capabilities

### Modified Capabilities
- `trash-module`: la vista `/papelera` ahora devuelve HTML maqueteado en lugar de JSON. El contrato JSON permanece para los consumers AJAX (Alpine).

## Impact

- `app/app/Modules/Papelera/Http/Controllers/PapeleraController.php` (modifica `index`).
- `app/resources/views/papelera/index.blade.php` (nuevo, movido desde `Modules/Papelera/resources/views/`).
- `app/app/Modules/Papelera/resources/views/index.blade.php` (eliminar, queda redundante).
- Sin migraciones, sin cambios de servicio, sin cambios de rutas.

## Non-goals

- Rediseño visual de la maqueta (banner de urgentes, breadcrumb de ubicación original, responsive tabla→cards, confirmación doble para vaciar). Queda como follow-up `papelera-ui-polish`.
- Creación del comando `php artisan trash:purge` y scheduler diario (tasks 5.3-5.4 del change archivado `2026-09-06-papelera-reciclaje`).
- Auditoría de `FileController@destroy` → `PapeleraService::softTrash` (marcado done, no se toca).
- Verificación de `PublicShareController` → 410 Gone para trashed (marcado done, no se toca).
