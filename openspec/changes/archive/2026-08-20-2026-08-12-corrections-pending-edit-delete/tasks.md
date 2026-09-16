# Tasks: Editar y eliminar correcciones pendientes

## 1. Backend — endpoint PATCH

- [x] Agregar `Route::patch('/correcciones/{id}', [CorreccionesController::class, 'update'])->whereNumber('id')` en `app/routes/web.php` después de la línea 232 (junto a `store`).
- [x] Agregar método `update(Request $request, int $id, CorrectionService $service)` en `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Validar `wrong_text` y `correct_text` (required, string, max:500).
  - Cargar correction o 404.
  - 409 si `status !== 'pending'`.
  - Llamar `$service->updatePending($correction, $wrong, $correct)`.
  - Devolver JSON `{correction: ...}` con `proposedBy` cargado.
- [x] Agregar método `updatePending(Correction $correction, string $wrong, string $correct): Correction` en `app/app/Services/Ia/CorrectionService.php`:
  - `DB::transaction` envolviendo toda la lógica.
  - `Keyword::asciiLower(trim($wrong))` para `wrong_normalized`.
  - `trim($correct)`.
  - Throw `InvalidArgumentException` si vacío.
  - Si `wrong_normalized` colisiona con approved existente (otra id) → status=`merged`.
  - Si colisiona con otra pending → upsert sobre esa fila + delete de la actual.
  - Else → update in-place de la fila actual.
- [x] Validar sintaxis PHP: `php -l` sobre los 2 archivos modificados.

## 2. UI — botón Editar + modal

- [x] Agregar botón `Editar` por fila en `index.blade.php:295-301` con icono `fa-pen` y handler `openEditPending(c)`.
- [x] Agregar modal "Editar corrección pendiente" (espejo del modal "Nueva corrección" línea 1384-1405) con campos `wrong_text`, `correct_text`, readonly `source` y `proposed_by.username`.
- [x] Estado Alpine nuevo: `editForm: { open: false, item: null, wrong: '', correct: '' }`.
- [x] Handler `openEditPending(c)` que prellena el form.
- [x] Handler `saveEditPending()` que hace `PATCH /ia/correcciones/{id}` y actualiza `this.pending` in-place o recarga si status pasa a `merged`.
- [x] Validación cliente: no permitir guardar si `wrong_text` o `correct_text` están vacíos.
- [x] Manejo de error 409 (no es pending): toast/alert claro.

## 3. UI — botón Eliminar por fila

- [x] Agregar botón `Eliminar` por fila en `index.blade.php:295-301` con icono `fa-trash`, estilo `bg-red-50`.
- [x] Handler `destroyPending(c)` que:
  - Abre modal custom con warning semántico (rechazar ≠ eliminar).
  - Confirmación explícita via `confirmDestroyPending()`.
  - Llama `DELETE /ia/correcciones/{id}`.
  - Limpia `selectedIds` y recarga `pending`.
- [x] Reemplazado `confirm()` del navegador por modal custom (más robusto, consistente con Rechazar).

## 4. UI — acción masiva Eliminar N en sticky bar

- [x] Agregar botón `Eliminar N` en `index.blade.php:1347-1360` (después de `Excluir N`), estilo `bg-red-700`.
- [x] Handler `openBulkDestroyPending()` + `confirmBulkDestroyPending()`:
  - Abre modal custom con conteo y warning.
  - Llama `POST /ia/correcciones/bulk-destroy-pending` con `{ids: [...]}` (endpoint NUEVO, separado de `bulk-destroy` que solo acepta approved).
  - `clearSelection()` + `loadPending()`.
- [x] Creado `CorrectionService::bulkDestroyPending()` para borrar pendientes en lote (no usa snapshot, no es reversible). Endpoint `POST /correcciones/bulk-destroy-pending`.

## 5. Tour guiado

- [x] Verificado: el módulo `/ia/correcciones` no expone botón "Guía" en su header ni tiene `startCorreccionesTour()` definido. La constraint `interactive_tours_must_include_new_features` aplica solo a módulos que YA tienen tour, así que no hay paso que actualizar.
- [x] N/A: pendiente a futuro — si en otro change se agrega tour a este módulo, los botones Editar/Eliminar (iconos `fa-pen`/`fa-trash`) deben incluirse como paso y se debe explicar la diferencia semántica rechazar (mantiene auditoría) vs eliminar (borra sin registro).

## 6. Verificación

- [x] Validar sintaxis PHP con `php -l` sobre todos los archivos modificados (4 archivos: `web.php`, `CorreccionesController.php`, `CorrectionService.php`, `index.blade.php`) — todos sin errores.
- [x] Confirmar que la ruta `PATCH ia/correcciones/{id}` quedó registrada vía `php artisan route:list`.
- [x] Smoke-test con `Reflection` confirma que `CorrectionService::updatePending(Correction, string, string): Correction` existe y que `CorreccionesController@update` está cableado.
- [ ] Probar manualmente con un ejemplo real tipo `io → Io` → editar a `io → Tio` → aprobar → verificar que entra al diccionario.
- [ ] Probar eliminación de una sugerencia "ruido" (palabra suelta como `D`) y confirmar que desaparece del listado sin aparecer en `rejected`.
- [ ] Probar bulk-eliminar 3-5 pendientes seleccionadas y confirmar que se borran todas.
- [ ] Validar que `PATCH` devuelve 409 si la corrección ya está approved.
- [ ] Verificar que el sticky bar muestra correctamente el contador al seleccionar varias filas.
- [ ] Probar en móvil que los botones no rompen el layout de la fila (puede requerir tooltip en lugar de texto completo en pantallas chicas).
