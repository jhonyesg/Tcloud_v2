## Context

Ver `proposal.md` para motivación. El módulo papelera ya está cableado (ver change archivado `2026-09-06-fix-papelera-view-routing`); ahora añadimos affordance educativa porque los usuarios no entienden el ciclo de vida del soft-trash y reportan "duplicidad en BD" o "el archivo desapareció".

Estado actual de `app/resources/views/papelera/index.blade.php`:
- Header con título + subtítulo "Los elementos se eliminan automáticamente después de 15 días"
- Botón "Vaciar papelera" (rojo, deshabilitado si vacía)
- Empty state con ícono + "La papelera está vacía" cuando no hay items
- Tabla con acciones (restaurar / eliminar definitivamente)

El panel se inserta entre el header y el bloque empty/tabla.

Patrón de referencia (idéntico para no inventar visual nuevo): `ia/api-transcriptor/index.blade.php:60-111`.

## Goals / Non-Goals

**Goals:**
- Panel colapsable, cerrado por default, expansible con un click.
- Cuatro bloques de explicación en dos columnas (md:grid-cols-2) en desktop, una columna en móvil.
- Texto plano en español, voz activa, sin eyebrow labels ni ALL CAPS decorativos.
- Términos técnicos (`is_trashed`, `trash:purge`, `original_parent_id`, `-restored-<timestamp>`) en `font-mono bg-slate-100` igual que la referencia.
- Sin cambios en controller, rutas, servicio, migraciones.

**Non-goals:**
- Estado persistente (no se guarda en `localStorage`).
- Tooltips inline en cada fila: ya tenemos el subtítulo general + acciones explícitas.
- Tutorial paso a paso / onboarding guiado.
- i18n.

## Decisions

### D1. Estado local Alpine, sin persistencia

```js
function papeleraApp() {
    return {
        // ... estado existente ...
        showHelp: false,  // panel colapsado por default
        // ... métodos existentes ...
        toggleHelp() { this.showHelp = !this.showHelp; },
    };
}
```

**Por qué:** el panel aparece una vez por sesión naturalmente; los usuarios que ya saben cómo funciona no lo van a abrir. Persistir `localStorage` añade complejidad sin beneficio claro. Si después se observa que el panel se abre mucho, se puede añadir `localStorage` en un follow-up.

**Alternativa descartada:** abrir el panel por default la primera vez y cerrarlo después — agrega cookie/localStorage. Lo evitamos.

### D2. Estructura visual: card blanca con header toggle + grid de 2 columnas

```blade
<div class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <button @click="showHelp = !showHelp"
            :aria-expanded="showHelp"
            class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-slate-50 transition-colors">
        <span class="flex items-center gap-2 text-sm font-semibold text-slate-700">
            <i class="fas fa-circle-info text-brand-500"></i>
            ¿Cómo funciona la papelera?
        </span>
        <i class="fas fa-chevron-down text-xs text-slate-400 transition-transform"
           :class="showHelp ? 'rotate-180' : ''"></i>
    </button>
    <div x-show="showHelp" x-collapse x-transition class="border-t border-slate-100">
        <div class="px-5 py-5 text-sm text-slate-600 grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- 4 bloques: 2 arriba, 2 abajo --}}
        </div>
    </div>
</div>
```

**Por qué:** idéntico a `ia/api-transcriptor/index.blade.php:60-68`. Reutilizamos `bg-white`, `rounded-xl`, `shadow-sm`, `border-slate-200`, `fa-circle-info text-brand-500` y la rotación del chevron. Sin reinventar el chrome.

### D3. Distribución de los 4 bloques en el grid

```
┌─────────────────────────────────┬─────────────────────────────────┐
│ 1. CUANDO BORRAS UN ARCHIVO    │ 2. CUÁNDO SE BORRA SOLO         │
│    • flags en BD                │    • trash:purge diario         │
│    • NO se mueve de disco       │    • retention configurable     │
│    • NO hay duplicidad          │    • guardarraíl anti mass-delete│
│    • recursión a hijos          │    • linked items protegidos    │
├─────────────────────────────────┼─────────────────────────────────┤
│ 3. RESTAURAR VS ELIMINAR       │ 4. ESPACIO Y COMPARTIDOS        │
│    • restaurar vuelve al orig   │    • cuota no se libera         │
│    • colisión → sufijo -restored│    • solo se libera al purgar   │
│    • eliminar borra fila+disco  │    • links públicos → 410 Gone   │
│    • no se puede si tiene links │                                  │
└─────────────────────────────────┴─────────────────────────────────┘
```

Diagrama de 2×2 con bloques de altura variable. En móvil (`< md`) se apilan verticalmente.

**Por qué este orden:**
- (1) Lo primero que el usuario quiere saber: ¿dónde está mi archivo? (resuelve la confusión de "duplicidad").
- (2) Lo segundo: ¿cuánto tiempo me queda?
- (3) Tercero: ¿qué hago con él?
- (4) Cuarto: detalle sobre cuota y links (los menos urgentes).

### D4. Sin cambios en backend, controller, ni servicio

- El panel es puramente presentacional. Ningún endpoint nuevo, ningún cambio de query, ningún cambio de migraciones.
- `PapeleraService`, rutas, sidebar, badge — todo queda igual.

## Risks / Trade-offs

- **[Riesgo] El panel agrega scroll vertical** → en móvil el panel plegado son ~50px, expandido ~400-500px. **Mitigación:** el botón "Vaciar papelera" sigue visible en el header; el listado queda debajo del panel. Si los items son muchos, el usuario hace scroll. Es comportamiento esperado y consistente con `ia/api-transcriptor`.
- **[Riesgo] Copy desactualizado si cambian configs** → los textos hardcoded mencionan `15 días` y `03:17`. **Mitigación:** si después se quiere hacer dinámico, se puede leer de `config('trash.retention_days')` y de `routes/console.php`. Para MVP es estático; documentar como follow-up si hay demanda.
- **[Trade-off] Sin persistencia del estado** → un usuario curioso va a tener que reabrir el panel cada vez. **Aceptado** para MVP; añadir `localStorage` en follow-up si hay datos de uso que lo justifiquen.
- **[Trade-off] Sin icono de "newness" o badge "info"** → si el usuario no sabe que el panel existe, no lo va a abrir. **Mitigación implícita:** el ícono `fa-circle-info text-brand-500` en el header del panel es la affordance estándar de la plataforma; el patrón ya está usado en otras vistas.

## Migration Plan

### Deploy
1. `git pull` (toma el `papelera/index.blade.php` modificado).
2. `php artisan view:clear && php artisan view:cache` (Blade recompila el panel).
3. Smoke test manual: login → `/papelera` → ver header del panel colapsado → click → expandir → leer contenido.

### Rollback
- `git revert <commit>`. No hay migración de BD, no hay estado a limpiar.

### Post-deploy verification (manual, via Playwright)
1. Login con usuario de prueba.
2. `GET /papelera` → screenshot: panel colapsado visible, tabla o empty state debajo.
3. Click en el toggle → screenshot: panel expandido con los 4 bloques.
4. Click de nuevo → screenshot: panel colapsado de nuevo.
5. Verificar que la página `/papelera` sigue respondiendo AJAX JSON al consumer de Alpine.

## Open Questions

Ninguna. Decisiones diferibles a follow-ups sin cambio de specs:
- ¿Persistir `showHelp` en `localStorage`? — Sí si métricas lo justifican.
- ¿Hacer el copy dinámico leyendo `config('trash.retention_days')`? — Sí si se internacionaliza o se permite al admin cambiar la retención sin redeploy.
