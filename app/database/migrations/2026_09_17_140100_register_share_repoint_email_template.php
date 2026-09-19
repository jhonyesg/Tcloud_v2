<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-share-folder-canonical-wiring` (Task 3.3):
 *
 * Registra el template de email `share-repoint-canonicalization` usado por
 * `NotifyRepointedShareCreatorsCommand`. El template explica al creador
 * del share que su folder fue canonicado y que las operaciones de delete
 * ahora son destructivas en disco.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO correo_plantillas (name, display_name, subject, body_html, variables, is_active)
            VALUES (
                'share-repoint-canonicalization',
                'Share repointed to canonical folder',
                'Tu share apunta al folder canónico — comportamiento de delete cambió',
                '<h1>Tu share fue canonicado</h1>
            <p>Hola {{nombre_destinatario}},</p>
            <p>El sistema ha corregido <strong>{{count_shares}} share(s)</strong> que apuntaban a un folder "espejo" (parent view) para que ahora apunten al folder real donde viven los archivos físicos.</p>
            <h2>¿Qué significa esto?</h2>
            <p><strong>Antes del cambio:</strong> si un visitante usaba tu share para borrar el folder, la operación era un no-op accidental (el folder "espejo" no tenía archivos físicos, así que el delete no borraba nada).</p>
            <p><strong>Después del cambio:</strong> tu share apunta al folder canónico. Cualquier operación de <strong>delete</strong> a través de este share <strong>ahora SÍ borra archivos del disco</strong>.</p>
            <h2>Shares afectados</h2>
            <pre>{{shares_detalle}}</pre>
            <h2>¿Qué deberías hacer?</h2>
            <ul>
            <li>Si querías que el share tuviera permisos destructivos: no necesitas hacer nada, todo funciona como esperabas.</li>
            <li>Si NO querías que el share pudiera borrar archivos: cambia los permisos del share de <code>write</code> o <code>full</code> a <code>read</code> desde la UI de Mis Archivos.</li>
            </ul>
            <p>Saludos,<br>El equipo de TCloud</p>',
                'nombre_destinatario, count_shares, shares_detalle',
                TRUE
            )
            ON CONFLICT (name) DO UPDATE SET
                subject = EXCLUDED.subject,
                body_html = EXCLUDED.body_html,
                variables = EXCLUDED.variables,
                is_active = EXCLUDED.is_active
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM correo_plantillas WHERE name = 'share-repoint-canonicalization'");
    }
};