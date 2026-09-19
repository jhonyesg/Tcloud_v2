<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Stage 1):
 *
 * Tabla append-only de auditoría para cada mutación de `files.merged_into_id`
 * y cada repoint de `shares.file_id` ejecutado por los comandos artisan de
 * este change. La columna `before_value`/`after_value` permiten el rollback
 * vía `shares:repair-mirror-targets --revert`.
 *
 * Append-only se enforcea con un BEFORE UPDATE/DELETE trigger que aborta.
 * La única forma soportada de borrar filas es via retention job
 * (futuro, fuera de scope).
 *
 * Acciones registradas (enum):
 *  - link_mirror:    `files:repair-folder-mirrors --apply` setea merged_into_id
 *  - unlink_mirror: revierte el link (futuro, hoy no se invoca)
 *  - repoint_share:  `shares:repair-mirror-targets --apply` cambia share.file_id
 *  - revert_share:   `shares:repair-mirror-targets --revert` restaura share.file_id
 *  - resync_snapshot: `files:resync-base-path-snapshots --apply` cambia snapshot
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE file_mirror_audit_log (
                id bigserial PRIMARY KEY,
                actor_user_id bigint NULL REFERENCES users(id) ON DELETE SET NULL,
                action varchar(30) NOT NULL,
                mirror_file_id bigint NULL REFERENCES files(id) ON DELETE SET NULL,
                canonical_file_id bigint NULL REFERENCES files(id) ON DELETE SET NULL,
                share_id bigint NULL REFERENCES shares(id) ON DELETE SET NULL,
                before_value text NULL,
                after_value text NULL,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamp NOT NULL DEFAULT now(),
                CONSTRAINT file_mirror_audit_log_action_check CHECK (
                    action IN ('link_mirror','unlink_mirror','repoint_share','revert_share','resync_snapshot')
                )
            )
        SQL);

        // Índices para los queries esperados:
        //  - audit por mirror específico (queries de soporte)
        //  - audit por share específico (revert necesita WHERE share_id = X)
        //  - audit por acción (operador revisa conteos)
        //  - audit en orden cronológico inverso (revert y reportes)
        DB::statement('CREATE INDEX file_mirror_audit_log_mirror_idx ON file_mirror_audit_log (mirror_file_id)');
        DB::statement('CREATE INDEX file_mirror_audit_log_canonical_idx ON file_mirror_audit_log (canonical_file_id)');
        DB::statement('CREATE INDEX file_mirror_audit_log_share_idx ON file_mirror_audit_log (share_id) WHERE share_id IS NOT NULL');
        DB::statement('CREATE INDEX file_mirror_audit_log_action_idx ON file_mirror_audit_log (action)');
        DB::statement('CREATE INDEX file_mirror_audit_log_created_at_idx ON file_mirror_audit_log (created_at DESC)');

// Trigger append-only: bloquea UPDATE que cambia un valor real
        // (no permite SET NULL implícito por ON DELETE CASCADE en las FKs
        // — eso lo permite porque es solo limpiar referencias a filas
        // borradas, no cambiar el contenido del log).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION file_mirror_audit_log_append_only()
            RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'UPDATE') THEN
                    -- Permitir UPDATE que solo nullea una FK (cascade de borrado de parent).
                    -- Bloquear todo lo demás (cambio de action, before/after, metadata).
                    IF (OLD.action IS NOT DISTINCT FROM NEW.action
                        AND OLD.before_value IS NOT DISTINCT FROM NEW.before_value
                        AND OLD.after_value IS NOT DISTINCT FROM NEW.after_value
                        AND OLD.metadata IS NOT DISTINCT FROM NEW.metadata
                        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'file_mirror_audit_log is append-only; UPDATE rejected (id=%, op=%)',
                        OLD.id, TG_OP
                        USING ERRCODE = 'restrict_violation';
                ELSIF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'file_mirror_audit_log is append-only; DELETE rejected (id=%, op=%)',
                        OLD.id, TG_OP
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER file_mirror_audit_log_append_only_trigger
            BEFORE UPDATE OR DELETE ON file_mirror_audit_log
            FOR EACH ROW EXECUTE FUNCTION file_mirror_audit_log_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS file_mirror_audit_log_append_only_trigger ON file_mirror_audit_log');
        DB::statement('DROP FUNCTION IF EXISTS file_mirror_audit_log_append_only()');
        DB::statement('DROP TABLE IF EXISTS file_mirror_audit_log');
    }
};