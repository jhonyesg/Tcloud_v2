## Context

`fileManager` en `files/index.blade.php` es un Alpine.js component con estado en memoria: `currentStorage`, `currentStorageName`, `currentFolder`, `currentFolderName`, `breadcrumbs`, `viewMode`. Al recargar la página Alpine reinicia todo ese estado a los valores por defecto. No hay URL routing — la navegación es puramente client-side en el SPA de archivos.

## Goals / Non-Goals

**Goals:**
- Restaurar la posición exacta de navegación (storage + carpeta + breadcrumbs) al recargar
- Manejar el caso donde el storage/carpeta ya no existe (fallback silencioso a raíz)
- Limpiar el estado al navegar explícitamente a la raíz

**Non-Goals:**
- Implementar URL routing (hash o pushState) — es una refactorización mayor fuera de scope
- Persistir estado entre diferentes usuarios o pestañas
- Restaurar el estado del visor de archivos abierto

## Decisions

**Decision 1: `localStorage` con clave fija**
Clave: `tcloud_files_nav`. Valor: JSON con `{ storageId, storageName, folderId, folderName, breadcrumbs, viewMode }`. Alternativa (sessionStorage) descartada porque sessionStorage se borra al cerrar la pestaña — el usuario espera persistencia entre sesiones.

**Decision 2: Guardar en cada método de navegación, no con un watcher**
Alpine v3 no tiene `$watch` reactivo por defecto en el contexto `Alpine.data()`. Es más explícito y predecible guardar en `enterStorage()`, `navigateToFolder()`, `navigateToBreadcrumb()`, `navigateToRoot()` y `setFilesViewMode()`. Esto garantiza que el estado guardado es siempre consistente.

**Decision 3: Validación lazy al restaurar**
Al restaurar, intentar cargar los archivos de la carpeta guardada. Si la API devuelve error o array vacío inesperado, no se hace rollback automático — la carga normal de `loadFiles()` maneja ese caso. Si `storageId` es null, se queda en la raíz. No se hace una llamada extra de validación para mantener la restauración en O(1) requests.

**Decision 4: Limpiar estado al navegar a raíz**
Cuando el usuario hace click en "Volver a Storages" o en el breadcrumb raíz, se llama `navigateToRoot()` — en ese método borrar la clave de `localStorage` para que la próxima recarga empiece limpia.

## Risks / Trade-offs

- **Estado stale**: Si el storage es eliminado por un admin, al recargar `loadFiles()` devolverá error/vacío — el usuario verá carpeta vacía en vez de error explícito. Aceptable para MVP.
- **Breadcrumbs huérfanos**: Los breadcrumbs son solo metadata de nombres/IDs, no se revalidan. Si una carpeta intermedia fue renombrada, el breadcrumb mostrará el nombre viejo pero la navegación seguirá funcionando.

## Migration Plan

No hay migración. El cambio es puramente aditivo — si no hay clave en `localStorage`, la app arranca en la raíz como antes.
