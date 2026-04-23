## 1. Docker Setup

- [x] 1.1 Create docker-compose.yml with 4 containers (nginx, php, postgres, redis)
- [x] 1.2 Create /data/ structure (storage/app, postgres_data, redis_data)
- [x] 1.3 Create nginx Dockerfile and config (sites-available/default.conf)
- [x] 1.4 Create php Dockerfile with extensions (pdo_pgsql, gd, redis, zip)
- [x] 1.5 Create postgres init.sql with full schema (6 tables + indexes)
- [x] 1.6 Create redis.conf with persistence config
- [x] 1.7 Create .env with DB, Redis, app configuration
- [x] 1.8 Test `docker-compose up -d --build`
- [x] 1.9 Verify containers persist data after `docker-compose down`

## 2. Laravel Project Setup

- [x] 2.1 Create Laravel 13 project structure in /app
- [x] 2.2 Configure database.php for PostgreSQL (host: postgres, database: tcloudstorage)
- [x] 2.3 Configure Redis for sessions and cache
- [x] 2.4 Create .env with APP_KEY, DB (tcloudstorage), Redis settings
- [x] 2.5 Create config/app.php, config/database.php, config/cache.php
- [x] 2.6 Test DB and Redis connections

## 3. Admin User Seeder

- [x] 3.1 Create DatabaseSeeder with admin user creation
- [x] 3.2 Admin credentials: jsuarez@mediaclouding.com / T3cn0l0g14
- [x] 3.3 Use Hash::make() for password (bcrypt)
- [x] 3.4 Run seeder after migrations

## 4. Database Migrations

- [x] 4.1 Create users table (id, email, password_hash, role, personal_quota_bytes, personal_used_bytes, created_at)
- [x] 4.2 Create storage_providers table (id, name, type, config, base_path, enabled)
- [x] 4.3 Create user_storages table (user_id, storage_provider_id, permissions, can_create_shares, assigned_at)
- [x] 4.4 Create files table (id, name, path, size, mime_type, storage_provider_id, owner_id, parent_id, created_at)
- [x] 4.5 Create shares table (id, file_id, token, password_hash, expires_at, permissions, created_by, created_at)
- [x] 4.6 Create share_access_log table (id, share_id, accessed_at, ip_address)
- [x] 4.7 Create indexes for performance
- [x] 4.8 Run migrations and verify tables

## 5. Authentication Module (user-auth)

- [x] 4.1 Create User model with Laravel relationships
- [x] 4.2 Implement login controller (bcrypt verification, Redis session)
- [x] 4.3 Implement logout controller (delete session)
- [x] 4.4 Create auth middleware (session validation, role detection)
- [x] 4.5 Configure session driver as Redis in config/session.php
- [x] 4.6 Create route /auth/login, /auth/logout, /auth/me
- [x] 4.7 Add role-based middleware (admin vs user)

## 5. User Management Module

- [x] 5.1 Create UserController with CRUD operations
- [x] 5.2 Admin-only middleware for user management routes
- [x] 5.3 User creation with role and personal_quota_bytes
- [x] 5.4 User listing with pagination
- [x] 5.5 User update (including quota adjustment)
- [x] 5.6 User delete with cascade (user_storages, files, shares)
- [x] 5.7 User self-profile endpoint (password change)

## 6. Dashboard Module

- [x] 6.1 Create DashboardController
- [x] 6.2 Admin dashboard: total users, storages, files, storage used, shares
- [x] 6.3 User dashboard: assigned storages with permissions, personal quota usage, recent files, shares count
- [x] 6.4 Role-based route protection

## 7. Storage Provider Module

- [x] 7.1 Create StorageProviderController
- [x] 7.2 CRUD operations (admin only)
- [x] 7.3 Local storage type with base_path validation
- [x] 7.4 S3 storage type with credentials
- [x] 7.5 Connection test functionality
- [x] 7.6 Storage listing with file count

## 8. User-Storage Assignment Module

- [x] 8.1 Create UserStorageController
- [x] 8.2 Assign storage to user with permissions (read/write/full) and can_create_shares
- [x] 8.3 Support many-to-many: same storage to multiple users with different permissions
- [x] 8.4 List user's storages with permissions
- [x] 8.5 Update permissions on existing assignment
- [x] 8.6 Remove storage assignment

## 9. File Management Module (Permission-Based)

- [x] 9.1 Create FileController
- [x] 9.2 File listing with parent_id and storage_id filters
- [x] 9.3 Permission check middleware on all file operations
- [x] 9.4 Folder creation (requires write/full permission)
- [x] 9.5 File upload with permission check
- [x] 9.6 Personal quota check for user's own files (skip for shared folder uploads)
- [x] 9.7 Update owner to storage owner for files in shared storages
- [x] 9.8 File download with Nginx X-Accel-Redirect
- [x] 9.9 File deletion (requires full permission AND ownership)
- [x] 9.10 Folder deletion (recursive)
- [x] 9.11 File rename (requires full permission AND ownership)
- [x] 9.12 Duplicate folder name prevention (409)

## 10. Sharing Module

- [x] 10.1 Create ShareController
- [x] 10.2 Check can_create_shares flag (403 if false)
- [x] 10.3 Check user's permission level on file (cannot give more than they have)
- [x] 10.4 Admin can share any file
- [x] 10.5 Generate secure 32-character token
- [x] 10.6 Share permissions: read, write, upload, full
- [x] 10.7 Password protection with bcrypt
- [x] 10.8 Expiration date support (410 when expired)
- [x] 10.9 Public share access endpoint (/s/{token})
- [x] 10.10 Password validation for protected shares
- [x] 10.11 Share modification (update expiration, permissions, password)
- [x] 10.12 List user's shares
- [x] 10.13 Delete/revoke share
- [x] 10.14 Access logging

## 11. Media Preview Module

- [x] 11.1 Image preview endpoint (jpeg, png, gif, webp)
- [x] 11.2 Inline Content-Disposition for images
- [x] 11.3 PDF preview endpoint
- [x] 11.4 Audio preview endpoint (mp3, mp4a) with HTML5 player support
- [x] 11.5 Video preview endpoint (mp4) with adaptive streaming
- [x] 11.6 Redis thumbnail cache (file:thumb:{id}, TTL: 1 day)
- [x] 11.7 Redis share metadata cache (share:meta:{token}, TTL: 1 hour)
- [x] 11.8 Redis video streaming cache (media:stream:{id}, TTL: 1 hour)
- [x] 11.9 Cache invalidation on file delete

## 12. Nginx Configuration for Fast Serving

- [x] 12.1 Configure X-Accel-Redirect for protected files
- [x] 12.2 Internal route /protected-files/ for file serving
- [x] 12.3 Range request support for video streaming
- [x] 12.4 Static file caching headers
- [x] 12.5 CORS headers for media preview

## 13. Frontend (Laravel Blade or React SPA)

- [x] 13.1 Layout with navigation (dashboard, files, shares)
- [x] 13.2 Login page
- [x] 13.3 Admin dashboard view
- [x] 13.4 User dashboard view
- [x] 13.5 File browser with folder navigation
- [x] 13.6 File upload component
- [x] 13.7 Share creation modal (permissions, expiration, password)
- [x] 13.8 Media preview player (images, PDF, audio, video)
- [x] 13.9 User management panel (admin)
- [x] 13.10 Storage assignment panel (admin)

## 15. Folder Sharing (Compartición de carpetas)

- [x] 15.1 Enable "Share" button for folders in files/index.blade.php (currently only files)
- [x] 15.2 Modify ShareController to allow share creation for folder file_id
- [x] 15.3 Update /s/{token} endpoint to detect if shared item is a folder
- [x] 15.4 Create folder content listing for shared folder view
- [x] 15.5 Add upload functionality for folders with `upload` permission
- [x] 15.6 Add rename functionality for files with `write` permission
- [x] 15.7 Add delete functionality for files with `write` permission
- [x] 15.8 Add create folder functionality for folders with `full` permission
- [x] 15.9 Remove "download all as ZIP" option for shared folders
- [x] 15.10 Update public share view to show folder actions based on permissions

## 16. Testing

- [ ] 16.1 Test folder sharing creation
- [ ] 16.2 Test public access to shared folder content
- [ ] 16.3 Test upload to shared folder (upload permission)
- [ ] 16.4 Test rename files in shared folder (write permission)
- [ ] 16.5 Test delete files in shared folder (write permission)
- [ ] 16.6 Test create folder in shared folder (full permission)
- [ ] 16.7 Test permission enforcement (read-only cannot upload/rename/delete)