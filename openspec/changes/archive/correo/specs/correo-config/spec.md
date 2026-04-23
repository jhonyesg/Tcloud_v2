## ADDED Requirements

### Requirement: Configuración de servidor SMTP
El sistema SHALL permitir configurar un servidor SMTP para el envío de correos desde el panel de administración.

#### Scenario: Guardar configuración SMTP
- **WHEN** el administrador guarda la configuración SMTP (host, port, secure, user, password, from_name, from_email)
- **THEN** el sistema SHALL almacenar la configuración encriptada en la base de datos

#### Scenario: Validar conexión SMTP
- **WHEN** el administrador prueba la conexión SMTP
- **THEN** el sistema SHALL verificar que el servidor responde correctamente y mostrar resultado

### Requirement: Recuperar configuración actual
El sistema SHALL permitir consultar la configuración SMTP actual (sin mostrar password).

#### Scenario: Consultar configuración guardada
- **WHEN** se solicita la configuración de correo
- **THEN** el sistema SHALL retornar la configuración excepto el password en texto plano

### Requirement: Proteger password en base de datos
El sistema SHALL encriptar el password SMTP antes de almacenarlo.

#### Scenario: Almacenar password encriptado
- **WHEN** se guarda la configuración con password
- **THEN** el sistema SHALL encriptar el password usando AES-256 antes de almacenarlo