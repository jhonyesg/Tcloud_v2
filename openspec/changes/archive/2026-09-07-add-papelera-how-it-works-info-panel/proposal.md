## Why

La papelera de reciclaje ya renderiza correctamente (HTML maqueteado, empty state y tabla funcionales), pero los usuarios no saben qué pasa internamente cuando borran un archivo. Concretamente:

1. Creen que el archivo "se va a otra papelera" y esperan encontrarlo en una carpeta distinta → no lo encuentran y reportan duplicidad o pérdida.
2. No entienden por qué el espacio en disco no se libera al borrar → se sorprenden cuando el cron los purga días después.
3. Intentan hard-deletear un archivo con transcripciones y no entienden por qué el botón está bloqueado.
4. Generan tickets de soporte preguntando qué es la papelera.

La página actual muestra el subtítulo `"Los elementos se eliminan automáticamente después de 15 días"` pero no aclara el resto. Un panel colapsable con la explicación completa resuelve los cuatro puntos sin saturar el listado principal.

## What Changes

- En `app/resources/views/papelera/index.blade.php`, añadir un panel colapsable "¿Cómo funciona la papelera?" entre el header y el listado, usando el mismo patrón visual que `ia/api-transcriptor/index.blade.php:60-111` (botón toggle + chevron + grid de dos columnas en `md:`).
- El panel cubre cuatro bloques: (1) qué pasa cuando borras, (2) cuándo se borra definitivamente, (3) restaurar vs eliminar definitivamente, (4) espacio en disco y links públicos.
- Sin cambios en `PapeleraController`, sin cambios en rutas, sin migraciones. Solo Blade + Alpine local (`showHelp: false`).
- Spec delta: añadir 1 requirement a `trash-module`: "How-it-works info panel is available on the trash view".

## Capabilities

### Modified Capabilities
- `trash-module`: el view `/papelera` MUST expose a collapsible help panel that explains the soft-trash lifecycle, retention purge, restore semantics, quota impact, and public-share 410 behavior.

## Impact

- `app/resources/views/papelera/index.blade.php` (modifica: añade panel + estado Alpine `showHelp`).
- Sin migraciones, sin cambios de servicio, sin cambios de rutas, sin cambios de controller.

## Non-goals

- Persistir el estado abierto/cerrado del panel entre sesiones (no hay demanda; el patrón de referencia `ia/api-transcriptor` tampoco lo persiste).
- Cambiar el copy del subtítulo existente ("Los elementos se eliminan automáticamente después de 15 días") — sigue ahí como resumen de una sola línea.
- Internacionalización: copy queda en español del layout principal.
- Tutorial guiado paso-a-paso (como `grabaciones_puntuales/canales`): otro patrón, overkill para un módulo ya simple.
