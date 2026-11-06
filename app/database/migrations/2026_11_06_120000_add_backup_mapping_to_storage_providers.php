<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `solicitud-backup-mis-archivos` (Grupo 1 — Fundaciones de esquema).
 *
 * Declara, en el storage de ORIGEN, cuál es su carpeta equivalente en el
 * árbol de backup de producción ("Producción").
 *
 * Contexto:
 *  - El árbol de backup es el export NFS `192.168.0.131:/media/difusor02/Backup_TV`
 *    montado en `Tcloud/Disco_D/backup`, con la misma estructura
 *    `<NN_Canal_BK>/<DDMMYYYY>/` que los discos de origen.
 *  - Los nombres de los dos lados NO se corresponden (`09 Redmas` ↔
 *    `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`), así que el
 *    vínculo tiene que ser explícito. No se puede deducir.
 *  - El vínculo vive en el storage de origen a propósito: registrar cada
 *    carpeta de backup como un storage propio (el patrón ya intentado con
 *    `35 Red Mas BK`, id 131) obliga a mover ~8.700 filas de `files` y
 *    duplica el catálogo, porque `files` es UNIQUE en
 *    `(storage_provider_id, path)`.
 *
 * Notas de diseño:
 *  - Ambas columnas son NULLABLE y sin DEFAULT: `ADD COLUMN` sin default no
 *    reescribe la tabla en PostgreSQL.
 *  - `backup_storage_id` es `ON DELETE SET NULL`: si el admin borra el storage
 *    de backup, el vínculo se apaga solo en vez de borrar la fila de origen.
 *  - El CHECK sobre `backup_folder_name` prohíbe `/`, `..` y el vacío. El
 *    nombre se concatena dentro de una ruta física; sin este guardia un
 *    valor malicioso podría apuntar fuera del árbol de backup. Es la misma
 *    defensa que aplica el guard de contención por cadena en el resolver.
 *  - El índice es PARCIAL: solo los ~28 storages con mapeo lo tienen.
 *
 * Reversible: el down() elimina el índice, el CHECK, la FK y las columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('backup_storage_id')->nullable()->after('duplicate_of_storage_id');
            $table->string('backup_folder_name', 120)->nullable()->after('backup_storage_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE storage_providers
            ADD CONSTRAINT storage_providers_backup_storage_id_fkey
            FOREIGN KEY (backup_storage_id) REFERENCES storage_providers(id) ON DELETE SET NULL
        SQL);

        // El nombre de la carpeta se concatena en una ruta fisica: se acota a
        // un unico segmento de nombre, sin separadores ni recorrido de padre.
        DB::statement(<<<'SQL'
            ALTER TABLE storage_providers
            ADD CONSTRAINT storage_providers_backup_folder_name_check
            CHECK (
                backup_folder_name IS NULL
                OR (
                    backup_folder_name <> ''
                    AND backup_folder_name NOT LIKE '%/%'
                    AND backup_folder_name NOT LIKE '%\%%'
                    AND backup_folder_name NOT LIKE '%..%'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX idx_storage_providers_backup_mapping
            ON storage_providers (backup_storage_id)
            WHERE backup_storage_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_storage_providers_backup_mapping');
        DB::statement('ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_backup_folder_name_check');
        DB::statement('ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_backup_storage_id_fkey');

        Schema::table('storage_providers', function (Blueprint $table) {
            $table->dropColumn(['backup_storage_id', 'backup_folder_name']);
        });
    }
};
