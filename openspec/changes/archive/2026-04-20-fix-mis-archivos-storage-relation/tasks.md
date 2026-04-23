## 1. Backend: Endpoint de storages (ya existente)

- [x] 1.1 Endpoint `GET /user/storages` ya implementado
- [x] 1.2 Ruta ya configurada en web.php

## 2. Frontend: Modelo de datos en Alpine.js

- [x] 2.1 Agregar `availableStorages: []` 
- [x] 2.2 Agregar `viewMode: 'storages' | 'files'`
- [x] 2.3 Agregar `currentStorageName` para mostrar nombre del storage
- [x] 2.4 Modificar `init()` para cargar storages y detectar modo

## 3. Frontend: Lógica de navegación

- [x] 3.1 `loadStorages()` - obtiene lista de storages
- [x] 3.2 `validateCurrentStorage()` - verifica si storage existe
- [x] 3.3 `enterStorage(storageId, storageName)` - entra a un storage y carga archivos
- [x] 3.4 `navigateToRoot()` - vuelve a la vista de storages

## 4. Frontend: UI para mostrar storages como carpetas

- [x] 4.1 Header muestra nombre del storage actual o "Selecciona un storage"
- [x] 4.2 UI condicional: storages como carpetas en vista raíz, archivos dentro de storage
- [x] 4.3 Breadcrumbs solo visibles en modo 'files'
- [x] 4.4 Botón "Volver a Storages" cuando está dentro de un storage
