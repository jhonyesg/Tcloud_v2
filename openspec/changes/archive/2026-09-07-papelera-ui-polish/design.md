## Context

Ver `proposal.md`. La maqueta actual de `/papelera` se construyó con el mínimo viable en el change archivado `2026-09-06-fix-papelera-view-routing`. Ahora se hace un pase de polish enfocado en **comunicar el ciclo de vida del soft-trash** que ya existía en los datos pero no se mostraba bien.

## Goals / Non-Goals

**Goals:**
- Stat tiles que dan contexto agregado (4 números).
- Progress bar por fila como visualización primaria del ciclo de vida.
- Filtros client-side para reducir ruido.
- Banner urgente cuando aplica.
- Responsive cards en móvil.

**Non-goals:**
- Persistencia del filtro.
- Reemplazar el botón "Vaciar papelera".
- Chart de tendencia histórica (overkill para una vista administrativa simple).

## Decisions

### D1. Backend: nuevo método `PapeleraService::statsFor(int $userId)`

```php
public function statsFor(int $userId): array
{
    $urgentThreshold = (int) config('trash.urgent_threshold_days', 3);
    $retentionDays = (int) config('trash.retention_days', 15);
    $cutoff = now()->subDays($retentionDays);
    $urgentCutoff = now()->subDays($retentionDays - $urgentThreshold);

    $base = File::trashed()->where('owner_id', $userId);
    $total = (clone $base)->count();
    $urgent = (clone $base)->where('deleted_at', '>=', $urgentCutoff)->count();

    // Space that will be freed at next purge: items that will actually be hard-deleted
    // (excluding linked items that the purge guardrail would skip).
    $purgableItems = (clone $base)->where('deleted_at', '<', $cutoff)->get();
    $sizeBytes = 0;
    foreach ($purgableItems as $f) {
        if (!$this->isFileLinked($f->id)) {
            $sizeBytes += (int) $f->size;
        }
    }

    // Next purge: 03:17 of next day if already past today's 03:17, else today 03:17
    $now = now();
    $nextPurge = $now->copy()->setTime(3, 17);
    if ($nextPurge->isPast()) {
        $nextPurge->addDay();
    }

    return [
        'total' => $total,
        'urgent' => $urgent,
        'critical' => (clone $base)->where('days_remaining', '<=', 1)->count(),
        'size_bytes' => $sizeBytes,
        'next_purge_date' => $nextPurge->toIso8601String(),
    ];
}
```

**Por qué:**
- Single query para contar + 1 query para los purgable items (necesitamos isFileLinked check).
- El `next_purge_date` se calcula en PHP porque el cron está hard-coded a 03:17 (routes/console.php:68). Si se cambia el schedule, también hay que cambiar este cálculo.

**Alternativas descartadas:**
- Hacer el cálculo en JS puro: requiere pasar más datos al frontend.
- Llamar `countFor` y extender: `countFor` es por-user con cache; `statsFor` agrega size_bytes + next_purge_date. Conviven.

### D2. Frontend: progress bar con color shifting

```html
<div class="mt-1 h-1 bg-slate-100 rounded-full overflow-hidden">
    <div :class="progressClass(item)" :style="`width: ${(item.days_remaining/15)*100}%`"></div>
</div>
```

```js
progressClass(item) {
    const pct = (item.days_remaining / 15) * 100;
    if (pct > 30) return 'h-full bg-brand-500';
    if (pct > 10) return 'h-full bg-amber-500';
    return 'h-full bg-red-500';
}
```

**Por qué:** un solo dato (días restantes) se muestra de dos formas complementarias — número + barra — sin duplicar información. La barra es primary visual; el número es accesibilidad.

**Alternativas descartadas:**
- Hacer la barra con clases Tailwind precomputadas: requeriría muchas clases para cada rango; el approach computacional es más limpio.
- Mostrar la fecha absoluta de purga en vez de días: requiere date math mental; los días son más inmediatos.

### D3. Stat tiles — minimal, no decoration

```
┌──────────────────┐
│  12              │  ← font-semibold, text-2xl
│  En papelera     │  ← text-sm, text-slate-500
└──────────────────┘
```

Sin border-radius distinto, sin sombra extra, sin gradiente. Mismo `rounded-lg border border-slate-200` que el resto. Cuatro tiles en grid 2x2 móvil, 1x4 desktop.

**Por qué:** consistencia. La página ya tiene `files/index` con tiles; este módulo debe sentirse parte del mismo sistema, no decorado especialmente.

### D4. Filtros — chips planos

```html
<button @click="filter = 'all'" :class="filter === 'all' ? 'bg-brand-500 text-white' : 'bg-white text-slate-600 border border-slate-200'">
    Todos ({{ items.length }})
</button>
```

Sin transiciones fancy. El chip activo se distingue por color de fondo sólido; los inactivos con border sutil.

### D5. Banner urgente — amber, dismissable

```html
<div x-show="stats.urgent > 0" x-transition class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-3">
    <i class="fas fa-exclamation-triangle text-amber-500"></i>
    <span class="text-sm text-amber-800">
        Tienes <b x-text="stats.urgent"></b> archivo<span x-show="stats.urgent > 1">s</span> que se borrarán en menos de 3 días.
        <a @click.prevent="filter = 'urgent'" href="#" class="underline">Ver cuáles</a>
    </span>
</div>
```

Click en el link cambia el filtro a `urgent`. No es dismissable — el banner aparece si la condición es verdadera; desaparece cuando el cron los purga o el usuario los restaura.

### D6. Responsive cards — flex < sm:

```html
<!-- Desktop: tabla -->
<div class="hidden sm:block">[tabla]</div>

<!-- Móvil: cards -->
<div class="sm:hidden space-y-2">
    <template x-for="item in items" :key="item.id">
        <div class="bg-white border border-slate-200 rounded-lg p-3">
            <div class="flex items-center justify-between">
                <span x-text="item.name" class="font-medium truncate"></span>
                <span x-text="item.days_remaining + 'd'" class="text-sm font-mono"></span>
            </div>
            <div class="mt-2 h-1 bg-slate-100 rounded-full overflow-hidden">
                <div :class="progressClass(item)" :style="`width: ${(item.days_remaining/15)*100}%`"></div>
            </div>
            <div class="mt-2 flex justify-end gap-2">
                <button @click="restore(item.id)" class="text-xs text-brand-600">Restaurar</button>
                <button @click="confirmHardDelete(item)" class="text-xs text-red-600">Eliminar</button>
            </div>
        </div>
    </template>
</div>
```

Mismos datos que la tabla, layout apilado.

## Risks / Trade-offs

- **[Riesgo bajo] Performance** → el método `statsFor` ejecuta queries adicionales (1 count + 1 query de purgable items). Para usuarios con cientos de items, sigue siendo sub-100ms. Aceptable.
- **[Riesgo bajo] Mismatch entre `countFor` y `statsFor`** → ambos calculan `urgent` independientemente. Si `urgent_threshold_days` cambia entre requests, podrían diferir brevemente. Aceptable porque ambos leen la misma config.
- **[Trade-off] Filter chip "Todos" count** → se calcula como `items.length` en JS, no del server. Refleja items cargados, no items totales en BD. Si hay más items que per_page, el badge muestra el conteo de la página actual.

## Migration Plan

### Deploy
1. `git pull` (toma 3 archivos modificados).
2. `php artisan view:clear && php artisan view:cache`.
3. Smoke test manual: login → /papelera → ver tiles, progress bars, filtros, banner.

### Rollback
- `git revert <commit>`.

### Post-deploy verification
1. Playwright (`tests/playwright_papelera_ui_polish.py`):
   - Stat tiles render with values.
   - Progress bar present per row.
   - Filter chip narrows the list.
   - Banner appears when `urgent > 0`, hidden otherwise.
   - Cards visible en viewport 375x667 (iPhone SE).
2. Regresión: `playwright_papelera_view.py`, `playwright_papelera_help_panel.py`, etc.

## Open Questions

Ninguna.
