## Why

La pestaña **Configuración** del módulo API Transcriptor (`app/resources/views/ia/api-transcriptor/_settings-tab.blade.php`, 996 líneas) muestra **siempre expandidos** los 11 grupos de knobs (`ritmo`, `staging`, `descubrimiento`, `api`, `workers`, `saturacion`, `burst`, `webhook`, `confiabilidad`, `ia`, `ui`) más el panel "Tarea programada + estado en vivo" (~366 líneas de monitoreo: cola, workers, estados, pipeline, remoto, saturación). El operador entra a tocar **1 knob** y tiene que escrolear 4-5 pantallas con todo desplegado. Solo el acordeón **por knob individual** (`Ver detalle` / `Ocultar detalle`) existe hoy; los grupos completos y el panel de monitoreo **no se pueden colapsar**.

El cambio de hoy hace que los grupos completos sean **plegables multi-selección** (varios abiertos a la vez), con botones "Expandir todo / Plegar todo" en la parte superior de la pestaña. Por defecto quedan plegados todos **excepto** `ritmo` y `staging` (los más tocados). El panel de monitoreo grande **NO** entra en este alcance — solo los 11 grupos de knobs.

## What Changes

- **Acordeón multi-selección** en el header de cada uno de los 11 grupos de knobs (líneas 402-508 de `_settings-tab.blade.php`):
  - Click en el header (icono + título + subtítulo + chevron) abre/cierra solo el cuerpo de ese grupo.
  - Estado independiente por grupo (no es acordeón "uno-a-la-vez").
  - Persistencia en `localStorage` (key `tcloud:api-transcriptor:cfg-groups:v1`) para recordar qué grupos estaban abiertos entre recargas.
- **Botones "Expandir todo" / "Plegar todo"** en una franja fina arriba del primer grupo, visibles siempre que el listado de knobs esté cargado.
- **Estado por defecto (primera carga, sin localStorage):** `ritmo` y `staging` abiertos, los otros 9 plegados. Decisión de diseño validada con el operador el 2026-09-15.
- **Animación:** `x-transition` con la misma curva y duración que `detailOpen` ya usa en los knobs (150 ms, opacity).
- **Patrón consistente** con el acordeón por knob (`detailOpen` / `toggleDetail` en `index.blade.php:1145-1148`): mismas clases CSS, mismo icono chevron, misma transición.

**Lo que NO cambia:**
- Acordeón por knob individual (`detailOpen`/`toggleDetail`) — ya existe, sigue funcionando idéntico.
- metadata: `cfgGroups`, `cfgGroupsOrder`, `cfgGroupLabels`, `cfgGroupIcons`, `cfgGroupHelps`, `cfgKeysIn(...)` — sin tocar.
- data-attrs de tour (`cfg-group-<grupo>`, `cfg-knob-<key>`, `cfg-save`, etc.) — sin tocar.
- Badges, sticky save bar, freno de emergencia, modal de progreso, modal del batch — sin tocar.
- Backend, endpoints, schema BD, settings keys.

## Capabilities

### New Capabilities
<!-- Sin nuevas capabilities de sistema. Los grupos plegables son estado de UI -->

### Modified Capabilities
<!-- Ningún requisito observable cambia: el knob guarda lo mismo, el endpoint guarda lo mismo, la validación es la misma. El "está abierto/cerrado" es estado local de Alpine + localStorage, no contrato de sistema. -->

**Este es un refactor puramente presentacional. Se marca `skip_specs: true`.**

## Impact

- **Archivos afectados:** 2
  - `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` (cabecera de los 11 grupos de knobs + franja de botones "Expandir/Plegar todo").
  - `app/resources/views/ia/api-transcriptor/index.blade.php` (estado Alpine del componente: `groupOpen`, `toggleGroup`, `allGroupsOpen`, `x-init` de hidratación desde localStorage).
- **APIs / rutas:** ninguna.
- **BD / migraciones:** ninguna.
- **Tests:** ninguno nuevo obligatorio. El harness `harness_dashboard_partials.php` no aplica (otro módulo). Si se quiere, se puede añadir una aserción Playwright/Cypress en otro change.
- **Persistencia:** `localStorage` solamente, no Redis, no BD. Si la key está corrupta, el `x-init` debe caer al default sin romper el render.
- **Riesgo tour:** los tours data-attr siguen en su sitio. Si el operador arranca un tour con grupos plegados, el primer paso del tour debe funcionar con el accordion cerrado (los tours ya hacen scroll al elemento).

## Non-goals

- **NO** se hace colapsable el panel "Tarea programada + estado en vivo" (~366 líneas, líneas 34-399). Ese queda fuera del alcance por ahora; si se quiere después, otro change.
- **NO** se cambia el patrón `detailOpen` por knob — ya funciona y se conserva tal cual.
- **NO** se añade persistencia de `detailOpen` por knob (solo de los grupos completos).
- **NO** se cambian labels, icons, orden, ni metadata de los grupos.
- **NO** se tocan los modales (progreso, batch, confirmaciones), el freno de emergencia, ni la barra de guardado.
- **NO** se modifica la pestaña de Storages ni la de Jobs — solo Configuración.
- **NO** se añaden tests automatizados nuevos en este change.
