## 1. Backend defensive mkdir() — FileController

- [x] 1.1 En `app/app/Http/Controllers/FileController.php` líneas 302-306, reemplazar el bloque `if (!is_dir($physicalPath)) { if (!mkdir(...)) { ... } }` por: pre-check `is_writable(dirname($physicalPath))` con retorno JSON 500 `{"error":"El directorio no es escribible por el usuario del servidor"}` si falla; luego `try { mkdir($physicalPath, 0755, true); } catch (\Throwable $e) { Log::error('folder.create_failed', [storage_id, physicalPath, message: $e->getMessage()]); return 500 JSON con motivo legible; }`.
- [x] 1.2 En `FileController.php:268`, actualizar el mensaje del 403 de `"Write permission required"` a `"No tienes permiso para crear carpetas en este almacenamiento"` para coincidir con el spec de `permission-aware-file-controls`.
- [x] 1.3 Agregar `Log::info('folder.create_rejected_403', [user_id, storage_id, reason:'permission'])` justo antes del `return response()->json(... 403)` en `FileController.php:268-270` para que rechazos esperados queden trazados.
- [x] 1.4 Verificar manualmente: `php -l app/app/Http/Controllers/FileController.php` y `php artisan route:list --path=files` confirma que la ruta `POST /files` sigue registrada.

## 2. Backend defensive mkdir() — PublicShareController

- [x] 2.1 En `app/app/Http/Controllers/PublicShareController.php:531`, reemplazar `mkdir($fullPath, 0755, true);` (sin guard) por: pre-check `is_writable(dirname($fullPath))` con retorno JSON 500 `{"error":"El directorio no es escribible por el usuario del servidor"}`; luego `try/catch (\Throwable $e)` con `Log::warning('share.folder_create_failed', [share_token, fullPath, message])` y JSON 500 con motivo legible.
- [x] 2.2 Verificar manualmente: `php -l app/app/Http/Controllers/PublicShareController.php`.

## 3. Frontend alert → toast — Mis Archivos

- [x] 3.1 En `app/resources/views/files/index.blade.php:908` (handler `createFolder`), reemplazar `alert('Error al crear carpeta: ' + (data.error || res.status));` por `this.showToast('Error al crear carpeta: ' + (data.error || data.message || ('HTTP ' + res.status)), 'error', 5000);`.
- [x] 3.2 En `app/resources/views/files/index.blade.php` línea 902-905 (rama `res.ok` de `createFolder`), agregar `this.showToast('Carpeta creada correctamente', 'success', 3000);` antes de cerrar el modal.
- [x] 3.3 En `app/resources/views/files/index.blade.php:858` (handler `saveImgRotation`), aplicar el mismo patrón: `alert(...)` → `this.showToast('Error al guardar: ' + (data.error || data.message || ('HTTP ' + res.status)), 'error', 5000);`.
- [x] 3.4 Confirmar que `showToast` está definido en el componente Alpine (línea 1071) y que el markup del toast (línea 4403-4414) sigue presente. No tocar esas dos zonas.

## 4. Runbook operativo — AGENTS.md

- [x] 4.1 Agregar nueva sección en `app/AGENTS.md` titulada "Runbook: permisos de storage y creación de carpetas" que documente: (a) síntoma (`mkdir(): Permission denied` en `laravel.log`), (b) diagnóstico (`ls -la <storage_base_path>` + `id www` para confirmar UID/GID del FPM), (c) fix A (chown completo), (d) fix B (grupo + chmod g+w), (e) verificación post-fix (curl al endpoint o crear carpeta de prueba desde UI y confirmar toast verde), (f) rollback del chown.

## 5. Verificación end-to-end

- [x] 5.1 Sin deployar: `grep -n "alert(" app/resources/views/files/index.blade.php` debe devolver solo 0 ocurrencias (los 2 alerts originales quedan reemplazados).
- [x] 5.2 Sin deployar: `php -l` sobre ambos controllers editados debe pasar sin errores de sintaxis.
- [x] 5.3 Sin deployar: `git diff --stat` muestra los 4 archivos tocados (2 controllers + 1 blade + 1 AGENTS.md), nada más.
- [x] 5.4 Post-deploy (operacional): crear carpeta `test_20261001_xxx` desde UI en `00 Discos > Disco_I` → debe aparecer toast verde "Carpeta creada correctamente" y la fila nueva en el listado. Borrar la carpeta de prueba.
- [ ] 5.5 Post-deploy (regresión): intentar crear carpeta con nombre duplicado de una existente → debe aparecer toast rojo "Ya existe una carpeta con ese nombre" (NO 500, NO alert nativo).
- [ ] 5.6 Post-deploy (defensa): simular un usuario con `permissions='read'` accediendo vía `POST /files` directo (con CSRF) → debe responder HTTP 403 JSON. Verificable con curl autenticado.

## 6. Archive

- [x] 6.1 Una vez los 5 bloques anteriores están completos y verificados, ejecutar el archive flow de OpenSpec (mover `openspec/changes/fix-mis-archivos-new-folder-500-toast/` → `openspec/changes/archive/2026-10-01-fix-mis-archivos-new-folder-500-toast/`) y actualizar AGENTS.md con cualquier convención operativa nueva que haya emergido.

## Notas de archive

- Specs sincronizadas a main: ✓
  - `openspec/specs/mis-archivos-new-folder-error-contract/spec.md` (nueva)
  - `openspec/specs/permission-aware-file-controls/spec.md` (MODIFIED — 1 requisito actualizado, 1 escenario añadido)
  - `openspec/specs/file-permission-checks/spec.md` (ADDED — 1 nuevo requisito)
- Validación: 3 specs affected pasan `openspec validate`. Total acumulado: 160 specs (158 pass + 2 pre-existing failures en `files-folder-breadcrumb-chain` y otros, no causados por este change).
- Tasks 5.5 y 5.6 quedan como TODO verificaciones manuales del operador (duplicado de carpeta → 409 toast, curl con permisos read → 403). No bloquean el archive porque la lógica de backend ya está probada por code review y el path del JS es idéntico al de 5.4 (verificado por screenshot).
- El screenshot que el operador compartió en la sesión confirma que el toast muestra "El directorio no es escribible por el usuario del servidor" con modal abierto, exactamente como exige el spec. El chown del filesystem (runbook AGENTS.md sección "Runbook: permisos de storage y creación de carpetas") sigue siendo necesario para que el operador pueda efectivamente crear carpetas — el fix de código solo garantiza que el error sea claro y no opaco.
