## Why

El módulo de Correo en la aplicación tiene dos problemas críticos que impiden su uso efectivo:
1. El formulario de configuración de correo no carga los datos existentes desde la base de datos, lo que dificulta probar la conexión porque el usuario debe rellenar todo manualmente cada vez.
2. Las plantillas de correo creadas en la base de datos no se muestran en la ventana de plantillas, y no existe funcionalidad para agregar, editar o eliminar plantillas desde la interfaz.

## What Changes

- **Configuración de correo**: Modificar el formulario de configuración para que cargue automáticamente los valores almacenados en la base de datos (servidor SMTP, puerto, usuario, contraseña, seguridad, remitente, etc.).
- **Gestión de plantillas**: Implementar CRUD completo (crear, leer, actualizar, eliminar) para las plantillas de correo en la interfaz de usuario.
- **Sincronización con base de datos**: Asegurar que tanto la configuración como las plantillas reflejen fielmente el estado actual de la base de datos.

## Capabilities

### New Capabilities
- `email-config-loader`: Carga automática de la configuración de correo desde la base de datos al formulario de configuración.
- `email-template-crud`: Operaciones CRUD completas para plantillas de correo (listar, crear, editar, eliminar) en la interfaz de usuario.

### Modified Capabilities
<!-- No hay capacidades existentes que requieran cambios a nivel de especificación -->

## Impact

- **Frontend**: Componentes de configuración de correo y ventana de plantillas.
- **Backend**: Endpoints/API para obtener configuración de correo y gestionar plantillas.
- **Base de datos**: Tablas existentes de configuración y plantillas de correo.
- **Usuario**: Mejora significativa en la usabilidad del módulo de correo.
