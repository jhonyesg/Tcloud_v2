## Why

El panel de compartidos del modal de detalle muestra todos los enlaces en una lista plana sin distinción de tipo de permiso, lo que dificulta encontrar rápidamente los enlaces de solo lectura vs escritura cuando hay varios. Tampoco es posible eliminar varios enlaces a la vez: cada eliminación requiere un click individual.

## What Changes

- La sección de compartidos del modal de detalle pasa a tener **pestañas por tipo de permiso**: Todos, Lectura, Escritura, Subida, Completo. Cada pestaña muestra el conteo de enlaces de ese tipo. La pestaña activa filtra la lista sin hacer peticiones adicionales al servidor.
- Se añade un **checkbox de selección** a cada enlace compartido visible.
- Aparece una barra de acciones cuando hay al menos un enlace seleccionado, con botón **"Eliminar seleccionados"** que llama secuencialmente a `DELETE /shares/{id}` por cada seleccionado y refresca la lista.
- Se añade un checkbox **"Seleccionar todos"** en la cabecera de la lista que selecciona/deselecciona todos los visibles en la pestaña activa.

## Capabilities

### New Capabilities
- `shares-tabs-bulk-delete`: Organización por pestañas de permiso y eliminación en bloque de enlaces compartidos en el modal de detalle de archivo.

### Modified Capabilities
_Ninguna._

## Impact

- **resources/views/files/index.blade.php**: sección de compartidos dentro de `showDetailModal` — pestañas, checkboxes, barra de acciones bulk
- **No requiere migración**: sin cambios en BD ni en el modelo `Share`
- **No requiere cambios en backend**: se usan los endpoints existentes (`GET /shares`, `DELETE /shares/{id}`) uno por uno

## Non-goals

- No se implementa un endpoint bulk de eliminación en el backend (se reutilizan llamadas individuales secuenciales).
- No se cambia la estructura del modelo Share ni los permisos disponibles.
- No se implementan pestañas fuera del modal de detalle (p. ej., en una vista global de compartidos).
