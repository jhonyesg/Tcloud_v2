## Why

El sistema de plugins de visualización de archivos tiene tres bugs que impiden que funcione: los plugins nunca se inicializan por un mismatch de nombres, los usuarios nuevos no ven herramientas disponibles porque ningún plugin tiene `is_default = true`, y el visor de PDF no renderiza contenido real. Adicionalmente, los plugins llevan el sufijo "pro" cuando representan funcionalidad básica estándar, lo que confunde la arquitectura del sistema (básico vs. premium).

## What Changes

- **Renombrar** todos los plugins de `-pro` a `-basic`: `image-viewer-pro → image-viewer-basic`, `video-player-pro → video-player-basic`, `audio-player-pro → audio-player-basic`, `pdf-viewer-pro → pdf-viewer-basic`
- **Fix crítico**: corregir el mismatch en `files/index.blade.php` donde se busca `window['image-viewer-pro_init']` (con guion) pero el plugin registra `window.image_viewer_pro_init` (con guion bajo) — los plugins nunca se activan
- **Fix seeder**: agregar `is_default => true` a todos los plugins para que usuarios nuevos los vean sin necesitar asignación manual
- **Fix PDF viewer**: reemplazar el placeholder de texto por un `<iframe>` apuntando a `/files/{id}/preview` con botón de descarga
- **Botón de descarga**: agregar a todos los plugins un botón que apunte a `/files/{id}/download`
- **Eliminar** el directorio `public/plugins/pdf-viewer-pro/` y los demás directorios `-pro`

## Capabilities

### New Capabilities

- `file-viewer-plugins`: Plugins básicos de visualización y reproducción de archivos (imagen, video, audio, PDF, texto) que cualquier usuario puede usar sin asignación especial, accesibles desde el módulo "Mis Archivos" al hacer click en un archivo compatible.

### Modified Capabilities

<!-- ninguna — no hay specs previos -->

## Impact

- `app/public/plugins/` — renombrar 4 directorios, actualizar manifests, JS, CSS
- `app/database/seeders/FileToolPluginSeeder.php` — actualizar slugs, agregar `is_default = true`
- `app/resources/views/files/index.blade.php` — fix en línea ~288: convertir slug a guiones bajos antes de buscar la función en `window`
- Base de datos: si hay plugins ya insertados, el seeder usa `insertOrIgnore` — se necesita limpiar y re-seedear, o migración manual de slugs
