## Context

La pestaña **Configuración** del módulo API Transcriptor vive en `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` (996 líneas) y se inyecta en `index.blade.php` (Alpine.js, kebab-case, usa `cfgGroups()`, `cfgKeysIn(group)`, `cfgMeta[k]`, `detailOpen`, `toggleDetail`). Los 11 grupos de knobs se renderizan con un `<template x-for="group in cfgGroups()" :key="group">` (líneas 402-508 de la partial) y dentro del grupo otro `<template x-for="k in cfgKeysIn(group)" :key="k">` para cada knob. El header del grupo (líneas 404-410) tiene icono + título + subtítulo en una franja `bg-slate-50/60` con borde inferior, pero el handler de plegado **no existe**: solo el body tiene su acordeón `detailOpen` por knob.

El estado del componente Alpine vive en un objeto raíz declarado con `x-data="..."` al inicio de `index.blade.php`. Los patrones a imitar ya están en producción: `detailOpen = {}` + `toggleDetail(k) { this.detailOpen = { ...this.detailOpen, [k]: !this.detailOpen[k] } }` (líneas 1144-1148). La persistencia cross-tab se hace en otras pantallas vía `window.tcloudFeatures` y `localStorage` con patrón `x-init` (ver `AGENTS.md`, sección sobre `FEATURE_MIS_ARCHIVOS_TRANSCRIPT_VIEWER=false`).

No hay backend que tocar: el contrato HTTP `/ia/api-transcriptor/config/{get,set}` queda igual, los settings guardan idéntico, los badges, sticky save bar y tour data-attrs se preservan.

## Goals / Non-Goals

**Goals:**
- Implementar el contrato de comportamiento descrito en `specs/transcriptor-config-collapsible-groups/spec.md` con el patrón Alpine ya en uso (`detailOpen` / `toggleDetail`).
- Persistir el estado en `localStorage` con la clave `tcloud:api-transcriptor:cfg-groups:v1` (versión en la clave para permitir migraciones futuras).
- Garantizar cero impacto en console (constraint UI_validation_clean_console) cuando el localStorage está corrupto: caer al default sin lanzar excepciones.
- Mantener compatibilidad con tours existentes (data-attrs intactos).

**Non-Goals:**
- NO se hace colapsable el panel "Tarea programada + estado en vivo" (líneas 34-399).
- NO se cambia el patrón `detailOpen` por knob.
- NO se añade persistencia para `detailOpen`.
- NO se introducen nuevas dependencias (ni paquetes npm, ni librerías JS, ni código PHP).
- NO se modifica ningún endpoint, ningún setting key, ningún schema BD.

## Decisions

### D1. Estado en el mismo Alpine `x-data`, no en nuevo componente

**Decisión:** añadir `groupOpen: {}`, `groupOpenLoaded: false`, `toggleGroup(g)`, `expandAllGroups()`, `collapseAllGroups()`, `hydrateGroupOpen()`, `persistGroupOpen()` al objeto raíz de `index.blade.php` (donde ya vive `detailOpen` y `toggleDetail`).

**Por qué:** consistencia con el patrón existente. El partial `_settings-tab.blade.php` solo necesita leer el flag y emitir eventos via `@click="toggleGroup(group)"`.

**Alternativa considerada:** crear un sub-componente Alpine (`x-data="groupAccordion()"`). Descartada: añade fricción para una sola pieza de estado local y obliga a mover el `<template x-for>` fuera del cuerpo actual.

### D2. Persistencia en `localStorage` con versión en la clave

**Decisión:** clave `tcloud:api-transcriptor:cfg-groups:v1` con valor `JSON.stringify(groupOpen)`. Si el valor falla el parse, se ignora silenciosamente y se cae al default.

**Por qué:**
- `localStorage` es la única pieza de almacenamiento client-side disponible sin backend, ya es el patrón del proyecto (ver `AGENTS.md` para `FEATURE_MIS_ARCHIVOS_TRANSCRIPT_VIEWER=false`).
- El sufijo `:v1` permite migrar el shape si en el futuro se quiere guardar más cosas sin romper storage viejo.
- Sin prefijo `tcloud:` la key podría colisionar con otras apps en el mismo dominio (no es el caso aquí, pero es barato blindarse).

**Alternativas consideradas:**
- Cookie: requiere enviar al backend en cada request, innecesario.
- IndexedDB: overkill para un objeto de 11 booleanos.
- Backend (tabla `system_settings`): NO — eso es para preferencias del servidor, no del usuario. Además introduce latencia de fetch + invalidación que no aporta nada.

### D3. Hidratación con `x-init` separado del toggle

**Decisión:** un solo `x-init` por instancia del componente raíz lee localStorage una sola vez al montar y rellena `groupOpen`. Después, `toggleGroup`, `expandAllGroups`, `collapseAllGroups` SIEMPRE persisten tras cada cambio.

**Por qué:** simple. Si quisiéramos debouncing de escritura (para no escribir en cada keystroke) tendría sentido, pero aquí cada cambio es un click humano y la frecuencia es mínima. No hay motivo para debounce.

**Alternativa considerada:** usar `$watch` para persistir. Descartada: `x-init` + persistencia explícita es más legible y predecible.

### D4. Default por defecto codificado en una constante

**Decisión:** una constante JS dentro de la closure del componente:
```js
const DEFAULT_OPEN_GROUPS = ['ritmo', 'staging'];
```
usada por `hydrateGroupOpen()` cuando el storage está vacío o corrupto.

**Por qué:** única fuente de verdad para el default; si en el futuro se quiere cambiar "qué grupos se abren por defecto", se toca en un solo lugar y la spec se actualiza.

### D5. Botones "Expandir/Plegar todo" como barra simple, no flotante

**Decisión:** una franja horizontal fina justo encima del primer `<template x-for="group in cfgGroups()">` con `bg-white` + borde + dos botones con icono. Se oculta si `!cfgMeta` (mismo `<template x-if="cfgMeta">` que envuelve a los grupos).

**Por qué:** patrón ya usado en el proyecto para barras de tooling (ver botón "Escanear storages" en la misma vista, modal del batch). Visible siempre que la lista esté cargada, sin estorbar el contenido.

**Alternativa considerada:** poner los botones en el header sticky inferior junto a "Guardar cambios". Descartada: ya tiene su propio patrón de save bar con badge de "Cambios sin guardar"; mezclar dos conceptos distintos allí lo vuelve confuso.

### D6. Estructura del header-clickable

**Decisión:** el `<div class="px-5 py-3 border-b border-slate-100 bg-slate-50/60 ...">` actual (líneas 404-410) se convierte en `<button type="button" @click="toggleGroup(group)" :aria-expanded="!!groupOpen[group]" aria-controls="cfg-group-body-${group}" class="w-full text-left ...">`. El `<div class="divide-y divide-slate-100">` que envuelve los knobs se envuelve en un `<div :id="..." x-show="!cfgMeta || groupOpen[group] !== false" x-collapse>` o, más simple, `x-show="groupOpen[group] !== false"`.

**Por qué:**
- `<button>` da accesibilidad gratis (focus, teclado, screen readers, `aria-expanded`).
- `x-show` (vs `x-if`) preserva el sub-árbol DOM → el input del knob mantiene foco si el operador edita y se reabre, no se re-renderiza.
- `x-collapse` de Alpine no es necesario: la altura variable del grupo podría causar saltos con `x-show` puro, pero el contenido es una lista de filas de knob que ya tienen su propia transición. Usamos `x-transition.opacity.duration.150ms` igual que `detailOpen`.

**Alternativa considerada:** `<details>`/`<summary>` HTML nativos. Descartada: gestión de estado Alpine/localStorage requeriría listeners manuales; mezclar ambos es peor.

### D7. Chevron en el header

**Decisión:** añadir un `<i class="fas fa-chevron-down text-slate-400 text-xs transition-transform" :class="groupOpen[group] === false ? 'rotate-180' : ''"></i>` a la derecha del header. Rotación con `transition-transform` nativa de Tailwind (consistente con cómo `bg-`/`text-` ya se anima via utility).

**Por qué:** rotación 180° es visualmente más limpio que cambiar entre dos iconos chevron-down/chevron-right.

**Nota:** `rotate-180` con `transition-transform` rota instantáneamente. Para animar suavemente, Tailwind necesita `duration-150` añadido a la clase.

## Risks / Trade-offs

- [Riesgo] `localStorage` puede no estar disponible (modo privado de Safari, políticas IT) → **Mitigación:** todos los accesos van envueltos en `try { localStorage... } catch {}`. Si falla, comportamiento = sin persistencia (el default aplica siempre, y los cambios del operador en la sesión siguen funcionando, simplemente no sobreviven a recargar).

- [Riesgo] Storage corrupto JSON → **Mitigación:** `JSON.parse` envuelto en try/catch; si falla, se ignora y se cae al default `DEFAULT_OPEN_GROUPS`.

- [Riesgo] Storage con claves desconocidas (futuro: si el operador borra un grupo, queda la clave muerta) → **Mitigación:** al hidratar se filtra contra `cfgGroupsOrder` y se descartan las claves que no estén. No se eliminan del storage en cada load para no escribir innecesariamente; se podan en cada `persistGroupOpen()`.

- [Riesgo] Console error visible en validación UI (constraint `ui_validation_clean_console`) → **Mitigación:** todos los try/catch silenciosos. No se usa `console.warn` "para debug" en producción. Si el operador reporta algo raro, se inspecciona manualmente.

- [Riesgo] Tour guiado espera grupos abiertos → **Mitigación:** los tours data-attr están en los headers, que SIEMPRE se renderizan (incluso plegados); el scrollTo + highlight funciona igual. Si el operador arrancó el tour y el primer paso pide desplegar, el tour debe disparar la apertura antes del scroll (verificar contra el driver de tour actual; si no lo hace, anotarlo como follow-up).

- [Riesgo] Animación con `x-show` puede causar "flash" de contenido al cargar si el estado hidratado es "abierto" → **Mitigación:** usar `x-cloak` si fuese necesario (en este caso no aplica: el grupo arranca ya en su estado correcto, no hay animación de entrada).

## Migration Plan

**Pre-deploy:** ninguno. Es un cambio puramente client-side.

**Deploy:**
1. Mergear el PR.
2. `php artisan view:clear` (o el equivalente del proyecto) para que Blade recompile el `_settings-tab.blade.php`.
3. `systemctl reload php84-php-fpm` para refrescar opcode cache (mismo patrón que rollback de `fix-transcriptor-batch-bg-launcher`).

**Post-deploy:** verificar manualmente en `/ia/api-transcriptor` que:
- Los 9 grupos plegados por defecto están cerrados.
- `ritmo` y `staging` están abiertos.
- Expandir / plegar manualmente actualiza el estado de inmediato.
- Recargar la página preserva el estado.
- Botones "Expandir todo" / "Plegar todo" funcionan.
- Console del navegador está limpia.

**Rollback:** trivial. `git revert <commit>` + `php artisan view:clear` + `reload php-fpm`. El estado en localStorage queda como "basura" con la clave `:v1` huérfana; el siguiente deploy del revert vuelve a abrir todos los grupos (porque ese código no sabe leer el storage), pero eso es inofensivo.

**Freno de emergencia sin deploy:** no hay flag. Si el operador reporta que el accordion UI se comporta raro, el rollback completo es la única palanca. Para evitar esto, las pruebas manuales en post-deploy son el seguro.

## Open Questions

Ninguna que deba resolverse antes de implementar.
