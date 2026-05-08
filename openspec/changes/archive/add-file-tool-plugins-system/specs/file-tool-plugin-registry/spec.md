## ADDED Requirements

### Requirement: Registrar plugin de herramienta de archivo
El sistema DEBE permitir registrar nuevos plugins de herramientas de archivo con su nombre único, tipo, tipos MIME soportados, recursos y configuración.

#### Scenario: Registrar nuevo plugin
- **WHEN** administrador crea un nuevo plugin con name, slug, type, supported_mimes, resources y config
- **THEN** el sistema DEBE almacenar el plugin en la base de datos
- **AND** DEBE validar que el slug sea único
- **AND** DEBE validar que los recursos JS/CSS existan en `public/plugins/<slug>/`

#### Scenario: Actualizar plugin existente
- **WHEN** administrador modifica un plugin existente
- **THEN** el sistema DEBE actualizar los campos modificados
- **AND** DEBE mantener la integridad de las asignaciones existentes a usuarios

#### Scenario: Desactivar plugin
- **WHEN** administrador establece is_active en false
- **THEN** el plugin NO DEBE aparecer disponible para ningún usuario
- **AND** los usuarios existentes no DEBEN ver el plugin en sus herramientas

### Requirement: Validar recursos de plugin
El sistema DEBE validar que los recursos JS/CSS declarados en un plugin existan en el sistema de archivos antes de activarlo.

#### Scenario: Plugin con recursos válidos
- **WHEN** se crea/actualiza un plugin con recursos que existen en `public/plugins/<slug>/`
- **THEN** el sistema DEBE permitir el registro sin errores

#### Scenario: Plugin con recursos faltantes
- **WHEN** se crea/actualiza un plugin con recursos que no existen
- **THEN** el sistema DEBE mostrar un error indicando qué recursos faltan
- **AND** NO DEBE guardar el plugin

### Requirement: Listar plugins disponibles
El sistema DEBE proporcionar un endpoint para listar todos los plugins activos disponibles en el sistema.

#### Scenario: Listar plugins
- **WHEN** se solicita la lista de plugins activos
- **THEN** el sistema DEBE devolver solo plugins con is_active = true
- **AND** cada plugin DEBE incluir su slug, name, type, supported_mimes y config
