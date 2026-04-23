## ADDED Requirements

### Requirement: Gestión de plantillas de email
El sistema SHALL permitir crear, editar y eliminar plantillas de email para diferentes notificaciones.

#### Scenario: Crear plantilla de email
- **WHEN** el administrador crea una plantilla con nombre, asunto, cuerpo HTML y variables
- **THEN** el sistema SHALL almacenar la plantilla en la base de datos

#### Scenario: Editar plantilla existente
- **WHEN** el administrador edita una plantilla existente
- **THEN** el sistema SHALL actualizar la plantilla manteniendo el identificador

#### Scenario: Eliminar plantilla
- **WHEN** el administrador elimina una plantilla
- **THEN** el sistema SHALL marcar la plantilla como inactiva sin eliminar físicamente

### Requirement: Variables en plantillas
El sistema SHALL permitir usar variables en las plantillas que se reemplazan dinámicamente al enviar.

#### Scenario: Usar variables en plantilla
- **WHEN** una plantilla contiene variables como {{nombre_usuario}} o {{enlace}}
- **THEN** el sistema SHALL reemplazar las variables con valores reales al momento del envío

### Requirement: Tipos de plantillas predefinidas
El sistema SHALL proporcionar plantillas predefinidas para: bienvenida, recuperación de contraseña, notificación de compartir enlace.

#### Scenario: Plantillas predefinidas disponibles
- **WHEN** se instala el módulo de correo
- **THEN** el sistema SHALL crear plantillas iniciales para los tipos más comunes