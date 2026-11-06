<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `solicitud-backup-mis-archivos` (Grupo 1 — Fundaciones de esquema).
 *
 * Tabla de solicitudes de backup: el registro que el cliente hace al pedir
 * el material de producción de una carpeta-día.
 *
 * Por qué una tabla propia y no leerlo de `shares`:
 *  - `shares.file_id` es FK `ON DELETE CASCADE`. El árbol de backup rota
 *    (~13 carpetas-día rodantes): cuando la carpeta-día sale del disco, el
 *    prune del sync borra su fila de `files` y el share del cliente muere
 *    solo, dejando un enlace 404 sin explicación. La solicitud tiene que
 *    sobrevivir al material.
 *  - El motivo de la solicitud no vive en `shares` (que es un catálogo de
 *    enlaces, no un buzón).
 *  - `shares.path_snapshot` se escribe pero no se lee en ningún lado: no
 *    sirve como fuente de verdad.
 *
 * Semántica de `status` (vocabulario cerrado, con CHECK):
 *  - `ready`       → hay enlace activo; el material estaba disponible.
 *  - `unavailable` → el backup de ese día no existe (aún no llega, o ya
 *                    salió de la ventana de retención). NO hay enlace.
 *  - `expired`     → el enlace venció su vigencia.
 *  - `revoked`     → el cliente revocó la solicitud.
 *
 * `source_folder_id` es `ON DELETE SET NULL`: si Mis Archivos borra la
 * carpeta-día del origen, la solicitud se conserva como histórico (el motivo
 * y el enlace siguen siendo válidos). `share_id` también es `SET NULL`: un
 * share borrado no debe borrar la solicitud, solo dejarla sin enlace.
 *
 * `day` es el nombre de la carpeta tal como aparece en disco (`DDMMYYYY`),
 * no una fecha normalizada: es lo que el cliente vio y lo que hay que
 * resolver en el árbol de backup.
 *
 * Reversible: el down() elimina la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_storage_id');
            $table->unsignedBigInteger('source_folder_id')->nullable();
            $table->unsignedBigInteger('backup_storage_id')->nullable();
            $table->unsignedBigInteger('share_id')->nullable();

            $table->string('day', 8);
            $table->text('motivo');
            $table->string('status', 20)->default('unavailable');
            $table->string('backup_path', 500)->nullable();
            $table->timestampTz('link_expires_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_storage_id')->references('id')->on('storage_providers')->cascadeOnDelete();
            $table->foreign('source_folder_id')->references('id')->on('files')->nullOnDelete();
            $table->foreign('backup_storage_id')->references('id')->on('storage_providers')->nullOnDelete();
            $table->foreign('share_id')->references('id')->on('shares')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE backup_requests
            ADD CONSTRAINT backup_requests_status_check
            CHECK (status IN ('ready', 'unavailable', 'expired', 'revoked'))
        SQL);

        // Historial del cliente: siempre "mis solicitudes, más recientes
        // primero". Cubre el listado y el contador de vivas.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_backup_requests_user_created
            ON backup_requests (user_id, created_at DESC)
        SQL);

        // Conteo de solicitudes vivas por estado (usado por el contador).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_backup_requests_user_status
            ON backup_requests (user_id, status)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_requests');
    }
};
