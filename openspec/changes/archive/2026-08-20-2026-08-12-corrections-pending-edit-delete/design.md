# Design: Editar y eliminar correcciones pendientes

## Overview

Cambio **principalmente de UI** con un único endpoint backend nuevo (`PATCH /correcciones/{id}`). Reutiliza los endpoints existentes `DELETE /correcciones/{id}` y `POST /correcciones/bulk-destroy`.

```text
┌─────────────────────────────────────────────────────────────────┐
│ Tab: Pendientes (x-show="tab === 'pending'")                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ☐  wrong_text → correct_text    Proponente  Fecha   Acciones  │
│  ☐  io        → Io      ✗       miner-1     ago     [...]      │
│                                                                 │
│                       ┌─────────────────────────┐               │
│                       │ Acciones por fila       │               │
│                       ├─────────────────────────┤               │
│                       │ [Aprobar]  [Rechazar]   │  ← ya existe   │
│                       │ [Excluir]               │  ← ya existe   │
│                       │ [Editar]   [Eliminar]   │  ← NUEVO       │
│                       └─────────────────────────┘               │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│ Sticky bar (N seleccionadas)                                   │
│   [✓ Aprobar N]  [✗ Rechazar N]  [🛡 Excluir N]                 │
│   [🗑 Eliminar N]                                               │  ← NUEVO
└─────────────────────────────────────────────────────────────────┘
```

## Backend

### Endpoint nuevo: PATCH /ia/correcciones/{id}

**Path**: `PATCH /ia/correcciones/{id}` (en `app/routes/web.php:230-233`, dentro del grupo `/ia`).

**Auth**: misma del grupo (admin autenticado por sesión).

**Validación**:
```php
[
    'wrong_text' => 'required|string|max:500',
    'correct_text' => 'required|string|max:500',
]
```

**Status guard**: 409 si `correction->status !== 'pending'`.

**Lógica** (`CorrectionService::updatePending` nuevo):

```
1. DB::transaction begin
2. Lock correction row (findOrFail, status='pending' check)
3. Compute wrongNorm = Keyword::asciiLower(trim(wrong_text))
4. If wrongNorm === '' || trim(correct_text) === '' → throw InvalidArgumentException (422)
5. Check if existing approved correction has same wrongNorm:
   - If yes → mark current as merged (same as propose() line 312-321)
   - Return merged correction
6. Check if another pending correction has same wrongNorm (excluding self):
   - If yes → upsert into that row: update wrong_text/correct_text, return refreshed
   - If no → update current row's wrong_text/correct_text/wrong_normalized, save
7. Return updated correction
```

Re-normaliza `wrong_normalized` con `Keyword::asciiLower(trim($wrong))` — mismo helper que `propose()` (`CorrectionService.php:298`).

### Endpoint existente reutilizado: DELETE /ia/correcciones/{id}

`CorreccionesController::destroy()` (`CorreccionesController.php:392`) — sin cambios. Ya funciona sobre cualquier status.

### Endpoint existente reutilizado: POST /ia/correcciones/bulk-destroy

`CorreccionesController::bulkDestroy()` (`CorreccionesController.php:863`) — sin cambios. Ya funciona y registra `CorrectionBulkAction` para auditoría (`bulk_destroy` es no-reversible por diseño — `CorrectionService.php:1114`).

### Controller

Nuevo método en `CorreccionesController`:

```php
public function update(Request $request, int $id, CorrectionService $service)
{
    $data = $request->validate([
        'wrong_text' => 'required|string|max:500',
        'correct_text' => 'required|string|max:500',
    ]);

    $correction = Correction::findOrFail($id);
    if ($correction->status !== Correction::STATUS_PENDING) {
        return response()->json([
            'error' => 'Solo se pueden editar correcciones pendientes.',
        ], 409);
    }

    $admin = $this->adminUser();
    $updated = $service->updatePending(
        $correction,
        $data['wrong_text'],
        $data['correct_text'],
    );

    return response()->json(['correction' => $updated->load('proposedBy')]);
}
```

### Ruta

En `app/routes/web.php` después de línea 232 (junto a `store`):

```php
Route::patch('/correcciones/{id}', [App\Http\Controllers\Ia\CorreccionesController::class, 'update'])->whereNumber('id');
```

### Service

Nuevo método en `app/app/Services/Ia/CorrectionService.php` siguiendo el patrón de `propose()`:

```php
public function updatePending(Correction $correction, string $wrong, string $correct): Correction
{
    $wrongNorm = Keyword::asciiLower(trim($wrong));
    $correct = trim($correct);

    if ($wrongNorm === '' || $correct === '') {
        throw new \InvalidArgumentException('wrong y correct no pueden estar vacíos.');
    }

    return DB::transaction(function () use ($correction, $wrong, $correct, $wrongNorm) {
        // Si ya hay approved con mismo wrong_normalized (y no es esta misma fila)
        $existingApproved = Correction::approved()
            ->where('wrong_normalized', $wrongNorm)
            ->where('id', '!=', $correction->id)
            ->first();
        if ($existingApproved) {
            $correction->update([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
                'status' => Correction::STATUS_MERGED,
            ]);
            return $correction->fresh();
        }

        // Si hay OTRA pending con mismo wrong_normalized (upsert sobre esa)
        $existingPending = Correction::where('status', Correction::STATUS_PENDING)
            ->where('wrong_normalized', $wrongNorm)
            ->where('id', '!=', $correction->id)
            ->latest()
            ->first();
        if ($existingPending) {
            $existingPending->update([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
            ]);
            $correction->delete(); // huérfana, fuera
            return $existingPending->fresh();
        }

        $correction->update([
            'wrong_text' => $wrong,
            'correct_text' => $correct,
            'wrong_normalized' => $wrongNorm,
        ]);
        return $correction->fresh();
    });
}
```

## UI

### Modal "Editar corrección pendiente"

Patrón espejo del modal "Nueva corrección" (`index.blade.php:1384-1405`) y del modal "Rechazar" (línea 1408). Estado Alpine nuevo:

```js
editForm: { open: false, item: null, wrong: '', correct: '' },
```

Handlers:

```js
openEditPending(c) {
    this.editForm = { open: true, item: c, wrong: c.wrong_text, correct: c.correct_text };
}
async saveEditPending() {
    const id = this.editForm.item.id;
    const res = await apiFetch('/ia/correcciones/' + id, {
        method: 'PATCH', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({
            wrong_text: this.editForm.wrong,
            correct_text: this.editForm.correct,
        }),
    });
    if (res.ok) {
        const d = await res.json();
        this.editForm.open = false;
        if (d.correction.status === 'merged') {
            // desapareció como pending: refrescar lista
            await this.loadPending();
        } else {
            // actualizar in-place
            const idx = this.pending.findIndex(p => p.id === id);
            if (idx >= 0) this.pending[idx] = d.correction;
        }
    } else {
        const d = await res.json().catch(() => ({}));
        alert(d.error || 'Error al editar.');
    }
}
```

### Botón "Editar" en cada fila

En `index.blade.php:295-301` añadir:

```html
<button @click="openEditPending(c)"
        class="px-3 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs rounded-lg">
    <i class="fas fa-pen"></i> Editar
</button>
```

### Botón "Eliminar" por fila

En `index.blade.php:295-301` añadir:

```html
<button @click="destroyPending(c)"
        class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-700 text-xs rounded-lg">
    <i class="fas fa-trash"></i> Eliminar
</button>
```

Handler:

```js
async destroyPending(c) {
    if (!confirm(`¿Eliminar esta sugerencia? No es una corrección, es ruido del flujo origen (no se contabilizará como rechazada).\n\n"${c.wrong_text}" → "${c.correct_text}"`)) return;
    const res = await apiFetch('/ia/correcciones/' + c.id, {
        method: 'DELETE', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
    });
    if (res.ok) {
        this.selectedIds.delete(c.id);
        await this.loadPending();
    } else {
        const d = await res.json().catch(() => ({}));
        alert(d.error || 'Error al eliminar.');
    }
}
```

### Acción masiva "Eliminar N"

En el sticky bar `index.blade.php:1347-1360` añadir botón (después de `Excluir N`):

```html
<button @click="bulkDestroyPending()"
        class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-xl text-sm font-medium">
    <i class="fas fa-trash"></i> Eliminar <span x-text="selectedIds.size"></span>
</button>
```

Handler (espejo de `bulkDestroyApproved()` en `index.blade.php:2565`):

```js
async bulkDestroyPending() {
    const ids = Array.from(this.selectedIds);
    if (ids.length === 0) return;
    if (!confirm(`Vas a eliminar ${ids.length} sugerencias pendientes. Esta acción NO se puede deshacer y NO se contabiliza como rechazada. ¿Continuar?`)) return;
    const res = await apiFetch('/ia/correcciones/bulk-destroy', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ ids }),
    });
    if (res.ok) {
        this.clearSelection();
        await this.loadPending();
    } else {
        const d = await res.json();
        alert(d.error || 'Error al eliminar en lote.');
    }
}
```

### Modal Editar (markup)

Patrón espejo del modal Nueva corrección (líneas 1384-1405). Insertar después del modal Rechazo en lote.

## Tour guiado

Aplicar `interactive_tours_must_include_new_features`: el paso del tab Pendientes en `TcloudTour` debe mencionar el botón Editar (con icono `fa-pen`) y Eliminar (con icono `fa-trash`), explicando la diferencia semántica entre rechazar (mantener audit) y eliminar (borrar sin rastro).

## Archivos a modificar

- `app/routes/web.php` — agregar `Route::patch('/correcciones/{id}', ...)`
- `app/app/Http/Controllers/Ia/CorreccionesController.php` — método `update()`
- `app/app/Services/Ia/CorrectionService.php` — método `updatePending()`
- `app/resources/views/ia/correcciones/index.blade.php` — botones fila, sticky bar, modal, handlers Alpine
- `app/resources/js/tours/*` o donde esté `TcloudTour` — actualizar paso Pendientes
