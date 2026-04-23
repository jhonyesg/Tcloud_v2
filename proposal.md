## Why

A self-hosted cloud storage solution similar to NextCloud with multi-level authentication (admin/standard user), granular folder permissions, personal quota for users, public sharing with expiration and permissions, and fast media preview/playback for images, PDFs, MP3, MP4A, and MP4.

Uses PHP/Laravel for easy extensibility with plugins, Docker for local development, and aaPanel for production deployment.

## What Changes

- Multi-level login: admin and standard user with role-based access
- Dashboard with summary for each role
- Storage module: manage folders linked to users with permissions (read/write/full)
- User-Storage assignment: many-to-many with granular permissions and can_create_shares flag
- Personal quota: optional GB limit for user's own files (shared folders bypass this)
- File module: navigate, upload, download, preview with permission-based access
- Public sharing: configurable expiration, permissions (read/write/upload/full), share creation control
- Media preview: images (modern viewer), PDFs (PDF.js), MP3/MP4A (audio), MP4 (video adaptive streaming)
- Redis for sessions, thumbnails cache, video streaming metadata
- Docker environment with separate containers (nginx, php, postgres, redis)
- Data persistent via host-mounted volumes (/data/) - survives container recreation
- Production deployment: same Docker setup or migrate to native server

## Capabilities

### New Capabilities

- `user-auth`: Multi-level login (admin/standard), Laravel session with Redis
- `user-management`: User CRUD, role assignment, personal quota management
- `dashboard`: Role-specific dashboard with summary statistics
- `storage-providers`: External storage folder management
- `user-storage-assignment`: Many-to-many with permissions (read/write/full) and can_create_shares flag
- `file-management`: Upload, download, folder navigation, preview, permission enforcement
- `sharing`: Public share links with expiration, permissions, and admin-controlled creation
- `media-preview`: Inline preview for images, PDFs, audio, video with Redis caching
- `frontend-ui`: Modern login page with animated particle background, gradient orbs, dark theme CSS variables for theming

### Modified Capabilities

<!-- No existing capabilities being modified -->

## Impact

- PHP 8.4 + Laravel 13 (single codebase)
- TailwindCSS 4.x + Alpine.js for frontend
- PostgreSQL 17: database `tcloudstorage` (6 normalized tables)
- Redis 7: Session management, thumbnails, video metadata
- Nginx: X-Accel-Redirect for fast file serving (no PHP file transfer)
- Docker: PHP + Nginx container for local development
- Production: aaPanel with PHP directly (no Docker needed)