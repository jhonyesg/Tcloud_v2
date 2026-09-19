## 1. Resolución de identidad física en lectura

- [x] 1.1 Reescribir `FilePhysicalIdentity::canonicalFor(File $file): File` para resolver por identidad física (`lower(rtrim(base_path || '/' || path))`) en vez de seguir `canonical_folder_id`; devolver la fila con `base_path` más específico; retornar el mismo input si no hay ambigüedad o si el snapshot es NULL (fallback JOIN a `storage_providers.base_path`).
- [x] 1.2 Agregar cache Redis TTL 300s en `canonicalFor()` (key `file:canonical:{id}`) e invalidación en `FileObserver::saved` cuando cambia `storage_provider_id` o `path`.
- [x] 1.3 Hacer que `File::isFolderMirror()` retorne `false` siempre y `File::canonicalFolder()` retorne `$this`, marcando ambos (y los aliases `isMirror()`/`canonical()`) con `@deprecated` por un ciclo de release.
- [x] 1.4 Agregar índice funcional `files_physical_path_normalized_idx` sobre `lower(rtrim(coalesce(base_path_snapshot,'') || '/' || coalesce(path,'')))` (NO único) en migración nueva; incluir `down()`.
- [x] 1.5 Agregar `--storage` scope y salida de conteos al comando `files:resync-base-path-snapshots` para poder acotar el backfill previo.

## 2. Listado cross-storage: caso raíz-de-sub-storage

- [x] 2.1 En `StorageSyncService::resolveListingTargets()` (`StorageSyncService.php:472`), tratar `$relativeToSub === ''` como el folder raíz del sub-storage (`parent_id = null`, `storage_provider_id = $sub->id`) en vez de retornar sin añadir el target.
- [x] 2.2 Verificar que `StorageSyncService::currentListing()` incluye el target raíz sin romper la dedup por peso (`strlen(base_path)`).
- [x] 2.3 En `FolderListingService::resolveFolderIds()`, incluir el caso de equivalencia con `path` vacío / `parent_id IS NULL` para el folder raíz de un sub-storage.
- [x] 2.4 Agregar invalidación de cache del folder raíz del sub-storage en `FileController::index()` (bloque `invalidateFolderCache`, líneas 216-227) cuando el target raíz entra en juego.

## 3. Frontend: adoptar el storage de la fila devuelta

- [x] 3.1 En `resources/views/files/index.blade.php`, extender `navigateToFolder(folderId, folderName)` (línea 669) a `navigateToFolder(folderId, folderName, storageId)` y actualizar `this.currentStorage = storageId` cuando difiera.
- [x] 3.2 Propagar `file.storage_provider_id` al `navigateToFolder(...)` desde los dos call sites del template (líneas ~2760 y ~2915, `@click="file.is_folder ? navigateToFolder(...)"`).
- [x] 3.3 Ajustar `saveNavState()` / restauración de estado de navegación para que persista el `currentStorage` adoptado y no el anterior.
- [x] 3.4 Verificar que `loadCopyMoveFolders()` y las demás construcciones de URL (`index.blade.php:446,548,1615`) siguen usando el storage activo correcto tras la adopción.

## 4. Canonicalización antes de tocar disco

- [ ] 4.1 En `FileController::download()` (`FileController.php:868`), resolver la fila canónica antes de calcular `$storage` y `$realFullPath`; responder 404 (no 400) si el path no existe tras resolver.
- [ ] 4.2 Hacer lo mismo en `FileController::downloadFolder()` (línea 900) para el cálculo de `$realBasePath` y en `addFolderToZip()`.
- [ ] 4.3 Hacer lo mismo en `FileController::preview()` (línea 1104), que hoy usa `response()->file($file->path)` sin base_path.
- [ ] 4.4 Canonicalizar en `FileController::downloadMulti()` (línea 1013) y en los métodos de `rotate`/`copy`/`move` que calculan path absoluto.
- [ ] 4.5 En `PublicShareController`, canonicalizar por identidad física en los métodos que leen disco (`mediaPreview`, `preview`, `download`) antes de calcular `$storage`/`$fullPath`.
- [ ] 4.6 Actualizar `ShareController::store()` (línea 98) para resolver el canónico por identidad física y no por `canonical_folder_id`.

## 5. Comando de retiro de mirrors

- [ ] 5.1 Crear `App\Console\Commands\MirrorRetirementCommand` (`files:mirror-retirement`) con flags `--dry-run` (default), `--apply`, `--revert`, `--batch`, `--storage`.
- [ ] 5.2 Implementar la fase de reporte/dry-run: contar mirrors candidatos, FKs a repuntar (78.547 transcriptions + 33 shares), hijos a delegar (219.647), hijos cross-storage a reparentar (89.747), y `unresolvable_mirror`.
- [ ] 5.3 Implementar el repunte de FKs: `UPDATE transcriptions SET file_id = <canonical>` y `UPDATE shares SET file_id = <canonical>`, auditando `action='repoint_fk'` en `file_mirror_audit_log`.
- [ ] 5.4 Implementar el reparent cross-storage: mover `parent_id` de los hijos al folder canónico equivalente; si no existe, `parent_id = NULL` + log `files.mirror_retirement.orphan_parent`; auditar `action='reparent_child'`.
- [ ] 5.5 Delegar los hijos mismo-storage invocando la lógica existente de delegación (`files:repair-delegation-leak`) en lugar de reimplementarla.
- [ ] 5.6 Implementar la verificación previa por fila: solo retirar si tiene canónico vivo con la misma identidad física y no tiene hijos; reportar el resto como `unresolvable_mirror`.
- [ ] 5.7 Implementar el retiro: `UPDATE files SET deleted_at = now() WHERE id IN (...)` en lotes, auditando `action='retire_mirror'` con `mirror_file_id`, `canonical_file_id` y `metadata` (conteos reparentados).
- [ ] 5.8 Implementar `--revert`: restaurar `deleted_at = NULL` sobre los ids retirados según `file_mirror_audit_log`, en orden inverso.
- [ ] 5.9 Garantizar idempotencia (NOT EXISTS sobre `canonical_folder_id IS NOT NULL`) y lock distribuido (`Cache::lock`) para evitar corridas solapadas.
- [ ] 5.10 Verificar que el trigger append-only de `file_mirror_audit_log` sigue activo y que el comando solo hace INSERT.

## 6. Retiro del código de materialización

- [ ] 6.1 Eliminar `App\Services\FileMirrorLinker` y sus call sites (incluido `FileRegistry::ensure()`).
- [ ] 6.2 Eliminar `App\Console\Commands\FilesRepairFileMirrorsCommand` y `App\Console\Commands\RepairFolderMirrorsCommand`.
- [ ] 6.3 Eliminar de `FileObserver::saving()` la invalidación de cache por `canonical_folder_id`; conservar únicamente el mantenimiento de `base_path_snapshot`.
- [ ] 6.4 Eliminar `File::scopeCanonical()` / `File::scopeMirror()` y actualizar sus call sites.
- [ ] 6.5 Revisar `StorageSyncService::createFileFromScan()` y `FileRegistry::ensure()` para que NO creen ni enlacen filas espejo; confirmar que la delegación usa `findMoreSpecificStorage()` + `ensureSubstorageFolderChain()`.
- [ ] 6.6 Ejecutar `rg "FileMirrorLinker|canonical_folder_id|repair-file-mirrors|repair-folder-mirrors" app/` y confirmar que solo quedan referencias `@deprecated` o del comando de retiro.

## 7. Harness de regresión

- [ ] 7.1 Crear `app/tests/harness_files_mirror_elimination.php` con tag único y limpieza defensiva, siguiendo el patrón de `harness_files_folder_mirror.php`.
- [ ] 7.2 Sección 1: `canonicalFor()` resuelve por identidad física el canónico correcto en un fixture cross-storage.
- [ ] 7.3 Sección 2: `resolveListingTargets()` retorna el target raíz del sub-storage cuando `relativeToSub === ''`.
- [ ] 7.4 Sección 3: el listado cross-storage devuelve los hijos del sub-storage sin filas espejo.
- [ ] 7.5 Sección 4: reparent cross-storage deja los hijos accesibles por listado.
- [ ] 7.6 Sección 5: el comando de retiro es idempotente y `--revert` restaura exactamente lo retirado.
- [ ] 7.7 Sección 6: invariante post-migración `count(canonical_folder_id IS NOT NULL) = 0` y ninguna transcripción/share perdió su archivo físico.

## 8. Verificación E2E con Playwright (cliente final)

- [ ] 8.1 Crear `app/tests/e2e/validate-mirror-elimination.spec.mjs` siguiendo el patrón de `files-storages.spec.mjs` (login, helpers PASS/FAIL, screenshots, reporte JSON).
- [ ] 8.2 Caso A: login con usuario **no-admin** con acceso al sub-storage (para no depender del bypass de admin).
- [ ] 8.3 Caso B: navegación click-through `200_Diarios > Portafolio > 20260918 > imagenes` y assert de los 16 PNGs visibles.
- [ ] 8.4 Caso C: `GET /files/{id}/download` de un PNG servido cross-storage → HTTP 200, `Content-Type` binario y bytes > 0.
- [ ] 8.5 Caso D: `GET /files/{id}/download` de una fila que antes era mirror → HTTP 200 (no 400 `Invalid file path`).
- [ ] 8.6 Caso E: navegación de la ruta reportada en la UI real con captura de pantalla, assert de consola sin errores y de que ningún request devuelve 400/403.
- [ ] 8.7 Correr el spec contra producción con `APP_URL` + credenciales de `jsuarez` y adjuntar el reporte JSON como evidencia.

## 9. Datos, documentación y cierre

- [ ] 9.1 Pre-deploy: correr `files:resync-base-path-snapshots --dry-run` y `files:mirror-retirement --dry-run`; adjuntar conteos reales y compararlos con los del design.
- [ ] 9.2 Aplicar en orden: `resync-base-path-snapshots --apply` → `files:mirror-retirement --apply` → `dashboard:clear-cache`.
- [ ] 9.3 Verificar invariantes post-migración con las queries del design y guardar la salida como evidencia.
- [ ] 9.4 Actualizar `AGENTS.md`: reemplazar la sección "Folder identity canónica para shares" para describir la resolución en lectura, y marcar `files-mirror-consolidation` como superseded.
- [ ] 9.5 Marcar el change `files-mirror-consolidation` como superseded/abandonado (no archivar como completado) y anotar la razón.
- [ ] 9.6 Documentar el rollback (`--revert` + `git revert`) y el runbook de verificación en `AGENTS.md`.
- [ ] 9.7 Correr `openspec validate files-mirror-elimination` y confirmar que pasa.
- [ ] 9.8 Commit final con mensaje conventional (`refactor(files): resolve physical identity at read time, retire mirror rows`).
