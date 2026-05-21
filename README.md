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

## Sincronización y Caché de Archivos

El sistema maneja ~1M de archivos distribuidos en ~23,000 carpetas mediante tres mecanismos complementarios.

### Smart sync con mtime

El comando `storage:sync` compara el `mtime` del directorio en disco con `file_modified_at` en la BD antes de escanear. Si el directorio no cambió, lo omite completamente.

```bash
# Sincronización normal (omite carpetas sin cambios)
php artisan storage:sync --all

# Forzar rescaneo completo (ignora mtime)
php artisan storage:sync --all --force

# Sincronizar un storage específico
php artisan storage:sync 3 --force
```

**Impacto:** El cron de 15 minutos pasó de ~4 minutos (escanear 23,000 carpetas) a ~14 segundos (solo carpetas del día actual que cambiaron). Las carpetas de días pasados se omiten porque su `mtime` no cambió.

**Primera visita a una carpeta vacía:** Si la BD devuelve 0 archivos para una carpeta de storage local, el servidor hace un `syncFolder()` automático antes de responder. Esto garantiza que nuevas carpetas aparezcan completas sin esperar el siguiente cron.

### Caché Redis con TTL diferenciado

Los listados de carpetas se cachean en Redis con TTL según antigüedad:

| Carpeta | TTL | Razón |
|---|---|---|
| Raíz del storage | 60 s | Puede recibir nuevas carpetas frecuentemente |
| Carpeta del día actual | 300 s (5 min) | Archivos nuevos llegan cada ~15 min |
| Carpetas de días pasados | 86400 s (24 h) | Contenido estático, no cambia |

La invalidación usa contadores de generación (`folder_gen:{storageId}:{parentId}`) en lugar de escaneo de claves, haciendo cada invalidación O(1).

**Impacto:** Navegación repetida a la misma carpeta: primera visita ~240 ms (DB), visitas posteriores < 2 ms (Redis).

### Paginación con scroll infinito

Las carpetas con muchos archivos (hasta 823 en el caso extremo) se sirven en páginas de 100 elementos. El frontend carga más al acercarse al final de la lista con `IntersectionObserver`.

```
GET /files?storage_id=3&parent_id=456&page=1&per_page=100
```

Respuesta incluye:
```json
{
  "pagination": {
    "page": 1,
    "per_page": 100,
    "total": 823,
    "has_more": true
  }
}
```

**Impacto:** Payload inicial de 295 KB → ~35 KB. Las páginas siguientes se cargan en segundo plano al hacer scroll.

---

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

---

## Guía de migración a otro servidor (Escenario A)

> **Escenario A**: solo se migra la plataforma (app + BD + Docker). Los archivos físicos de los storages quedan en el mismo lugar o se montan en el nuevo servidor con el mismo path. Este es el caso habitual.

### Qué se mueve

| Componente | Tamaño aprox. | Cómo |
|---|---|---|
| Código (`Tcloud_v2/`) | ~50 MB | `rsync` |
| Base de datos (`tcloudstorage`) | ~286 MB | `pg_dump` / `pg_restore` |
| `.env` | KB | copia manual |
| Docker (PG + Redis) | imagen pública | se descarga sola |

Los archivos físicos (grabaciones, prensa, radio) **no se copian** — se montan desde su ubicación actual.

---

### Paso 1 — Tomar backup de la BD (hacerlo ANTES de migrar)

```bash
# Genera un archivo .dump comprimido en el volumen del contenedor
docker exec tcloud_postgres pg_dump \
  -U cloud -d tcloudstorage \
  --no-owner --no-acl -Fc \
  -f /var/lib/postgresql/data/backup_tcloudstorage_$(date +%Y-%m-%d_%H-%M-%S).dump

# Verificar que se creó (~30-60 MB)
ls -lh /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/data/postgres_data/backup_*.dump
```

El archivo queda en `data/postgres_data/` (volumen montado), accesible desde el host.

---

### Paso 2 — Copiar todo al nuevo servidor

```bash
# Desde el servidor actual — ajusta el destino
rsync -avz --exclude='data/postgres_data/' \
  /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/ \
  usuario@nuevo-server:/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/

# Copiar el backup de BD por separado
scp /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/data/postgres_data/backup_*.dump \
  usuario@nuevo-server:/tmp/
```

> Se excluye `data/postgres_data/` del rsync porque los datos de PG se restauran con `pg_restore`, no copiando el directorio binario.

---

### Paso 3 — Levantar Docker en el nuevo servidor

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2

# Crear la red si no existe
docker network create clouding_network 2>/dev/null || true

# Levantar PG y Redis
docker compose -f docker-compose.production.yml up -d
```

---

### Paso 4 — Restaurar la base de datos

```bash
# Copiar el backup al contenedor
docker cp /tmp/backup_tcloudstorage_FECHA.dump tcloud_postgres:/tmp/

# Restaurar (el contenedor debe estar corriendo con la BD vacía)
docker exec tcloud_postgres pg_restore \
  -U cloud -d tcloudstorage \
  --no-owner --no-acl \
  /tmp/backup_tcloudstorage_FECHA.dump

# Verificar integridad
docker exec tcloud_postgres psql -U cloud -d tcloudstorage \
  -c "SELECT COUNT(*) FROM files; SELECT COUNT(*) FROM users;"
```

---

### Paso 5 — Ajustar paths si cambiaron (solo si el path de los discos es diferente)

Si en el nuevo servidor los archivos físicos están en un path distinto al actual (`/www/wwwroot/data.mediaserver.com.co/Tcloud/`), actualizar en la BD:

```bash
docker exec tcloud_postgres psql -U cloud -d tcloudstorage -c "
  UPDATE storage_providers
  SET base_path = replace(base_path,
    '/www/wwwroot/data.mediaserver.com.co',
    '/NUEVO/PATH/AQUI')
  WHERE type = 'local';
"
```

Si el path es idéntico en el nuevo servidor, **omitir este paso**.

---

### Paso 6 — Configurar la app

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app

# Revisar/ajustar .env (credenciales, dominio, etc.)
# Instalar dependencias si no se copiaron con rsync
composer install --no-dev --optimize-autoloader

# Correr migraciones pendientes (si las hay)
/www/server/php/84/bin/php artisan migrate

# Limpiar cachés
/www/server/php/84/bin/php artisan config:cache
/www/server/php/84/bin/php artisan route:cache
/www/server/php/84/bin/php artisan view:clear
```

---

### Paso 7 — Verificación final

```bash
# Procesos PHP-FPM saludables (debe ser ~10 workers, máx 50)
ps --ppid $(cat /www/server/php/84/var/run/php-fpm.pid) | wc -l

# Redis responde
docker exec clouding_redis redis-cli -a "$REDIS_PASSWORD" ping

# PG responde
docker exec tcloud_postgres psql -U cloud -d tcloudstorage -c "SELECT 1;"

# Abrir en browser y hacer login
```

---

### Checklist rápido

```
□ Backup de BD tomado (data/postgres_data/backup_*.dump)
□ Código copiado al nuevo server (rsync)
□ .env copiado y ajustado
□ Docker network creada (clouding_network)
□ Contenedores PG + Redis corriendo
□ BD restaurada con pg_restore
□ Paths de storage_providers verificados/ajustados
□ composer install ejecutado
□ php artisan migrate ejecutado
□ Cachés limpiados (config:cache, route:cache)
□ Login exitoso en browser
```
