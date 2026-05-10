## Why

El modal de usuarios del storage tiene dos problemas: el typeahead de búsqueda no muestra resultados al enfocarse (los usuarios nunca aparecen en la lista), y la UI de asignación es un formulario plano que no refleja visualmente quiénes ya están asignados. Se quiere una interfaz tipo chips/etiquetas donde cada usuario asignado aparece como una píldora con "×" para removerlo, más una opción de asignar a "Todas las personas".

## What Changes

- Se corrige el bug del typeahead: la búsqueda vacía ahora muestra todos los usuarios disponibles al abrir el dropdown (sin necesidad de escribir).
- Los usuarios ya asignados a un storage se muestran como chips/etiquetas removibles (tipo badge con ×) dentro del modal.
- Se añade un checkbox "Todas las personas" que asigna automáticamente todos los usuarios del sistema al storage con permisos básicos de lectura.
- Se elimina la tabla de usuarios asignados, reemplazándola por la vista de chips que es más compacta y visual.
- El formulario de edición de permisos de un usuario asignado se abre al hacer clic sobre su chip.

## Capabilities

### New Capabilities

- `storage-users-chip-ui`: Vista de chips/etiquetas para usuarios asignados en el modal de storage, con búsqueda funcional y opción "Todas las personas".

### Modified Capabilities

- `storage-users-management-modal`: El requisito de visualización de usuarios asignados cambia de tabla a chips; se añaden requisitos de búsqueda al enfocar y asignación masiva.

## Impact

- `app/resources/views/admin/storages.blade.php` — rediseño del bloque del modal de usuarios (HTML + Alpine.js).
- `app/app/Http/Controllers/StorageProviderController.php` — posible ajuste en `searchUsers` para garantizar que query vacío devuelva resultados; nuevo endpoint o lógica para asignación masiva ("Todas las personas").
- `app/routes/web.php` — posible nueva ruta para asignación masiva.
- Sin migraciones ni nuevas dependencias.
