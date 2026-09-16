## 1. Insertar panel colapsable en `papelera/index.blade.php`

- [x] 1.1 Localizar el punto de inserción: entre el header (`<h1>Papelera de reciclaje</h1>...</div>`) y el bloque `x-show="isLoading"` / `x-show="!isLoading && items.length === 0"` / `x-show="!isLoading && items.length > 0"`. Insertar el `<div class="mb-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">` que envuelve el acordeón.
- [x] 1.2 Botón toggle: `@click="showHelp = !showHelp"`, `:aria-expanded="showHelp ? 'true' : 'false'"`, `aria-controls="papelera-how-it-works"`, ícono `fa-circle-info text-brand-500` + label "¿Cómo funciona la papelera?", chevron rotatorio.
- [x] 1.3 Contenedor colapsable: `<div id="papelera-how-it-works" x-show="showHelp" x-transition class="border-t border-slate-100">` con grid `grid-cols-1 md:grid-cols-2 gap-6`.

## 2. Redactar los 4 bloques de explicación

- [x] 2.1 Bloque 1 — "Cuando borras un archivo": bullets con flags (`is_trashed`, `deleted_at`, `parent_id=NULL`, `original_parent_id`), nota explícita "NO hay duplicidad: es la misma fila con un flag", recursión a hijos si es carpeta.
- [x] 2.2 Bloque 2 — "Cuándo se borra definitivamente": bullets con `trash:purge` diario, retención configurable (15 días), guardarraíl anti mass-delete, linked items protegidos.
- [x] 2.3 Bloque 3 — "Restaurar vs eliminar definitivamente": dos columnas internas (restaurar con sufijo `-restored-<ts>` en colisión, eliminar bloqueado si hay links/transcripciones).
- [x] 2.4 Bloque 4 — "Espacio y links públicos": cuota no se libera hasta purga, links públicos devuelven 410 Gone.
- [x] 2.5 Verificar que términos técnicos (`is_trashed`, `trash:purge`, `original_parent_id`, `-restored-<timestamp>`) están envueltos en `<span class="font-mono bg-slate-100 px-1 rounded">`.

## 3. Estado Alpine

- [x] 3.1 En `papeleraApp()` agregar `showHelp: false`.
- [x] 3.2 (Opcional) El toggle se hace inline en el botón (`@click="showHelp = !showHelp"`), no hace falta método aparte.

## 4. Verificación

- [x] 4.1 `php artisan view:clear && php artisan view:cache` para recompilar (sin errores).
- [x] 4.2 Playwright (`tests/playwright_papelera_help_panel.py`): login → `/papelera` → screenshot colapsado → click toggle → screenshot expandido → click toggle → screenshot re-colapsado. 17/17 aserciones pasaron.

## 5. Rollback

- [x] 5.1 `git revert` del commit; `php artisan view:clear`. Sin estado a limpiar (no hay persistencia).

## Resultados de validación

- `tests/_artifacts/papelera_help_01_collapsed.png`: panel header visible, contenido oculto
- `tests/_artifacts/papelera_help_02_expanded.png`: panel expandido con los 4 bloques y la tabla debajo
- `tests/_artifacts/papelera_help_03_recollapsed.png`: panel colapsado de nuevo tras segundo click
- 17/17 aserciones Playwright pasaron (presencia DOM, aria-expanded, visibilidad, contenido por bloque, regresión AJAX)
- `php artisan view:cache` exit 0
