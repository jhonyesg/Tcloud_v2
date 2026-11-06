## MODIFIED Requirements

### Requirement: Ocultar controles de escritura en storages de solo lectura
El sistema SHALL ocultar los botones "Subir Archivo" y "Nueva Carpeta" en el módulo "Mis Archivos" cuando el usuario tiene permisos de solo lectura (`read`) en el storage activo. Adicionalmente, SHALL rechazar en el backend cualquier intento de crear carpeta o subir archivo en un storage donde el usuario no tiene permiso de escritura, devolviendo HTTP 403 con `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}`.

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

#### Scenario: Usuario con permiso read intenta crear carpeta por bypass de UI
- **WHEN** un usuario con permiso `read` envía POST a `/files` con `is_folder=true` (bypass de la UI que oculta el botón)
- **THEN** el endpoint responde HTTP 403 con `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}`
- **AND** no se ejecuta `mkdir()` ni se crea fila en `files`
