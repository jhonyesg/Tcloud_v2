## 1. Frontend: fix del shape en `loadCopyMoveFolders`

- [ ] 1.1 Editar `resources/views/files/index.blade.php` línea 1156: cambiar `(Array.isArray(data) ? data : []).filter(f => f.is_folder)` a `const list = Array.isArray(data) ? data : (data?.files ?? []); this.copyMoveFolders = list.filter(f => f.is_folder);`. Verificar paridad con el patrón de las líneas 440 y 1274.

## 2. Frontend: modal abre en el folder actual

- [ ] 2.1 Editar `resources/views/files/index.blade.php` `openCopyMoveModal` (línea 1137-1147): cambiar `copyMoveDestFolderId = null` → `copyMoveDestFolderId = this.currentFolder`; cambiar `copyMoveDestLabel = 'Raíz'` → `copyMoveDestLabel = this.currentFolderName || 'Raíz'`; cambiar `loadCopyMoveFolders(null)` → `loadCopyMoveFolders(this.currentFolder)`. Mantener `copyMoveBreadcrumb = []` (sin trail de recorrido).

## 3. Frontend: nuevo método `navigateCopyMoveUp` y botón "↑ Subir"

- [ ] 3.1 Agregar método `navigateCopyMoveUp()` en Alpine store: si `copyMoveDestFolderId === null`, return (ya en raíz). Si no, fetch `/files/{id}` con headers `Accept: application/json` + `X-Requested-With: XMLHttpRequest`. Leer `data.parent_id`. Actualizar `copyMoveDestFolderId` (o `null` si llegamos a la raíz del storage), `copyMoveDestLabel` (`currentFolderName || 'Raíz'`), y llamar `loadCopyMoveFolders(newParentId)`.
- [ ] 3.2 Agregar el botón "↑ Subir" en el bloque del modal (alrededor de la línea 3545-3550, antes del listado de carpetas hijas). Atributos: `@click="navigateCopyMoveUp()"`, `:disabled="copyMoveDestFolderId === null"`, clases Tailwind consistentes con el resto del modal (`bg-white border border-slate-200 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed`).

## 4. Validación end-to-end

- [ ] 4.1 Recargar PHP-FPM: `/etc/init.d/php-fpm-84 reload` (o `systemctl reload php-fpm` equivalente) para liberar el opcode cache del Blade.
- [ ] 4.2 Hard-refresh del navegador (Ctrl+Shift+R) y validar manualmente el caso de la captura original: storage "209 QHB Medellin" → click "Mover" en `Oct 02-...zip` → modal debe listar `Base` y `20261001` como destinos.
- [ ] 4.3 Validar navegación desde subcarpeta: entrar a `Base`, click "Mover" en un archivo → modal abre con "Base" como destino default y muestra sus subcarpetas. Click "↑ Subir" → destino pasa a "Raíz" y el botón se deshabilita.
- [ ] 4.4 Confirmar que mover una carpeta dentro de sí misma sigue retornando HTTP 422 desde el backend (guardrail existente en `FileController::move:1126`); el modal debe mostrar el mensaje vía `copyMoveError`.
- [ ] 4.5 Limpiar PHP-FPM opcode cache si quedó stale (`rm -rf /www/server/php/84/opcache/*` o `find /tmp -name "*.php.bin" -delete` según el entorno).

## 5. Archivado

- [ ] 5.1 Confirmar que no hay regresiones en los otros 3 call-sites que consumen `/files`: líneas 440 (`refreshFiles`), 1274 (`loadFileShares`), y `admin/user-storages.blade.php:34` (otro endpoint, no `/files`, no debería verse afectado pero se incluye para descarte).
- [ ] 5.2 Ejecutar `openspec validate fix-mis-archivos-move-modal-shape --strict` y resolver cualquier issue.
- [ ] 5.3 Una vez deployed y validado, archivar el change vía `/opsx:archive` o `openspec archive fix-mis-archivos-move-modal-shape`.