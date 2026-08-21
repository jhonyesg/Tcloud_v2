# Tasks: Moderación en lote (con undo)

## 0. Schema: undo log

- [x] Crear `app/database/migrations/2026_07_30_120000_create_correction_bulk_actions_table.php` con 2 tablas (action + items). Ver §1.1 de design.md.
- [x] Crear `app/config/corrections.php` con `undo_window_minutes` (5) y `bulk_max_ids` (500).
- [x] Agregar a `.env.example`: `CORRECTIONS_UNDO_WINDOW_MINUTES=5`, `CORRECTIONS_BULK_MAX_IDS=500`.
- [x] `php artisan migrate --force` para aplicar en producción. (migrated en 62.69ms)
- [x] Crear modelos Eloquent `app/app/Models/CorrectionBulkAction.php` y `CorrectionBulkActionItem.php` con relaciones.

## 1. Backend: Service layer

- [x] En `app/app/Services/Ia/CorrectionService.php`:
  - [x] Agregar `bulkApprove(array $ids, User $by): array` que crea CorrectionBulkAction, snapshotea cada item, itera approve(), retorna `{approved, merged, errors, bulk_action_id, undo_expires_at}`.
  - [x] Agregar `bulkReject(array $ids, ?string $reason, User $by): array` con mismo patrón.
  - [x] Agregar `bulkDestroy(array $ids, User $by): array` (snapshot completo del row antes de DELETE).
  - [x] Agregar `undoBulkAction(string $bulkActionId, User $by): array` que restaura cada item.
  - [x] Helper privado `markPreviousBulkActionsSuperseded(User $by, string $excludeId)`.
- [x] `php -l` validar syntax.

## 2. Backend: Controller

- [x] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - [x] Agregar `bulkApprove(Request $request, CorrectionService $service)`.
  - [x] Agregar `bulkReject(Request $request, CorrectionService $service)`.
  - [x] Agregar `bulkDestroy(Request $request, CorrectionService $service)`.
  - [x] Agregar `undoBulkAction(string $bulkActionId, CorrectionService $service)` con manejo de errores → 410/409/404.
- [x] `php -l` validar syntax.

## 3. Rutas

- [x] En `app/routes/web.php`:
  ```php
  Route::post('/correcciones/bulk-approve',  [CorreccionesController::class, 'bulkApprove']);
  Route::post('/correcciones/bulk-reject',   [CorreccionesController::class, 'bulkReject']);
  Route::post('/correcciones/bulk-destroy',  [CorreccionesController::class, 'bulkDestroy']);
  Route::post('/correcciones/undo/{bulkActionId}', [CorreccionesController::class, 'undoBulkAction'])
      ->where('bulkActionId', '[A-Za-z0-9_-]+');
  ```

## 4. Frontend: UI bulk operations

- [x] En `app/resources/views/ia/correcciones/index.blade.php`:
  - [x] Agregar al state Alpine: `selectedIds: Set()`, `sourceFilter: 'all'`, `bulkRejectReason: ''`, `showBulkReject: false`.
  - [x] Getters computados: `allSelected`, `someSelected`, `pendingFiltered`.
  - [x] Métodos: `toggleAll()`, `toggleOne(id)`, `clearSelection()`, `bulkApprove()`, `openBulkReject()`, `confirmBulkReject()`, `bulkDestroy()`.
  - [x] Tabla pending: columna checkbox al inicio + binding `:checked` + `@change`.
  - [x] Filtro por source arriba de la tabla.
  - [x] Barra de acción sticky abajo (visible solo si `selectedIds.size > 0`).
  - [x] Modal "Rechazar en lote" con textarea de motivo común.
  - [x] Tabla approved: columna checkbox + botón "Eliminar N" en la barra de acción.

## 5. Frontend: Toast de undo (NUEVO)

- [x] Markup HTML del toast fixed bottom-left (z-40), oculto por defecto.
- [x] Estado Alpine `undoToast: {visible, title, detail, icon, bulkActionId, expiresAt, expired, countdown}`.
- [x] Método `showUndoToast(result, action)` que arranca interval de countdown (1s).
- [x] Método `performUndo()` que llama POST `/correcciones/undo/{id}` y maneja success/error.
- [x] Método `formatCountdown(expiresAt)` que retorna "M:SS".
- [x] Auto-hide del toast 3s después de expirar.
- [x] `bulkApprove/ConfirmBulkReject/bulkDestroy` deben llamar `showUndoToast` con el `result` y el `action` correspondiente.

## 6. Cleanup job

- [x] Crear `app/app/Console/Commands/CleanupBulkActionsLogCommand.php`:
  - [x] Borra `correction_bulk_actions` con `expires_at < now() - 7 days` (CASCADE borra items).
- [x] Registrar en `app/routes/console.php` (note: este proyecto usa routes/console.php, no Kernel.php) para correr diariamente.
- [x] `php -l` validar.

## 7. Tests

- [x] Crear `app/tests/Feature/CorreccionesBulkModerationTest.php`:
  - [x] 9 tests: signature, return shape, routes, config, constants, state methods, bulk approve/reject/destroy arg shape, undoBulkAction signature.
  - [x] Suite Correction: 27/27 passing (incluye 9 nuevos).
  - **Nota**: tests originales del design especificaban 16 tests con BD real. Limitación: el proyecto no tiene test DB con migraciones completas; se usó LaravelTestCase + Reflection para validar signatures y state sin tocar BD. La validación end-to-end se hizo vía smoke test live (ver §8).

## 8. Verificación manual

- [x] Login admin → `/ia/correcciones` → pestaña Pendientes.
  - [x] Verificar checkboxes por fila + tri-state en header.
- [x] Click "seleccionar todas" → barra inferior aparece.
- [x] Click "Aprobar" → loading → toast aparece bottom-left con [Deshacer] + countdown.
- [x] Click [Deshacer] antes de que expire → toast cambia a "Acción revertida", recarga, pending reaparecen.
- [x] Smoke test live (artisan tinker): pending → approved → undo → pending. ✓
- [x] Fix post-implementación: `@foreach` huérfano en blade causaba 500 → corregido a `@endforeach`.

## 9. Spec delta

- [x] Editar `openspec/changes/2026-07-30-corrections-bulk-moderation/specs/transcription-corrections/spec.md`:
  - [x] ADDED: `Requirement: Admin puede aprobar múltiples correcciones pendientes en lote`
  - [x] ADDED: `Requirement: Admin puede rechazar múltiples correcciones pendientes en lote con motivo común`
  - [x] ADDED: `Requirement: Admin puede eliminar múltiples correcciones aprobadas en lote`
  - [x] ADDED: `Requirement: Admin puede revertir una acción masiva dentro de una ventana de 5 minutos`

## 10. Artefactos OpenSpec

- [x] `openspec/changes/2026-07-30-corrections-bulk-moderation/proposal.md` ✓ (actualizado con undo)
- [x] `openspec/changes/2026-07-30-corrections-bulk-moderation/design.md` ✓ (actualizado con undo)
- [x] `openspec/changes/2026-07-30-corrections-bulk-moderation/tasks.md` ✓ (este archivo, actualizado)
- [x] `openspec/changes/2026-07-30-corrections-bulk-moderation/specs/transcription-corrections/spec.md` ✓
- [x] `openspec/changes/2026-07-30-corrections-bulk-moderation/.openspec.yaml` ✓

## 11. Resumen de archivos

### Modificados
- `app/app/Services/Ia/CorrectionService.php` (4 métodos nuevos: bulkApprove, bulkReject, bulkDestroy, undoBulkAction)
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (4 métodos nuevos)
- `app/routes/web.php` (4 rutas nuevas)
- `app/resources/views/ia/correcciones/index.blade.php` (UI bulk + toast undo)
- `app/routes/console.php` (registrar cleanup job)
- `.env.example` (2 variables nuevas)

### Nuevos
- `app/database/migrations/2026_07_30_120000_create_correction_bulk_actions_table.php`
- `app/app/Models/CorrectionBulkAction.php`
- `app/app/Models/CorrectionBulkActionItem.php`
- `app/config/corrections.php`
- `app/app/Console/Commands/CleanupBulkActionsLogCommand.php`
- `app/tests/Feature/CorreccionesBulkModerationTest.php`
