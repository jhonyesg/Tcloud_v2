# Design: Moderación en lote

## 1. Schema: tabla de undo log

Necesitamos persistir el estado previo de cada correction afectada por una acción masiva para poder revertirla. Esto es crítico especialmente para el caso de **merge** (donde se modifica `correct_text` de una approved existente).

### 1.1. Nueva migration `2026_07_30_120000_create_correction_bulk_actions_table.php`

```sql
CREATE TABLE correction_bulk_actions (
    id VARCHAR(64) PRIMARY KEY,         -- ULID/UUID para evitar guess
    action VARCHAR(20) NOT NULL,         -- 'bulk_approve' | 'bulk_reject' | 'bulk_destroy'
    performed_by INT NOT NULL REFERENCES users(id),
    performed_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,       -- performed_at + 5min
    undone_at TIMESTAMP NULL,
    undone_by INT NULL REFERENCES users(id),
    superseded_at TIMESTAMP NULL,        -- cuando otra acción toma precedencia
    item_count INT NOT NULL,
    notes TEXT NULL                      -- e.g. rejected_reason
);

CREATE INDEX idx_cba_expires ON correction_bulk_actions(expires_at)
    WHERE undone_at IS NULL AND superseded_at IS NULL;

CREATE TABLE correction_bulk_action_items (
    id SERIAL PRIMARY KEY,
    bulk_action_id VARCHAR(64) NOT NULL REFERENCES correction_bulk_actions(id) ON DELETE CASCADE,
    correction_id INT NOT NULL,          -- la correction que se afectó
    previous_status VARCHAR(20) NOT NULL,
    -- Para undo del MERGE: guardamos el estado de la approved que fue modificada
    merge_target_id INT NULL,            -- id de la approved preexistente
    merge_previous_correct_text TEXT NULL,
    -- Para undo del MERGE: si approved_previous era NULL (no había merge), null
    applied BOOLEAN NOT NULL DEFAULT TRUE  -- false si fue skipeada (no estaba pending)
);

CREATE INDEX idx_cbai_bulk_action ON correction_bulk_action_items(bulk_action_id);
```

### 1.2. Configuración

En `.env`:
```
CORRECTIONS_UNDO_WINDOW_MINUTES=5
```

En `config/corrections.php` (NUEVO si no existe):
```php
return [
    'undo_window_minutes' => env('CORRECTIONS_UNDO_WINDOW_MINUTES', 5),
    'bulk_max_ids' => env('CORRECTIONS_BULK_MAX_IDS', 500),
];
```

## 2. Backend: Service layer

### 2.1. `CorrectionService::bulkApprove(array $ids, User $by): array`

```php
public function bulkApprove(array $ids, User $by): array
{
    $bulkAction = CorrectionBulkAction::create([
        'id' => (string) Str::ulid(),
        'action' => 'bulk_approve',
        'performed_by' => $by->id,
        'performed_at' => now(),
        'expires_at' => now()->addMinutes(config('corrections.undo_window_minutes')),
        'item_count' => count($ids),
    ]);

    // Marcar anteriores bulk_actions activas como superseded
    CorrectionBulkAction::where('performed_by', $by->id)
        ->whereNull('undone_at')
        ->whereNull('superseded_at')
        ->where('id', '!=', $bulkAction->id)
        ->update(['superseded_at' => now()]);

    $approved = 0; $merged = 0; $errors = [];

    foreach ($ids as $id) {
        DB::beginTransaction();
        try {
            $correction = Correction::find($id);
            if (!$correction) {
                $errors[] = ['id' => $id, 'message' => 'no existe'];
                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $id,
                    'previous_status' => 'unknown',
                    'applied' => false,
                ]);
                DB::rollBack();
                continue;
            }
            if ($correction->status !== Correction::STATUS_PENDING) {
                $errors[] = ['id' => $id, 'message' => 'no está pendiente'];
                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $id,
                    'previous_status' => $correction->status,
                    'applied' => false,
                ]);
                DB::rollBack();
                continue;
            }

            // Snapshoteamos el estado ANTES de approve
            $existingApproved = Correction::approved()
                ->where('wrong_normalized', $correction->wrong_normalized)
                ->where('id', '!=', $correction->id)
                ->first();

            $snapshot = [
                'bulk_action_id' => $bulkAction->id,
                'correction_id' => $correction->id,
                'previous_status' => Correction::STATUS_PENDING,
                'merge_target_id' => $existingApproved?->id,
                'merge_previous_correct_text' => $existingApproved?->correct_text,
                'applied' => true,
            ];

            $result = $this->approve($correction, $by);

            CorrectionBulkActionItem::create($snapshot);

            if ($result->status === Correction::STATUS_MERGED) $merged++;
            else $approved++;

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = ['id' => $id, 'message' => $e->getMessage()];
        }
    }

    $bulkAction->update(['item_count' => $approved + $merged]);

    return [
        'approved' => $approved,
        'merged' => $merged,
        'errors' => $errors,
        'bulk_action_id' => $bulkAction->id,
        'undo_expires_at' => $bulkAction->expires_at->toIso8601String(),
    ];
}
```

### 2.2. `CorrectionService::bulkReject(array $ids, ?string $reason, User $by): array`

Mismo patrón. Snapshotea `previous_status='pending'`. Retorna `bulk_action_id`.

### 2.3. `CorrectionService::bulkDestroy(array $ids, User $by): array`

Snapshotea estado completo (incluyendo correct_text, source, applies_count, etc.) antes de DELETE. Retorna `bulk_action_id` PERO `undo_expires_at` puede ser null o muy corto (1 min) porque el undo tiene que restaurar todo el registro. Para DELETE la reversión es restaurar el row desde el snapshot, no solo cambiar status.

**Decisión**: para bulk_destroy el undo es complejo. Documentamos que el endpoint `/undo/{id}` rechaza undo de `bulk_destroy` con 409 Conflict ("Esta acción no es reversible después de la ventana"). En práctica el admin debe usar backup antes.

### 2.4. `CorrectionService::undoBulkAction(string $bulkActionId, User $by): array`

```php
public function undoBulkAction(string $bulkActionId, User $by): array
{
    $bulkAction = CorrectionBulkAction::find($bulkActionId);
    if (!$bulkAction) {
        throw new \RuntimeException('Bulk action no encontrada');
    }
    if ($bulkAction->undone_at) {
        throw new \RuntimeException('Esta acción ya fue revertida');
    }
    if ($bulkAction->superseded_at) {
        throw new \RuntimeException('Esta acción ya no se puede revertir (fue superada por otra)');
    }
    if ($bulkAction->expires_at->isPast()) {
        throw new \RuntimeException('La ventana de undo expiró');
    }
    if ($bulkAction->action === 'bulk_destroy') {
        throw new \RuntimeException('bulk_destroy no es reversible');
    }

    DB::beginTransaction();
    try {
        $items = CorrectionBulkActionItem::where('bulk_action_id', $bulkActionId)
            ->where('applied', true)
            ->get();

        foreach ($items as $item) {
            $correction = Correction::find($item->correction_id);
            if (!$correction) continue; // ya no existe, skip silenciosamente

            // Restaurar status de la correction
            if ($bulkAction->action === 'bulk_approve') {
                // Si era un approve plain (no merge): approved → pending
                // Si era un merge: la correction ya está en 'merged'; restaurar a 'pending'
                $correction->update([
                    'status' => Correction::STATUS_PENDING,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                // Si había merge_target, restaurar el correct_text de esa approved
                if ($item->merge_target_id) {
                    $target = Correction::find($item->merge_target_id);
                    if ($target && $item->merge_previous_correct_text !== null) {
                        $target->update([
                            'correct_text' => $item->merge_previous_correct_text,
                            'approved_at' => $bulkAction->performed_at,
                            'approved_by' => $bulkAction->performed_by,
                        ]);
                    }
                }
            } elseif ($bulkAction->action === 'bulk_reject') {
                $correction->update([
                    'status' => Correction::STATUS_PENDING,
                    'rejected_reason' => null,
                ]);
            }
        }

        $bulkAction->update([
            'undone_at' => now(),
            'undone_by' => $by->id,
        ]);

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        throw $e;
    }

    return [
        'undone' => true,
        'bulk_action_id' => $bulkActionId,
        'items_restored' => $items->count(),
    ];
}
```

**Limitaciones documentadas del undo**:
- Si entre la aprobación y el undo hubo un `corrections:apply-run`, los `applies_count` de las reglas NO se revierten (el undo solo toca status y correct_text del merge target).
- Si una regla fue eliminada manualmente después de ser aprobada, no se puede deshacer esa eliminación (el item del bulk log puede seguir apuntando a un id que ya no existe).
- `bulk_destroy` no es reversible. El admin debe tener backup.

## 3. Backend: Controller

### 3.1. Endpoints bulk

```php
public function bulkApprove(Request $request, CorrectionService $service)
{
    $data = $request->validate([
        'ids' => 'required|array|min:1|max:' . config('corrections.bulk_max_ids'),
        'ids.*' => 'integer|min:1',
    ]);

    $admin = $this->adminUser();
    $result = $service->bulkApprove($data['ids'], $admin);

    return response()->json($result);
}

public function bulkReject(Request $request, CorrectionService $service)
{
    $data = $request->validate([
        'ids' => 'required|array|min:1|max:' . config('corrections.bulk_max_ids'),
        'ids.*' => 'integer|min:1',
        'rejected_reason' => 'nullable|string|max:1000',
    ]);

    $admin = $this->adminUser();
    $result = $service->bulkReject($data['ids'], $data['rejected_reason'] ?? null, $admin);

    return response()->json($result);
}

public function bulkDestroy(Request $request, CorrectionService $service)
{
    $data = $request->validate([
        'ids' => 'required|array|min:1|max:' . config('corrections.bulk_max_ids'),
        'ids.*' => 'integer|min:1',
    ]);

    $admin = $this->adminUser();
    $result = $service->bulkDestroy($data['ids'], $admin);

    return response()->json($result);
}

public function undoBulkAction(string $bulkActionId, CorrectionService $service)
{
    try {
        $admin = $this->adminUser();
        $result = $service->undoBulkAction($bulkActionId, $admin);
        return response()->json($result);
    } catch (\RuntimeException $e) {
        $status = str_contains($e->getMessage(), 'expiró') ? 410
                : (str_contains($e->getMessage(), 'ya fue') ? 409
                : (str_contains($e->getMessage(), 'superada') ? 409
                : 404));
        return response()->json(['error' => $e->getMessage()], $status);
    }
}
```

## 4. Rutas

```php
Route::post('/correcciones/bulk-approve',  [CorreccionesController::class, 'bulkApprove']);
Route::post('/correcciones/bulk-reject',   [CorreccionesController::class, 'bulkReject']);
Route::post('/correcciones/bulk-destroy',  [CorreccionesController::class, 'bulkDestroy']);
Route::post('/correcciones/undo/{bulkActionId}', [CorreccionesController::class, 'undoBulkAction']);
```

## 5. Frontend: Toast de undo

```html
<div x-show="undoToast.visible"
     x-transition.opacity
     class="fixed bottom-6 left-6 z-40 max-w-md">
    <div class="bg-slate-800 text-white rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3">
        <i class="fas" :class="undoToast.icon"></i>
        <div class="flex-1">
            <div class="text-sm font-medium" x-text="undoToast.title"></div>
            <div class="text-xs text-slate-300" x-text="undoToast.detail"></div>
        </div>
        <button @click="performUndo()"
                class="px-3 py-1 bg-brand-500 hover:bg-brand-600 text-white rounded-lg text-sm font-medium"
                x-show="!undoToast.expired">
            Deshacer
        </button>
        <span class="text-xs text-slate-400" x-text="undoToast.countdown" x-show="!undoToast.expired"></span>
    </div>
</div>
```

### 5.1. Estado Alpine (nuevo)

```javascript
undoToast: {
    visible: false,
    title: '',
    detail: '',
    icon: '',
    bulkActionId: null,
    expiresAt: null,
    expired: false,
    countdown: '',
},

showUndoToast(result, action) {
    const messages = {
        bulk_approve: `${result.approved} aprobadas, ${result.merged} consolidadas`,
        bulk_reject: `${result.rejected} rechazadas`,
        bulk_destroy: `${result.deleted} eliminadas`,
    };
    this.undoToast = {
        visible: true,
        title: 'Acción completada',
        detail: messages[action],
        icon: action === 'bulk_approve' ? 'fa-check-circle text-green-400'
              : action === 'bulk_reject' ? 'fa-times-circle text-red-400'
              : 'fa-trash text-orange-400',
        bulkActionId: result.bulk_action_id,
        expiresAt: new Date(result.undo_expires_at),
        expired: false,
        countdown: this.formatCountdown(new Date(result.undo_expires_at)),
    };

    // Auto-expirar cuando llegue a 0
    const tickInterval = setInterval(() => {
        const remaining = this.formatCountdown(this.undoToast.expiresAt);
        this.undoToast.countdown = remaining;
        if (new Date() >= this.undoToast.expiresAt) {
            this.undoToast.expired = true;
            setTimeout(() => this.undoToast.visible = false, 3000);
            clearInterval(tickInterval);
        }
    }, 1000);
},

async performUndo() {
    const id = this.undoToast.bulkActionId;
    if (!id) return;

    try {
        const res = await apiFetch('/ia/correcciones/undo/' + id, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf },
        });
        if (res.ok) {
            const d = await res.json();
            this.undoToast = {
                visible: true,
                title: 'Acción revertida',
                detail: `${d.items_restored} correcciones restauradas`,
                icon: 'fa-undo text-brand-400',
                bulkActionId: null,
                expired: false,
                countdown: '',
            };
            await this.loadPending();
            setTimeout(() => this.undoToast.visible = false, 3000);
        } else {
            const d = await res.json();
            this.undoToast.title = 'No se pudo revertir';
            this.undoToast.detail = d.error || 'Error';
            this.undoToast.icon = 'fa-exclamation-triangle text-red-400';
            this.undoToast.bulkActionId = null;
        }
    } catch (e) {
        this.undoToast.title = 'Error de red al revertir';
    }
},

formatCountdown(expiresAt) {
    const seconds = Math.max(0, Math.round((expiresAt - new Date()) / 1000));
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
},
```

## 6. Tests

### 6.1. Bulk operations
- `test_bulk_approve_approves_multiple_pending` — 3 pending → todas approved, snapshot guardado.
- `test_bulk_approve_marks_merged_when_already_approved` — pending + approved existente → segunda merged, snapshot guarda merge_target.
- `test_bulk_approve_skips_non_pending` — mezcla de statuses → solo pending cambia.
- `test_bulk_approve_reports_errors_for_missing_ids` — IDs inexistentes en errors[].
- `test_bulk_reject_applies_shared_reason` — motivo común.
- `test_bulk_reject_without_reason_works`.
- `test_bulk_destroy_removes_multiple_approved` — y guarda snapshot completo.
- `test_bulk_endpoints_require_authentication` — sin sesión → 403.
- `test_bulk_validate_max_ids` — array de 501 → 422.

### 6.2. Undo
- `test_undo_reverts_approved_to_pending` — bulk approve → undo → todas pending de nuevo.
- `test_undo_restores_merged_target_correct_text` — merge undo restaura el correct_text original.
- `test_undo_reverts_rejected_to_pending` — bulk reject → undo → pending.
- `test_undo_fails_after_window_expires` — modificar `expires_at` al pasado → 410.
- `test_undo_fails_if_already_undone` — segundo undo → 409.
- `test_undo_fails_if_superseded` — bulk nuevo entre medio → undo del viejo → 409.
- `test_undo_only_restores_applied_items` — items con `applied=false` no se tocan.

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Admin aprueba 50 por accidente | Toast con [Deshacer] visible 5min |
| Admin aprueba regla mala que rompe segments | Undo revierte status; si aplicó retroactivo en ventana, undo NO decrementa applies_count (warning visible en UI) |
| Admin marca superseded (hizo 3 bulks en sucesión) | Solo el último tiene undo activo; los anteriores se marcan superseded |
| Admin hace bulk_destroy y luego undo | 409 Conflict — bulk_destroy NO reversible (admin debe tener backup) |
| Race condition entre undo y retroactivo en curso | Las transacciones son cortas; si hay conflicto, el último en COMMIT gana. Worst case: undo se ejecuta pero el retroactivo ya incrementó applies_count. UI muestra warning si retroactivo corrió en la ventana |
| Storage de undo log crece mucho | Cleanup job nocturno: borrar entries con `expires_at < NOW() - 7 days` |

## 8. Cleanup job

`app/app/Console/Commands/CleanupBulkActionsLogCommand.php`:
- Borra `correction_bulk_actions` con `expires_at < now() - 7 days` (CASCADE borra items).
- Corre diario via scheduler.

## 9. Métricas / observabilidad

Cada `correction_bulk_actions` row queda en BD para auditoría. El admin puede ver quién aprobó/rechazó/undo qué y cuándo via una vista futura (no en este scope).