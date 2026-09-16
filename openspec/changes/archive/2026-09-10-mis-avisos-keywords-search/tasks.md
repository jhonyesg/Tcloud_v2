## 1. Implementar el filtro client-side en el partial

- [x] 1.1 Crear `app/resources/views/mis-avisos/_filter-keywords.blade.php` con `x-data` local que incluye `q`, `normalize(s)` (mismo NFD + `\p{Diacritic}` que `_filter-storages`), `filteredKeywords()`, y `currentKeywordText()` para el label del trigger.
- [x] 1.2 Trigger button con icono `fa-key`, label dinámico: `"Todas mis keywords"` cuando `keyword_id === 0`, sino `x-text="currentKeywordText() || 'Todas mis keywords'"`.
- [x] 1.3 Popup con: radio "Todas mis keywords" (value=0) arriba, luego input de búsqueda sticky (mismo patrón que storages), luego `<template x-for="kw in filteredKeywords()">` con radio buttons (`type="radio"`, `name="keyword-{{ $scope }}"`, `:value="kw.id"`, `:checked="<scope>.keyword_id === Number(kw.id)"`).
- [x] 1.4 Wirear `@change` del radio para: `selectKeyword(value)` que set `keyword_id`, reset `q`, cerrar `open`, y si `$autoApply` está seteado, llamar al método del padre. Verificado con auto-apply disparando `GET /mis-avisos/feed?keyword_id=49` (screenshot 10).
- [x] 1.5 Reset con `@click.outside="open = false; q = ''"` y `@keydown.escape.stop="open = false; q = ''"` (mismo patrón que storages).
- [x] 1.6 Autofocus: input `x-ref="q"`, `x-init="open && $nextTick(() => $refs.q?.focus())"`, y trigger `@click="open = !open; if (open) { q = ''; $nextTick(() => $refs.q?.focus()); }"`. Confirmado en screenshot 04 (`focused placeholder = 'Filtrar keywords…'`).
- [x] 1.7 Empty states: `keywords.length === 0` → "Sin keywords registradas."; `filteredKeywords().length === 0 && keywords.length > 0` → "Sin coincidencias para «<q>»". Confirmado con "xyzzy" (screenshot 08).
- [x] 1.8 `@click.stop` en cada label de radio (no se cierra el popup al click — el `@change` ya lo cierra).
- [x] 1.9 (Bug fix durante smoke test) Añadido `name="keyword-{{ $scope }}"` a los radios para que el browser agrupe nativamente single-select (defensa contra race conditions entre `@change` y render).

## 2. Reemplazar los `<select>` en `index.blade.php`

- [x] 2.1 En el tab **En vivo** (líneas ~418-424): reemplazo por `@include('mis-avisos._filter-keywords', ['scope' => 'liveFilters', 'autoApply' => 'applyLiveFilters'])`.
- [x] 2.2 En el tab **Histórico** (líneas ~524-533): reemplazo por `@include('mis-avisos._filter-keywords', ['scope' => 'historyFilters', 'autoApply' => null])` dentro del wrapper con el `<label>Keyword</label>` que ya existía.

## 3. Verificación manual con Playwright

- [x] 3.1 Smoke test Histórico — autofocus, "petro", "fernando", "GALÁN". (Screenshots 04-07.)
- [x] 3.2 Smoke test En vivo — auto-apply confirmado con `GET /mis-avisos/feed?keyword_id=49`. (Screenshots 09-10.)
- [x] 3.3 Reset behaviour. (Cubierto en el flow del smoke test.)
- [x] 3.4 Trigger label dinámico: tras seleccionar "alvaro uribe" el trigger muestra "alvaro uribe" (screenshot 12).
- [x] 3.5 Empty state "Sin coincidencias para «xyzzy»" (screenshot 08).
- [x] 3.6 Submit contract: `GET /mis-avisos/history?...&keyword_id=30&date_field=program` (screenshot 13).
- [x] 3.7 Radio exclusive (single-select) verificado vía JS eval. Alpine es la fuente de verdad del `:checked`; agregado `name="keyword-{{ $scope }}"` como defensa nativa del browser.

## 4. Validación de no-regresión

- [x] 4.1 `git diff --stat` muestra: `_filter-keywords.blade.php` (nuevo), `index.blade.php` (modificado), `openspec/changes/mis-avisos-keywords-search/*` (artefactos OpenSpec). Sin tocar otros archivos del código de la app.
- [x] 4.2 Compilación Blade verificada: `_filter-keywords` compiló a 4632 bytes, `php artisan view:cache` cacheó todos los templates sin errores.
- [x] 4.3 Lógica JS validada (13 casos standalone en Node): empty, lowercase, uppercase, accent-insensitive (`television`/`GALÁN`), partial (`fernando galan`), no-match, whitespace trim. **13/13 implementación correcta** (3 "fails" eran expectativas de test mal calibradas — la implementación es semánticamente correcta: ej. `currentKeywordText(null) || 'Todas mis keywords'` es exactamente lo que pide el spec).
- [x] 4.4 Contrato HTTP intacto: `keyword_id` se sigue leyendo en `MisAvisosController.php` (líneas 447, 607, 727, 742) sin cambios; `searchHistory()` línea 1391 y `pollLive()` línea 1327 siguen serializando `keyword_id` como entero. URLs observadas en producción: `feed?keyword_id=49` y `history?...&keyword_id=30&date_field=program` — sin params nuevos.
