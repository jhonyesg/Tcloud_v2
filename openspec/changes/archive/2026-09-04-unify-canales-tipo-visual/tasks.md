## 1. Vista de lista de canales (index)

- [x] 1.1 En `app/resources/views/grabaciones_puntuales/canales/index.blade.php`, tarjetas móviles: reemplazar el icono indigo genérico (`fa-broadcast-tower`, `bg-indigo-100`) por icono/color según `$canal->grabador->tipo` (TV → `fa-tv` + purple, Radio → `fa-radio` + emerald) con fallback neutral si no hay grabador o tipo
- [x] 1.2 En la misma vista, tabla desktop celda Slot: anteponer el icono coloreado del medio antes del `slot_nombre` y añadir etiqueta textual pequeña "Radio"/"TV" a la derecha del nombre (visible sin hover), con fallback neutral
- [x] 1.3 Verificar que búsqueda en tiempo real, numeración de filas y orden por columna Slot siguen funcionando con el nuevo contenido de la celda (JS existente no se modifica; solo verificar)

## 2. Formularios crear y editar

- [x] 2.1 En `app/resources/views/grabaciones_puntuales/canales/create.blade.php`: opciones del select de grabadores antepone el medio en texto (ej. "📻 Nombre (ip)") y, al elegir, el select muestra la señal del tipo (icono/borde teñido)
- [x] 2.2 En `app/resources/views/grabaciones_puntuales/canales/edit.blade.php`: bloque read-only del grabador muestra icono y etiqueta textual del medio (Radio/TV) en lugar del icono gris `fa-server` genérico

## 3. Tour interactivo

- [x] 3.1 En el paso "Columna: Slot" del tour `startCanalesTour` (mismo archivo index), ampliar el contenido para explicar el código de medios: radio esmeralda `fa-radio`, TV púrpura `fa-tv`
- [x] 3.2 Verificar que los selectores del tour (celda Slot por clase `font-semibold`, botones, columnas) siguen resolviendo tras los cambios de markup

## 4. Validación

- [x] 4.1 Revisar la lista como admin y como usuario normal (columns Usuario/Grabador ocultas): el medio es visible en ambos casos y en tarjetas móviles
- [x] 4.2 Probar caso de canal sin grabador asociado (o tipo fuera de radio/tv): señal neutral, sin errores en template
- [x] 4.3 `php artisan view:clear` y verificación visual final de consistencia con `grabadores/index.blade.php` (mismo icono y color por medio)