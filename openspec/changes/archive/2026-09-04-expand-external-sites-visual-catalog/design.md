## Context

El módulo admin Sites Externos (`admin/external-sites.blade.php`) define en su componente Alpine los arrays `icons` (24), `colors` (8 × `{name, hex}`) y los mapas `colorBg()`/`colorText()`. La misma paleta existe en el sidebar (`layouts/app.blade.php:379-388`, array PHP `$siteColors` con `bg`/`text`) y en la validación backend (`ExternalSiteController`, `in:blue,...,slate` en `store()` y `update()`) — 4 copias manuales que hoy coinciden y que deben crecer juntas.

El backend valida `icon` solo como `string|max:60` (sin lista), así que los iconos nuevos son 100% frontend. Los colores nuevos exigen tocar las 2 reglas `in:` del controller. El sidebar aplica `?? $siteColors['blue']` como fallback silencioso para colores desconocidos.

Decisión de alcance del usuario: tocar solo el modal de agregar/editar y la vista del módulo admin; el resto (visor iframe, modal de usuarios, diseño del sidebar, refactor de fuente única) queda como está.

## Goals / Non-Goals

**Goals:**
- Catálogo de ~48 iconos agrupados por categoría con búsqueda en vivo, sin perder el icono ya seleccionado.
- Paleta de 16-18 colores con tripletas hex (base/pastel/texto) coherentes con las 8 existentes (estilo Tailwind 600/100/700).
- Preview del modal a 48px con actualización en vivo.
- Backend `in:` sincronizado con la paleta nueva (store y update).
- Sidebar: solo entradas de datos nuevas en `$siteColors`, sin tocar markup ni clases.

**Non-Goals:**
- No refactor a config/constante única de paleta (se mantiene el patrón actual de copias sincronizadas a mano).
- No cambiar el visor `/sites/{id}`, el modal de usuarios ni el diseño/estructura del sidebar.
- No tocar otros consumos del sistema (dashboard, widgets).

## Decisions

1. **Iconos: grid con encabezados de categoría + buscador, no acordeón.**
   Un solo grid continuo con títulos de sección (Media, Datos, Comunicación, Seguridad, Herramientas, General) y un input de filtro encima. Alternativas descartadas: acordeón por categoría (2 clics para llegar al icono, oculta el catálogo); `<datalist>`/select nativo (pierde el preview visual por icono). El buscador filtra por el nombre Font Awesome (`fa-chart-bar` matchea "chart"). Sin resultados → mensaje explícito, no grid vacío.

2. **Iconos nuevos: nombres semánticos de Font Awesome 5/6 free sólidos ya disponibles en el proyecto.**
   Extensión del array actual manteniendo el formato `fa-*` plano: comunicación (`fa-envelope`, `fa-comment`, `fa-phone`, `fa-wifi`, `fa-podcast`, `fa-headphones`), media extra (`fa-image`, `fa-play-circle`, `fa-photo-video`, `fa-clapperboard`*), datos (`fa-chart-pie`, `fa-table`, `fa-file-alt`, `fa-folder`, `fa-search`, `fa-gauge`*), seguridad (`fa-user-shield`, `fa-key`, `fa-eye`, `fa-fingerprint`), herramientas (`fa-sliders-h`, `fa-plug`, `fa-cubes`, `fa-rocket`, `fa-lightbulb`, `fa-calendar`), general (`fa-home`, `fa-book`, `fa-shopping-cart`, `fa-credit-card`, `fa-gavel`, `fa-flask`, `fa-paper-plane`, `fa-map-marked-alt`). *Verificar disponibilidad exacta en la versión de Font Awesome del proyecto durante la implementación; si un glyph no existe, sustituir por el equivalente free disponible (la spec exige ≥40 válidos, no la lista literal).

3. **Colores: 8 nuevos → 16 total, tripletas coherentes con el patrón actual.**
   Añadir: `indigo #4f46e5/#e0e7ff`, `teal #0d9488/#ccfbf1`, `orange #ea580c/#ffedd5`, `sky #0284c7/#e0f2fe`, `lime #65a30d/#ecfccb`, `fuchsia #c026d3/#fae8ff`, `pink #db2777/#fce7f3`, `yellow #ca8a04/#fef9c3` (base 600 + pastel 100). Los 8 existentes no cambian de hex (retrocompatibilidad con sites ya guardados). El total debe garantizar distinción visual entre tonos vecinos en chips de 20px (por eso yellow≠amber y sky≠blue se mantienen ambos pero con separación de tono clara; si al implementar colisionan visualmente, sustituir yellow por `stone` y sky queda solo si no choca con cyan).

4. **Validación backend: extender el literal `in:` en las 2 reglas (store/update).**
   Sin migración ni modelo: `color` es `string` en DB (`fillable` ya lo admite). Mantener el literal en vez de derivarlo de config — es el patrón actual y el cambio mínimo; el riesgo de desync se documenta y se mitiga con tarea de verificación cruzada (comparar `colors` JS vs `in:` vs `$siteColors`).

5. **Sidebar: solo datos.**
   Añadir al array PHP `$siteColors` las 8 entradas nuevas con los mismos hex del módulo. Ni una clase, ni estructura, ni lógica nueva: el `?? blue` fallback queda intacto como red de seguridad.

6. **Preview 48px con doble contexto.**
   El preview del modal pasa de 36px a 48px mostrando nombre y URL como hoy. Alternativa descartada: preview dual (tamaño tabla + tamaño sidebar) — útil, pero añade complejidad fuera del alcance visual acordado.

## Risks / Trade-offs

- [Glyphs de Font Awesome inexistentes en la versión del proyecto] → Verificar en implementación contra el build cargado en `layouts/app.blade.php`; sustituir por equivalentes free disponibles antes de cerrar tareas.
- [Cuarta copia de la paleta sigue siendo manual] → Mitigado con tarea de verificación cruzada explícita (JS colors ↔ controller `in:` ↔ sidebar `$siteColors`); el refactor a fuente única queda documentado como non-goal por decisión de alcance.
- [Colores nuevos con tono colisionante en chips 20×20] → Elección de tonos 600 separados; verificación visual incluida en tareas.
- [Grid de 48 iconos alarga el modal] → Buscador + categorías reducen el costo de navegación; el modal ya es scrolleable.

## Migration Plan

Sin migración ni deploy especial. Actualizar la vista, el controller y el sidebar; `php artisan view:clear` + `view:cache`. Rollback: revertir los 3 archivos. Sites existentes no cambian (sus valores `icon`/`color` siguen siendo válidos).

## Open Questions

Ninguna — la lista concreta de glyphs se resuelve en implementación verificando la versión de Font Awesome cargada (la spec exige ≥40 válidos, no una lista literal).