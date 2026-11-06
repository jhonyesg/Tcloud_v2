## Why

El operador `jsuarez` reportó que al crear una carpeta desde el modal "Nueva Carpeta" de Mis Archivos, la operación falla con un alert nativo del navegador mostrando literal `Error al crear carpeta: 500` (verificado en `app/storage/logs/laravel.log:17160`, hoy 2026-10-01 13:01:24). El stack trace apunta a `FileController.php:303` con `mkdir(): Permission denied`. La causa raíz es **medioambiental**: PHP-FPM corre como `www:www` pero algunas raíces de storage están `drwxr-xr-x root root 755` (ej. `Tcloud/Disco_I/`), así que `mkdir()` no puede crear la carpeta. Pero el síntoma UX tiene una segunda capa: PHP convierte el `E_WARNING` de `mkdir()` en `ErrorException` ANTES del guard `if (!mkdir(...))` que existe en `FileController.php:303-305`, así que el cliente recibe `{"message":"Server Error"}` genérico en vez del JSON detallado `{"error":"No se pudo crear el directorio"}` que el método sí sabe devolver. El frontend entonces cae al fallback `res.status = 500`. Sumado a esto, el módulo Mis Archivos sigue usando `alert()` nativos en dos sitios (`files/index.blade.php:858` rotación de imagen, `files/index.blade.php:908` crear carpeta) cuando **el mismo componente Alpine ya tiene un helper `this.showToast()` operativo** (`files/index.blade.php:1071-1075`) que usa 16+ veces para el resto de errores del módulo. La preferencia de UI del proyecto (`ui_alerts_prefer_toasts` en AGENTS.md) pide reemplazar alerts por toasts como regla general.

## What Changes

- **`mis-archivos-new-folder-error-contract`** (nueva capability): el endpoint `POST /files` cuando crea una carpeta SHALL devolver un JSON con campo `error` semántico y HTTP apropiado (500 con detalle, 403 si el usuario no puede escribir en ese storage, 409 si ya existe una carpeta con ese nombre en el mismo padre). El cliente SHALL leer `data.error || data.message || ("HTTP " + res.status)` en orden de preferencia para mostrar mensajes útiles.
- **Guard defensivo de `mkdir()`** en `FileController::store` líneas 295-307: pre-check `is_writable(dirname($physicalPath))` antes del `mkdir()`, y `try/catch (\ErrorException $e)` envolviendo el `mkdir()` para que el warning de PHP no escale a excepción HTTP. Mismo patrón aplicado a `PublicShareController::createFolder` línea 531 (mismo bug latente, misma causa).
- **Conversión de los 2 alerts** del módulo Mis Archivos a toast no-bloqueante usando el helper `this.showToast(msg, type, ms)` ya existente, sin introducir infraestructura nueva:
  - `files/index.blade.php:858` — rotación de imagen
  - `files/index.blade.php:908` — crear carpeta (caso del reporte)
- **Runbook operativo** (sección nueva en AGENTS.md) para alinear ownership de raíces de storage con el usuario de PHP-FPM, con verificación previa de salud del módulo y rollback explícito.

## Capabilities

### New Capabilities
- `mis-archivos-new-folder-error-contract`: contrato de error del flujo "Nueva Carpeta" en Mis Archivos — pre-condiciones del filesystem, semántica de la respuesta JSON del backend, y mensaje toast no-bloqueante en el cliente.

### Modified Capabilities
- `permission-aware-file-controls`: el chequeo de permiso de escritura SHALL ejecutarse antes del `mkdir()` y SHALL mapear a HTTP 403 con `{"error":"..."}` específico cuando falle (sin escritura en almacenamiento).
- `file-permission-checks`: el helper centralizado `User::hasStoragePermission()` SHALL invocarse al inicio de `FileController::store` para que rechace carpetas en storages donde el usuario no tiene `permissions='write'` o `'full'`, con JSON error claro.

## Impact

**Código afectado**:
- `app/app/Http/Controllers/FileController.php` (modificación ~30 líneas: `is_writable` pre-check, `try/catch` sobre `mkdir`, llamada a `User::hasStoragePermission()`, mensajes JSON específicos)
- `app/app/Http/Controllers/PublicShareController.php` (modificación ~10 líneas: mismo `try/catch` en `createFolder` línea 531)
- `app/resources/views/files/index.blade.php` (modificación 4 líneas: dos `alert(...)` → `this.showToast(..., 'error', 5000)`)
- `app/AGENTS.md` (sección nueva "Runbook: permisos de storage y creación de carpetas" con comandos `ls -la`, `chown`, verificación post-fix)

**Riesgos**:
- (R1) Cambiar ownership de carpetas storage en producción (`chown -R www:www ...`) puede romper scripts de administración que esperan `root`. Mitigación: documentar en runbook, ofrecer alternativa de grupo escribible (`chmod g+w` + `usermod -aG wwwroot www`) si el operador lo prefiere.
- (R2) `try/catch` sobre `mkdir` puede ocultar bugs reales de configuración (ej. tmpfs lleno). Mitigación: loguear `mkdir.failed` con contexto (`path`, `errno`, `storage_id`) antes de devolver el 500 para que el operador pueda diagnosticar.
- (R3) Cambiar alerts a toast cambia la UX para un usuario acostumbrado al bloqueo nativo. Mitigación: el toast es visualmente equivalente (fondo rojo, esquina inferior centro), aparece al mismo tiempo y muestra el mismo texto — solo cambia que la página no se congela detrás.

**Sin migración de BD**, sin cambio de schema, sin migración requerida. No toca workers supervisord ni el cron del transcriptor.

**Rollback**: `git revert <commit>` restaura los `alert()` y el `mkdir()` desnudo; el comportamiento queda idéntico al pre-fix. El chown operativo se revierte con `chown -R root:root ...` si fuera necesario.

## Non-goals

- **No se consolida la infraestructura de toast global** (`window.showToast` en `layouts/app.blade.php`). Cada vista seguirá con su propio helper local; ese es un cambio aparte que afecta 5+ vistas y 55 alerts adicionales, fuera de scope.
- **No se tocan los `mkdir()` de upload** (`FileController.php:432`) ni los de `MediaClipController`, `TranscriptionSubmitService`, etc. — solo los dos sitios identificados (`store` crear carpeta y `PublicShareController::createFolder`). Se documentan como follow-up si aparecen reportes similares.
- **No se cambia el árbol DOM ni los selectores CSS** del modal "Nueva Carpeta"; solo el handler de error.
- **No se modifica el breadcrumb ni la cadena `parentChain`** que el change `fix-mis-archivos-breadcrumb-duplicates` está arreglando — eso es otro workstream activo.
