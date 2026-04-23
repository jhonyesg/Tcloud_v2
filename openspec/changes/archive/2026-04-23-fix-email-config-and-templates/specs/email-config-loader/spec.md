## ADDED Requirements

### Requirement: Cargar configuración de correo desde la base de datos
El sistema DEBE cargar automáticamente la configuración de correo almacenada en la base de datos cuando el usuario abra el formulario de configuración.

#### Scenario: Configuración existente en base de datos
- **WHEN** el usuario navega al formulario de configuración de correo
- **THEN** el sistema DEBE mostrar los valores actuales de servidor SMTP, puerto, usuario, contraseña, tipo de seguridad y dirección del remitente cargados desde la base de datos

#### Scenario: Configuración vacía en base de datos
- **WHEN** el usuario abre el formulario de configuración y no existe configuración previa
- **THEN** el sistema DEBE mostrar los campos en blanco o con valores por defecto
- **AND** DEBE permitir al usuario ingresar y guardar nueva configuración

### Requirement: Guardar configuración de correo
El sistema DEBE permitir al usuario guardar o actualizar la configuración de correo en la base de datos.

#### Scenario: Guardar nueva configuración
- **WHEN** el usuario completa el formulario de configuración y presiona "Guardar"
- **THEN** el sistema DEBE validar los campos obligatorios (servidor, puerto, usuario)
- **AND** DEBE almacenar la configuración en la base de datos
- **AND** DEBE mostrar un mensaje de confirmación de éxito

#### Scenario: Validación de campos obligatorios
- **WHEN** el usuario intenta guardar sin completar campos obligatorios
- **THEN** el sistema DEBE mostrar mensajes de error indicando los campos faltantes
- **AND** NO DEBE permitir el guardado hasta que se completen

### Requirement: Probar conexión de correo
El sistema DEBE permitir probar la conexión SMTP con los datos cargados o ingresados en el formulario.

#### Scenario: Prueba de conexión exitosa
- **WHEN** el usuario presiona el botón "Probar conexión"
- **THEN** el sistema DEBE usar la configuración actual del formulario para intentar conectar con el servidor SMTP
- **AND** DEBE mostrar un mensaje indicando si la conexión fue exitosa o fallida
