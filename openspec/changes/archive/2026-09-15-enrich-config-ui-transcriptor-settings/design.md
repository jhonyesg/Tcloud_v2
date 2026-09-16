# enrich-config-ui-transcriptor-settings — Design

## Context

Ver `proposal.md` para motivación. El estado actual:

- 54 settings en 10 grupos con helps de 1-2 líneas.
- Blade `_settings-tab.blade.php` renderiza label + key + help + default + control.
- Sin iconos ni badges. Sin distinción LOCAL vs REMOTO.

## Goals / Non-Goals

**Goals:**

- Cada knob tiene icono + badges de scope/state.
- Cada grupo tiene icono en el header.
- 10 knobs (los más complejos) tienen detail estructurado expandible con `x-collapse`.
- Helps cortas (1 línea) se mantienen en los 44 settings restantes (no agrego ruido).
- 10 helps reescritas con detalle estructurado (`alcance`, `cuando_tocar`, `riesgos`).

**Non-Goals:**

- No cambia validación, persistencia, ni comportamiento runtime.
- No introduce filtros, búsqueda, ni agrupación nueva.
- No cambia el modal "Escanear storages", el progress modal, ni otras pestañas.

## Decisions

### Decisión 1: 3 nuevos campos por schema entry (`icon`, `scope`, `state`)

**Elegido**: agregar 3 campos requeridos por cada una de las 54 entries.

```php
'foo' => [
    'type' => 'int',
    'group' => 'ritmo',
    'default' => 140,
    'env_key' => 'TRANSCRIPTOR_FOO',
    'label' => '...',
    'help' => '...',
    'icon' => 'fa-layer-group',  // NUEVO
    'scope' => 'local',           // NUEVO: local | remoto | mixto
    'state' => 'live',            // NUEVO: live | experimental | manual | obsoleto
    'detail' => [...],            // OPCIONAL (solo 10 entradas)
],
```

**Por qué**:
- `icon`: requerimiento directo del operador. Sin icono, no hay anclaje visual.
- `scope`: el operador ya demostró que necesita saber **a quién afecta** cada knob (LOCAL/REMOTO/MIXTO). Es la información más valiosa que un badge puede dar.
- `state`: los knobs de `burst_*` y `webhook_*` no son parte del flujo automático; necesitan marcarse como `[MANUAL]` o `[EXPERIMENTAL]` para no inducir error.
- `detail` opcional: solo cuando el help corto no alcanza. La mayoría de los 54 settings tienen helps que se defienden en una línea.

**Alternativa**: hacer `detail` requerido para los 54. Descartada por redundancia — many settings tienen helps claros sin necesidad de popover.

### Decisión 2: badge `state=live` no se renderiza

**Elegido**: el badge solo aparece para `experimental`, `manual`, `obsoleto`. `live` se omite.

**Por qué**: si todos los knobs tuvieran badge `[LIVE]`, la UI se llenaría de ruido. El badge tiene valor solo cuando señala algo distinto del estado por defecto.

**Alternativa**: mostrar `[LIVE]` siempre. Descartada.

### Decisión 3: acordeón inline con `x-collapse`

**Elegido**: cuando hay `detail` y el operador clickea "▸ Ver detalle", se expande un panel debajo del knob (no flotante). Plugin Alpine `x-collapse` (oficial, CDN unpkg).

**Por qué**:
- Más accesible que popover flotante (tab + enter funciona).
- Sin problemas de overflow en pantallas chicas.
- Animación de altura suave (~150ms) ayuda a entender que se está expandiendo contenido.
- `x-collapse` es plugin oficial Alpine; pesa ~5KB; ya hay `@alpinejs/collapse` en npm si se quisiera self-host.

**Alternativas consideradas**:

- **Popover flotante con `position: absolute`**: requiere click-outside-to-close custom; problema de overflow en pantallas chicas; menos accesible. Descartada.
- **`x-show` + `x-transition` simple**: sin animación de altura (solo fade). Menos pulido. Descartada por requerimiento explícito del operador de sumar el plugin.

### Decisión 4: 10 entradas con detail

**Elegido**: los 10 settings que reciben detail estructurado son los más consultados y los que más dudas generan:

1. `dispatch_paused` — el freno de emergencia, su comportamiento importa.
2. `target_pg_queue` — el regulador central del sistema.
3. `scope` — controla toda la frontera del escaneo.
4. `regulator_mode` — define si el regulador mira GPU remota o no.
5. `target_remote_queue` — interactúa con la API upstream.
6. `floor_remote_queue` — interacción con el upstream.
7. `audio_output_format` — formato del audio, tiene constraint de proyecto.
8. `burst_max_upstream_queue` — knob de burst, no automático.
9. `ai_coherence_enabled` — pase de LLM, tiene costo.
10. `submit_with_callback` — webhook experimental.

Los 44 restantes conservan su help actual. Si el operador los pide en una segunda iteración, se agregan.

**Alternativa**: detail para los 54. Descartada por costo de redacción y mantenimiento.

### Decisión 5: layout del knob

**Elegido**:

```
[icono] Label  [badge-scope] [badge-state]  [ⓘ]
   key
   help (1-2 líneas)
   ▸ Ver detalle                                    ← solo si detail presente
   Por defecto: X (archivo)        restaurar
                                                         [control]
```

```
Cuando se expande:
   ┌────────────────────────────────────────────┐
   │ Alcance: ...                               │
   │ Cuándo tocar: ...                          │
   │ Riesgos: ...                               │
   └────────────────────────────────────────────┘
```

**Por qué**: el icono a la izquierda del label crea un anclaje visual; los badges a la derecha dan info semántica sin romper la lectura. El acordeón abre solo si hace falta, sin desordenar el panel principal.

### Decisión 6: CDN del plugin `x-collapse`

**Elegido**: `<script defer src="https://unpkg.com/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>` antes del cierre del body en el layout.

**Por qué**: ya hay otros CDN en el proyecto (Tailwind, Alpine core). Un CDN más no rompe el patrón.

**Riesgo**: si unpkg cae, el acordeón no funciona (pero la UI no rompe, solo el botón no expande). Mitigación: el operador puede cambiar a self-host en un futuro change.

**Alternativa**: self-host descargar el plugin y servirlo local. Descartada por simplicidad.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Altura del panel crece ~30% por knob | Scroll natural; cada grupo sigue siendo identificable por icono. |
| Operador se acostumbra a los badges y luego ve otro módulo sin badges, le parece inconsistente | Documentar en AGENTS.md como patrón reutilizable. Fuera de scope de este change. |
| Si una help nueva se redacta mal, el operador la lee igual | 10 helps no son tantas; se revisan en el review del commit. |
| CDN unpkg cae → acordeón no anima | Fallback aceptable: el botón existe, no expande. Se arregla con self-host en otro change. |
| Si un campo nuevo (`detail`) tiene HTML mal escapado, podría romper render | `x-text` se usa para todos los campos, no `x-html`. No hay riesgo de XSS porque el schema es server-side. |

## Migration Plan

**Deploy:** ninguno especial. Cambios son 3 archivos.

**Pasos:**

1. `git pull` con el commit.
2. (Opcional) `php artisan view:clear` si hay cache de Blade.
3. Smoke test en `/ia/api-transcriptor` (rol admin) → pestaña "Configuración":
   - Ver 10 grupos con iconos.
   - Ver 54 knobs con iconos a la izquierda del label.
   - Ver badges (LOCAL/REMOTO/MIXTO/EXPERIMENTAL/MANUAL) donde corresponda.
   - Click "▸ Ver detalle" en 2-3 knobs → ver acordeón con 3 secciones.

**Rollback:** `git revert <commit>`. Sin estado persistente.

## Open Questions

Ninguna.
