## Why

El módulo admin Sites Externos ofrece solo 24 iconos (grid plano sin categorías) y 8 colores para personalizar los sites; el usuario no distingue bien sus sites entre sí, especialmente en el sidebar donde el chip es de 20×20 y la distinción real la da el color. La paleta tri-plicada (JS del módulo, PHP del sidebar, `in:` del controller) ya obliga a sincronizar a mano cada color entre 4 lugares, lo que hace insostenible ampliar el catálogo sin una decisión sobre esos puntos.

## What Changes

- Ampliar el catálogo visual del módulo admin Sites Externos (`admin/external-sites.blade.php`):
  - Iconos: 24 → ~48, agrupados por categoría (Media, Datos, Comunicación, Seguridad, Herramientas, General) con buscador que filtra en vivo.
  - Colores: 8 → 16-18 swatches, manteniendo el formato `{name, hex}` y el triple mapa (bg/text) que ya usa la vista.
  - Preview del modal: 36px → 48px para apreciar la combinación icono+color elegida.
- Extender la validación de color en `ExternalSiteController` (`store` y `update`) para aceptar los colores nuevos.
- Añadir las entradas de datos de los colores nuevos al mapa `$siteColors` del sidebar (`layouts/app.blade.php`) — solo datos, sin cambio de diseño — para que los sites con colores nuevos no caigan al fallback azul.

## Capabilities

### New Capabilities

- `external-sites-visual-catalog`: catálogo amplio de iconos (agrupados y buscables) y colores para personalizar sites externos, con validación backend alineada y render correcto en sidebar.

### Modified Capabilities

## Impact

- **Vistas**: `app/resources/views/admin/external-sites.blade.php` (arrays `icons`/`colors`/`colorBg`/`colorText` en JS, markup del picker de iconos, preview); `app/resources/views/layouts/app.blade.php` (solo entradas nuevas en el array `$siteColors`).
- **Backend**: `app/app/Http/Controllers/ExternalSiteController.php` — regla `in:` de `color` en `store()` (línea 27) y `update()` (línea 54). Sin migración, sin cambios de modelo/ruta.
- **Fuera de alcance**: visor iframe (`sites/show.blade.php`), modal de usuarios asignados, sidebar (diseño/estructura), refactor de fuente única de la paleta.
- **Riesgo de consistencia**: color guardado que el sidebar no conozca cae a fallback azul silencioso (`?? $siteColors['blue']`); por eso el sidebar recibe las entradas de datos nuevas en este mismo change.