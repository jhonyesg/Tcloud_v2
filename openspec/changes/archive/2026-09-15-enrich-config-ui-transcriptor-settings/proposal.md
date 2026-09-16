# enrich-config-ui-transcriptor-settings

## Why

La UI de Configuración del módulo API Transcriptor muestra los 54 settings con helps cortos (1-2 líneas) que no permiten entender **a quién afecta** cada opción (este servidor / API remota / mixto), **si es experimental o de uso manual**, ni **cuándo conviene tocarla**. La conversación del 2026-09-15 documentó la frustración del operador con knobs de "purpose oscuro" en este módulo. Adicionalmente, el panel no tiene iconos, por lo que la lectura es monótona y la información semántica del dominio (LOCAL/REMOTO/MANUAL/EXPERIMENTAL) está ausente.

## What Changes

**Schema (`TranscriptorSettings::SCHEMA`)** — cada entry gana 3-4 campos nuevos:

- `icon` (str, requerido): clase FontAwesome 6 Free (`fa-…`) que adorna el knob.
- `scope` (str, requerido): uno de `local`, `remoto`, `mixto`.
- `state` (str, requerido): uno de `live`, `experimental`, `manual`, `obsoleto`.
- `detail` (array, opcional): detalle estructurado para el acordeón expandible, con keys `alcance`, `cuando_tocar`, `riesgos`. Solo presente en entradas donde suma información al `help` corto.

De los 54 settings, **10 reciben `detail` completo** (los más complejos):
`dispatch_paused`, `target_pg_queue`, `scope`, `regulator_mode`, `target_remote_queue`, `floor_remote_queue`, `audio_output_format`, `burst_max_upstream_queue`, `ai_coherence_enabled`, `submit_with_callback`.

Los 44 restantes conservan el help de 1 línea y campos icon/scope/state.

**Helps reescritas** (54 entries, no solo las 10 detalladas): se enriquecen las 10 primeras; el resto se queda con su help actual (ya validado por operador). Se documenta en `tasks.md` cuáles se reescriben y cuáles se conservan.

**Layout del panel (`_settings-tab.blade.php`)**:

- Icono por grupo (en el `<h3>` del header).
- Icono por knob (a la izquierda del label).
- Badges de `scope` (LOCAL/REMOTO/MIXTO) y `state` (EXPERIMENTAL/MANUAL/OBSOLETO) — LIVE no se muestra para no saturar.
- Botón "▸ Ver detalle" que abre un acordeón inline (no popover flotante). Usa `x-collapse` de Alpine.
- Detalle estructurado en 3 secciones (alcance / cuándo tocar / riesgos).

**Plugin Alpine `x-collapse`** (oficial, ~5KB) agregado al layout (`resources/views/layouts/app.blade.php` o donde el módulo cargue Alpine). Animación de altura al expandir/colapsar.

## Capabilities

### New Capabilities

Ninguna. Es refactor de UI + enriquecimiento de schema. No introduce comportamiento nuevo.

### Modified Capabilities

Ninguna. La lógica de los 54 settings no cambia; lo que cambia es su visibilidad y descripción.

## Non-goals

- NO se cambia la persistencia, validación, ni comportamiento runtime de los 54 settings.
- NO se borran los 14 settings ocultos del change anterior; siguen visibles.
- NO se hace commit en esta fase. La fase `apply` se hace cuando el operador dé el OK para salir de explore.
- NO se rediseñan los controles (toggle/enum/input number quedan igual).
- NO se agregan tabs, accordions por grupo, ni filtros. Solo enriquecimiento lineal.

## Impact

**Archivos modificados (3-4):**

- `app/app/Services/Ia/TranscriptorSettings.php`:
  - 54 entries del SCHEMA: +3 campos (`icon`, `scope`, `state`) por cada una.
  - 10 entries: +1 campo `detail` con 3 secciones estructuradas.
  - Reescritura del help en 10 entries (las detalladas).
- `app/config/transcriptor.php`:
  - **Tarea 1.1b** (agregada por el operador durante la fase de discovery): +1 entry `scan_skip_latest_per_storage` que el schema declara pero el config no respalda (mismo patrón que el cleanup change).
- `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php`:
  - Header de grupo: icono + label + help corto (sin cambios estructurales).
  - Knob: icono + label + badge scope + badge state + help + acordeón de detail + control.
  - ~120-200 líneas modificadas.
- `app/resources/views/layouts/app.blade.php` (o equivalente donde se carga Alpine):
  - 1 línea: agregar `<script defer src="https://unpkg.com/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>` antes del cierre de Alpine.

**APIs / rutas / modelos**: ninguno.

**Migraciones de BD**: ninguna.

**Riesgo**: bajo. Cambios son aditivos al schema (no se borra ningún campo existente). Tests existentes verifican `validationRules()`, `keys()`, `effective()` — no se tocan esos métodos.

**Rollback**: `git revert <commit>`. Sin estado persistente.

## Caveats heredados (no resueltos aquí, registrados para futuras iteraciones)

- Hay 4 settings en grupo `burst_*` que el cron NO ejecuta automáticamente (declarado en el change `expose-all-transcriptor-settings-in-config-ui`). Con los nuevos badges `[MANUAL]` queda explícito; el badge ayuda a evitar la confusión que vos reportaste.
- Hay 2 settings en grupo `webhook_*` marcados `[EXPERIMENTAL]` por el constraint del schema; el operador debe coordinarlos con la API upstream antes de activarlos.
- El plugin `x-collapse` suma 1 CDN a la carga inicial. Si te preocupa el peso, se puede cambiar a `x-show` simple en un cambio posterior sin tocar el resto.
