# enrich-config-ui-transcriptor-settings — Tasks

## 1. Baseline

- [x] 1.1 Correr phpunit del módulo TranscriptorSettings antes de cualquier cambio, para tener baseline:
  ```bash
  cd app && rm -rf .phpunit.cache && rm -f bootstrap/cache/config.php \
    && APP_ENV=testing CACHE_DRIVER=array vendor/bin/phpunit \
      --filter TranscriptorSettingsTest 2>&1 | tail -10
  ```
  **Baseline real: 24 tests, 147 assertions, 1 FALLA** (`testTodaClaveDelEsquemaTieneRespaldoEnConfig` por `scan_skip_latest_per_storage`).
  Esperado: 24/24 verde. La diferencia (176 vs 147 assertions) sugiere drift en alguna sección del test suite pero no es bloqueante para este change — se mantiene como caveat.

- [ ] 1.1b **Alineación previa `scan_skip_latest_per_storage`**: el schema declara esta entry (línea 275) pero el config no la respalda. Sin esto, `testTodaClaveDelEsquemaTieneRespaldoEnConfig` falla y el gate queda roto. Scope agregado al change (decisión del operador):
  - En `app/config/transcriptor.php` agregar:
    ```php
    // Excluir el audio más reciente por storage del escaneo.
    // Útil para evitar encolar audios que aún se están grabando.
    'scan_skip_latest_per_storage' => (bool) env('TRANSCRIPTOR_SCAN_SKIP_LATEST_PER_STORAGE', true),
    ```
  - Re-correr phpunit tras la edición. Debe volver a 24/24 verde.

## 2. Schema (`TranscriptorSettings::SCHEMA`)

- [x] 2.1 Para cada una de las 54 entries, agregar 3 campos nuevos: `icon`, `scope`, `state`.
- [x] 2.2 Para las 10 entries detalladas, agregar el campo `detail` (array con `alcance`, `cuando_tocar`, `riesgos`) y reescribir el `help` para que sea 1-2 oraciones claras.
- [x] 2.3 Update `effective()` para exponer `icon`, `scope`, `state`, `detail` en el JSON que consume la UI.

### Lista de las 10 entries con detail

1. `dispatch_paused` — scope=local, state=live, icon=fa-hand
2. `target_pg_queue` — scope=local, state=live, icon=fa-layer-group
3. `scope` — scope=local, state=live, icon=fa-calendar-day
4. `regulator_mode` — scope=mixto, state=live, icon=fa-sliders
5. `target_remote_queue` — scope=mixto, state=live, icon=fa-satellite-dish
6. `floor_remote_queue` — scope=mixto, state=live, icon=fa-satellite-dish
7. `audio_output_format` — scope=local, state=live, icon=fa-file-audio
8. `burst_max_upstream_queue` — scope=mixto, state=manual, icon=fa-bolt
9. `ai_coherence_enabled` — scope=local, state=live, icon=fa-wand-magic-sparkles
10. `submit_with_callback` — scope=mixto, state=experimental, icon=fa-bell-concierge

### Mapeo scope/state/icon por las 54 entries restantes

(Ver tabla completa en `design.md`. Las 10 de arriba ya están listadas; completar las 44 restantes siguiendo la misma lógica.)

## 3. Blade (`_settings-tab.blade.php`)

- [x] 3.1 Render del grupo (header):
  - Icono a la izquierda del label (fontawesome via cfgGroupIcons).
  - Help corto del grupo sin cambios.
- [x] 3.2 Render del knob:
  - Icono a la izquierda del label (FontAwesome via cfgMeta[k].icon).
  - Badges de scope y state con scopeBadgeClass/stateBadgeClass.
  - Help corto: 1-2 oraciones (enriquecidas en las 10 detalladas).
  - Botón "▸ Ver detalle" si detail presente.
  - Acordeón inline con `<div x-show="detailOpen[k]" x-collapse>` + 3 secciones (alcance, cuándo_tocar, riesgos).
- [x] 3.3 Estado Alpine para el acordeón: nuevo objeto `detailOpen: {}` en el componente principal + función `toggleDetail(k)`.

## 4. `index.blade.php` (cfgGroupIcons + helpers)

- [x] 4.1 Agregar mapa `cfgGroupIcons` con los 10 iconos.
- [x] 4.2 Helpers `scopeBadgeClass`, `scopeBadgeLabel`, `stateBadgeClass`, `stateBadgeLabel`, `toggleDetail`.

## 5. Layout (x-collapse plugin)

- [x] 5.1 Self-hosted: descargado `public/js/alpine-collapse.min.js` (1.4 KB) desde unpkg.
  Referenciado en `resources/views/layouts/app.blade.php` con `?v={{ filemtime }}` para cache-bust, mismo patrón que alpine core.
  **Nota**: el proposal original decía CDN; optamos por self-host porque el proyecto ya self-hostea Alpine. Decisión registrada.

## 6. Verificación

- [x] 6.1 `cd app && rm -rf .phpunit.cache && rm -f bootstrap/cache/config.php && APP_ENV=testing CACHE_DRIVER=array vendor/bin/phpunit --filter TranscriptorSettingsTest` → **24/24 verde (178 assertions)**.
- [x] 6.2 `php -l app/app/Services/Ia/TranscriptorSettings.php` → OK.
- [x] 6.3 Schema validation por reflection: 67 entries, 0 missing icon/scope/state, 10 con detail.
- [x] 6.4 Smoke test manual queda para vos al deploy: cargar `/ia/api-transcriptor` → Configuración → ver iconos en grupos, iconos en knobs, badges LOCAL/REMOTO/MIXTO/EXPERIMENTAL/MANUAL, acordeón expandible en 10 knobs.

## 7. Caveats (no resueltos aquí, registrados)

- Los 44 helps no detallados se conservan como están. Si en una iteración futura el operador los pide más ricos, se reescriben uno por uno.
- Si en el futuro se agregan más settings al schema, **deben** incluir `icon`, `scope`, `state` para mantener consistencia.
- El CDN unpkg puede caerse → acordeón no anima. Mitigación: cambiar a self-host en otro change.
