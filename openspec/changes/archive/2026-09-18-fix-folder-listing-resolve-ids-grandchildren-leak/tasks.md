## 1. Fix de raíz en `FolderListingService`

- [x] 1.1 (Bug 1) Eliminar la línea `$ids = array_merge($ids, File::where('parent_id', $canonical->id)->pluck('id')->all());` en `app/app/Services/FolderListingService.php` dentro de `resolveFolderIds()`. Comentario explicativo reemplazado.

- [x] 1.2 (Bug 2) Añadir búsqueda de folders equivalentes vía `physical_path_normalized` en el mismo método. Cubre el caso del share #764 (folder vacío en storage 5 + folder con archivos en storage 34, mismo path físico, sin mirror linkage). Usa el índice `files_storage_base_path_snapshot_idx`.

## 2. Verificación de no-regresión vía queries directos a BD

- [x] 2.1 Crear `app/tests/verify_folder_listing_fix.php` que itera sobre 5 folders reales de producción (7631760, 7244379, 7244491, 1524, 7346139) y compara `FolderListingService::listContents()` contra los conteos esperados tras el fix (35, 97, 97, 121, 2). **Resultado**: 5/5 ✓.

## 3. Extensión del harness de regresión

- [x] 3.1 (b.1) "Canonical with sub-folder children returns sub-folders only": 3 sub-folders hijos + 15 nietos → `listContents` retorna 3, no 18.

- [x] 3.2 (b.2) "Canonical with mirror that has file children returns union": canonical vacío + mirror con 4 archivos → `listContents` retorna 4.

- [x] 3.3 (b.3) "Equivalent folder via physical_path_normalized, no mirror linkage": folder A vacío en storage parent + folder B con 4 archivos en sub-storage (mismo path físico, NO vinculados como mirrors) → `listContents(folderA)` retorna 4. Este escenario reproduce exactamente el caso del share #764.

- [x] 3.4 Correr `cd app && php tests/harness_share_folder_canonical.php`. **Resultado**: 13/13 ✓ (10 originales + 3 nuevos).

## 4. Validación manual en Mis Archivos

- [ ] 4.1 Operador (masmedios) confirma en navegador que:
  1. El share reportado (`/s/1a35201a224b75da143ced0583b2b5b9`) muestra los 35 archivos esperados.
  2. Otros 2-3 shares de folder muestran el mismo conteo que Mis Archivos al navegar la misma carpeta.

## 5. Verificación operativa post-deploy

- [ ] 5.1 Confirmar que `kill -USR2 <php-fpm-master-pid>` (o el equivalente según el sistema de gestión del pool FPM) se ejecuta tras el merge para liberar el opcode cache del archivo `FolderListingService.php` modificado.

- [x] 5.2 Pre-deploy check de logs: `grep -E "FolderListingService|folder_listing" app/storage/logs/laravel.log` retorna 0 matches. Sin errores previos en el código tocado. Post-deploy: monitorear durante 1h.
