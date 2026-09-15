## Context

El bug es **estructural del Blade**: en `app/resources/views/ia/api-transcriptor/index.blade.php` el modal "Archivos — …" está escrito como hijo del `<div x-data="apiTranscriptor(...)">` (L6) según la indentación y la posición del fuente (L702 dentro del Storages tab L318), pero el HTML servido al navegador (probado con Playwright y con `curl` autenticado) coloca el modal como **hermano** del wrapper. Esto fuerza a Alpine a evaluar las directivas del modal contra el scope del sidebar, generando 225 warnings de "X is not defined" y un fallo funcional: el `<h2>` del modal queda vacío y los bindings de `filesMode` no aplican el resaltado activo.

La verificación con Playwright bloqueando `alpine.min.js` confirma que la estructura DOM rota viene del HTML parseado por el navegador, no de un side-effect de Alpine.

## Goals / Non-Goals

**Goals:**
- Que el HTML servido coloque `<div x-show="showFiles">` **dentro** del `<div x-data="apiTranscriptor(...)">`.
- Conservar el resto del comportamiento existente sin cambios.
- Cubrir el caso con regression test ejecutable contra el servidor real.

**Non-Goals:**
- No refactorizamos el componente `apiTranscriptor`.
- No reorganizamos los 4 tabs ni el banner de "carpetas sin archivos".
- No tocamos el controller, el modelo, ni los endpoints.
- No introducimos nueva spec de capability (es una corrección a una spec existente).

## Decisions

### D1: Fix de balance de `<div>` en el fuente Blade, no parche runtime
**Por qué:** La causa raíz es indentación/cierre de `<div>` mal contado en el fuente Blade que el compilador está honrando literalmente. Parchearlo en runtime (p. ej. envolver el modal en un `x-data` propio que duplique las refs) duplicaría estado y agregaría complejidad innecesaria. Corregir el balance en el fuente resuelve el problema en su origen y mantiene una sola fuente de verdad.

**Alternativas consideradas:**
- *Envolver el modal en su propio `x-data` con copia de las props* → duplica estado, riesgo de drift, mayor superficie de bugs.
- *Mover el modal dentro del wrapper via JS post-mount (`$el.appendChild(...)`)* → workaround frágil, rompería la lógica de `x-show`/`x-transition` y haría el modal sensible al orden de carga.
- *Convertir el wrapper `apiTranscriptor` en `x-data="{...objeto...}"` inline con todos los 225 keys* → infactible (objeto gigante, perderíamos el método `apiTranscriptor()`).

### D2: Sin migración de BD ni cache key nueva
**Por qué:** El fix es puramente DOM/Blade. Las caches de Redis (`transcriptor:*`) se invalidan solas con el rebuild de la vista. No hay cambio de schema, no hay nuevos endpoints.

### D3: Regression test = extensión del harness `playwright_api_transcriptor_console_errors.py`
**Por qué:** Ya existe el harness que cubre el cambio 2026-09-07 sobre el banner de "carpetas sin archivos". El nuevo escenario (modal dentro del scope) es una extensión natural del mismo flujo. Lo extendemos en vez de crear un script nuevo, siguiendo la convención del repo (ver AGENTS.md §"Harnesses de regresión").

## Risks / Trade-offs

- **[Riesgo] El fix podría mover un `</div>` que cierre otro scope distinto** → Mitigación: validar con `python3 -c "delta tracking"` que el balance general del archivo no se altera, y correr el harness Playwright completo antes de hacer commit.
- **[Riesgo] Hay cambios sin commit en el archivo (412 líneas modificadas)** que podrían estar relacionados con la regresión o ser independientes. → Mitigación: si el `</div>` faltante/extr a está en el rango modificado por cambios sin commit, preguntar al operador antes de tocar; si está en código estable, hacer el fix directamente.
- **[Trade-off] Reescribir el HTML servido en el test podría ser flaky si cambia el orden de los hijos de `<main>` por motivos no relacionados** → Mitigación: el test verifica la **relación de parentesco DOM** (`apiTIsAncestorOfModal`), no el orden ni el conteo de hijos.

## Migration Plan

1. Aplicar el fix en el fuente Blade (cambio de 1-2 líneas).
2. `php artisan view:clear` desde `app/` para invalidar la vista compilada cacheada en `storage/framework/views/`.
3. Recargar `php-fpm` o esperar al siguiente request para que sirva la versión nueva.
4. Correr el harness extendido: `python3 tests/playwright_api_transcriptor_console_errors.py`.
5. Rollback: `git revert <commit>` + `php artisan view:clear` (no hay migración que revertir).

## Open Questions

_Ninguna._ El fix es lo suficientemente acotado como para no necesitar preguntas diferibles. Si el `</div>` faltante resulta estar dentro de los 412 líneas modificadas sin commit, eso lo decidiríamos en el task de implementación, no acá.
