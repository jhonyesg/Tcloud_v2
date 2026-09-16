## Context

El módulo Grabadores (`grabadores/index.blade.php`) ya definió y estabilizó un lenguaje visual por tipo: TV = `fa-tv` + paleta púrpura (`purple-100/600/700`), Radio = `fa-radio` + paleta esmeralda (`emerald-100/600/700`). El dato `grabadores.tipo` existe desde la migración `2026_05_09_220000_add_tipo_to_grabadores_and_nullable_usuario_in_canales.php` con valores `radio|tv` (default `radio`).

Las vistas de canales (`canales/index|create|edit.blade.php`) son hoy indiferenciadas (todo indigo/`fa-broadcast-tower`), pese a que el tipo ya viaja gratis: `CanalController::index` hace eager-load de `grabador` para ambos roles, y `edit` opera sobre `$canal->grabador` cargado. El cambio es 100% de presentación: no hay migraciones, ni consultas nuevas, ni cambios de controller/ruta/modelo.

## Goals / Non-Goals

**Goals:**
- Propagar el código visual TV/Radio de grabadores a las tres vistas de canales, visible para todos los roles.
- Redundancia accesible: icono + color + etiqueta textual "Radio"/"TV" (no depender solo del color).
- Actualizar el tour interactivo para que explique el código de medios y no quede desincronizado con la UI.

**Non-Goals:**
- No tocar el badge verde/rojo de Activo (semántica de disponibilidad ≠ medio).
- No cambiar botones de acción, header de página, empty states, sidebar ni dashboard.
- No derivar el tipo desde el prefijo del `slot_nombre` (editable y frágil; `grabador.tipo` es la verdad).
- No crear columna "Tipo" separada ordenable.

## Decisions

1. **Reusar tokens de grabadores, no inventar paleta nueva.**
   `canales-tipo-visual` hereda exactamente `purple-100/600/700` + `fa-tv` para TV y `emerald-100/600/700` + `fa-radio` para radio. Alternativa descartada: paleta propia para canales — rompería la consistencia intra-módulo y obligaría a mantener dos lenguajes.

2. **Señal en la celda Slot, no en columna nueva.**
   En la tabla desktop el indicador (icono coloreado a la izquierda + etiqueta textual pequeña a la derecha del nombre) vive dentro de la celda Slot. Alternativa descartada: columna "Tipo" independiente — añade ancho de tabla, duplica la información con la celda Slot y complica el sort index (`indiceCelda` usa posiciones reales de `th`).

3. **Neutral como fallback, no error.**
   Si `$canal->grabador` es null (canal huérfano) o `tipo` no es `tv`/`radio`, se muestra la señal genérica actual (indigo/`fa-broadcast-tower`, sin etiqueta). Se implementa como guard en el template, no como asunción de datos.

4. **Header y empty states quedan neutros.**
   El header representa el módulo completo (contiene ambos medios); el color de tipo solo se gasta donde significa algo (principio de la skill `frontend-design`: *spend boldness in one place*). Alternativa descartada: header dual con dos iconos.

5. **Select de crear teñido por tipo elegido, no reemplazado por cards.**
   En `create`, cada `<option>` antepone el medio en texto ("📻 Nombre") — los `<option>` no aceptan color en todos los navegadores, así que la señal fuerte (borde/badge del select teñido) aparece al elegir. Alternativa descartada: reemplazar el `<select>` por tarjetas radio tipo grabadores (más invasivo de lo necesario para este alcance).

6. **Tour actualizado en el paso Slot, no como paso nuevo.**
   Modificar el contenido del paso "Columna: Slot" del tour (`startCanalesTour`) para mencionar el código de medios. Un paso nuevo incrementaría la longitud del tour ya extenso (14 pasos).

## Risks / Trade-offs

- [Etiqueta textual en la celda Slot compite con el nombre] → La etiqueta es texto pequeño (`text-[10px]/text-xs`, `text-slate-500`), no badge sólido; el nombre sigue siendo `font-semibold` dominante.
- [Búsqueda en tiempo real usa `textContent` de la fila] → Añadir la etiqueta "Radio"/"TV" cambia el texto indexable; aceptable y de hecho útil (buscar "radio" ahora filtra radios primero). No se altera la lógica del JS.
- [Sort por la celda Slot compara el texto con icono+nombre+etiqueta] → `ordenarTabla` usa `textContent` de la celda; el nombre del slot sigue siendo la parte dominante alfabéticamente. Riesgo bajo aceptado.
- [Tour con selector por clase `font-semibold` en la celda Slot] → Al añadir el icono dentro de la misma celda, el selector del tour sigue resolviendo (busca la celda, no el texto puro); verificar en la tarea del tour.
- [`grabadores.estado` referencia una vista inexistente] (`grabaciones_puntuales.estado` no existe en el filesystem) → Fuera de este cambio; se anota para separarlo.

## Migration Plan

Sin migración. Despliegue = actualizar las tres vistas Blade (y su cache de vistas si aplica: `php artisan view:clear`). Rollback trivial: revertir los archivos de vistas.