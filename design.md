## Context

A self-hosted cloud storage system similar to NextCloud with multi-level authentication (admin/standard user), file management with external storage support, granular permissions on assigned folders, public sharing with expiration and permissions, and media preview/playback for images, PDFs, MP3, MP4A, and MP4. Uses PHP 8.4 + Laravel 13 + Docker for development, PostgreSQL, Redis, and Nginx.

## Goals / Non-Goals

**Goals:**
- Multi-level login: admin and standard user with different dashboards
- Dashboard with system summary
- Storage module: link folders to users with granular permissions (read/write/upload/full)
- Personal quota for user's own uploaded files
- File module: navigate files with permission-based access
- Public sharing with expiration, permissions (read/write/upload/full), and admin-controlled share creation
- Media preview: inline viewing for images, PDFs, audio (MP3, MP4A), video (MP4) with adaptive streaming
- Redis caching for fast video loading and thumbnails
- Docker environment for local development (PHP + Nginx in one container)
- Production deployment on aaPanel (PHP direct, no Docker needed)

**Non-Goals:**
- Real-time collaboration
- Office document editing
- Mobile app
- User-to-user sharing (only public links)

## Decisions

### 1. Technology Stack

| Component | Technology | Notes |
|-----------|------------|-------|
| Backend/Frontend | PHP 8.4 + Laravel 13 | PHP 8.4 stable (Apr 2026), Laravel 13 latest stable |
| CSS Framework | TailwindCSS 4.x | Utility-first, moderna, fácil de personalizar |
| Frontend JS | Alpine.js | Ligero, para interactividad sin framework pesado |
| Development | Docker containers | Nginx, PHP, PostgreSQL, Redis separados |
| Data Storage | Host-mounted volumes | /data/ fuera de containers (persiste si borras containers) |
| Database | PostgreSQL 17 | Database name: `tcloudstorage` |
| Cache | Redis 7 | For sessions and thumbnails |
| File Storage | Nginx X-Accel-Redirect | Fast streaming, no PHP file serving |

**Importante:** La data (archivos subidos, DB, caché) se guarda en `/data/` del host, NO dentro de los containers. Así puedes borrar y recrear containers sin perder información.

```
/data/
├── storage/           # Archivos subidos por usuarios
├── postgres_data/     # Datos de PostgreSQL
└── redis_data/        # Datos de Redis
```

### 2. Frontend Stack (TailwindCSS + Alpine.js)

**Por qué TailwindCSS:**
- No require build complejo (works with just CDN in dev)
- Rápido prototyping - clases utilitarias
- Fácil crear UI moderna como NextCloud
- Customize colors, fonts, spacing fácilmente
- Purgecss elimina CSS no usado en producción

**Por qué Alpine.js:**
- Ligero (~15kb) para interactividad
- Funciona bien con Laravel Blade
- No requiere build step
- Para cosas más complejas: Livewire o React

**Estructura frontend:**
```
resources/
├── css/
│   └── app.css        # Tailwind directives
├── js/
│   └── app.js         # Alpine.js components
└── views/
    ├── layouts/
    │   └── app.blade.php
    ├── auth/
    │   └── login.blade.php
    ├── dashboard/
    │   ├── admin.blade.php
    │   └── user.blade.php
    ├── files/
    │   ├── index.blade.php
    │   └── preview.blade.php
    └── shares/
        └── create.blade.php
```

### 3. Database Schema Design (tcloudstorage)

**Diagrama de relaciones:**

```
┌─────────────┐         ┌───────────────────┐         ┌─────────────────────┐
│   users     │         │  storage_providers │
└──────┬──────┘         └─────────┬───────────┘         └──────────┬──────────┘
       │                           │                               │
       │ 1:N                       │ 1:N                          │ 1:N
       ▼                           ▼                              ▼
┌─────────────────┐       ┌─────────────────┐            ┌─────────────┐
│ user_storages   │       │      files       │            │   shares    │
│ (tabla intermedia)      └────────┬─────────┘            └──────┬──────┘
└─────────────────┘                │                             │
        │                         │                             │ N:1
        │ N:1                     │ N:1                         │
        ▼                         ▼                   ┌──────────────┐
┌─────────────┐          ┌─────────────┐            │share_access_ │
│  (owner)    │          │self (folders)│            │    log       │
└─────────────┘          └─────────────┘            └──────────────┘
```

**Tablas con campos, primary keys, foreign keys y constraints:**

#### 1. users (Usuarios)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| email | VARCHAR(255) | UNIQUE, NOT NULL |
| password_hash | VARCHAR(255) | NOT NULL |
| role | VARCHAR(20) | NOT NULL, CHECK (role IN ('admin', 'user')) |
| personal_quota_bytes | BIGINT | DEFAULT 0 (0 = unlimited) |
| personal_used_bytes | BIGINT | DEFAULT 0 |
| created_at | TIMESTAMP | DEFAULT NOW() |
| updated_at | TIMESTAMP | DEFAULT NOW() |

**Relaciones:** 1:N → user_storages, files (owner), shares (created_by)

---

#### 2. storage_providers (Proveedores de almacenamiento)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| name | VARCHAR(255) | NOT NULL |
| type | VARCHAR(50) | NOT NULL, CHECK (type IN ('local', 's3')) |
| config | JSONB | Encrypted credentials |
| base_path | VARCHAR(500) | For local type |
| enabled | BOOLEAN | DEFAULT true |
| created_at | TIMESTAMP | DEFAULT NOW() |
| updated_at | TIMESTAMP | DEFAULT NOW() |

**Relaciones:** 1:N → user_storages, files

---

#### 3. user_storages (User ↔ Storage - tabla intermedia con permisos)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| user_id | BIGINT | FOREIGN KEY → users.id, NOT NULL, ON DELETE CASCADE |
| storage_provider_id | BIGINT | FOREIGN KEY → storage_providers.id, NOT NULL, ON DELETE CASCADE |
| permissions | VARCHAR(20) | NOT NULL, CHECK (permissions IN ('read', 'write', 'upload', 'full')) |
| can_create_shares | BOOLEAN | DEFAULT false |
| assigned_at | TIMESTAMP | DEFAULT NOW() |

**Constraints:** UNIQUE(user_id, storage_provider_id) - una relación única por usuario-almacenamiento

**Relaciones:** N:1 → users, N:1 → storage_providers

---

#### 4. files (Archivos y carpetas)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| name | VARCHAR(255) | NOT NULL |
| path | VARCHAR(500) | NOT NULL, UNIQUE |
| size | BIGINT | DEFAULT 0 (bytes) |
| mime_type | VARCHAR(100) | |
| storage_provider_id | BIGINT | FOREIGN KEY → storage_providers.id, ON DELETE CASCADE, NULL = personal space |
| owner_id | BIGINT | FOREIGN KEY → users.id, NOT NULL |
| parent_id | BIGINT | FOREIGN KEY → files.id, ON DELETE CASCADE, NULL = root |
| is_folder | BOOLEAN | DEFAULT false |
| is_personal | BOOLEAN | DEFAULT false, true = consume quota del owner |
| created_at | TIMESTAMP | DEFAULT NOW() |
| updated_at | TIMESTAMP | DEFAULT NOW() |

**Relaciones:** N:1 → storage_providers (nullable), N:1 → users (owner), N:1 → files (parent, self-ref), 1:N → shares

---

#### 5. shares (Enlaces compartidos públicos)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| file_id | BIGINT | FOREIGN KEY → files.id, NOT NULL, ON DELETE CASCADE |
| token | VARCHAR(64) | UNIQUE, NOT NULL, INDEX |
| password_hash | VARCHAR(255) | NULL (opcional) |
| expires_at | TIMESTAMP | NULL (NULL = nunca expira) |
| permissions | VARCHAR(20) | NOT NULL, CHECK (permissions IN ('read', 'write', 'upload', 'full')) |
| created_by | BIGINT | FOREIGN KEY → users.id, NOT NULL |
| created_at | TIMESTAMP | DEFAULT NOW() |
| updated_at | TIMESTAMP | DEFAULT NOW() |

**Relaciones:** N:1 → files, N:1 → users (created_by), 1:N → share_access_log

---

#### 6. share_access_log (Log de accesos a shares)
| Campo | Tipo | Constraints |
|-------|------|-------------|
| id | BIGSERIAL | PRIMARY KEY |
| share_id | BIGINT | FOREIGN KEY → shares.id, NOT NULL, ON DELETE CASCADE |
| accessed_at | TIMESTAMP | DEFAULT NOW() |
| ip_address | VARCHAR(45) | IPv4 or IPv6 |

**Relaciones:** N:1 → shares

---

### Reglas de negocio (Lógica de permisos)

| Permission | Ver | Descargar | Subir nuevos | Crear carpetas | Renombrar | Mover/Copiar | Eliminar | Reemplazar |
|------------|-----|-----------|--------------|----------------|-----------|--------------|----------|------------|
| `read` | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| `write` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| `upload` | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| `full` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**Reglas de Owner:**
- Archivos en storage compartido: `owner_id = admin` (dueño del storage), `is_personal = false`
- Archivos en espacio personal: `owner_id = usuario`, `is_personal = true`, `storage_provider_id = NULL`
- Cuando colaborador crea archivo/carpeta en storage compartido: owner = admin del storage

**Reglas de Quota:**
- Solo archivos con `is_personal = true` consumen quota del owner
- Archivos en storage compartido NO consumen quota personal del colaborador

**Reglas de Sharing:**
- Solo public share links (no user-to-user sharing)
- Admin asigna storage a usuarios
- `can_create_shares = true` → usuario puede crear links públicos
- Admin puede crear shares para cualquier archivo
- Los shares solo pueden crearse para archivos donde el usuario tiene permisos

### Índices (Optimizados para velocidad)

```sql
-- Búsqueda de archivos en carpeta (navegación)
CREATE INDEX idx_files_parent_id ON files(parent_id);

-- Búsqueda de archivos por storage
CREATE INDEX idx_files_storage_id ON files(storage_provider_id);

-- Búsqueda de archivos por owner
CREATE INDEX idx_files_owner_id ON files(owner_id);

-- Archivos personales (para quota tracking rápido)
CREATE INDEX idx_files_personal ON files(owner_id, is_personal) WHERE is_personal = true;

-- Lookup de shares por token (acceso público)
CREATE INDEX idx_shares_token ON shares(token);

-- Shares por archivo
CREATE INDEX idx_shares_file_id ON shares(file_id);

-- Búsqueda de storages por usuario
CREATE INDEX idx_user_storages_user ON user_storages(user_id);

-- Búsqueda de usuarios por storage
CREATE INDEX idx_user_storages_storage ON user_storages(storage_provider_id);
```

---

### Resumen de relaciones

| Relación | Tipo | Descripción |
|----------|------|-------------|
| users → user_storages | 1:N | Un usuario tiene muchas asignaciones de storage |
| storage_providers → user_storages | 1:N | Un storage tiene muchos usuarios asignados |
| user_storages | N:1 | Tabla intermedia con permisos |
| users → files (owner) | 1:N | Un usuario tiene muchos archivos propios |
| storage_providers → files | 1:N | Un storage tiene muchos archivos (nullable para personal) |
| files → files (parent) | 1:N (self-ref) | Carpetas contienen archivos/carpetas |
| files → shares | 1:N | Un archivo tiene muchos shares |
| users → shares (creator) | 1:N | Un usuario crea muchos shares |
| shares → share_access_log | 1:N | Un share tiene muchos logs de acceso |

---

### 4. User Roles

| Role | Description |
|------|-------------|
| `admin` | Full access: manage users, storages, all files, admin dashboard, can share any file |
| `user` | Limited: assigned storages with specific permissions, personal quota, own shares |

### 5. Docker Development Environment (Contenedores separados)

**Arquitectura Docker con data persistente:**

```
┌─────────────────────────────────────────────────────────────┐
│                    Host Machine                            │
│                                                             │
│  /data/  ←─── Persiste aunque borres containers            │
│  ├── storage/         (archivos subidos)                   │
│  ├── postgres_data/  (base de datos)                       │
│  └── redis_data/     (caché)                               │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│                    Docker Network                           │
│                                                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│  │   nginx  │  │    php   │  │ postgres │  │   redis  │   │
│  │  :80/:443│  │  :9000   │  │   :5432  │  │   :6379  │   │
│  └────┬─────┘  └────┬─────┘  └──────────┘  └──────────┘   │
│       │             │                                       │
│       └─────────────┴───────────────────────────────────────│
│                         app_code (Laravel)                  │
└─────────────────────────────────────────────────────────────┘
```

**docker-compose.yml completo:**

```yaml
services:
  # Nginx - Servidor web y reverse proxy
  nginx:
    build:
      context: ./docker/nginx
      dockerfile: Dockerfile
    container_name: tcloud_nginx
    ports:
      - "8080:80"
    volumes:
      - ./app:/var/www/html
      - ./data/storage:/var/www/html/storage/app  # Data persistente
      - ./docker/nginx/logs:/var/log/nginx
    depends_on:
      - php
    networks:
      - tcloud_network

  # PHP - Procesador PHP-FPM
  php:
    build:
      context: ./docker/php
      dockerfile: Dockerfile
    container_name: tcloud_php
    volumes:
      - ./app:/var/www/html
      - ./data/storage:/var/www/html/storage/app  # Data persistente
    depends_on:
      - postgres
      - redis
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=tcloudstorage
      - DB_USERNAME=cloud
      - DB_PASSWORD=cloud123
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    networks:
      - tcloud_network

  # PostgreSQL - Base de datos
  postgres:
    image: postgres:17-alpine
    container_name: tcloud_postgres
    environment:
      POSTGRES_DB: tcloudstorage
      POSTGRES_USER: cloud
      POSTGRES_PASSWORD: cloud123
    volumes:
      - ./data/postgres_data:/var/lib/postgresql/data  # Data persistente
      - ./docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql
    ports:
      - "5432:5432"
    networks:
      - tcloud_network

  # Redis - Caché y sesiones
  redis:
    image: redis:7-alpine
    container_name: tcloud_redis
    volumes:
      - ./data/redis_data:/data  # Data persistente
      - ./docker/redis/redis.conf:/usr/local/etc/redis/redis.conf
    ports:
      - "6379:6379"
    networks:
      - tcloud_network

networks:
  tcloud_network:
    driver: bridge
```

**Estructura de carpetas:**

```
cloud-storage/
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile
│   │   ├── nginx.conf
│   │   └── sites-available/default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   ├── postgres/
│   │   └── init.sql
│   └── redis/
│       └── redis.conf
├── app/                    # Código Laravel
├── data/                   # DATA PERSISTENTE (no se borra con containers)
│   ├── storage/
│   │   └── app/           # Archivos subidos
│   ├── postgres_data/     # DB PostgreSQL
│   └── redis_data/        # Caché Redis
├── docker-compose.yml
└── .env
```

**¿Por qué /data/ en el host?**
- Si ejecutas `docker-compose down` o `docker-compose rm -f` → la data NO se borra
- Si quieres hacer backup → solo copias `/data/`
- Si migrar a otro servidor → mueves `/data/` y los contenedores funcionan igual
- Si quieres migrar a nativo (sin Docker) → montas las mismas carpetas en el servidor

### 6. Personal Quota System

| Field | Description |
|-------|-------------|
| `users.personal_quota_bytes` | Quota for files in personal space (0 = unlimited) |
| `users.personal_used_bytes` | Current usage for personal files |

**Quota enforcement:**
- Only files with `is_personal = true` count against user's quota
- User uploads to personal space → checks `personal_used_bytes + file.size <= personal_quota_bytes`
- User uploads to shared storage → quota NOT consumed (file owner is admin)

### 7. Sharing System

**Share creation rules:**
- Admin: can share any file in system
- User: can share if `can_create_shares=true` on storage AND has at least read permission on the file

**Share permissions:**

| Permission | View | Download | Upload | Delete |
|------------|------|----------|--------|--------|
| `read` | ✓ | ✓ | ✗ | ✗ |
| `write` | ✓ | ✓ | ✓ | ✗ |
| `upload` | ✓ | ✓ | ✓ | ✗ |
| `full` | ✓ | ✓ | ✓ | ✓ |

**Share features:**
- Token-based access (32 character secure random)
- Optional password protection (bcrypt)
- Expiration date (optional)
- Permissions: read, write, upload, full

### 8. Media Preview

| Type | MIME Types | Preview Method |
|------|------------|----------------|
| Images | image/jpeg, image/png, image/gif, image/webp | Inline display, modern viewer |
| PDFs | application/pdf | PDF.js viewer |
| Audio | audio/mpeg, audio/mp4a | HTML5 audio player |
| Video | video/mp4 | HTML5 video with adaptive streaming |

### 9. Redis Usage

| Key Pattern | Purpose | TTL |
|-------------|--------|-----|
| `session:{token}` | User session data | 7 days |
| `file:thumb:{file_id}` | Thumbnail cache | 1 day |
| `share:meta:{token}` | Share metadata cache | 1 hour |
| `media:stream:{file_id}` | Video streaming metadata | 1 hour |

### 10. Fast File Serving (Nginx X-Accel-Redirect)

```php
// Laravel controller - NO transfiere archivo, solo dice a Nginx qué servir
public function download($fileId) {
    $file = File::findOrFail($fileId);
    
    // Verificar permisos...
    
    // Nginx sirve el archivo directamente (super rápido)
    return response()->download(
        storage_path("app/{$file->path}"),
        $file->name,
        ['X-Accel-Redirect' => "/protected-files/{$file->path}"]
    );
}
```

```
┌─────────┐     ┌─────────┐     ┌───────────┐
│ Cliente │────▶│ Laravel │────▶│ Nginx     │
└─────────┘     │ (auth)  │     │ (sirve)   │
                └─────────┘     └───────────┘
```

Nginx sirve archivos directamente - Laravel solo verifica permisos.

### 11. API/Routes Structure

```
# Auth
POST   /auth/login          - Login
POST   /auth/logout         - Logout
GET    /auth/me             - Current user

# Dashboard
GET    /dashboard           - Role-based dashboard

# Users (admin)
GET    /users              - List users
POST   /users              - Create user
PUT    /users/{id}         - Update user
DELETE /users/{id}         - Delete user

# Storage Providers (admin)
GET    /storages           - List storages
POST   /storages           - Create storage
PUT    /storages/{id}      - Update storage
DELETE /storages/{id}      - Delete storage

# User-Storage Assignment (admin)
GET    /users/{id}/storages            - Get user's storages with permissions
POST   /users/{id}/storages            - Assign storage (permissions, can_create_shares)
PUT    /users/{id}/storages/{storageId} - Update permissions
DELETE /users/{id}/storages/{storageId} - Remove assignment

# Files
GET    /files              - List files (?parent_id, ?storage_id)
POST   /files             - Create folder
POST   /files/upload      - Upload file
GET    /files/{id}         - Get file
PUT    /files/{id}         - Rename/Move (if permission allows)
DELETE /files/{id}         - Delete (if full permission AND owner check)
GET    /files/{id}/download - Download (X-Accel-Redirect)
GET    /files/{id}/preview  - Preview media

# Shares
GET    /shares             - List user's shares
POST   /shares             - Create share (if can_create_shares + permission)
PUT    /shares/{id}        - Update share (expiration, permissions, password)
DELETE /shares/{id}        - Delete share

# Public Share
GET    /s/{token}          - Access shared content
POST   /s/{token}/download - Download shared file
POST   /s/{token}/upload   - Upload to shared folder (if share permits)
```

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| Large file uploads timeout | Laravel chunked upload, configurable timeouts |
| S3 credentials exposure | Encrypt config, use .env variables |
| Share token collision | Use cryptographically secure random |
| Personal quota enforcement | Check quota before upload, track used_bytes |
| Video streaming performance | Nginx X-Accel-Redirect + Redis cache |
| Docker dev vs aaPanel prod | Same PHP code, just different deployment method |

## Migration Plan

**Development (Docker):**
1. Create Laravel project in /app
2. Run `docker-compose up -d --build`
3. Containers automatically create DB schema via init.sql
4. Develop and test locally
5. Data persists in /data/ (postgres, redis, storage)

**Migrar a otro servidor o nativo:**
1. Copiar carpeta completa (docker/ + app/ + data/)
2. En servidor nuevo: `docker-compose up -d`
3. Si nativo (sin Docker):
   - Instalar PHP 8.4, Nginx, PostgreSQL, Redis
   - Montar /data/ en las mismas rutas
   - Configurar .env pointing to local services
   - Funciona igual porque /data/ tiene todo

**Backup:**
- Solo hacer backup de /data/
- Contiene: DB, archivos subidos, caché de Redis
- docker/ y app/ se pueden recrear desde cero

## Open Questions

- Default personal quota for new users (0 = unlimited or X GB)?
- Max upload file size (default 100MB or unlimited)?
- Trash/bin retention period (7 days, 30 days, never)?
- Thumbnail generation on upload or on-demand?