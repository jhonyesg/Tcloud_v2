## Context

El módulo de archivos actual permite subir, descargar y compartir archivos. No existe un sistema de herramientas/plugin que permita extender la funcionalidad por usuario. La necesidad surge de modelos de negocio diferenciados donde ciertos clientes pagan por herramientas avanzadas (visores mejorados, editores, reproductores premium).

Los plugins son librerías JS/CSS locales que se integran con tipos específicos de archivos. Ejemplos: PDF.js para visores de PDF, un editor de imágenes con canvas, un reproductor de video con más controles.

## Goals / Non-Goals

**Goals:**
- Sistema de registro de plugins donde cada plugin define qué tipos MIME soporta, qué recursos carga, y cómo se instancia.
- Asociación de plugins por usuario individual con estados activo/inactivo y expiración opcional.
- Punto de integración en el módulo de archivos que presenta las herramientas disponibles según el usuario logueado.
- Panel de administración para gestionar plugins disponibles y asignaciones por usuario.

**Non-Goals:**
- Sistema de facturación o pagos (se asume que ya existe o se hará externamente).
- Plugins que requieren backend (editores colaborativos, etc) - solo plugins client-side.
- Actualización automática de plugins - se actualizan manualmente.

## Decisions

- **Estructura de plugins**: Cada plugin es un registro en BD con: name, slug, type (viewer, editor, player), supported_mimes (JSON array), resources (JSON con rutas a JS/CSS), config (JSON con configuración específica), is_active.
- **Recursos locales**: Los archivos JS/CSS de los plugins se almacenan en `public/plugins/<slug>/` y se cargan dinámicamente según el tipo de archivo.
- **Integración con módulo de archivos**: El componente de detalle de archivo consulta los plugins disponibles para el usuario y el tipo MIME del archivo, mostrando los botones de herramientas correspondientes.
- **Admin para plugins**: CRUD completo de plugins en el panel admin, y asignación de plugins a usuarios específicos.
- **Fallback**: Si un usuario no tiene un plugin específico, se usa la herramienta por defecto del navegador o se muestra un mensaje de que no tiene acceso.

## Risks / Trade-offs

- **[Riesgo]** Cargar plugins dinámicamente puede causar conflictos de CSS/JS → **[Mitigación]** Usar iframes隔离 para editores avanzados o namespaces en CSS.
- **[Riesgo]** Un plugin mal configurado puede romper el módulo de archivos → **[Mitigación]** Cada plugin tiene `is_active` y se valida antes de cargar.
- **[Trade-off]** Almacenar plugins en BD vs código duro → BD permite administración sin deploy, pero requiere sincronización de archivos físicos.

## Migration Plan

1. Crear tablas `file_tool_plugins` y `user_file_tool_plugins`.
2. Crear seeders con plugins básicos predefinidos.
3. Implementar modelos, servicios y controladores.
4. Agregar endpoints API para plugins.
5. Crear vista admin para gestión de plugins.
6. Integrar en el módulo de archivos existente.

## Open Questions

- ¿Cómo se manejan los tipos MIME compuestos? (ej: un archivo puede abrirse con visor de imagen O visor de documento)
- ¿Los plugins premium reemplazan completamente al visor por defecto o se muestran como opciones?
- ¿Se necesita un orden de prioridad entre plugins para el mismo tipo de archivo?
