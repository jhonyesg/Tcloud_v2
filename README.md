# TCloud v2

Plataforma de almacenamiento cloud auto-alojada (self-hosted), similar a NextCloud, construida con Laravel 13 y Alpine.js.

## Stack

- **Backend:** PHP 8.4 + Laravel 13
- **Frontend:** Alpine.js + Tailwind CSS
- **Base de datos:** PostgreSQL
- **Caché / Sesiones:** Redis
- **Almacenamiento:** Local (filesystem) + AWS S3

## Módulos

### Usuarios
Gestión de usuarios con roles (`admin` / `usuario`), cuotas de almacenamiento personal y control de permisos por storage.

### Mis Archivos
Explorador de archivos con navegación por carpetas, vistas grid y lista, búsqueda recursiva y soporte para múltiples storages.

**Funcionalidades:**
- Subida de archivos con validación de cuota
- Descarga de archivos individuales
- **Descarga de carpetas como ZIP** (límite 2 GB — carpetas más grandes muestran un toast de error)
- Previsualización de imágenes, video, audio, PDF y texto
- Editor de texto inline
- Rotación de imágenes
- Renombrar y eliminar (con permisos)
- Creación de carpetas

### Compartidos
Sistema de links públicos para compartir archivos y carpetas con token único, con soporte de permisos de solo lectura o escritura.

### Storages
Administración de proveedores de almacenamiento (local y S3). Asignación de permisos (`read`, `write`, `full`) por usuario.

### Sistema *(solo admin)*
Grupo en el sidebar que agrupa herramientas de infraestructura y comunicación:

| Módulo | Ruta | Descripción |
|---|---|---|
| Sesiones | `/admin/sessions` | Monitoreo y cierre de sesiones activas |
| Redis | `/admin/redis` | Inspección de claves, flush y estadísticas |
| PostgreSQL | `/admin/postgres` | Consultas SQL directas y métricas de BD |
| Correo | `/correo` | Configuración y prueba de SMTP |

### Editor de Medios *(solo admin)*
Herramienta de corte y edición de clips de video/audio con línea de tiempo, previsualización y exportación.

### Grabadores *(solo admin)*
Gestión de grabadores de canales en tiempo real.

### Sites Externos *(solo admin)*
Panel con accesos directos a servicios externos vinculados a la plataforma.

### Multimedia
Reproducción y navegación de canales y grabaciones puntuales disponibles para todos los usuarios autenticados.

## Instalación

```bash
# Clonar el repositorio
git clone <repo-url>
cd Tcloud_v2/app

# Instalar dependencias PHP
composer install

# Configurar entorno
cp .env.example .env
php artisan key:generate

# Migrar base de datos
php artisan migrate

# (Opcional) Limpiar cachés
php artisan view:clear
php artisan route:clear
php artisan cache:clear
```

## Variables de entorno relevantes

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=tcloud

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587

FILESYSTEM_DISK=local
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
```

## Permisos de storage

Cada usuario puede tener permisos distintos por storage:

- `read` — solo lectura y descarga
- `write` — lectura + subida de archivos
- `full` — todo lo anterior + renombrar y eliminar
