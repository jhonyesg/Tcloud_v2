<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger BEFORE DELETE en storage_providers:
        // Ajusta personal_used_bytes de todos los usuarios afectados antes de que
        // el CASCADE elimine sus archivos. Sin esto, borrar un storage personal
        // deja el contador inflado para siempre.
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_storage_provider_delete_quota()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.base_path LIKE '/home/www/Usuarios_tcloud/%' THEN
                    UPDATE users u
                    SET personal_used_bytes = GREATEST(0, personal_used_bytes - sq.total)
                    FROM (
                        SELECT owner_id, COALESCE(SUM(size), 0) AS total
                        FROM files
                        WHERE storage_provider_id = OLD.id
                          AND is_folder = FALSE
                        GROUP BY owner_id
                    ) sq
                    WHERE u.id = sq.owner_id;
                END IF;
                RETURN OLD;
            END;
            \$\$
        ");

        DB::statement("
            CREATE TRIGGER trg_storage_provider_delete_quota
            BEFORE DELETE ON storage_providers
            FOR EACH ROW EXECUTE FUNCTION fn_storage_provider_delete_quota()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_storage_provider_delete_quota ON storage_providers');
        DB::statement('DROP FUNCTION IF EXISTS fn_storage_provider_delete_quota()');
    }
};
