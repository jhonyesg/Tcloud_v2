## 1. Preparación del estado Alpine (fileManager)

- [x] 1.1 Agregar `_navGen: 0` al estado inicial del componente Alpine `fileManager` en `app/resources/views/files/index.blade.php` (junto al resto de campos reativos, ~línea 130).

## 2. Incremento del contador en cada punto de navegación

- [x] 2.1 En `navigateToFolder(folderId, folderName)` (~línea 641), después del early-return por `isNavigating`, agregar `this._navGen++` antes de tocar `this.currentFolder`.
- [x] 2.2 En `navigateToBreadcrumb(breadcrumb, index)` (~línea 674), después del early-return por `isNavigating`, agregar `this._navGen++` antes del slice de breadcrumbs.
- [x] 2.3 En `goToStorageRoot()` (~línea 664), agregar `this._navGen++` al inicio del cuerpo.
- [x] 2.4 En `enterStorage(storageId, storageName)` (~línea 388), agregar `this._navGen++` antes de modificar `this.currentStorage`.
- [x] 2.5 En `navigateToRoot()` (~línea 419), agregar `this._navGen++` al inicio del cuerpo.
- [x] 2.6 En `restoreNavState()` (~línea 301), agregar `this._navGen++` solo cuando `state.folderId` no es null (es decir, restaura una carpeta concreta, no vista de storages).

## 3. Guarda de obsolescencia en `loadFiles`

- [x] 3.1 En `loadFiles()` (~línea 435), después de armar la URL y antes de `apiFetch()`, capturar:
  ```
  const myGen = this._navGen;
  const capturedFolder = this.currentFolder;
  const capturedStorage = this.currentStorage;
  ```
- [x] 3.2 En el `.then(data => {...})` (~línea 470), al inicio del callback (antes de asignar `this.files`), validar:
  ```
  if (this._navGen !== myGen || this.currentFolder !== capturedFolder || this.currentStorage !== capturedStorage) {
      this.isNavigating = false; this.navigatingToId = null;
      this.isLoadingFiles = false; this.showEmptyState = false;
      return null;
  }
  ```
- [x] 3.3 En el `.catch(err => {...})` (~línea 511), aplicar la misma guarda con `err.name === 'AbortError'` como excepción temprana.

## 4. Guarda de obsolescencia en `loadMore`

- [x] 4.1 En `loadMore()` (~línea 541), después de los early-returns por `isLoadingMore` y `!hasMore`, capturar `myGen`, `capturedFolder`, `capturedStorage` como en loadFiles.
- [x] 4.2 En el `.then(data => {...})` de loadMore, antes de la concatenación a `this.files`, validar:
  ```
  if (this._navGen !== myGen || this.currentFolder !== capturedFolder || this.currentStorage !== capturedStorage) {
      this.isLoadingMore = false; return null;
  }
  ```

## 5. Guarda de obsolescencia en `silentSync`

- [x] 5.1 En `silentSync()` (~línea 641), después del early-return por prerrequisitos (`currentPage>1`, `viewMode`, `currentStorage`), capturar `myGen = this._navGen`, `capturedFolder = this.currentFolder`, `capturedStorage = this.currentStorage`.
- [x] 5.2 En el `try` después de `const data = await res.json();`, validar:
  ```
  if (this._navGen !== myGen || this.currentFolder !== capturedFolder || this.currentStorage !== capturedStorage) return;
  ```
  antes del bloque que decide si sobrescribir `this.files` con `newFiles`.

## 6. Verificación manual

- [x] 6.1 Probar en Mis Archivos con un storage que tenga ≥3 carpetas fecha. Navegar X→Y→X→Z→X en menos de 1 segundo. Confirmar que la última vista de X muestra los archivos correctos.

**Validación 2026-09-15 (Playwright contra `cloud.mediaserver.com.co`, login `Massmedios`)**:

```
=== Scenario: ping-pong X↔Y con 100ms gaps + Z→X→Y→X ===

Final: currentFolder=7098964 name=14092026
       files=96 sample_parent=7098964 sample_name=capital_14092026_234501.mp4
DB dice: folder 7098964 tiene 96 archivos

  files match DB: True | parent of first file = currentFolder: True
  no stuck isLoadingFiles: True | no HTTP 500 / page errors: True

=== Result: PASS ===
```

Suite: `/tmp/kilo/clean_test.py` (login + scroll + click rápido + verificación final).

- [x] 6.2 Verificar que `isLoadingFiles` no queda en `true` atascado tras la navegación rápida (debe limpiarse tanto en la response válida como en las obsoletas, según 3.2).
- [x] 6.3 Repetir el flujo con `loadMore` activo (paginación) y confirmar que una página siguiente obsoleta no se concatena al cambiar de carpeta (4.2).
- [x] 6.4 Comprobar la pestaña Network de DevTools: las responses obsoletas llegan pero no generan errores visibles en la UI ni toasts.

## 8. Validación ampliada con Playwright (multi-storage, sin recargas)

Script: `/tmp/kilo/multi_v2.py`. Login una vez, `goto /files` una vez, navegación rápida entre carpetas en **5 storages distintos** sin recargar la página.

```
📦 5 storages seleccionados: 38 Primera Pagina, 26 Cortos, Negocios Ditu,
                             01 Caracol Tv, 06 Capital

  5/5 storages → rapid 5-click [0,1,2,3,0] @ 110ms → OK
  • 127 "38 Primera Pagina": final 13092026 (id=7049042), 95 files / 95 DB ✅
  •  31 "26 Cortos":         final CityTv_C  (id=7155741), 1 file / 1 DB   ✅
  • 169 "Negocios Ditu":     final 13092026 (id=7049044), 95 files / 95 DB ✅
  •   6 "01 Caracol Tv":     final 13092026 (id=7049034), 95 files / 95 DB ✅
  •  11 "06 Capital":        final 13092026 (id=7049114), 94 files / 94 DB ✅

  5/5 goToStorageRoot() → vuelve a la lista raíz del storage (61-62 carpetas)

  Carpetas fecha clickeadas en una sesión sin recargas: 25
  HTTP 500 capturados: 0   |   Excepciones JS: 0
  isLoadingFiles atascado: nunca

  OVERALL: PASS ✅
```

El primer archivo de cada respuesta (`files[0]`) tiene `parent_id` que **coincide** con el `currentFolder` mostrado — confirmación dura de que NO hay pisada de datos de otra carpeta.

> Verificaciones requieren navegador con sesión activa contra `cloud.mediaserver.com.co`. Cambio ya aplicado en `app/resources/views/files/index.blade.php`. Si la view está cacheada, basta con `php artisan view:cache` o esperar al reload de PHP-FPM.

## 7. Cierre

- [ ] 7.1 Marcar la change como lista para archivar cuando todos los checks anteriores pasan.
- [ ] 7.2 Commit con mensaje conventional: `fix(mis-archivos): descartar responses obsoletas de silentSync/loadFiles/loadMore al navegar rápido entre carpetas`.

> El commit NO se hace automáticamente — esperará confirmación explícita según la política del repo (AGENTS.md).
