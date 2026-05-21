## MODIFIED Requirements

### Requirement: Zona de drop en la vista principal de archivos
El sistema SHALL mostrar un overlay visual cuando el usuario arrastra archivos o carpetas sobre el área principal del módulo "Mis Archivos", y SHALL subir el contenido al soltarlo sin necesidad de abrir el modal. Si lo arrastrado es una carpeta, se procesará con la FileSystemEntry API. El overlay y el handler de drop SOLO se activarán cuando el usuario tenga permisos de escritura (`write`, `upload` o `full`) en el storage activo.

#### Scenario: Arrastrar archivo sobre la vista principal
- **WHEN** el usuario con permisos de escritura arrastra uno o más archivos sobre el panel de archivos mientras está dentro de un storage
- **THEN** aparece un overlay con texto "Suelta para subir" que cubre el área de archivos

#### Scenario: Soltar archivos en la vista principal
- **WHEN** el usuario suelta archivos sobre el overlay
- **THEN** el overlay se cierra, el modal de subida se abre mostrando el progreso de cada archivo, y los archivos se suben al directorio actual

#### Scenario: Soltar una carpeta en la vista principal
- **WHEN** el usuario suelta una carpeta sobre el overlay
- **THEN** el overlay se cierra y el sistema procesa la carpeta con `uploadFolder()`, creando la estructura y subiendo todos los archivos

#### Scenario: Arrastrar sin estar en un storage
- **WHEN** el usuario arrastra archivos mientras está en la vista de selección de storages (no dentro de uno)
- **THEN** el overlay no aparece (o aparece con mensaje "Selecciona un storage primero") y no se inicia ninguna subida

#### Scenario: Arrastrar sobre storage de solo lectura
- **WHEN** el usuario con permiso `read` arrastra archivos sobre el panel de archivos dentro de un storage
- **THEN** el overlay NO aparece y no se inicia ninguna subida
