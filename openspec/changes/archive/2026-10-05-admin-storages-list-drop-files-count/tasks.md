# Tasks — admin-storages-list-drop-files-count

## 1. Backend — quitar `files_count` del listado

- [x] 1.1 En `app/app/Http/Controllers/StorageProviderController.php:33-36`, cambiar `computeAdminStorages()` para que devuelva `StorageProvider::get()->toArray()` (sin `withCount('files')`).
- [x] 1.2 Actualizar el comentario de `StorageProviderController.php:18-21` para reflejar que el caché ya no existe por el `withCount` (el listado no toca `files`); explicar que el conteo se resuelve bajo demanda en `show()`.
- [x] 1.3 Confirmar que `show()` (`StorageProviderController.php:103-107`) **conserva** `withCount('files')` y añadir un comentario de una línea indicando que es intencionalmente un fetch por-storage (no usar en bucles).
- [x] 1.4 Verificar: `php -l app/app/Http/Controllers/StorageProviderController.php`.

## 2. Frontend — eliminar la columna "Archivos"

- [x] 2.1 En `app/resources/views/admin/storages.blade.php`, eliminar el `<th>` ordenable "Archivos" (líneas ~569-574) junto con su `@click="toggleSort('files_count')"` e indicador de dirección.
- [x] 2.2 Eliminar el `<td>` de la columna Archivos (línea ~599, `<td ... x-text="storage.files_count"></td>`).
- [x] 2.3 En la tarjeta móvil (línea ~502), cambiar `'ID: ' + storage.id + ' · ' + storage.files_count + ' archivos'` por `'ID: ' + storage.id`.
- [x] 2.4 Verificar por grep que no queda ninguna referencia a `files_count` en la vista salvo la del modal (que se reemplaza en el bloque 3).

## 3. Frontend — conteo bajo demanda en el modal de borrado

- [x] 3.1 Añadir estado Alpine `deletingStorageCount: null` junto a `deletingStorage` (línea ~13).
- [x] 3.2 Crear el método `openDeleteModal(storage)` que: asigna `deletingStorage`, resetea `deletingStorageCount = null`, abre `showDeleteModal = true`, y hace `apiFetch('/admin/storages/' + storage.id, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })`; en `res.ok` setea `deletingStorageCount = payload.files_count ?? 0`, en fallo setea `deletingStorageCount = -1`.
- [x] 3.3 Reemplazar los dos call sites `deletingStorage = storage; showDeleteModal = true` (líneas ~529 y ~624) por `openDeleteModal(storage)`.
- [x] 3.4 En el modal de borrado (línea ~780), reemplazar el `<li x-text="(deletingStorage.files_count || 0) + ' archivo(s) asociado(s)'">` por tres ramas: `null` → "Calculando archivos asociados…", `-1` → "los archivos asociados a este storage", `>= 0` → `N + ' archivo(s) asociado(s)'`.
- [x] 3.5 Verificar que el botón "Eliminar" del modal no depende de `deletingStorageCount` (queda habilitado siempre).

## 4. Frontend — estado de carga y error del listado

- [x] 4.1 Añadir estado Alpine `loadingStorages: true` y `storagesLoadError: ''` (o reutilizar el toast existente) junto a `storages: []`.
- [x] 4.2 Reescribir `loadStorages()` con `try { ... } catch { ... } finally { this.loadingStorages = false; }`: en `res.ok` setear `this.storages`; en `!res.ok` leer `err = await res.json().catch(() => ({}))` y mostrar toast rojo `err.error || err.message || ('HTTP ' + res.status)` usando el helper `showToast()` existente.
- [x] 4.3 Ocultar el mensaje "No hay storages configurados" (líneas ~540-542 móvil y ~640-642 escritorio) mientras `loadingStorages === true`, y mostrar en su lugar "Cargando storages…".
- [x] 4.4 Verificar que tras un error el estado "Cargando" se apaga y se ve un mensaje accionable (no el vacío silencioso).

## 5. Verificación end-to-end (sin deploy)

- [x] 5.1 `grep -rn "files_count" app/resources/views/admin/storages.blade.php` → solo debe aparecer en el render de las 3 ramas del modal (o cero si se usa una variable local); ninguna referencia a `storage.files_count` del listado.
- [x] 5.2 `grep -n "withCount" app/app/Http/Controllers/StorageProviderController.php` → solo en `show()`.
- [x] 5.3 `php -l` sobre el controlador; `git diff --stat` muestra exactamente 2 archivos (controlador + blade) + artefactos openspec. *(Verificado: los otros archivos modificados en el working tree son trabajo previo no relacionado; el diff de mis 2 archivos es exclusivamente este change.)*
- [x] 5.4 Medir con el harness/tinker: `StorageProvider::get()->count()` y confirmar listado sin `files_count`; medir `GET /admin/storages` (AJAX autenticado) `< 250 ms` frío. *(Medido por la ruta real `StorageProviderController::index`: cold estable ~80 ms tras el primer request de boot; warm ~3-6 ms. OLD con `withCount` era 550-843 ms con Xdebug off.)*

## 6. Verificación post-deploy (operacional)

- [x] 6.1 Invalidar la caché vieja del shape anterior: `redis-cli -a 'Clouding2026!Redis' -n 1 DEL 'tcloud_tcloud_cache_admin:storages:index'`.
- [x] 6.2 `systemctl reload php84-php-fpm` para descartar opcode cache de la vista y el controlador. *(Servicio real: `php-fpm-84`; recargado OK. opcache tiene `validate_timestamps=On`.)*
- [x] 6.3 Abrir `/admin/storages` → debe cargar sin columna "Archivos" y sin el mensaje vacío; medir con DevTools Network que la respuesta ya no trae `files_count`. *(Verificado programáticamente por la ruta real: 196 filas, `files_count` ausente, ~80 ms frío / ~3-6 ms warm. Vista renderizada sin el `<th>` Archivos.)*
- [ ] 6.4 Abrir el modal "Eliminar" de un storage con muchos archivos → debe aparecer el número exacto tras un instante ("Calculando…"). *(Pendiente: verificación visual del operador en navegador. `show()` devuelve `files_count=247095` para el storage #134 en ~105 ms.)*
- [ ] 6.5 Simular fallo del conteo (bloquear `GET /admin/storages/{id}` en DevTools) → el modal debe caer a "los archivos asociados a este storage" y el botón "Eliminar" seguir operativo. *(Pendiente: verificación visual del operador. La rama `deletingStorageCount === -1` está cableada.)*

## 7. Archive

- [ ] 7.1 Ejecutar el archive flow de OpenSpec, sincronizando los 4 spec deltas a `openspec/specs/` y añadiendo la nota operativa de invalidación de caché a `AGENTS.md`.
