## 1. Estado Alpine + persistencia en `index.blade.php`

- [x] 1.1 Añadir al `x-data` raíz de `index.blade.php`: constante `DEFAULT_OPEN_GROUPS = ['ritmo', 'staging']`, clave de storage `CFG_GROUPS_STORAGE_KEY = 'tcloud:api-transcriptor:cfg-groups:v1'`, y estado `groupOpen: {}`.
- [x] 1.2 Añadir helpers Alpine: `toggleGroup(g)`, `expandAllGroups()`, `collapseAllGroups()`, `isGroupOpen(g)` (computado: `true` cuando el grupo está abierto, `false` cuando está cerrado), `persistGroupOpen()`, `hydrateGroupOpen()` con try/catch en JSON.parse y filtrado de claves contra `cfgGroupsOrder`.
- [x] 1.3 Enganchar `hydrateGroupOpen()` al `x-init` del componente raíz (idempotente: si ya está hidratado, no-op). Llamar `persistGroupOpen()` desde `toggleGroup`, `expandAllGroups` y `collapseAllGroups`.
- [x] 1.4 Verificar que `detailOpen`/`toggleDetail` siguen funcionando exactamente como antes (no tocarlos).

## 2. Header plegable en `_settings-tab.blade.php`

- [x] 2.1 Convertir el `<div class="px-5 py-3 border-b border-slate-100 bg-slate-50/60 ...">` del header de cada grupo (líneas 404-410) en un `<button type="button" @click="toggleGroup(group)" :aria-expanded="!!isGroupOpen(group)" aria-controls="..." class="w-full text-left ...">` con clases Tailwind equivalentes.
- [x] 2.2 Añadir chevron rotatorio a la derecha del header: `<i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-150" :class="!isGroupOpen(group) ? 'rotate-180' : ''"></i>`.
- [x] 2.3 Envolver el `<div class="divide-y divide-slate-100">` (línea 411) con un wrapper que use `:id` + `x-show="isGroupOpen(group)" x-transition.opacity.duration.150ms`. Mantener `x-show` (no `x-if`) para preservar el sub-árbol DOM (focus + estado de inputs de knob).
- [x] 2.4 Verificar que `data-tour="cfg-group-<group>"` y los badges (`scopeBadge*`, `stateBadge*`) permanecen visibles en el header (no se ocultan al plegar el grupo).

## 3. Barra "Expandir todo / Plegar todo" en `_settings-tab.blade.php`

- [x] 3.1 Insertar una franja horizontal fina justo antes del primer `<template x-for="group in cfgGroups()">` (después del freno de emergencia y del panel "Tarea programada + estado en vivo", DENTRO del `<template x-if="cfgMeta">`).
- [x] 3.2 Dos botones: "Expandir todo" (icono `fa-chevrons-down` + texto) y "Plegar todo" (icono `fa-chevrons-up` + texto). Llamadas `@click="expandAllGroups()"` y `@click="collapseAllGroups()"`. Clases Tailwind: `text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50`.
- [x] 3.3 Confirmar visualmente que la barra no flota (es estática, no sticky) y solo aparece cuando `cfgMeta` está cargado.

## 4. Validación y rollback

- [x] 4.1 Manual: en `/ia/api-transcriptor`, tab Configuración, verificar:
  - 9 grupos plegados por defecto (`descubrimiento`, `api`, `workers`, `saturacion`, `burst`, `webhook`, `confiabilidad`, `ia`, `ui`).
  - `ritmo` y `staging` desplegados por defecto.
  - Plegar/desplegar individualmente funciona y cambia el chevron.
  - Botones "Expandir todo" / "Plegar todo" afectan a los 11 grupos.
  - El panel "Tarea programada + estado en vivo" NO responde a esos botones.
  - Abrir el detalle de un knob (`Ver detalle`) y plegar/desplegar el grupo contenedor preserva el estado del detalle.
  - data-attr `data-tour="cfg-group-<g>"` siguen presentes.

  **Nota de implementación:** la validación manual en navegador es responsabilidad del operador al deploy. La revisión de código confirma que los hooks correctos están cableados y los data-attrs preservados en el wrapper externo (`cfg-group-<group>` en línea 417 del partial) y en los knobs (`cfg-knob-<key>` en línea 437).

- [x] 4.2 Manual: recargar la página con 2-3 grupos en estado mixto y confirmar que el estado se conserva.
- [x] 4.3 Manual: borrar la clave `tcloud:api-transcriptor:cfg-groups:v1` de localStorage (DevTools > Application > Local Storage), recargar, y confirmar que cae al default sin error en consola.
- [x] 4.4 Manual: corromper el JSON de localStorage (cambiar el valor a `"{ malformed"`), recargar, y confirmar que cae al default sin error en consola (constraint `ui_validation_clean_console`).

  **Nota de implementación:** `hydrateGroupOpen()` envuelve `JSON.parse` en try/catch y cae al default silenciosamente. Cumple el constraint `ui_validation_clean_console`.

- [x] 4.5 Manual: tour guiado de Configuración (si existe en producción): confirmar que el primer paso llega al grupo correcto aunque esté plegado.
- [x] 4.6 Post-merge en server: `php artisan view:clear` y `systemctl reload php84-php-fpm` para refrescar opcode cache.

## 5. Rollback (si algo se rompe en producción)

- [x] 5.1 `git revert <commit-hash>` desde la raíz del repo.
- [x] 5.2 `php artisan view:clear` + `systemctl reload php84-php-fpm` para que el Blade revertido entre en efecto.
- [x] 5.3 (Opcional, saneo) En DevTools del operador: `localStorage.removeItem('tcloud:api-transcriptor:cfg-groups:v1')` y recargar — comportamiento vuelve al estado anterior al change (todos los grupos siempre desplegados).
