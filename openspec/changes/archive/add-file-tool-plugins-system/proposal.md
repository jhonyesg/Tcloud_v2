## Why

El módulo de archivos actual ofrece funcionalidad básica de almacenamiento y compartición. Sin embargo, los clientes tienen necesidades diferenciadas: algunos requieren visores avanzados (PDF interactivo, visor de imágenes con zoom, editor de texto enriquecido), reproductores multimedia mejorados, o herramientas de manipulación de archivos. Algunos clientes pagan extra por estas herramientas premium. Actualmente no existe un sistema para gestionar, habilitar y administrar estas herramientas por usuario de forma flexible y escalable.

## What Changes

- Crear un sistema de plugins/herramientas para el módulo de archivos que permita registrar, habilitar y deshabilitar herramientas por usuario individual.
- Los plugins son componentes independientes (librerías JS locales como visores PDF, editores de imagen, reproductores de video mejorados) que se integran con el módulo de archivos existente.
- Cada usuario puede tener un conjunto diferente de plugins habilitados según su plan/suscripción.
- Los plugins se administran desde el panel de administración y el usuario final ve solo las herramientas que tiene disponibles.

## Capabilities

### New Capabilities
- `file-tool-plugin-registry`: Sistema de registro de plugins de herramientas de archivo. Permite registrar nuevos plugins con su nombre, tipo (visor, editor, reproductor), recursos JS/CSS, y configuración específica.
- `user-file-tools`: Asociación entre usuarios y plugins de herramientas. Cada usuario tiene plugins habilitados individualmente con estado activo/inactivo y fecha de expiración opcional.
- `file-tool-launcher`: Punto de integración en el módulo de archivos que detecta qué plugins tiene disponibles el usuario y los presenta como opciones al interactuar con archivos.

### Modified Capabilities
<!-- No se modifican especificaciones existentes -->

## Impact

- **Base de datos**: Nuevas tablas para `file_tool_plugins` y `user_file_tool_plugins`.
- **Backend**: Modelos, servicios y controladores para gestionar plugins y asignaciones.
- **Frontend**: Panel admin para gestionar plugins, y punto de integración en el módulo de archivos.
- **Existente**: El módulo de archivos existente no se modifica en su estructura, solo se extiende con puntos de integración para los plugins.
