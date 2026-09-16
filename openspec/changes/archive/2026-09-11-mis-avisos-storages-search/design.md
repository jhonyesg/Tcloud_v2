## Context

El módulo Mis Avisos tiene un dropdown multi-selección de medios (storage providers con acceso al cliente) renderizado por el partial `app/resources/views/mis-avisos/_filter-storages.blade.php`. El partial es compartido entre las pestañas En vivo e Histórico vía `@include('mis-avisos._filter-storages', ['scope' => 'liveFilters' | 'historyFilters'])` desde `app/resources/views/mis-avisos/index.blade.php` (líneas 417 y 523).

El partial define su propio scope Alpine local `x-data="{ open: false }"`, totalmente independiente del componente padre `misAvisosPage()` definido en `index.blade.php` línea 704. La lista de medios llega al padre como `storages` (inicializado en línea 751 con `@json($accessibleStorages)`) y es accesible desde el scope local del partial por el mecanismo estándar de Alpine (closures / data inheritance). El submit de la selección se serializa en `storage_ids[]` vía `URLSearchParams.append('storage_ids[]', id)` desde `searchHistory()` (línea 1404) y `pollLive()` (línea 1340), y se consume server-side en `MisAvisosController::history()` (línea 606) y `feed()` (línea 446).

Motivación de la propuesta en `proposal.md`. Requirements funcionales en `specs/mis-avisos-storages-filter/spec.md`. Este documento cubre las decisiones técnicas de implementación.

## Goals / Non-Goals

**Goals:**
- Agregar filtrado client-side dentro del popup sin introducir dependencias nuevas ni requests HTTP.
- Mantener el contrato HTTP intacto (`storage_ids[]` sigue siendo el único canal al backend para selección de medios).
- Mantener el partial autocontenido: el estado del filtro vive en el scope local del partial, no en `misAvisosPage()`, para preservar el contrato "modifica un solo archivo".
- Que el cambio aplique gratis a En vivo e Histórico por reutilización del partial.

**Non-goals:**
- No se agrega debounce ni async filtering (la lista es local, ~50-200 items máximo, match O(n) es sub-ms).
- No se persiste la query entre reloads, navegaciones SPA ni sesiones.
- No se modifica el orden de la lista ni la fuente de datos (`$accessibleStorages`).
- No se introduce una librería de normalización de texto (un helper inline con `String.prototype.normalize` es suficiente).

## Decisions

### D1. Scope local del partial para `q` (no en `misAvisosPage()`)

**Decisión:** El estado del input de búsqueda (`q`) vive en el `x-data` local del partial junto con `open`, no se sube al componente padre.

**Rationale:** El partial ya aísla su estado (`open`) del padre. Subir `q` al padre violaría el contrato actual del partial (que recibe solo `$scope` como parámetro) y obligaría a coordinar dos `q` independientes (uno por scope). Mantenerlo local mantiene el archivo como la única unidad de cambio.

**Alternativa considerada:** Mover `q` a `misAvisosPage()` con prefijo por scope (`liveFilters.searchQ`, `historyFilters.searchQ`). Rechazada porque:
- Obliga a modificar dos archivos (partial + `index.blade.php`).
- Crea estado que sobrevive a la apertura del popup, contradiciendo el requirement de reset al cerrar.
- No agrega valor: el padre nunca consume `q` para nada (es 100% cliente-side).

### D2. Helper de filtrado como método del scope local

**Decisión:** Exponer `filteredStorages()` como método en el scope local del partial. La iteración se hace con `x-for="st in filteredStorages()"`.

**Rationale:** Alpine.js re-evalúa el `x-for` cuando cambian sus dependencias reactivas. Como `filteredStorages()` lee `this.storages` y `this.q` (ambas reactivas), la lista se re-filtra automáticamente al tipear sin lógica extra.

**Alternativa considerada:** Computar el filtro inline con `storages.filter(s => match(s))` directamente en `x-for`. Rechazada porque:
- Repite la lógica de matching en cada `x-for` (alpine no cachea).
- Hace menos legible el template.
- El método permite testear la lógica de matching como unidad JS pura si más adelante se quisiera.

### D3. Normalización con `String.prototype.normalize('NFD')`

**Decisión:** Implementar `normalize(s)` así:
```js
normalize(s) {
    return (s || '')
        .toString()
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');
}
```

**Rationale:** El cliente hispanohablante suele tipear sin tildes ("television", "caracol", "noticias"). `normalize('NFD')` descompone los caracteres con diacríticos en base + combining mark, y la regex `\p{Diacritic}` elimina las combining marks. Esto da match "television" ↔ "Televisión" sin necesidad de listas hardcodeadas.

**Alternativas consideradas:**
- Lista explícita de reemplazos (`á→a`, `é→e`, ...): más rápida pero brittle para todos los casos Unicode (no cubre ñ→n si el usuario la busca, ni caracteres menos comunes).
- `String.localeCompare` con `sensitivity: 'base'`: API más limpia pero inconsistente entre navegadores y más lenta por crear collators. Innecesario para un dataset chico.

### D4. Reset de `q` con `$nextTick` después de cerrar el popup

**Decisión:** Vincular el reset a la **transición de cierre**, no al inicio de la siguiente apertura. Implementación: cuando `open` pasa de `true` a `false`, también se setea `q = ''`. Mecánicamente:
- `@click.outside="open = false; q = ''"`
- `@keydown.escape.stop="open = false; q = ''"`
- El botón trigger ya tiene `@click="open = !open"` — al re-abrir no se resetea automáticamente (porque `!true = false`); agregamos explícitamente `q = ''` al toggle del trigger para que cualquier path de cierre+apertura limpie.

**Rationale:** El requirement pide que el input esté vacío al **reabrir** (post-close). La forma más robusta es resetear al momento del close, no condicionar al open (que tendría race con `$nextTick`).

**Alternativa considerada:** Usar `x-effect="if (open) q = ''"` para resetear al abrir. Funciona, pero introduce un tick donde el usuario ve brevemente el valor viejo antes del reset. Visualmente peor.

### D5. Autofocus con `$refs` + `x-init`

**Decisión:** En el input: `x-ref="q"`, `x-init="open && $nextTick(() => $refs.q.focus())"`. Adicionalmente, en el botón trigger: `@click="open = !open; if (open) $nextTick(() => $refs.q.focus())"`.

**Rationale:** Alpine expone `$refs` para acceder a elementos por `x-ref`. `x-init` se ejecuta una vez al montar; necesitamos foco en cada apertura, por eso también lo disparamos desde el trigger click. `$nextTick` espera al próximo tick del DOM para asegurar que el popup esté visible antes de focar.

**Alternativa considerada:** Usar el atributo HTML5 `autofocus` en el input. Rechazada porque solo funciona en el foco inicial de la página, no en reaperturas del popup. Tampoco respeta la asincronía del `x-show`.

### D6. Sticky positioning del input de búsqueda dentro del popup

**Decisión:** El input va envuelto en un `<div class="sticky top-0 bg-white z-10 pb-1">` para que permanezca visible al hacer scroll de la lista filtrada.

**Rationale:** Si el filtro devuelve muchos resultados y el popup tiene `max-h-72 overflow-y-auto`, el input se perdería al scrollear. Sticky top lo mantiene accesible para refinar el filtro o limpiarlo.

**Alternativa considerada:** No usar sticky (asumir que la lista filtrada cabe en pantalla). Rechazada porque contradice el caso de uso principal: cliente con muchos medios que justo gracias al filtro ve una lista acotada pero aún necesita scrollear para encontrar el correcto.

### D7. Empty state dual con condicionales separados

**Decisión:** Dos bloques condicionales:
- `<div x-show="storages.length === 0" ...>Sin medios con acceso.</div>` (existente, sin cambios).
- `<div x-show="storages.length > 0 && filteredStorages().length === 0" ...>Sin coincidencias para «<span x-text="q"></span>»</div>` (nuevo).

**Rationale:** Diferenciar las dos causas de lista vacía (sin medios vs sin matches) evita confusión: el cliente que ve "Sin medios con acceso" sabe que es un tema de permisos/alta; el que ve "Sin coincidencias para «rc»" sabe que es la query que escribió.

**Alternativa considerada:** Un único bloque con mensaje dinámico (`x-text="storages.length === 0 ? 'Sin medios...' : 'Sin coincidencias...'"`). Más conciso pero menos descubrible al leer el template; además el `<span x-text="q">` adentro agrega anidamiento.

### D8. Sin cambios en backend, controlador, modelo, ni rutas

**Decisión:** Cero cambios fuera del partial.

**Rationale:** El filtrado es 100% visual / client-side. El input nunca se serializa al submit (no tiene `name`, no se incluye en `URLSearchParams`). El backend ya consume `storage_ids[]` correctamente y no necesita enterarse de la UX del filtro.

**Validación de no-regresión:** El comportamiento de `toggleFilterStorage('{{ $scope }}', st.id, checked)` (index.blade.php:1198-1203) y `searchHistory()` / `pollLive()` no se toca. Las queries `MisAvisosController::storages()` (líneas 404-424) e `index()` (líneas 41-52) que cargan `$accessibleStorages` no se tocan.

## Risks / Trade-offs

- **[R1] `filteredStorages()` se re-evalúa en cada render del template, no solo cuando cambia `q`** → Con listas de ~50-200 items y un template chico, es despreciable. Si en el futuro un cliente tiene 1000+ medios, refactorizar a un getter cacheado. Mitigación: monitoring del `loadKeywords()` y del `accessibleStorages` count en producción para detectar este caso.

- **[R2] `normalize('NFD')` con regex `\p{Diacritic}` requiere soporte Unicode moderno** → Todos los navegadores soportados por el proyecto (Chrome/Edge/Firefox/Safari actuales) lo soportan desde 2018+. No es un riesgo real.

- **[R3] Sticky dentro de `overflow-y-auto` puede no funcionar si el contenedor padre tiene `overflow-hidden`** → El popup padre tiene `overflow-y-auto`, lo cual sí permite sticky en descendientes. Verificado mentalmente con el flow CSS estándar. Mitigación: si se rompiera en algún navegador, fallback es quitar el sticky y aceptar scroll-through del input.

- **[R4] El autofocus del input puede ser molesto para usuarios que navegan con teclado y querían tab-ar al primer checkbox** → Trade-off aceptable: la mayoría de los usuarios abren el dropdown para filtrar (caso de uso principal del change). Para usuarios de teclado avanzados, `Tab` desde el input sigue funcionando normalmente.

- **[R5] El reset al cerrar destruye una query que el usuario podría querer preservar** → Por diseño. El spec lo requiere explícitamente. Si en el futuro se quiere persistir la query entre aperturas, basta con eliminar el `q = ''` del handler de cierre.

## Migration Plan

No requiere migración:
- No hay migración de BD.
- No hay deploy especial (es un cambio Blade + Alpine).
- El cambio es **backward-compatible**: si por algún motivo Alpine no ejecuta el scope local (no debería pasar), el dropdown funciona idéntico al estado previo, simplemente sin el input de búsqueda visible.
- Para revertir basta con `git revert <commit>` del cambio (un solo archivo).

## Open Questions

Ninguna. Todas las decisiones de UX fueron confirmadas en la fase de exploración con el usuario.
