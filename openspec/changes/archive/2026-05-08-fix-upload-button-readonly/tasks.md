## 1. Estado reactivo de permisos en Alpine.js

- [x] 1.1 Añadir propiedad `currentStoragePermission: 'read'` al data del componente `fileManager` en `index.blade.php`
- [x] 1.2 Añadir método `canWrite()` al componente que retorne `true` si `currentStoragePermission` es `write`, `upload` o `full`
- [x] 1.3 Modificar `enterStorage()` para buscar el permiso del storage en `availableStorages` por ID y asignarlo a `currentStoragePermission`
- [x] 1.4 Modificar `saveNavState()` para incluir `currentStoragePermission` en el JSON guardado en localStorage
- [x] 1.5 Modificar `restoreNavState()` para restaurar `currentStoragePermission` buscando en `availableStorages` por el `storageId` guardado

## 2. Condicionar visibilidad de controles de escritura

- [x] 2.1 Añadir `x-show` con `canCreateFolders()` al botón "Nueva Carpeta"
- [x] 2.2 Añadir `x-show` con `canUpload()` al botón "Subir Archivo"
- [x] 2.3 Añadir `x-show` con `canUpload()` al botón "Subir Archivo" del empty state
- [x] 2.4 Añadir `x-show` con `canUpload()` al modal de subida

## 3. Condicionar drag-and-drop

- [x] 3.1 Añadir condición `canUpload()` al handler `@drop.prevent` del contenedor principal
- [x] 3.2 Añadir condición `canUpload()` al overlay visual de drag
- [x] 3.3 Añadir condición `canUpload()` al handler `@dragenter.prevent` del contenedor principal

## 4. Validación y permiso upload (solo subir, sin crear carpetas)

- [x] 4.1 Ajustar `canWrite()` para distinguir entre `upload` (solo subir) y `write`/`full` (subir + crear carpeta): crear método `canUpload()` y `canCreateFolders()`
- [x] 4.2 Usar `canUpload()` en el botón "Subir Archivo" y drag-and-drop
- [x] 4.3 Usar `canCreateFolders()` en el botón "Nueva Carpeta"
