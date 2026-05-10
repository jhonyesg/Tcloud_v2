## Why

Los usuarios graban contenido (mp4, mp3, m4a) y necesitan recortar o concatenar segmentos de esas grabaciones sin salir de la plataforma. Hoy deben descargar el archivo, usar un editor externo y re-subirlo. El servidor ya cuenta con FFmpeg nativo, lo que hace viable un editor de corte en el propio módulo de archivos.

## What Changes

- Se añade un **feature flag por usuario** (`media_editor_enabled`) que el admin puede activar/desactivar. El rol `admin` lo tiene siempre activo.
- En el módulo de archivos, en las acciones de cada archivo de tipo mp4/mp3/m4a, aparece un nuevo botón "✂ Cortar" cuando el usuario tiene el feature habilitado.
- Al hacer clic se abre un **modal editor de corte** que carga el archivo en un reproductor HTML5 y muestra controles de segmentos (inicio/fin) con marcadores de tiempo.
- El usuario puede definir **uno o múltiples segmentos** del mismo archivo que quiere conservar.
- Al presionar **"Generar corte"** el servidor ejecuta FFmpeg, produce un archivo temporal con el nombre `{nombre_original}_corte.{ext}` y lo devuelve como descarga directa al navegador. El archivo temporal se elimina tras la descarga.
- **Cada ejecución queda registrada** en una tabla `media_edit_jobs` (usuario, archivo origen, segmentos, estado, fecha) para habilitar futuros límites de uso por plan.
- FFmpeg se instala en el contenedor PHP (modificación del Dockerfile).

## Capabilities

### New Capabilities
- `media-editor-access-control`: Feature flag por usuario, activable desde admin. Admin siempre habilitado.
- `media-clip-editor-ui`: Modal editor en el módulo de archivos con reproductor, marcadores de segmento y botón de generación.
- `media-clip-processor`: Backend que recibe segmentos, ejecuta FFmpeg, devuelve descarga y registra el uso.
- `media-edit-log`: Tabla `media_edit_jobs` para tracking de uso (base para billing futuro).

### Modified Capabilities
<!-- Sin cambios a specs existentes. -->

## Impact

- `docker/php/Dockerfile`: Se añade instalación de FFmpeg.
- `app/database/migrations/`: Migración para añadir `media_editor_enabled` a `users` y crear `media_edit_jobs`.
- `app/app/Models/User.php`: Método `canUseMediaEditor()`.
- `app/app/Http/Controllers/MediaClipController.php`: Nuevo controlador (procesa y descarga).
- `app/routes/web.php`: Nueva ruta `POST /files/{file}/clip`.
- `app/resources/views/files/index.blade.php`: Botón ✂ en acciones + modal editor.
- `app/resources/views/admin/`: Panel de administración para activar el feature por usuario (puede ser en la vista existente de usuarios si existe, o en storages-users).
