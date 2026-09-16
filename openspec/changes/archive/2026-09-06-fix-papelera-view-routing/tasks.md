## 1. Mover la vista Blade a la ubicación descubrible

- [x] 1.1 Copiar `app/app/Modules/Papelera/resources/views/index.blade.php` → `app/resources/views/papelera/index.blade.php` (verificar que el contenido se preserva idéntico, incluyendo las directivas `@extends('layouts.app')` y `@section('content')`).
- [x] 1.2 Eliminar el archivo original `app/app/Modules/Papelera/resources/views/index.blade.php` (dejar copia única en la nueva ubicación).
- [x] 1.3 Verificar que `view('papelera.index')` resuelve con `php -r "require 'vendor/autoload.php'; ..."` o `php artisan view:cache` (debe compilar sin error).

## 2. Controller dual-mode (HTML al navegador, JSON a Alpine)

- [x] 2.1 En `PapeleraController::index`, agregar rama JSON-only para `$request->expectsJson()` o `$request->ajax()` que mantenga el `response()->json([items, pagination])` actual (sin cambios funcionales, ya estaba).
- [x] 2.2 Al final del método `index()` (caso navegación normal), retornar `return view('papelera.index');`.
- [x] 2.3 Refinar el guard de `$user` no encontrado: si el request es JSON → 401 JSON, si es HTML → redirect a `/login` (mismo patrón que `FileController::index:43-47`).
- [x] 2.4 Verificar que los métodos `restore`, `destroy`, `empty` siguen siendo JSON-only (POST/DELETE sin cambios).

## 3. Verificación y limpieza

- [x] 3.1 `php artisan view:clear` (re-compila el caché de Blade con la nueva ubicación).
- [x] 3.2 Confirmar que `app/app/Modules/Papelera/resources/views/` ya no contiene el archivo viejo.
- [x] 3.3 Sintaxis: `php -l` sobre el controller modificado.

## 4. Validación end-to-end con Playwright

- [x] 4.1 Escribir `tests/playwright_papelera_view.py` (o `.js`): login con usuario de prueba → navegar a `/papelera` → capturar screenshot → assert que el HTML contiene el sidebar (`a[href="/papelera"]`) y NO contiene la cadena literal `{"items":` en el body visible.
- [x] 4.2 Validar la rama JSON: con Playwright, ejecutar `page.evaluate("fetch('/papelera', {headers: {Accept: 'application/json'}}).then(r => r.json())")` y verificar que devuelve `{items: [...], pagination: {...}}`.
- [x] 4.3 Flujo borrar-desde-archivos-y-ver-en-papelera: desde el explorador de archivos, eliminar un item → navegar a `/papelera` → confirmar que el item aparece en la tabla renderizada.
- [x] 4.4 Capturar screenshot de `/papelera` con items (papelera no vacía) para evidencia visual.
- [x] 4.5 Si Playwright detecta fallos, registrar la falla y corregir antes de marcar las tareas como completas.

## 5. Rollback si la validación falla

- [x] 5.1 Si la validación 4.x falla por motivos ajenos a este cambio (ej: el login de prueba no funciona por cambios no relacionados), documentar el síntoma y NO bloquear este cambio. El fix de wiring es independiente.

## Resultados de validación

- `papelera_02_index.png`: empty state con sidebar y chrome pintados correctamente
- `papelera_04_with_items.png`: archivo `red_06092026_110202.mp3` aparece tras eliminación manual desde /ingresos
- 11/11 aserciones Playwright pasaron (login, HTML response, AJAX JSON, sidebar, /files navigation)
- `php -l` y `php artisan view:cache` exit 0

