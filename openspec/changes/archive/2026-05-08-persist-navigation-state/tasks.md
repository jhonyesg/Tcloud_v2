## 1. Agregar helper de persistencia al Alpine component

- [x] 1.1 Agregar método `saveNavState()` que serializa `{ storageId: currentStorage, storageName: currentStorageName, folderId: currentFolder, folderName: currentFolderName, breadcrumbs, viewMode }` en `localStorage` con clave `tcloud_files_nav`
- [x] 1.2 Agregar método `clearNavState()` que elimina la clave `tcloud_files_nav` de `localStorage`
- [x] 1.3 Agregar método `restoreNavState()` que lee `localStorage`, y si hay estado guardado con `storageId`, restaura todas las variables y llama `loadFiles()`

## 2. Llamar saveNavState() en cada método de navegación

- [x] 2.1 Llamar `this.saveNavState()` al final de `enterStorage()`
- [x] 2.2 Llamar `this.saveNavState()` al final de `navigateToFolder()`
- [x] 2.3 Llamar `this.saveNavState()` al final de `navigateToBreadcrumb()`
- [x] 2.4 Llamar `this.saveNavState()` al final de `setFilesViewMode()` (para persistir el modo de vista)

## 3. Limpiar estado al volver a la raíz

- [x] 3.1 Llamar `this.clearNavState()` en `navigateToRoot()` en vez de `saveNavState()`

## 4. Restaurar estado en init()

- [x] 4.1 Cambiar el método `init()` para llamar `await this.restoreNavState()` después de `loadStorages()` — si hay estado guardado, restaura y carga archivos; si no, queda en la raíz como antes
