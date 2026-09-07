<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * mis-avisos-menciones: plantilla de correo para el envío manual del link
 * de exportación del histórico de menciones.
 *
 * Nota: la plantilla 'ia-alert-match' usada por el digest NUNCA fue creada
 * en correo_plantillas (los envíos del módulo viejo habrían fallado). Se
 * crea aquí también, idempotente, porque SendAlertDigest la consume.
 */
return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            [
                'name' => 'ia-alert-match',
                'display_name' => 'Aviso de coincidencia en grabación',
                'subject' => 'Avisos de hoy: {{filename}}',
                'body_html' => '<h2 style="margin:0 0 12px;color:#1e293b;">Coincidencias detectadas</h2>'
                    . '<p>Hola {{user}}, tus palabras clave aparecieron en <strong>{{filename}}</strong> ({{match_count}} coincidencia(s)).</p>'
                    . '{{#each matches}}<div style="border-left:3px solid #4654a8;padding:8px 12px;margin:10px 0;background:#f8fafc;">'
                    . '<div><strong>{{keyword}}</strong> — minuto {{minute_label}}</div>'
                    . '<div style="color:#475569;font-size:13px;">{{snippet}}</div></div>{{/each}}'
                    . '<p style="margin-top:16px;"><a href="{{file_url}}" style="color:#4654a8;">Ver la grabación en TCloud</a></p>'
                    . '<p style="color:#94a3b8;font-size:12px;">Aviso automático de Mis Avisos · TCloud</p>',
                'variables' => 'user, filename, match_count, matches, file_url',
            ],
            [
                'name' => 'mentions-export-link',
                'display_name' => 'Link de exportación de histórico',
                'subject' => 'Tu exportación del histórico de Mis Avisos está lista',
                'body_html' => '<h2 style="margin:0 0 12px;color:#1e293b;">Exportación lista</h2>'
                    . '<p>Hola {{user}}, tu exportación del histórico ({{rows_count}} coincidencia(s)) está lista.</p>'
                    . '<p><a href="{{download_url}}" style="display:inline-block;background:#4654a8;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;">Descargar CSV</a></p>'
                    . '<p style="color:#64748b;font-size:13px;">{{expires_note}}</p>'
                    . '<p style="color:#94a3b8;font-size:12px;">Solicitado por ti desde Mis Avisos · TCloud</p>',
                'variables' => 'user, rows_count, download_url, expires_note',
            ],
        ];

        foreach ($templates as $t) {
            $exists = DB::table('correo_plantillas')->where('name', $t['name'])->exists();
            if (!$exists) {
                DB::table('correo_plantillas')->insert([
                    'name' => $t['name'],
                    'display_name' => $t['display_name'],
                    'subject' => $t['subject'],
                    'body_html' => $t['body_html'],
                    'variables' => $t['variables'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Log::info('migrations.mentions_email_templates_ready');
    }

    public function down(): void
    {
        DB::table('correo_plantillas')->whereIn('name', ['ia-alert-match', 'mentions-export-link'])->delete();
    }
};