## Why

El módulo Mis Archivos (`/files`) ejecuta un `silentSync()` después de cada navegación exitosa que dispara una request al backend con `?sync=1` para mantener la lista sincronizada con disco, **sin** `AbortController` y **sin** validar que el folder/almacenamiento al que la response corresponde siga siendo el que el usuario está viendo. Cuando el usuario navega rápido entre carpetas (X→Y→X→Z→X), múltiples `silentSync` quedan corriendo en background y pueden llegar fuera de orden: un sync de Y o Z vuelve después de que el usuario volvió a X y pisa `this.files`, mostrando contenido de otra carpeta o dejándola vacía.

## What Changes

- `files/index.blade.php` agrega un contador de generación `_navGen` que se incrementa en cada navegación (`navigateToFolder`, `navigateToBreadcrumb`, `goToStorageRoot`, `enterStorage`, `restoreNavState`, `navigateToRoot`, `refreshFiles`, inicio de `loadFiles` y `loadMore`).
- `silentSync()` captura `(currentFolder, currentStorage, myGen)` al arrancar la request. Cuando llega la response, descarta los datos si `_navGen !== myGen` o si `currentFolder/currentStorage` cambiaron.
- Misma guarda (captura + validación al retornar) se aplica a `loadFiles()` y `loadMore()` por consistencia — defense in depth.
- `loadFiles` mantiene su `AbortController` existente; la guarda de `_navGen` cubre el caso de requests que ya no se pueden abortar pero cuyo resultado llegaría tarde.

## Non-goals

- No tocar el backend (`FileController::index`). El race es 100% frontend; el server responde correctamente a cada request individual.
- No introducir AbortController nuevo en silentSync (la guarda de generación es suficiente y menos invasiva).
- No cambiar el contrato HTTP del endpoint `/files`.
- No tocar el cache de `folder_listing:*` / `folder_gen:*` del backend.

## Capabilities

### New Capabilities

- `files-folder-context-staleness-guard`: garantiza que cualquier response que alimente `this.files` (vía `silentSync`, `loadFiles` o `loadMore`) corresponda a la carpeta + almacenamiento que el usuario está viendo en el momento en que la response arriba. Responses obsoletas se descartan silenciosamente (no toast, no error visible) porque el reemplazo natural por la siguiente navegación correcta es el comportamiento esperado.

### Modified Capabilities

- (vacío — `navigation-state-persistence` trata del localStorage, no del comportamiento de requests en vuelo)

## Impact

- **Archivo único**: `app/resources/views/files/index.blade.php` (Alpine store `fileManager`).
- Sin migración, sin cambio de rutas, sin cambio de contratos.
- Riesgo bajo: la guarda es aditiva; si por algún motivo `_navGen` no se incrementa, el comportamiento es idéntico al actual (no rompe nada).
- Compatibilidad: el contador es estado interno de Alpine — no afecta a otras pestañas, otros módulos ni otros usuarios.
