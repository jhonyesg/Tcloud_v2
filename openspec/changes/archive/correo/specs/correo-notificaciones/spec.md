## ADDED Requirements

### Requirement: Enviar correo de notificación
El sistema SHALL permitir enviar correos de notificación utilizando plantillas configuradas.

#### Scenario: Enviar notificación por correo
- **WHEN** se solicita enviar una notificación por correo con plantilla y datos
- **THEN** el sistema SHALL reemplazar las variables y enviar al destinatario

#### Scenario: Fallo en envío de correo
- **WHEN** el servidor SMTP falla al enviar un correo
- **THEN** el sistema SHALL registrar el error y retornar estado de fallo

### Requirement: Notificación al compartir enlace
El sistema SHALL enviar un correo al destinatario cuando se comparte un enlace.

#### Scenario: Compartir enlace por correo
- **WHEN** el usuario selecciona "enviar por correo" al compartir un enlace e ingresa el destinatario
- **THEN** el sistema SHALL usar la plantilla de compartir enlace y enviar el correo

### Requirement: Notificación al crear usuario
El sistema SHALL enviar un correo de bienvenida cuando se crea un nuevo usuario.

#### Scenario: Crear usuario con notificación
- **WHEN** se crea un usuario con opción de notificación por correo habilitada
- **THEN** el sistema SHALL usar la plantilla de bienvenida y enviar al usuario

### Requirement: Recuperación de contraseña
El sistema SHALL enviar un correo con enlace para recuperar contraseña cuando el usuario lo solicita.

#### Scenario: Solicitar recuperación de contraseña
- **WHEN** el usuario solicita recuperar su contraseña desde la pantalla de login
- **THEN** el sistema SHALL enviar correo con enlace único de recuperación usando la plantilla correspondiente

### Requirement: Registro de envíos
El sistema SHALL mantener un log de todos los correos enviados con estado y timestamp.

#### Scenario: Registrar envío de correo
- **WHEN** se envía un correo exitosamente
- **THEN** el sistema SHALL registrar en la tabla correo_log: destinatario, plantilla usada, timestamp, estado