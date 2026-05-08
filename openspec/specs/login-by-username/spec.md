# login-by-username Specification

## Purpose
TBD - created by archiving change login-with-username. Update Purpose after archive.
## Requirements
### Requirement: Login con email o nombre de usuario
El sistema SHALL permitir autenticarse usando el email registrado o el nombre de usuario asignado, en un único campo de login.

#### Scenario: Login exitoso con email
- **WHEN** el usuario ingresa su email y contraseña correctos en el formulario de login
- **THEN** se inicia sesión correctamente y se redirige al dashboard

#### Scenario: Login exitoso con username
- **WHEN** el usuario ingresa su nombre de usuario (sin @) y contraseña correctos
- **THEN** se inicia sesión correctamente y se redirige al dashboard

#### Scenario: Credenciales inválidas
- **WHEN** el usuario ingresa un email/username o contraseña incorrectos
- **THEN** se muestra el mensaje "Credenciales inválidas" y no se inicia sesión

#### Scenario: Campo vacío
- **WHEN** el usuario envía el formulario con el campo de login vacío
- **THEN** se muestra error de validación antes de consultar la base de datos

### Requirement: Asignación de username al administrador
El sistema SHALL asignar automáticamente el username `jsuarez` al usuario administrador existente al ejecutar la migración de datos.

#### Scenario: Migration asigna username al admin
- **WHEN** se ejecuta la migración de base de datos
- **THEN** el usuario con `role = 'admin'` recibe el username `jsuarez` en la columna `username`

### Requirement: Username único por usuario
El sistema SHALL garantizar que no existan dos usuarios con el mismo nombre de usuario.

#### Scenario: Registro con username duplicado
- **WHEN** se intenta crear o actualizar un usuario con un username que ya existe
- **THEN** se retorna un error de validación indicando que el username ya está en uso

### Requirement: Gestión de username en panel admin
El sistema SHALL permitir al administrador ver y editar el username de cada usuario desde el panel de gestión de usuarios.

#### Scenario: Ver username en tabla de usuarios
- **WHEN** el administrador accede al listado de usuarios
- **THEN** se muestra la columna username junto al email y rol de cada usuario

#### Scenario: Editar username de un usuario
- **WHEN** el administrador edita un usuario y modifica el campo username
- **THEN** el nuevo username se guarda en la base de datos respetando la unicidad

