<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalización del esquema de storage (change normalize-storage-schema).
 *
 * 1. `storage_providers.is_personal`: bandera que identifica los storages
 *    personales. Se siembra desde el prefijo `/home/www/Usuarios_tcloud/`
 *    (config('storage.personal_base_path')) y pasa a ser la ÚNICA fuente de
 *    verdad; la app deja de comparar prefijos de path.
 * 2. `user_storages.transcription_enabled`: columna MUERTA (documentada en
 *    2026_08_18_210000). Nadie la lee ni escribe. Se elimina con su índice
 *    parcial.
 * 3. `files.is_personal`: columna espejo de la bandera del storage, escrita
 *    en 9 lugares y leída en ninguno. Se elimina con su índice parcial.
 * 4. `external_sites_color_check`: la app valida 16 colores desde el
 *    2026-09-04 pero la BD solo aceptaba 8 → 500 al guardar un color nuevo.
 *    Se reemplaza por el CHECK con los 16 colores.
 * 5. `fn_storage_provider_delete_quota`: el trigger de borrado pasa de
 *    comparar `OLD.base_path LIKE` a leer `OLD.is_personal`.
 */
return new class extends Migration
{
    private const COLORS_16 = [
        'blue', 'sky', 'cyan', 'teal', 'green', 'lime', 'yellow', 'amber',
        'orange', 'red', 'rose', 'pink', 'fuchsia', 'purple', 'indigo', 'slate',
    ];

    private const COLORS_8 = [
        'blue', 'green', 'red', 'purple', 'amber', 'cyan', 'rose', 'slate',
    ];

    public function up(): void
    {
        // 1. Bandera is_personal + backfill desde el prefijo.
        Schema::table('storage_providers', function ($table) {
            $table->boolean('is_personal')->default(false)->after('allow_parent_overlap');
        });

        $prefix = rtrim((string) config('storage.personal_base_path', '/home/www/Usuarios_tcloud/'), '/');
        DB::statement('UPDATE storage_providers SET is_personal = true WHERE base_path LIKE ?', [$prefix . '/%']);

        // 2. Columna muerta de user_storages.
        DB::statement('DROP INDEX IF EXISTS idx_user_storages_tx_enabled');
        Schema::table('user_storages', function ($table) {
            $table->dropColumn('transcription_enabled');
        });

        // 3. Columna espejo de files.
        DB::statement('DROP INDEX IF EXISTS idx_files_personal');
        Schema::table('files', function ($table) {
            $table->dropColumn('is_personal');
        });

        // 4. CHECK de color alineado con la validación de la app.
        DB::statement('ALTER TABLE external_sites DROP CONSTRAINT IF EXISTS external_sites_color_check');
        $colors = implode("', '", self::COLORS_16);
        DB::statement("ALTER TABLE external_sites ADD CONSTRAINT external_sites_color_check CHECK (color IN ('{$colors}'))");

        // 5. Trigger de cuota por bandera.
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_storage_provider_delete_quota()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.is_personal THEN
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

        // El trigger original (2026_05_16_100005) pudo haber sido dropeado
        // manualmente en algún momento: la función existía pero el trigger no.
        // Se garantiza su existencia para que la cuota se descuente al borrar.
        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_storage_provider_delete_quota') THEN
                    CREATE TRIGGER trg_storage_provider_delete_quota
                    BEFORE DELETE ON storage_providers
                    FOR EACH ROW EXECUTE FUNCTION fn_storage_provider_delete_quota();
                END IF;
            END
            \$\$
        ");
    }

    public function down(): void
    {
        // 5. Trigger por prefijo (versión original).
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

        // El trigger se deja tal como estaba antes de esta migración: si no
        // existía (estado pre-existente), no se recrea en el rollback.
        DB::statement('DROP TRIGGER IF EXISTS trg_storage_provider_delete_quota ON storage_providers');

        // 4. CHECK de 8 colores (versión original).
        DB::statement('ALTER TABLE external_sites DROP CONSTRAINT IF EXISTS external_sites_color_check');
        $colors = implode("', '", self::COLORS_8);
        DB::statement("ALTER TABLE external_sites ADD CONSTRAINT external_sites_color_check CHECK (color IN ('{$colors}'))");

        // 3. Columna espejo de files.
        Schema::table('files', function ($table) {
            $table->boolean('is_personal')->default(false);
        });
        DB::statement('CREATE INDEX idx_files_personal ON files(owner_id, is_personal) WHERE is_personal = true');

        // 2. Columna muerta de user_storages.
        Schema::table('user_storages', function ($table) {
            $table->boolean('transcription_enabled')->default(false)->after('can_create_shares');
        });
        DB::statement('CREATE INDEX IF NOT EXISTS idx_user_storages_tx_enabled
                       ON user_storages (storage_provider_id)
                       WHERE transcription_enabled');

        // 1. Bandera is_personal.
        Schema::table('storage_providers', function ($table) {
            $table->dropColumn('is_personal');
        });
    }
};
