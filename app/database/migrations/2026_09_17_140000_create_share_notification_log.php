<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-share-folder-canonical-wiring` (Task 3.1):
 *
 * Tabla para idempotencia del comando `shares:notify-repointed`. Cada row
 * registra que un share fue notificado a su creador tras un repoint. El
 * comando cruza con esta tabla antes de re-enviar emails para evitar
 * duplicados dentro del mismo window.
 *
 * Diferencia con `file_mirror_audit_log`:
 *  - `file_mirror_audit_log` registra el repoint en sí (hecho).
 *  - `share_notification_log` registra la comunicación al creador (acción UX).
 *
 * Borrar filas de esta tabla re-habilita la notificación del share asociado.
 * Cleanup: rows con `notified_at < now() - interval '180 days'` pueden
 * purarse sin perder información operacional (los audit rows siguen en
 * `file_mirror_audit_log`).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE share_notification_log (
                id bigserial PRIMARY KEY,
                share_id bigint NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
                recipient_user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                kind varchar(50) NOT NULL DEFAULT 'repoint_share_canonicalization',
                notified_at timestamp NOT NULL DEFAULT now(),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb
            )
        SQL);

        DB::statement('CREATE INDEX share_notification_log_share_idx ON share_notification_log (share_id)');
        DB::statement('CREATE INDEX share_notification_log_recipient_idx ON share_notification_log (recipient_user_id)');
        DB::statement('CREATE INDEX share_notification_log_notified_at_idx ON share_notification_log (notified_at DESC)');
        DB::statement('CREATE INDEX share_notification_log_kind_idx ON share_notification_log (kind)');

        // Idempotencia: una sola fila por (share_id, kind). El comando usa esta
        // constraint para deduplicar sin escanear toda la tabla.
        DB::statement('CREATE UNIQUE INDEX share_notification_log_unique_per_share_kind ON share_notification_log (share_id, kind)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS share_notification_log');
    }
};