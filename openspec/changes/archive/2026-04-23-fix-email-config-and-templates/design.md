## Context

El módulo de Correo actualmente tiene dos componentes principales: Configuración y Plantillas. La configuración de correo (servidor SMTP, credenciales, puerto, etc.) se almacena en la base de datos, pero el formulario de configuración en la interfaz no carga estos valores existentes, forzando al usuario a reintroducirlos manualmente cada vez que quiere probar la conexión. Por otro lado, las plantillas de correo existen en la base de datos pero la ventana de plantillas en la interfaz no las muestra ni permite gestionarlas.

## Goals / Non-Goals

**Goals:**
- Cargar automáticamente la configuración de correo desde la base de datos al abrir el formulario de configuración.
- Permitir actualizar y guardar la configuración de correo desde el formulario.
- Listar todas las plantillas de correo existentes en la base de datos en la ventana de plantillas.
- Permitir crear nuevas plantillas de correo desde la interfaz.
- Permitir editar plantillas existentes (asunto, cuerpo, variables).
- Permitir eliminar plantillas de correo desde la interfaz.
- Sincronizar en tiempo real los cambios con la base de datos.

**Non-Goals:**
- No se modificará el sistema de envío de correos propiamente dicho.
- No se implementará un editor WYSIWYG avanzado para plantillas (se usará texto plano/HTML simple).
- No se agregará soporte para archivos adjuntos en plantillas.

## Decisions

- **Carga de configuración**: Se utilizará un endpoint GET `/api/email/config` que devuelva la configuración actual. El frontend hará la petición al montar el componente y poblará los campos del formulario.
- **Guardado de configuración**: Se utilizará un endpoint POST/PUT `/api/email/config` para actualizar los valores. Se validarán los campos obligatorios antes de enviar.
- **CRUD de plantillas**: Se implementarán endpoints RESTful `/api/email/templates` (GET list, POST create, PUT update, DELETE delete).
- **UI/UX**: Se usará una tabla/lista para mostrar plantillas con botones de acción (editar/eliminar). Un modal o panel lateral para el formulario de creación/edición.
- **Validación**: Campos obligatorios para plantillas: nombre, asunto, cuerpo. Para configuración: servidor, puerto, usuario.

## Risks / Trade-offs

- **[Riesgo]** Exposición de credenciales SMTP en la respuesta del API → **[Mitigación]** Asegurar que solo usuarios autorizados (admin) puedan acceder al endpoint de configuración.
- **[Riesgo]** Eliminación accidental de plantillas en uso → **[Mitigación]** Agregar confirmación antes de eliminar y/o validar si la plantilla está siendo usada por algún proceso.
- **[Trade-off]** Mostrar contraseña SMTP en el formulario vs. seguridad → Se puede mostrar como campo password o con opción de revelar, pero siempre sobre HTTPS.

## Migration Plan

No aplica migración de datos. Los cambios son puramente de interfaz y API sobre tablas existentes.

## Open Questions

- ¿La contraseña SMTP debe mostrarse en el formulario o solo permitir actualizarla?
- ¿Existen validaciones específicas de formato para las plantillas (por ejemplo, variables tipo `{{nombre}}`)?
