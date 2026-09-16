## 1. Frontend binding fix

- [x] 1.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, cambiar el estado inicial `emptyFolders: null` (línea ~1798) por `emptyFolders: { items: [], storages_with_empty: 0, total_missing_folders: 0 }` con un comentario corto apuntando a este change.

- [x] 1.2 En el mismo archivo, reemplazar el contenedor `<div x-show="emptyFolders && emptyFolders.total_missing_folders > 0" data-tour="storages-empties" class="...">` por `<template x-if="emptyFolders && emptyFolders.total_missing_folders > 0"><div data-tour="storages-empties" class="...">`, dejando el contenido del banner intacto y cerrando con `</div></template>`. Mantener `data-tour` y las clases CSS en el `<div>` interior para no romper el tour guiado ni los selectores CSS existentes.

## 2. Verificación manual

- [x] 2.1 Login con las credenciales del operador (`jsuarez` / `T3cn0l0g14`) y abrir `/ia/api-transcriptor`. Confirmar en DevTools → Console que ya no aparecen los `Uncaught TypeError: Cannot read properties of null (reading 'storages_with_empty'/'items')`.

- [x] 2.2 Verificar que el banner ámbar aparece cuando hay carpetas vacías y NO aparece cuando no las hay. Probar también el toggle "Ver lista" / chevron para confirmar que `emptyFoldersExpanded` sigue funcionando.

## 3. Cierre

- [x] 3.1 Confirmar que no hay cambios en backend (`ApiTranscriptorController::emptyFolders`), rutas, migraciones ni configuración. Ejecutar `openspec validate 2026-09-07-api-transcriptor-fix-empty-folders-console-errors` para validar la propuesta.
