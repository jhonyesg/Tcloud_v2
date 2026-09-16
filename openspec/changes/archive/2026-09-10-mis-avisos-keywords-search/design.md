## Context

El módulo Mis Avisos tiene un `<select>` nativo para elegir keyword en cada una de sus dos pestañas (En vivo e Histórico). El `<select>` está en `app/resources/views/mis-avisos/index.blade.php` líneas ~418-424 (En vivo) y líneas ~524-533 (Histórico). El array de keywords vive en `misAvisosPage()` como `keywords: <JSON>` (línea 717-727), con shape `[{id, text, storage_ids, category_id}]`.

La propuesta y motivación completa están en `proposal.md`; los requirements funcionales están en `specs/mis-avisos-keywords-filter/spec.md`. Este documento cubre las decisiones técnicas —el "cómo"— para implementar la spec.

El patrón del dropdown de medios está archivado en `openspec/changes/archive/2026-09-10-mis-avisos-storages-search/`. Las decisiones D1-D8 de ese design se aplican aquí (autogestión de `q`, método `filteredStorages()`, normalización NFD, reset con `$nextTick`, autofocus con `$refs`, sticky input, empty state dual, sin cambios en backend). Las decisiones D9+ que son específicas del caso keywords están aquí.

## Goals / Non-Goals

**Goals:**
- Crear un partial espejado del `_filter-storages` para keywords, con radio buttons (single-select) en vez de checkboxes.
- Reutilizar exactamente la misma normalización (sin crear una librería compartida — son pocas líneas y mantener cada partial autocontenido evita acoplamientos cruzados).
- Mantener el contrato HTTP intacto (`keyword_id` como entero único).
- Hacer que el auto-apply por pestaña sea **configurable al incluir el partial**, no un toggle del padre.

**Non-goals:**
- No se agrega un componente "Selector multi-keyword" — el dominio es single-select.
- No se introduce un sistema de auto-apply genérico — cada `<select>` que se migre en el futuro se resuelve a nivel de partial.
- No se mueve estado al padre (`misAvisosPage()`); el input de búsqueda vive en el scope local del partial, igual que en storages.

## Decisions

### D1. Replicar el partial completo en lugar de extraer un componente compartido

**Decisión:** `_filter-keywords.blade.php` se reescribe desde cero en vez de importar helpers compartidos de `_filter-storages.blade.php`.

**Rationale:** Los dos partials comparten el 90% del markup (popup, sticky search input, empty states), pero las diferencias semánticas son importantes: checkboxes vs radios, single-select vs multi-select, contador vs label de keyword seleccionada, auto-apply vs manual. Mantener cada partial autocontenido (por duplicación) evita un tercer archivo de configuración que añadiría complejidad por poca ganancia.

**Alternativa considerada:** Extraer `_search-dropdown.blade.php` con props (`multi`, `countLabel`, `autoApply`). Más DRY, pero añade una capa de indirección y vincula el ciclo de vida de ambos módulos. Si en el futuro un tercer dropdown necesita lo mismo, ahí sí justifica refactorizar — pero con dos casos es premature optimization.

### D2. Radio buttons en lugar de `<input list="...">` (datalist)

**Decisión:** Usar un `<template x-for>` con radio buttons estilizados igual que los checkbox del partial de medios (mismo `w-3.5 h-3.5 accent-brand-600`, mismo hover/border).

**Rationale:** Las `<datalist>` nativas de HTML5 permiten filtrar pero:
- La presentación es del navegador (no se puede personalizar el popup).
- En Safari/iOS el comportamiento de filtrado varía.
- No se puede usar Alpine para autofocus ni para reset controlado.
- No permiten empty states custom.

Un dropdown custom con radio buttons espeja consistencia visual y UX con el de medios, y respeta todos los requirements del spec.

**Alternativa considerada:** Mantener el `<select>` con el atributo nativo `list=` apuntando a un `<datalist>`. Rechazada por las razones anteriores.

### D3. Auto-apply como `@if($autoApply)` Blade condicional

**Decisión:** El partial acepta una variable Blade `$autoApply` que es o bien el nombre del método del padre (`'applyLiveFilters'`), o null. En el partial:

```blade
@change="{{ $autoApply ? $autoApply . '()' : '' }}"
```

**Rationale:** El método `applyLiveFilters()` ya existe en `misAvisosPage()` y Alpine resuelve expresiones buscando en scopes padres. Pasar el nombre del método como string y emitirlo en la expresión Blade es la forma más simple de configurar el comportamiento por inclusión.

**Alternativa considerada:** Usar un flag `$autoApply: bool` y llamar a un wrapper `selectKeyword(value)` que decida. Más limpio pero requiere un método wrapper en el partial que internamente llama a `this.$root.applyLiveFilters()` — más indirección. La expresión Blade directa es menos código.

### D4. Trigger label usa el `text` de la keyword seleccionada, no un contador

**Decisión:** El label del trigger es:
- `keyword_id === 0` → `"Todas mis keywords"`.
- Else → `<span x-text="currentKeywordText() || 'Todas mis keywords'"></span>` con un helper `currentKeywordText()` que hace `this.keywords.find(k => Number(k.id) === Number(this[scope].keyword_id))?.text`.

**Rationale:** Coincide con el `<select>` nativo, donde el botón muestra el texto de la opción seleccionada. En single-select siempre es 0 o 1, así que "N keyword(s)" sería ruidoso. Si la keyword desaparece del array (caso edge: se borró en otra pestaña), el `|| 'Todas mis keywords'` cae al fallback para no dejar un label huérfano.

**Alternativa considerada:** Mostrar `"1 keyword"` siempre que haya selección. Más simple que buscar el texto, pero pierde el valor UX de mostrar al usuario qué keyword está aplicada (que es justamente lo que hace el `<select>` original).

### D5. Selección cierra el popup y resetea `q`

**Decisión:** El `@change` del radio ejecuta:
```js
this[scope].keyword_id = Number(value);
this.q = '';
this.open = false;
@if($autoApply) this.{{ $autoApply }}(); @endif
```

**Rationale:** En single-select no hay razón para mantener el popup abierto tras la elección. Cerrar reduce fricción ("ya elegí, ya está"). Y como cerrar implica resetear `q` (per spec), no hay forma de que el input aparezca en otro estado que no sea vacío al reabrir.

**Alternativa considerada:** No cerrar el popup tras seleccionar. Pero rompería la consistencia con el patrón de storages (donde marcar checkboxes tampoco cierra el popup; es multi-select). En single, cerrar es lo natural.

### D6. Sin cambios en `index.blade.php` fuera de los dos `<select>` blocks

**Decisión:** El único cambio en `index.blade.php` es reemplazar dos bloques `<select>` por dos `@include`. No se toca `misAvisosPage()` factory, ni los métodos `applyLiveFilters()`, `searchHistory()`, `pollLive()`, ni nada que no sean los dos `<select>`.

**Rationale:** Refuerza el cambio mínimo y verificable. Toda la lógica nueva vive en el partial.

### D7. No usar `x-cloak` adicional — `x-show` ya lo hace

**Decisión:** El popup usa `x-show="open"` igual que el partial de medios. No se agrega wrapper `x-cloak`.

**Rationale:** El layout `layouts/app.blade.php` ya tiene `[x-cloak] { display: none !important }` global (mismo que usa storages). Replicar exactamente.

## Risks / Trade-offs

- **[R1] Radio buttons + Alpine requieren `@click.stop` en la label** — Mismo patrón que en storages: si no, click en el label puede disparar `@click.outside`. Mitigación: agregar `@click.stop` a cada label.

- **[R2] Si Alpine no carga el scope local, los radios siguen funcionando** — Porque Alpine los renderiza con los attrs `:checked` que se aplican al primer paint. Si Alpine no monta el scope, los radios se ven pero no responden. Mitigación: riesgo aceptable, es la misma degraded-UX que el cambio de storages.

- **[R3] Cambio en el width del trigger al cambiar de keyword** — El label del trigger cambia de "Todas mis keywords" (20 chars) a "Petro" (5 chars). El botón cambia de ancho. Visualmente puede ser confuso si el usuario estaba mirando el ancho al click. Mitigación: aceptable, es exactamente el comportamiento del `<select>` original; no introduce un nuevo surprise.

- **[R4] Si una keyword tiene texto > 30 caracteres, el trigger puede ser muy ancho** — Aceptable. Las keywords existentes en TCloud suelen ser de 1-3 palabras. Si en el futuro hay keywords largas, se puede truncar con `truncate max-w-xs`.

## Migration Plan

No requiere migración:
- Sin migración de BD.
- Sin deploy especial (Blade + Alpine).
- Backward-compatible: si Alpine no ejecuta el scope local, el partial renderiza con todos los radios visibles desde el primer paint (degraded but functional — Alpine `:checked` resuelve al hidration).
- Para revertir: `git revert <commit>` (un partial + dos edits en `index.blade.php`).

## Open Questions

Ninguna. El spec sigue el patrón del archivado `mis-avisos-storages-search`; las decisiones nuevas (radio vs checkbox, auto-apply configurable, label con texto) están justificadas arriba.
