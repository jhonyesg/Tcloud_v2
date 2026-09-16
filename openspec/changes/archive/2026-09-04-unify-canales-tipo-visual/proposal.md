## Why

El módulo Grabadores distingue claramente grabadores de TV (púrpura, `fa-tv`) de los de Radio (esmeralda, `fa-radio`), pero el módulo Medios Puntuales (canales) — que se alimenta de esos grabadores — es completamente indiferenciado: todo se muestra en indigo con `fa-broadcast-tower` genérico, sin importar si el canal graba de una emisora de radio o de un canal de TV. El usuario no puede distinguir visualmente el medio de sus canales en ninguna pantalla del módulo.

## What Changes

- Reutilizar el código cromático ya existente en `grabadores/index.blade.php` (TV = `purple-100/600/700` + `fa-tv`; Radio = `emerald-100/600/700` + `fa-radio`) en las vistas de canales:
  - `canales/index.blade.php`: icono + color por tipo en las tarjetas móviles y en la celda Slot de la tabla desktop (icono a la izquierda del nombre + etiqueta textual "TV"/"Radio" a la derecha como redundancia accesible).
  - `canales/create.blade.php`: opciones del select de grabadores muestran el icono/tipo y el estado del select refleja el tipo elegido.
  - `canales/edit.blade.php`: el bloque read-only de grabador muestra el icono y badge del tipo.
- Actualizar el paso del tour interactivo de la columna Slot para explicar el código de color de tipos (precedente: tour de Files explica encabezados ordenables).
- Header de página y empty states permanecen neutros (indigo genérico): el tipo es atributo de cada canal, no del módulo.

## Capabilities

### New Capabilities

- `canales-tipo-visual`: señalización visual del medio (TV/Radio) en las vistas del módulo Medios Puntuales, heredada del tipo del grabador asociado, consistente con el lenguaje visual del módulo Grabadores.

### Modified Capabilities

## Impact

- **Vistas** (única superficie; sin lógica, sin consultas nuevas):
  - `app/resources/views/grabaciones_puntuales/canales/index.blade.php` (header no se toca; cards móvil, tabla desktop, tour)
  - `app/resources/views/grabaciones_puntuales/canales/create.blade.php`
  - `app/resources/views/grabaciones_puntuales/canales/edit.blade.php`
- **Fuente de datos**: `$canal->grabador->tipo` — ya viene eager-loaded en `CanalController::index` (`Canal::with(['grabador'])`) y cargado en edit. No requiere migración, ni cambios en modelos (`Canal`, `Grabador`), ni rutas, ni controllers.
- **Fuera de alcance**: badge verde/rojo de Activo (semántica de disponibilidad, distinta), colores de botones de acción, derivación del tipo desde el prefijo del nombre del slot.

## Non-goals

- No modificar la vista de grabadores (es la referencia, ya está bien).
- No crear columna "Tipo" separada ordenable en la tabla de canales.
- No cambiar iconos del sidebar (`layouts/app.blade.php`) ni del dashboard (`dashboard/user.blade.php`).