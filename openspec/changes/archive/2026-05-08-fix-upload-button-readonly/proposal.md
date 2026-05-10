## Why

Cuando un admin comparte un storage con permisos de solo lectura (`read`), el usuario destino ve los botones "Subir Archivo", "Nueva Carpeta" y el área de drag-and-drop en el módulo "Mis Archivos". Al intentar usarlos, el backend responde con un error 403. Esto genera confusión y una mala experiencia de usuario. El sistema de permisos ya existe en el backend y en la API — el problema es que el frontend no los usa para condicionar la visibilidad de estos controles.

## What Changes

- Añadir una variable reactiva `currentStoragePermission` al componente Alpine.js `fileManager` que almacene el nivel de permiso del storage activo.
- Modificar `enterStorage()` para buscar y guardar el permiso del storage seleccionado desde `availableStorages`.
- Modificar `restoreNavState()` para restaurar el permiso al recargar la página.
- Añadir condiciones `x-show` a los botones "Nueva Carpeta" y "Subir Archivo" para que solo sean visibles cuando el permiso sea `write`, `upload` o `full`.
- Añadir la misma validación al overlay de drag-and-drop y al handler de drop.
- Condicionar igualmente el botón de subida del estado vacío (empty state) y el modal de subida.

## Capabilities

### New Capabilities
- `permission-aware-file-controls`: Los controles de escritura del file manager (subir archivo, nueva carpeta, drag-and-drop) se ocultan automáticamente cuando el usuario tiene permisos de solo lectura en el storage activo.

### Modified Capabilities
- `file-upload-ux`: El escenario "Sin permisos de escritura" se refuerza con la ocultación preventiva del botón, no solo el mensaje de error post-intento.

## Impact

- **Frontend**: `resources/views/files/index.blade.php` — componente Alpine.js `fileManager` y sus templates Blade.
- **Backend**: Sin cambios. La API `/user/storages` ya devuelve el campo `permissions` por cada storage.
- **Dependencias**: Ninguna nueva.
- **Riesgo**: Bajo. Solo afecta visibilidad de UI, el backend ya valida permisos.
