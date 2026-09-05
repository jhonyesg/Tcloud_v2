## Why

La familia de vistas admin de storage quedó fuera del house style de modales que ya aplican `correcciones`, `external-sites`, `files` y `api-transcriptor`: contenedor `rounded-lg` plano sin sombra, botones de acción en colores sueltos (green/blue/indigo/gray300), labels sin jerarquía, inputs sin focus states y overlay sin blur ni padding móvil. Además el modal de creación usa `alert()` para errores y `onchange` con DOM directo — patrones que el resto de la app ya reemplazó por toasts y Alpine. El change `normalize-storage-schema` (archivado hoy) dejó explícitamente la capa visual como "change visual posterior"; este es ese change.

## What Changes

- Restyle de los 4 modales de `admin/storages.blade.php` (crear, editar, eliminar, usuarios) al house style: `rounded-2xl shadow-2xl`, overlay `bg-black/50 backdrop-blur` con `p-4`, labels `uppercase tracking-wide`, inputs con `focus:ring-brand-500`, footer de botones con acción primaria `flex-1` (brand-600) + cancelar (slate-100), destructivo `red-500`.
- Alineación de comportamiento en el modal crear: `alert()` → toast existente; `onchange` DOM-directo → Alpine (`x-model` + `x-show`).
- Restyle de modales y botones de acción en `admin/storage-users.blade.php` y `admin/user-storages.blade.php` (misma familia, mismas clases).
- Unificar botones de acción de la tabla: de texto plano (`text-green-600`) a estilo consistente con el resto del admin.
- No se cambia ninguna ruta, controlador, modelo ni lógica de negocio; solo presentación y feedback de error en frontend.

## Capabilities

### New Capabilities
- `storage-admin-modal-ui`: el house style de modales de la familia storage admin (contenedor, overlay, labels, inputs, footer de botones, feedback de errores via toast).

### Modified Capabilities
- `storage-users-management-modal`: el modal de gestión de usuarios adopta el house style visual (contenedor, labels, inputs, footer) sin cambiar sus requisitos funcionales.
- `storage-users-chip-ui`: los chips y el panel de edición inline de permisos adoptan el house style visual sin cambiar su comportamiento.

## Impact

- **Vistas**: `app/resources/views/admin/storages.blade.php`, `admin/storage-users.blade.php`, `admin/user-storages.blade.php` (solo HTML/Alpine; sin tocar JS de datos ni endpoints).
- **Sin migraciones**, sin backend, sin rutas nuevas.
- **Riesgo**: el tour guiado (`startStoragesTour`) referencia selectores CSS de la tabla (encabezados, filas, botones por texto); el restyle de botones de acción debe preservar el texto visible para no romper `getActionButton()`.
- **Estado del repo**: los cambios se aplican sobre el working tree actual (56 archivos sin commitear, incluidas estas 3 vistas con loading states ya implementados).

## Non-goals

- No se crea componente Blade `<x-modal>` (opción B descartada; el restyle es inline).
- No se migran otros módulos (correcciones ya cumple; files/external-sites ya cumplen).
- No se toca la lógica de asignación/permisos ni los endpoints `/admin/storages/*`.
- No se rediseña la tabla ni los filtros (fuera del alcance: solo modales y botones de acción).