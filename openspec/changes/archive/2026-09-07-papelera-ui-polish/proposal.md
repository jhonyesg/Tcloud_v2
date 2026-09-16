## Why

La maqueta de `/papelera` quedó funcional pero básica en la primera iteración. Comparándola con `files/index.blade.php` (la página de archivos con polish aplicado), tiene 4 huecos que afectan la comprensión del estado de la papelera:

1. **Sin stat tiles** — el usuario ve una tabla pero no sabe cuántos items tiene en total, cuántos están por expirar, cuánto espacio se liberará al purgar, ni cuándo es la próxima purga.
2. **Sin progress bar por fila** — "15 días restantes" es un número abstracto. Una barra que muestra el ciclo de vida del item (100% recién trashado → 0% a punto de purgar) comunica mejor el estado.
3. **Sin filtros** — la tabla carga todos los items juntos. No hay forma de ver "solo los urgentes" sin scroll.
4. **Sin banner urgente** — si hay items con <3 días restantes, el usuario no se entera hasta verlos en la tabla.

Adicional: en móvil (< sm:) la tabla hace overflow horizontal en vez de convertirse en cards.

## What Changes

- **Stat tiles (4)** arriba del header: `Total`, `Por expirar pronto`, `Espacio a liberar`, `Próxima purga`. Calculados en el backend vía `PapeleraService`.
- **Progress bar por fila** en la columna "Días restantes": ancho = `(days_remaining / 15) * 100`, color = brand-500 (>30%), amber-500 (10-30%), red-500 (<10%).
- **Filtros** debajo del header: `Todos`, `Por expirar pronto (<3d)`, `Críticos (<1d)`. Estado Alpine local, no recarga del servidor.
- **Banner urgente** arriba si `stats.urgent > 0`, color amber, link para filtrar.
- **Responsive cards** en `< sm:` reemplazando la tabla.
- Backend: agregar método `PapeleraService::statsFor(int $userId)` que retorna `['total', 'urgent', 'critical', 'size_bytes', 'next_purge_date']`. Usado por `PapeleraController::indexJson`.

## Capabilities

### Modified Capabilities
- `trash-module`: el view `/papelera` MUST mostrar stat tiles con conteos agregados y próxima purga estimada.
- `trash-module`: el view MUST mostrar progress bar por fila que visualiza el ciclo de vida del item.
- `trash-module`: el view MUST soportar filtros de estado (Todos / Urgentes / Críticos) sin recargar el servidor.
- `trash-module`: el view MUST convertir la tabla a cards en viewports < sm:.

## Impact

- `app/app/Modules/Papelera/Services/PapeleraService.php` (agrega método `statsFor`).
- `app/app/Modules/Papelera/Http/Controllers/PapeleraController.php` (modifica `indexJson` para incluir stats en la respuesta).
- `app/resources/views/papelera/index.blade.php` (rediseño significativo del body, manteniendo el header + help panel).
- Sin migraciones, sin cambios en rutas, sin cambios de cache.

## Non-goals

- Refactorizar `countFor()` (que ya existe para el sidebar) en `statsFor()`. `countFor` solo necesita `total` + `urgent` para el badge; `statsFor` agrega size_bytes y next_purge_date. Conviven.
- Persistir el filtro seleccionado entre sesiones (no hay demanda; mismo patrón que `files/index`).
- Internacionalización de los strings nuevos (queda en español, mismo idioma que el resto).
- Reemplazar el `Vaciar papelera` button actual. Se mantiene.
