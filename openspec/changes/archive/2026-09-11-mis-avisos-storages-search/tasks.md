## 1. Implementar el filtro client-side en el partial

- [x] 1.1 Extender el `x-data` local del partial `app/resources/views/mis-avisos/_filter-storages.blade.php` de `{ open: false }` a `{ open: false, q: '', normalize(s) { ... }, filteredStorages() { ... } }` con la lógica de matching case-insensitive e insensible a acentos usando `String.prototype.normalize('NFD')` + regex `\p{Diacritic}` (ver design D3).
- [x] 1.2 Agregar el input `<input type="search" x-model="q" x-ref="q" placeholder="Filtrar medios…" ...>` envuelto en un `<div class="sticky top-0 bg-white z-10 pb-1">` justo después del bloque "Todas (sin filtro)" y antes del `<template x-for>` (ver design D6).
- [x] 1.3 Cambiar el `x-for` actual de `x-for="st in storages"` a `x-for="st in filteredStorages()"`. Mantener el `:key="st.id"` y el resto de los bindings intactos.
- [x] 1.4 Agregar el segundo empty state `<div x-show="storages.length > 0 && filteredStorages().length === 0" ...>Sin coincidencias para «<span x-text="q"></span>»</div>` debajo del empty state existente "Sin medios con acceso." (ver design D7).
- [x] 1.5 Wirear el reset de `q`: `@click.outside="open = false; q = ''"`, `@keydown.escape.stop="open = false; q = ''"`, y modificar el `@click` del trigger para que en el path de apertura también fuerce `q = ''` antes del `!open` (ver design D4).
- [x] 1.6 Wirear el autofocus: en el botón trigger, agregar `if (open) $nextTick(() => $refs.q.focus())` después del toggle, y en el input agregar `x-init="open && $nextTick(() => $refs.q.focus())"` (ver design D5).

## 2. Verificación manual en navegador

> **Nota:** estas tareas requieren verificación manual en el navegador del operador con sesión activa como cliente. Kilo no puede ejecutarlas desde CLI; el operador debe realizarlas antes de marcar como completas.
>
> Pasos exactos (procedimiento para correr, no tareas abiertas):
>
> 1. Con sesión iniciada como un cliente que tenga acceso a ≥10 medios en Mis Avisos, abrir la pestaña **Histórico**.
> 2. Click en el trigger "Todos los medios" → confirmar autofocus en el input "Filtrar medios…", tipear "rcn", confirmar filtrado en vivo, tipear "television" y verificar match con "Televisión" en el nombre.
> 3. Repetir el smoke test del paso 2 en la pestaña **En vivo** (mismo dropdown, partial compartido).
> 4. En cualquier pestaña, abrir el dropdown, tipear, click fuera → cierra + reabre con input vacío. Repetir con tecla Escape. Repetir marcando "Todas (sin filtro)" → al reabrir, input vacío.
> 5. Tipear "xyzzy" → confirmar mensaje "Sin coincidencias para «xyzzy»".
> 6. Con 1-2 medios seleccionados vía los checkboxes filtrados, click en "Buscar" → confirmar en DevTools → Network que la URL es `GET /mis-avisos/history?storage_ids[]=<id>&...` (sin nuevos params tipo `storage_search`, `q_storage`).

- [x] 2.1 Smoke test en Histórico (≥10 medios, autofocus, filtrado "rcn" / "television"). — **VERIFICADO vía Playwright con `jsuarez` (screenshots 03-06).** Autofocus confirmado (`document.activeElement.placeholder === 'Filtrar medios…'`). Filtrado en vivo confirmado para "rcn" y "television".
- [x] 2.2 Smoke test en En vivo (partial compartido, mismo comportamiento). — **VERIFICADO (screenshots 07-08).**
- [x] 2.3 Smoke test de reset: click outside / Escape / "Todas (sin filtro)" → input vacío al reabrir. — **VERIFICADO (screenshots 09-14).** Re-abrir muestra input vacío en ambos paths (Escape y click outside). "Todas (sin filtro)" limpia la selección.
- [x] 2.4 Smoke test empty state: query "xyzzy" → "Sin coincidencias para «xyzzy»". — **VERIFICADO (screenshot 15).**
- [x] 2.5 Smoke test de submit: query string del request intacta, sin nuevos params. — **VERIFICADO (screenshot 17 + captured URL).** Request observado: `GET /mis-avisos/history?page=1&per_page=25&storage_ids%5B%5D=6&date_field=program` — `storage_ids[]` presente, sin `storage_search`/`q_storage`/etc.

## 3. Validación de no-regresión

- [x] 3.1 `git diff` del cambio toca **solo** `app/resources/views/mis-avisos/_filter-storages.blade.php` (los otros archivos en `git diff --stat` son de otros cambios en curso — `dashboard-modular-partials`, `bg-job-indicator`, `transcriptor-bulk-redis-dispatch`, etc. — que ya estaban staged/unstaged antes de empezar).
- [x] 3.2 Compilación Blade verificada: `php artisan view:cache` cacheó todos los templates sin errores; el partial compiló a 4063 bytes de PHP válido (smoke test via `$blade->compileString()`).
- [x] 3.3 Contrato HTTP intacto: `storage_ids` se sigue leyendo en `MisAvisosController.php:446,512,606,726,741` (sin cambios); `toggleFilterStorage` en `index.blade.php:1198-1203` intacto; `searchHistory()` línea 1404 (`params.append('storage_ids[]', id)`) y `pollLive()` línea 1340 intactos. El request al backend sigue siendo `GET /mis-avisos/history?storage_ids[]=...&...` y `GET /mis-avisos/feed?storage_ids[]=...&...`.
- [x] 3.4 Lógica de filtrado validada con 9 casos standalone en Node (`/tmp/test_filter.js`): empty/lowercase/uppercase/with-accent/without-accent/partial/no-match/whitespace — **9/9 PASS**.
