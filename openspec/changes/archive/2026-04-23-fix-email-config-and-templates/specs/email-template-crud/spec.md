## ADDED Requirements

### Requirement: Listar plantillas de correo
El sistema DEBE mostrar todas las plantillas de correo almacenadas en la base de datos en la ventana de plantillas.

#### Scenario: Visualización de plantillas existentes
- **WHEN** el usuario navega a la ventana de plantillas de correo
- **THEN** el sistema DEBE listar todas las plantillas con su nombre, asunto y fecha de última modificación
- **AND** DEBE mostrar un mensaje si no existen plantillas

### Requirement: Crear nueva plantilla de correo
El sistema DEBE permitir al usuario crear nuevas plantillas de correo desde la interfaz.

#### Scenario: Crear plantilla exitosamente
- **WHEN** el usuario presiona "Nueva plantilla" y completa los campos nombre, asunto y cuerpo
- **THEN** el sistema DEBE validar que el nombre y asunto no estén vacíos
- **AND** DEBE guardar la plantilla en la base de datos
- **AND** DEBE actualizar la lista de plantillas en la interfaz

### Requirement: Editar plantilla de correo
El sistema DEBE permitir al usuario modificar plantillas de correo existentes.

#### Scenario: Editar plantilla existente
- **WHEN** el usuario selecciona una plantilla y presiona "Editar"
- **THEN** el sistema DEBE cargar los datos actuales de la plantilla en un formulario
- **AND** DEBE permitir modificar nombre, asunto y cuerpo
- **AND** al guardar DEBE actualizar la plantilla en la base de datos
- **AND** DEBE refrescar la lista de plantillas

### Requirement: Eliminar plantilla de correo
El sistema DEBE permitir al usuario eliminar plantillas de correo existentes.

#### Scenario: Eliminar plantilla con confirmación
- **WHEN** el usuario presiona "Eliminar" sobre una plantilla
- **THEN** el sistema DEBE solicitar confirmación antes de eliminar
- **AND** al confirmar DEBE eliminar la plantilla de la base de datos
- **AND** DEBE actualizar la lista de plantillas en la interfaz

#### Scenario: Cancelar eliminación
- **WHEN** el usuario presiona "Eliminar" pero cancela la confirmación
- **THEN** el sistema NO DEBE eliminar la plantilla
- **AND** la lista de plantillas DEBE permanecer sin cambios
