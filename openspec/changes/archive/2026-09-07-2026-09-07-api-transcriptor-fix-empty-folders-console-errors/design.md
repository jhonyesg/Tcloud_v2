## Context

El banner "carpetas sin archivos" en `app/resources/views/ia/api-transcriptor/index.blade.php`
renderiza durante el primer paint con `emptyFolders === null` (estado inicial
del componente Alpine). Aunque está envuelto en `x-show="emptyFolders && ..."`,
Alpine.js evalúa TODAS las expresiones del subárbol — `x-show` solo aplica
`display:none`, no desmonta el árbol. Esto produce los errores de consola
reportados y rompe también cualquier binding nuevo que se agregue sin
defensive coding.

Hay tres fixes posibles identificados en `proposal.md`. Este documento
decide entre ellos y concreta los detalles operativos para `tasks.md`.

## Goals / Non-Goals

**Goals:**
- Eliminar los `TypeError` de consola en el primer render del banner.
- Blindar el banner contra regresiones si en el futuro se agregan bindings
  nuevos al subárbol.
- Mantener comportamiento observable idéntico: el banner sigue apareciendo
  exactamente con el mismo contenido y los mismos controles cuando hay
  carpetas vacías.

**Non-Goals:**
- No tocar el backend (`ApiTranscriptorController::emptyFolders`), su caché
  ni la forma del JSON.
- No introducir un sistema genérico de loading skeletons para otros datos
  asíncronos de la vista (salud, stats, storages, jobs) que ya cargan
  correctamente.
- No migrar el banner a un componente Alpine independiente.

## Decisions

### Decisión 1: combinar shape inicial vacío + `<template x-if>`

En lugar de elegir una sola técnica, se aplican las dos juntas porque son
ortogonales y se refuerzan mutuamente:

1. **Shape inicial no-nulo.** En la sección `data()` del componente
   Alpine (línea ~1798), cambiar `emptyFolders: null` por
   `emptyFolders: { items: [], storages_with_empty: 0, total_missing_folders: 0 }`.
   Esto coincide con la forma que ya devuelve el bloque `else/catch` de
   `loadEmptyFolders()` (líneas 2182-2183), por lo que se elimina la
   asimetría "estado inicial != estado tras error".
2. **`<template x-if>` en lugar de `<div x-show>`.** Envolver el banner
   completo en `<template x-if="emptyFolders &&
   emptyFolders.total_missing_folders > 0">…</template>`. Con `x-if`, Alpine
   no monta el subárbol hasta que el predicado sea `true`, así que ni el
   `x-for` ni las expresiones `x-text`/`x-show` internas se evalúan mientras
   `emptyFolders` tiene `total_missing_folders === 0`.

**Por qué ambas y no una sola:**

| Opción | Ventaja | Razón para combinarla |
|---|---|---|
| Shape inicial | Cambio mínimo, cero errores aunque el subárbol se evalúe | Por sí sola deja vivo el subárbol con `total_missing_folders=0`; un dev futuro que agregue `x-text="emptyFolders.otro_campo"` rompería la consola si el campo no está en el shape |
| `<template x-if>` | Cero bindings evaluados mientras no haya datos | Por sí sola no protege contra errores si en un futuro `emptyFolders` cambia a un objeto parcial |
| Las dos juntas | El subárbol no se monta hasta haber datos, Y si por cualquier motivo se monta, todas las props tienen shape válido | Doble red de seguridad |

**Alternativas descartadas:**

- **Optional chaining (`?.`) en cada expresión** (`emptyFolders?.items`,
  `emptyFolders?.storages_with_empty`…). Cambio mínimo pero frágil: cada
  binding nuevo en el subárrol puede reventar la consola si el autor olvida
  el `?.`. No escala.
- **Reubicar el banner dentro del `x-show="tab === 'storages'"` con
  loading skeleton.** Reescribe la UX sin pedirlo el operador y agrega
  scope no acordado.

### Decisión 2: `<template>` debe incluir el `<div>` exterior, no un inner

El `<template x-if>` reemplaza al `<div x-show="...">` que vive en la línea
160 de `index.blade.php`. La estructura resultante es:

```blade
<template x-if="emptyFolders && emptyFolders.total_missing_folders > 0">
    <div data-tour="storages-empties" class="mb-4 bg-amber-50 border border-amber-200 rounded-xl">
        <!-- header del banner con icon, contadores, botón expandir -->
        <!-- div x-show="emptyFoldersExpanded" con el template x-for -->
    </div>
</template>
```

La razón: `<template x-if>` solo puede contener UN elemento raíz. El
`<div>` mantiene el `data-tour` y las clases de estilo que el tour
(`data-tour="storages-empties"`) y los selectores CSS esperan encontrar.

### Decisión 3: nuevo comentario inline explicando el por qué del shape

Agregar un comentario corto sobre la línea del state (`emptyFolders: { ... }`)
referenciando este change, para que un dev futuro entienda que el shape es
parte del contrato con el binding y no se "simplifique" a `null`.

## Risks / Trade-offs

- **[Riesgo] Cambio de plantilla Blade rompe `data-tour="storages-empties"`.**
  → Mitigación: el `<div>` interior mantiene el `data-tour`; solo cambia el
  wrapper. Verificar en navegador que el tour sigue encontrando el
  selector.
- **[Riesgo] `<template x-if>` puede no preservar transiciones.**
  → Mitigación: el banner ya no usa `x-transition` en su contenedor
  exterior, solo en el panel expandido (`x-show="emptyFoldersExpanded"
  x-transition`). Eso sigue funcionando porque vive dentro del bloque
  condicional.
- **[Riesgo] Tests E2E que asuman que el banner existe desde el primer
  render y leen `data-tour` antes de la respuesta del fetch.**
  → Mitigación: documentar en el commit que el banner ahora aparece
  solo cuando `total_missing_folders > 0`; los tests que escanean su
  presencia deben esperar a que `loadEmptyFolders()` termine (el helper
  `apiFetch` ya se completa con await en `indexData()`).
- **[Trade-off] El banner aparece un frame más tarde cuando SÍ hay
  carpetas vacías.** Antes se pintaba con `display:none` y al recibir
  datos se mostraba; ahora el DOM no existe hasta que llegan los datos.
  → En la práctica imperceptible: el fetch tarda ~50-200 ms y el
  `x-transition:enter.opacity.duration.150ms` del tab Storages ya da
  feedback visual durante ese intervalo.

## Migration Plan

- **Deploy:** un único commit modificando `index.blade.php` (líneas 160-223
  y 1798). Sin migración de BD, sin reinicio de PHP-FPM necesario
  (Blade recompila al siguiente request).
- **Verificación manual:**
  1. Login como admin (`jsuarez` / `T3cn0l0g14` según credenciales
     proporcionadas por el operador).
  2. Abrir `/ia/api-transcriptor`.
  3. DevTools → Console debe estar vacía de errores `TypeError` relacionados
     con `emptyFolders`.
  4. Si la cuenta tiene storages con carpetas vacías: el banner ámbar
     aparece con su contenido.
  5. Si no los tiene: el banner no aparece en ningún momento.
- **Verificación opcional con Playwright:** navegar a `/ia/api-transcriptor`,
  esperar a que la red esté quieta, capturar `console.error` y comprobar
  `length === 0`.
- **Rollback:** `git revert` del único commit; sin estado intermedio que
  migrar. El estado inicial `emptyFolders: null` reintroduce los errores
  de consola pero no rompe funcionalidad.

## Open Questions

- ¿Hay tests automatizados (PHPUnit/Dusk/Playwright) que asuman
  `data-tour="storages-empties"` accesible desde el primer render? Si
  existen, podrían necesitar un pequeño ajuste. Resoluble en la fase de
  implementación revisando `tests/` por el selector.
