## ADDED Requirements

### Requirement: Asignar plugin a usuario
El sistema DEBE permitir asignar uno o más plugins a un usuario específico, habilitando herramientas premium por usuario individual.

#### Scenario: Asignar plugin a usuario
- **WHEN** administrador asigna un plugin a un usuario específico
- **THEN** el sistema DEBE crear un registro en user_file_tool_plugins
- **AND** el plugin DEBE estar disponible para ese usuario inmediatamente

#### Scenario: Asignar plugin con expiración
- **WHEN** administrador asigna un plugin a un usuario con fecha de expiración
- **THEN** el sistema DEBE almacenar la fecha expires_at
- **AND** después de esa fecha el plugin NO DEBE estar disponible para el usuario

#### Scenario: Multiple plugins para un usuario
- **WHEN** un usuario tiene múltiples plugins asignados
- **THEN** el sistema DEBE permitir que todos estén activos simultáneamente
- **AND** el usuario DEBE ver todas las herramientas disponibles

### Requirement: Revocar plugin de usuario
El sistema DEBE permitir revocar (desactivar) un plugin asignado a un usuario sin eliminar el registro.

#### Scenario: Revocar plugin manualmente
- **WHEN** administrador desactiva la asignación de un plugin a un usuario
- **THEN** el usuario NO DEBE ver ese plugin en sus herramientas disponibles
- **AND** el registro en la base de datos DEBE mantenerse para auditoría

#### Scenario: Plugin expirado automáticamente
- **WHEN** la fecha expires_at de una asignación ha pasado
- **THEN** el sistema DEBE considerar esa asignación como inactiva
- **AND** el plugin NO DEBE aparecer disponible para el usuario

### Requirement: Consultar plugins activos de usuario
El sistema DEBE proporcionar un método para obtener todos los plugins activos de un usuario específico.

#### Scenario: Obtener plugins activos de usuario
- **WHEN** se solicita los plugins activos de un usuario
- **THEN** el sistema DEBE retornar solo plugins donde user_file_tool_plugins.is_active = true
- **AND** la fecha actual sea menor a expires_at (si existe)
- **AND** el plugin en file_tool_plugins tenga is_active = true

### Requirement: Filtrar plugins por tipo MIME de archivo
El sistema DEBE permitir filtrar los plugins de un usuario por tipo MIME para mostrar solo los aplicables a un archivo específico.

#### Scenario: Filtrar plugins por tipo de archivo
- **WHEN** se solicita plugins activos de un usuario para un archivo con MIME "application/pdf"
- **THEN** el sistema DEBE filtrar solo plugins cuyo supported_mimes incluya "application/pdf" o wildcards como "application/*"
