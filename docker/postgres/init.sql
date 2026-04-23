-- ============================================
-- TCloud Storage - Database Schema
-- Database: tcloudstorage
-- ============================================

-- ============================================
-- USERS TABLE
-- ============================================
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    personal_quota_bytes BIGINT DEFAULT 0,  -- 0 = unlimited
    personal_used_bytes BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Admin user (password will be set by Laravel seeder: T3cn0l0g14)
-- El hash real se crea con: Hash::make('T3cn0l0g14')
INSERT INTO users (email, password_hash, role, personal_quota_bytes, created_at, updated_at)
VALUES ('jsuarez@mediaclouding.com', '$2y$12$PLACEHOLDER_HASH_REPLACE_WITH_LARAVEL', 'admin', 0, NOW(), NOW());

-- ============================================
-- STORAGE_PROVIDERS TABLE
-- ============================================
CREATE TABLE storage_providers (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL CHECK (type IN ('local', 's3')),
    config JSONB NOT NULL DEFAULT '{}',
    base_path VARCHAR(500),
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- USER_STORAGES TABLE (N:N with permissions)
-- ============================================
CREATE TABLE user_storages (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    storage_provider_id BIGINT NOT NULL REFERENCES storage_providers(id) ON DELETE CASCADE,
    permissions VARCHAR(20) NOT NULL CHECK (permissions IN ('read', 'write', 'upload', 'full')),
    can_create_shares BOOLEAN DEFAULT false,
    assigned_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(user_id, storage_provider_id)
);

-- ============================================
-- FILES TABLE
-- ============================================
CREATE TABLE files (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(500) NOT NULL UNIQUE,
    size BIGINT DEFAULT 0,
    mime_type VARCHAR(100),
    storage_provider_id BIGINT REFERENCES storage_providers(id) ON DELETE CASCADE,
    owner_id BIGINT NOT NULL REFERENCES users(id),
    parent_id BIGINT REFERENCES files(id) ON DELETE CASCADE,
    is_folder BOOLEAN DEFAULT false,
    is_personal BOOLEAN DEFAULT false,  -- true = consume quota del owner
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- SHARES TABLE
-- ============================================
CREATE TABLE shares (
    id BIGSERIAL PRIMARY KEY,
    file_id BIGINT NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    expires_at TIMESTAMP,
    permissions VARCHAR(20) NOT NULL CHECK (permissions IN ('read', 'write', 'upload', 'full')),
    created_by BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- SHARE_ACCESS_LOG TABLE
-- ============================================
CREATE TABLE share_access_log (
    id BIGSERIAL PRIMARY KEY,
    share_id BIGINT NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    accessed_at TIMESTAMP DEFAULT NOW(),
    ip_address VARCHAR(45)
);

-- ============================================
-- INDEXES: Optimizados para velocidad
-- ============================================

-- Files navigation
CREATE INDEX idx_files_parent_id ON files(parent_id);
CREATE INDEX idx_files_storage_id ON files(storage_provider_id);
CREATE INDEX idx_files_owner_id ON files(owner_id);

-- Personal files for quota tracking (partial index)
CREATE INDEX idx_files_personal ON files(owner_id, is_personal) WHERE is_personal = true;

-- Shares lookup
CREATE INDEX idx_shares_token ON shares(token);
CREATE INDEX idx_shares_file_id ON shares(file_id);

-- User-Storages relationships
CREATE INDEX idx_user_storages_user ON user_storages(user_id);
CREATE INDEX idx_user_storages_storage ON user_storages(storage_provider_id);

-- Share access logs
CREATE INDEX idx_share_access_share_id ON share_access_log(share_id);
CREATE INDEX idx_share_access_accessed_at ON share_access_log(accessed_at);

-- ============================================
-- COMMENTS
-- ============================================
COMMENT ON TABLE users IS 'User accounts with role and personal quota';
COMMENT ON TABLE storage_providers IS 'Storage backends (local folders, S3)';
COMMENT ON TABLE user_storages IS 'User-Storage assignment with permissions';
COMMENT ON TABLE files IS 'Files and folders metadata';
COMMENT ON TABLE shares IS 'Public share links with expiration and permissions';
COMMENT ON TABLE share_access_log IS 'Share access history for analytics';