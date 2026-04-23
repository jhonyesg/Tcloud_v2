## Why

El sistema actual no cuenta con una herramienta de administración de PostgreSQL integrada. El admin necesita:
- Gestionar la conexión a PostgreSQL (configurar credenciales)
- Visualizar el esquema de la base de datos como diagrama
- Ejecutar consultas SQL directamente
- Realizar backups locales o via FTP

## What Changes

**Nuevo módulo:** `/admin/postgres` - Panel de Administración de PostgreSQL

**Funcionalidades:**

1. **Configuración de Conexión**
   - Formulario para establecer host, puerto, database, usuario, contraseña
   - Guardar configuración en archivo .env o base de datos
   - Botón "Probar conexión"

2. **Diagrama de Base de Datos**
   - Visualización gráfica de tablas
   - Mostrar relaciones (foreign keys) entre tablas
   - Diseño automático del diagrama
   - Zoom y pan para navegación

3. **SQL Query Runner**
   - Editor de SQL con sintaxis coloreada
   - Ejecutar consultas
   - Mostrar resultados en tabla
   - Historial de queries

4. **Sistema de Backup**
   - Backup local (download .sql)
   - Backup a FTP (subir archivo .sql)
   - Configuración de FTP

## Capabilities

### New Capabilities

- `postgres-admin`: Módulo completo de administración PostgreSQL con:
  - `postgres-config`: Configuración de conexión
  - `postgres-schema`: Diagrama visual de esquema
  - `postgres-query`: Ejecutor de queries SQL
  - `postgres-backup`: Sistema de backup local/FTP

## Impact

- **Nuevo archivo de ruta**: `web.php` - `/admin/postgres`
- **Nuevo controlador**: `PostgresAdminController.php`
- **Nueva vista**: `resources/views/admin/postgres.blade.php`
- **Dependencias**: 
  - `php` extensión PDO PostgreSQL (ya disponible en contenedor)
  - Librería JavaScript para diagrama (ej: d3.js o similar liviano)
