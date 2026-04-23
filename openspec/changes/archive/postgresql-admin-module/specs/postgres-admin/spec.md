## ADDED Requirements

### Requirement: Módulo de administración PostgreSQL
El sistema DEBERÁ tener un módulo `/admin/postgres` accesible solo para admins que incluya 4 funcionalidades: Configuración, Diagrama, Query, Backup.

### Requirement: Configuración de conexión PostgreSQL
El admin DEBERÁ poder configurar y guardar los datos de conexión PostgreSQL.

#### Scenario: Guardar configuración
- **WHEN** admin guarda configuración de PostgreSQL
- **THEN** sistema SHALL guardar host, puerto, database, usuario, contraseña

#### Scenario: Probar conexión
- **WHEN** admin hace clic en "Probar conexión"
- **THEN** sistema SHALL verificar conexión y mostrar éxito/error

### Requirement: Diagrama visual del esquema
El sistema DEBERÁ mostrar un diagrama visual con las tablas y sus relaciones.

#### Scenario: Mostrar diagrama
- **WHEN** admin accede al tab "Diagrama"
- **THEN** sistema SHALL cargar todas las tablas de la base de datos
- **AND** SHALL mostrar rectángulos por tabla con columnas
- **AND** SHALL mostrar líneas de relación entre tablas

#### Scenario: Navegación del diagrama
- **WHEN** admin interactúa con el diagrama
- **THEN** SHALL permitir zoom in/out
- **AND** SHALL permitir arrastrar para navegar

### Requirement: Query Runner SQL
El admin DEBERÁ poder ejecutar queries SQL y ver resultados.

#### Scenario: Ejecutar query SELECT
- **WHEN** admin escribe query SELECT y hace clic en "Ejecutar"
- **THEN** sistema SHALL mostrar resultados en tabla

#### Scenario: Query con resultado vacío
- **WHEN** admin ejecuta query que no retorna datos
- **THEN** sistema SHALL mostrar mensaje "Query ejecutado exitosamente"

### Requirement: Sistema de Backup
El admin DEBERÁ poder hacer backup de la base de datos.

#### Scenario: Backup local
- **WHEN** admin hace clic en "Backup local"
- **THEN** sistema SHALL generar archivo .sql
- **AND** SHALL descargar automáticamente

#### Scenario: Backup a FTP
- **WHEN** admin configura credenciales FTP y hace clic en "Backup a FTP"
- **THEN** sistema SHALL subir archivo .sql al FTP
- **AND** SHALL mostrar confirmación de éxito/error
