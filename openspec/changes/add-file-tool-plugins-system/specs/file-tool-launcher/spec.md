## ADDED Requirements

### Requirement: Presentar herramientas disponibles en módulo de archivos
Cuando un usuario selecciona un archivo en el módulo de archivos, el sistema DEBE mostrar las herramientas (plugins) disponibles para ese tipo de archivo según los plugins activos del usuario.

#### Scenario: Usuario con plugin para tipo de archivo
- **WHEN** usuario selecciona un archivo PDF y tiene asignado el plugin "pdf-viewer-pro"
- **THEN** el sistema DEBE mostrar un botón o opción para "Abrir con Visor PDF Pro"
- **AND** el botón DEBE cargar el plugin al hacer clic

#### Scenario: Usuario sin plugin para tipo de archivo
- **WHEN** usuario selecciona un archivo PDF y NO tiene asignado ningún plugin para PDF
- **THEN** el sistema NO DEBE mostrar opciones de herramientas avanzadas
- **AND** el archivo SE DEBERÁ abrir con el visor por defecto del navegador

#### Scenario: Múltiples plugins para mismo tipo
- **WHEN** usuario tiene múltiples plugins asignados para el mismo tipo MIME
- **THEN** el sistema DEBE mostrar todas las opciones disponibles
- **AND** el usuario DEBE poder elegir cuál usar

### Requirement: Inicializar plugin de herramienta
El sistema DEBE cargar los recursos JS/CSS del plugin seleccionado y ejecutarlo con el archivo y configuración appropriados.

#### Scenario: Inicializar plugin exitosamente
- **WHEN** usuario selecciona un plugin y hace clic en "Abrir con X"
- **THEN** el sistema DEBE cargar los recursos declarados en el plugin
- **AND** DEBE instanciar el plugin con el archivo actual y container DOM
- **AND** DEBE pasar la configuración específica del plugin

#### Scenario: Error al cargar plugin
- **WHEN** los recursos del plugin no pueden cargarse
- **THEN** el sistema DEBE mostrar un mensaje de error al usuario
- **AND** DEBE ofrecer la opción de usar el visor por defecto como fallback

### Requirement: Integración sin modificar módulo existente
El sistema de plugins DEBE integrarse con el módulo de archivos sin modificar la estructura existente del módulo, usando puntos de extensión.

#### Scenario: Integración hook en detalle de archivo
- **WHEN** se muestra el modal/página de detalle de un archivo
- **THEN** el sistema DEBE invocar el servicio de plugins para obtener herramientas disponibles
- **AND** DEBE agregar dinámicamente los botones de herramientas al DOM existente

#### Scenario: Plugins no afectan funcionalidad base
- **WHEN** un plugin falla o está deshabilitado
- **THEN** la funcionalidad base del módulo de archivos NO DEBE verse afectada
- **AND** el usuario DEBERÁ poder seguir interactuando con el archivo normalmente
