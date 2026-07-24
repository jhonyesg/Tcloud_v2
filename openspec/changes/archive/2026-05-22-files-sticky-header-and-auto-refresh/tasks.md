## 1. Header sticky

- [x] 1.1 En `app/resources/views/files/index.blade.php` línea 2042, cambiar la clase del `<header>` de `"bg-white shadow-sm border-b border-slate-200"` a `"bg-white shadow-sm border-b border-slate-200 sticky top-0 z-10"`.

## 2. Método silentSync()

- [x] 2.1 En `app/resources/views/files/index.blade.php`, añadir el método `silentSync()` al componente Alpine `fileManager`, después del método `refreshFiles()` (~línea 466). El método debe:
  - Abortar si `this.currentPage > 1` (usuario ya cargó más páginas)
  - Abortar si `this.viewMode !== 'files'` (no está en vista de archivos)
  - Construir la URL con `?page=1&sync=1&nb=1` más `parent_id` y `storage_id` si aplica
  - Hacer `fetch` con `credentials: 'include'` y headers `Accept: application/json`, `X-Requested-With: XMLHttpRequest`
  - Al recibir respuesta: calcular fingerprint de `newFiles` y comparar con `this.files`
  - Si difieren: actualizar `this.files`, `this.currentPage` y `this.hasMore` — sin tocar `isLoadingFiles`, sin toast, sin spinner
  - Envolver todo en try/catch silencioso (errores de red no deben mostrarse al usuario)

## 3. Disparar silentSync() desde restoreNavState()

- [x] 3.1 En el método `restoreNavState()` (~línea 265), después de la llamada `this.loadFiles(false, true)`, añadir en la siguiente línea: `this.silentSync()` (sin await — debe correr en paralelo).

## 4. Verificación

- [ ] 4.1 Navegar a "Mis Archivos", hacer scroll hacia abajo — verificar que el header con botones y breadcrumb permanece visible. *(verificación manual)*
- [ ] 4.2 Añadir un archivo directamente en el filesystem o via otro cliente, luego recargar la página (F5) — verificar que el archivo aparece sin necesidad de hacer clic en "Actualizar". *(verificación manual)*
- [ ] 4.3 Verificar que la recarga es rápida: los datos cacheados aparecen inmediatamente, la lista se actualiza silenciosamente después (~1-2 segundos) si hay diferencias. *(verificación manual)*
- [ ] 4.4 Hacer scroll hasta cargar página 2 con infinite scroll, luego verificar que `silentSync()` no sobreescribe la lista (abrir DevTools Network y confirmar que el request sync=1 se hace pero los archivos visibles no saltan). *(verificación manual)*
- [ ] 4.5 Verificar que el botón "Actualizar" manual sigue funcionando igual (toast con conteo de archivos). *(verificación manual)*
- [ ] 4.6 Verificar que en móvil el header sticky no tapa contenido — revisar z-index con modales abiertos. *(verificación manual)*
