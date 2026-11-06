<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change `fix-shares-module-regression` (Grupo 11 — Ola 2, retiro de la
 * familia mirror).
 *
 * Elimina la tabla `share_notification_log`.
 *
 * Por que: la tabla solo la consumia el comando `shares:notify-repointed`,
 * que nunca llego a implementarse, y que notificaba a los creadores de
 * shares de folder con permisos destructivos cuando la canonicalizacion
 * (`files.canonical_folder_id` -> `files.merged_into_id` -> ...) re-apuntaba
 * un share al folder canonico.
 *
 * Toda esa familia fue desmontada deliberadamente por el restore de Mis
 * Archivos de agosto 2026 (`2026_09_20_120000_drop_mirror_columns_and_
 * restore_baseline`): la columna `files.canonical_folder_id` y la tabla
 * `file_mirror_audit_log` ya no existen, y el comando
 * `shares:repair-mirror-targets` fue eliminado en este mismo change. Sin la
 * operacion de repoint, el aviso "tu share ahora apunta al folder canonico"
 * describe un evento que el sistema ya no puede producir: la tabla queda
 * sin consumidor.
 *
 * Los 2 registros historicos que quedaban (kind
 * `repoint_share_canonicalization`, 2026-09-17) se archivaron antes del drop
 * en `backups/share_notification_log_<timestamp>.csv`. Son evidencia de una
 * migracion puntual, no estado vivo.
 *
 * Reversible: el down() recrea la tabla con el mismo schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('share_notification_log');
    }

    public function down(): void
    {
        Schema::create('share_notification_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('share_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('kind', 100)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->jsonb('metadata')->nullable();
        });
    }
};
