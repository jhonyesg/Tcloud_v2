## Context

El admin necesita una herramienta visual para administrar PostgreSQL. Actualmente no existe ningún módulo similar en el sistema. El módulo estará protegido por el middleware `auth` y `admin`.

## Goals / Non-Goals

**Goals:**
- Proveer una interfaz visual para administrar PostgreSQL
- Permitir ver el esquema de la base de datos como diagrama
- Permitir ejecutar queries SQL
- Permitir hacer backups locales y via FTP

**Non-Goals:**
- No modificar datos de la aplicación directamente (solo consultas de lectura en schema)
- No implementar un cliente SQL completo (solo queries simples)
- No soporte para múltiples conexiones simultáneas

## Decisions

**Decisión 1: Arquitectura**

```
┌─────────────────────────────────────────────────────────────┐
│                 POSTGRESQL ADMIN MODULE                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  PostgresAdminController                                    │
│  ├── index()      → Vista principal con tabs                │
│  ├── test()       → Probar conexión                         │
│  ├── schema()     → Obtener esquema (tablas, columnas, FK)  │
│  ├── query()      → Ejecutar query SQL                      │
│  ├── backup()     → Generar backup .sql                     │
│  └── uploadFtp()  → Subir backup a FTP                     │
│                                                             │
│  postgres.blade.php (vista única con tabs)                  │
│  ├── Tab: Configuración                                     │
│  ├── Tab: Diagrama                                          │
│  ├── Tab: Query SQL                                         │
│  └── Tab: Backup                                            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Decisión 2: Configuración de conexión**

Guardar en `.env` o en un archivo de configuración dedicado:
```php
// En config/database.php o .env
PG_HOST=localhost
PG_PORT=5432
PG_DATABASE=tcloud
PG_USERNAME=postgres
PG_PASSWORD=secret
```

O usar un modelo `DatabaseConfig` para guardar múltiples conexiones.

**Decisión 3: Obtención del esquema**

Consultas PostgreSQL para obtener información:
```sql
-- Tablas
SELECT table_name FROM information_schema.tables 
WHERE table_schema = 'public';

-- Columnas
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'users';

-- Foreign keys
SELECT 
    tc.table_name, kcu.column_name,
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu ON ...
```

**Decisión 4: Visualización del diagrama**

Usar Canvas o SVG con JavaScript vanilla:
- Cada tabla = rectángulo con nombre y lista de columnas
- Relaciones = líneas con flechas entre campos
- Layout automático: usar un algoritmo simple de posicionamiento

Alternativa: usar una librería ligera como `浙江省` o similar.

**Decisión 5: Query Runner**

- Textarea para escribir SQL
- Botón "Ejecutar"
- Mostrar resultados en tabla HTML
- Limitar a queries SELECT para seguridad (opcional: permitir UPDATE/INSERT con confirmación)

**Decisión 6: Sistema de Backup**

```php
// Generar SQL dump
$command = "pg_dump -h {$host} -U {$user} -d {$db} -f backup.sql";

// Subir a FTP
$ftp = ftp_connect($ftpHost);
ftp_put($ftp, $filename, $localFile, FTP_BINARY);
```

## Risks / Trade-offs

| Riesgo | Mitigación |
|--------|------------|
| Credenciales en texto plano | Usar .env con permisos restrictivos |
| Queries destructivos accidentales | Confirmación para UPDATE/DELETE/INSERT |
| Timeout en queries grandes | Limitar resultados o usar paginación |
| Backup grande consume memoria | Stream directly to file |

## Migration Plan

1. Crear PostgresAdminController
2. Agregar ruta `/admin/postgres`
3. Crear vista postgres.blade.php con tabs
4. Implementar tab de configuración + test
5. Implementar tab de diagrama (schema)
6. Implementar tab de query SQL
7. Implementar tab de backup

**Rollback**: Eliminar controlador, ruta y vista.
