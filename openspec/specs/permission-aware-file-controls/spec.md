## ADDED Requirements

### Requirement: Ocultar controles de escritura en storages de solo lectura
El sistema SHALL ocultar los botones "Subir Archivo" y "Nueva Carpeta" en el módulo "Mis Archivos" cuando el usuario tiene permisos de solo lectura (`read`) en el storage activo.

#### Scenario: Usuario con permiso read entra a un storage
- **WHEN** un usuario con permiso `read` en un storage navega a ese storage
- **THEN** los botones "Subir Archivo" y "Nueva Carpeta" NO son visibles

#### Scenario: Usuario con permiso write entra a un storage
- **WHEN** un usuario con permiso `write` en un storage navega a ese storage
- **THEN** los botones "Subir Archivo" y "Nueva Carpeta" son visibles

#### Scenario: Usuario con permiso full entra a un storage
- **WHEN** un usuario con permiso `full` en un storage navega a ese storage
- **THEN** los botones "Subir Archivo" y "Nueva Carpeta" son visibles

#### Scenario: Usuario con permiso upload entra a un storage
- **WHEN** un usuario con permiso `upload` en un storage navega a ese storage
- **THEN** el botón "Subir Archivo" es visible y el botón "Nueva Carpeta" NO es visible

### Requirement: Ocultar zona de drag-and-drop en storages de solo lectura
El sistema SHALL NO mostrar el overlay de "Suelta para subir" ni procesar el evento de drop cuando el usuario tiene permisos de solo lectura en el storage activo.

#### Scenario: Arrastrar archivo sobre storage de solo lectura
- **WHEN** un usuario con permiso `read` arrastra un archivo sobre el panel de archivos
- **THEN** NO aparece el overlay de "Suelta para subir" y el archivo no se procesa

#### Scenario: Arrastrar archivo sobre storage con permisos de escritura
- **WHEN** un usuario con permiso `write` o `full` arrastra un archivo sobre el panel de archivos
- **THEN** aparece el overlay de "Suelta para subir" normalmente

### Requirement: Estado vacío respete permisos
El sistema SHALL ocultar el botón de subida en el estado vacío (empty state) del file manager cuando el usuario tiene permisos de solo lectura.

#### Scenario: Storage vacío con permiso read
- **WHEN** un usuario con permiso `read` entra a un storage sin archivos
- **THEN** el botón "Subir Archivo" del estado vacío NO es visible

#### Scenario: Storage vacío con permiso write o full
- **WHEN** un usuario con permiso `write` o `full` entra a un storage sin archivos
- **THEN** el botón "Subir Archivo" del estado vacío es visible
