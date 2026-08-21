# Change: Moderación en lote de correcciones pendientes

## Why

El admin de `/ia/correcciones` actualmente tiene que aprobar o rechazar cada corrección pendiente **una por una** con dos clicks cada una. Después de la oleada del change 2026-07-29-corrections-dictionary-bootstrapping (que dejó 138 pending de round2 + round3) el flujo de moderación se vuelve tedioso: 276+ clicks para limpiar la cola.

Además, el flujo bulk introduce un nuevo riesgo: **aprobar por accidente un lote de reglas malas**. Sin red de seguridad, un admin distraído podría aprobar 50 reglas incorrectas y tener que rechazarlas una por una después. Necesitamos una **ventana de undo** que permita revertir las últimas acciones masivas dentro de un tiempo corto.

```
HOY (sin bulk):
  138 pending × 2 clicks = 276+ interacciones
  
DESEADO (con bulk):
  1 click "seleccionar todas" + 1 click "aprobar todas" = 2 interacciones
  + red de seguridad: toast con [Deshacer] visible 5 minutos
```

## What Changes

### Backend
- **Service**: agregar `bulkApprove(array $ids, User $by): array` y `bulkReject(array $ids, ?string $reason, User $by): array` en `CorrectionService`. Cada uno itera sobre IDs llamando los métodos individuales `approve()`/`reject()` (reusa la lógica de merge con approved existente, transacciones individuales por id).
- **Undo**: agregar `bulkApprove()`, `bulkReject()` y `bulkDestroy()` que **snapshotean** el estado previo de cada correction ANTES de aplicar el cambio, en una tabla `correction_bulk_actions` + `correction_bulk_action_items`. El snapshot permite revertir la acción completa más tarde vía `undoBulkAction(string $bulkActionId, User $by)`.
- **Controller**: agregar `bulkApprove(Request $request)`, `bulkReject(Request $request)`, `bulkDestroy(Request $request)`, y `undoBulkAction(string $bulkActionId)`. El response de bulk-* devuelve `{approved, merged, errors, bulk_action_id, undo_expires_at}` para que la UI sepa si puede deshacer y hasta cuándo.
- **Rutas**: agregar `POST /correcciones/bulk-approve`, `POST /correcciones/bulk-reject`, `POST /correcciones/bulk-destroy`, y `POST /correcciones/undo/{bulkActionId}` dentro del bloque admin IA.
- **Validación**: `ids` requerido, array, max 500 items por lote. Cada id debe existir y estar en `pending`; los que no cumplen se reportan en `errors[]` sin abortar el lote.
- **Ventana de undo**: 5 minutos (configurable via env `CORRECTIONS_UNDO_WINDOW_MINUTES`). Pasado ese tiempo, el endpoint retorna 410 Gone.
- **Migrations**: nueva tabla `correction_bulk_actions` + `correction_bulk_action_items` para snapshot de undo.
- **Respuesta**: `{approved: N, merged: M, errors: [{id, message}], bulk_action_id: string, undo_expires_at: ISO8601}`.

### Frontend (Blade + Alpine.js)
- **Tabla pending**: agregar columna inicial con checkbox por fila. Header tiene checkbox "select all" en estado tri-state (vacío / parcial / completo).
- **Estado Alpine**: `selectedIds` (Set), `selectAll`, `someSelected`, computados reactivos. Métodos `toggleAll()`, `toggleOne(id)`, `clearSelection()`.
- **Barra de acción condicional**: aparece sticky abajo cuando `selectedIds.size > 0`. Muestra `"N de M seleccionadas"` + botones `Aprobar N` / `Rechazar N` + botón "Limpiar selección".
- **Modal rechazar en lote**: pide un único motivo que se aplica a TODAS las del lote.
- **Filtro por source**: dropdown arriba de la tabla con opciones "Todos", "Round 1 (bootstrapping)", "Round 2", "Round 3", "Legacy".
- **Bulk-eliminate en approved** (mismo patrón): checkbox en tabla approved + botón "Eliminar N" con confirmación.
- **Toast de undo (nuevo)**: después de cualquier acción masiva exitosa, aparece un toast fijo abajo-izquierda con el resumen (`"X aprobadas, Y consolidadas"`) y un botón `[Deshacer]` visible hasta `undo_expires_at`. Si el admin hace click antes de que expire, se ejecuta POST `/correcciones/undo/{bulkActionId}` y se restaura el estado.
- **Manejo de undo expirado**: si pasan >5min y el admin clickea Deshacer, mostrar mensaje claro "La ventana de undo expiró".

## Non-goals

- **Undo retroactivo**: si entre la aprobación y el undo hubo un `corrections:apply-run`, el `applies_count` de las reglas ya cambió. El undo revierte el status pero NO decrementa `applies_count` (no sabemos qué aplicar fue de esas reglas vs de las preexistentes). Documentado en la UI con warning si aplica retroactivo se ejecutó en la ventana.
- **Undo de bulk destroy**: si el admin eliminó 10 approved, no podemos recuperarlas (DELETE físico). El botón de undo se omite para esa acción. Se loguea como `bulk_destroyed` para auditoría pero no es reversible.
- **Undo multi-nivel**: solo se puede deshacer la ÚLTIMA acción masiva. Si el admin hace 3 bulks en sucesión, solo el último tiene undo activo (los anteriores se marcan `superseded_at`).
- **Bulk propose desde cliente**: solo admin.
- **Bulk approve + reject en la misma selección**: una acción a la vez.
- **Preview de impacto antes de aprobar**: queda como follow-up.

## Impact

- **Specs affected**: `transcription-corrections` (ADDED 4 requirements: bulk approve, bulk reject, bulk destroy, undo).
- **Code affected**:
  - `app/app/Models/CorrectionBulkAction.php` (NUEVO)
  - `app/app/Models/CorrectionBulkActionItem.php` (NUEVO)
  - `app/app/Services/Ia/CorrectionService.php` (4 métodos: bulkApprove, bulkReject, bulkDestroy, undoBulkAction)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (4 métodos nuevos)
  - `app/routes/web.php` (4 rutas nuevas)
  - `app/resources/views/ia/correcciones/index.blade.php` (UI: checkboxes, action bar, modales, filtro source, toast undo)
  - `app/tests/Feature/CorreccionesBulkModerationTest.php` (NUEVO)
- **Migrations**: 1 nueva (`2026_07_30_120000_create_correction_bulk_actions_table` con 2 tablas: action + items).
- **OpenSpec**: `openspec/changes/2026-07-30-corrections-bulk-moderation/specs/transcription-corrections/spec.md` (4 ADDED requirements).