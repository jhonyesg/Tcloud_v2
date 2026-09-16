<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Concurrent indexes must run outside Laravel's transaction wrapper. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('files', 'availability_state')) {
            DB::statement("ALTER TABLE files ADD COLUMN availability_state VARCHAR(20) NOT NULL DEFAULT 'unknown'");
        }

        if (!Schema::hasColumn('files', 'last_verified_at')) {
            DB::statement('ALTER TABLE files ADD COLUMN last_verified_at TIMESTAMP NULL');
        }

        if (!Schema::hasColumn('files', 'missing_since_at')) {
            DB::statement('ALTER TABLE files ADD COLUMN missing_since_at TIMESTAMP NULL');
        }

        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_availability_state_check CHECK (availability_state IN ('unknown', 'available', 'missing'))");

        // The audit found no NULL accessed_at rows. Keep this backfill defensive
        // for deployments where older data was written outside the application.
        DB::statement('UPDATE share_access_log SET accessed_at = NOW() WHERE accessed_at IS NULL');
        DB::statement('ALTER TABLE share_access_log ALTER COLUMN accessed_at SET NOT NULL');

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_files_availability_state ON files (availability_state, last_verified_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_shares_expires_at ON shares (expires_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_shares_owner_created_at ON shares (created_by, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_shares_owner_expires_at ON shares (created_by, expires_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_share_access_share_accessed_at ON share_access_log (share_id, accessed_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_share_access_share_accessed_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_shares_owner_expires_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_shares_owner_created_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_shares_expires_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_files_availability_state');

        DB::statement('ALTER TABLE share_access_log ALTER COLUMN accessed_at DROP NOT NULL');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check');

        if (Schema::hasColumn('files', 'missing_since_at')) {
            DB::statement('ALTER TABLE files DROP COLUMN missing_since_at');
        }
        if (Schema::hasColumn('files', 'last_verified_at')) {
            DB::statement('ALTER TABLE files DROP COLUMN last_verified_at');
        }
        if (Schema::hasColumn('files', 'availability_state')) {
            DB::statement('ALTER TABLE files DROP COLUMN availability_state');
        }
    }
};
