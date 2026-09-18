<?php

namespace App\Console\Commands;

use App\Models\Share;
use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change `2026-09-17-share-folder-canonical-wiring` (Task 3.2):
 *
 * Notifica a los creadores de shares de folder con permisos destructivos
 * (`write` o `full`) cuyo `file_id` fue repuntado en los últimos N días
 * (default 7). El repoint es operacionalmente correcto pero cambia el
 * comportamiento de delete: antes era no-op accidental, ahora es destructivo
 * real sobre el canónico.
 *
 * Idempotencia: cruza con `share_notification_log` para no re-enviar emails
 * ya notificados dentro del mismo window. Para forzar re-notificación, el
 * operador puede borrar las filas de log correspondientes.
 *
 * Uso:
 *   php artisan shares:notify-repointed --dry-run       (auditoría)
 *   php artisan shares:notify-repointed --apply         (envía emails)
 *   php artisan shares:notify-repointed --days=30       (window custom)
 */
class NotifyRepointedShareCreatorsCommand extends Command
{
    protected $signature = 'shares:notify-repointed
                            {--dry-run : Solo reporta, no envía}
                            {--apply : Envía los emails}
                            {--days=7 : Window de lookback en días}';

    protected $description = 'Notifica a creadores de shares write/full cuyo file_id fue repuntado';

    public function handle(NotificationService $notificationService): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if (!$apply && !$dryRun) {
            $this->warn('Sin --apply ni --dry-run; asumiendo --dry-run.');
            $dryRun = true;
        }

        // Detección: shares repuntados dentro del window, con permisos
        // destructivos, cuyo `file_id` actual está en el canónico (no mirror),
        // y que NO no han been notificados.
        //
        // Filtro clave: usamos `a.created_at` (audit log) NO `s.created_at` (share).
        // Los shares 46/47 se crearon en 2026-05 pero el repoint fue el 2026-09-17;
        // el window se mide sobre el evento de repoint, no sobre la creación
        // del share.
        $sql = <<<SQL
SELECT DISTINCT ON (s.id)
    s.id AS share_id,
    s.token,
    s.permissions,
    s.file_id AS canonical_id,
    f.path AS folder_path,
    s.created_by AS creator_id,
    u.email AS creator_email,
    (SELECT before_value FROM file_mirror_audit_log a2
     WHERE a2.action = 'repoint_share' AND a2.share_id = s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS original_mirror_id,
    (SELECT after_value FROM file_mirror_audit_log a2
     WHERE a2.action = 'repoint_share' AND a2.share_id = s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS canonical_id_str,
    (SELECT created_at FROM file_mirror_audit_log a2
     WHERE a2.action = 'repoint_share' AND a2.share_id = s.id
     ORDER BY a2.created_at ASC LIMIT 1) AS repoint_at
FROM file_mirror_audit_log a
JOIN shares s ON s.id = a.share_id
JOIN files f ON f.id = s.file_id
JOIN users u ON u.id = s.created_by
WHERE a.action = 'repoint_share'
  AND a.created_at > now() - (make_interval(days => $days))
  AND s.permissions IN ('write', 'full')
  AND f.canonical_folder_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM share_notification_log n
      WHERE n.share_id = s.id AND n.kind = 'repoint_share_canonicalization'
  )
ORDER BY s.id
SQL;

        $pending = DB::select($sql);
        $this->info(sprintf('Detectados %d shares pendientes de notificación (window=%d días).', count($pending), $days));

        if (empty($pending)) {
            $this->info('Nada que notificar.');
            return self::SUCCESS;
        }

        // Agrupar por creator (un email por creator, con todos sus shares listados).
        $byCreator = [];
        foreach ($pending as $row) {
            $cid = $row->creator_id;
            if (!isset($byCreator[$cid])) {
                $byCreator[$cid] = [
                    'creator_id' => $cid,
                    'email' => $row->creator_email,
                    'shares' => [],
                ];
            }
            $byCreator[$cid]['shares'][] = $row;
        }

        $this->info(sprintf('Agrupados en %d creadores únicos.', count($byCreator)));

        if ($dryRun) {
            foreach ($byCreator as $group) {
                $this->line(sprintf('  [dry-run] creator=%s (%s) shares=%d',
                    $group['creator_id'],
                    $group['email'] ?: '(no email)',
                    count($group['shares'])));
                foreach ($group['shares'] as $s) {
                    $this->line(sprintf('    - share #%d token=%s folder=%s',
                        $s->share_id, substr($s->token, 0, 14), $s->folder_path));
                }
            }
            $this->info('DRY-RUN: use --apply para enviar.');
            return self::SUCCESS;
        }

        // Apply: enviar un email por creator y registrar en share_notification_log.
        $sent = 0;
        $skipped = 0;

        foreach ($byCreator as $group) {
            if (empty($group['email'])) {
                Log::warning('shares:notify-repointed skipped creator without email', [
                    'creator_id' => $group['creator_id'],
                    'share_count' => count($group['shares']),
                ]);
                $this->warn(sprintf('  ! creator #%s sin email — saltado', $group['creator_id']));
                $skipped++;
                continue;
            }

            $shareLines = '';
            foreach ($group['shares'] as $s) {
                $shareLines .= sprintf(
                    "  • Share #%d (token %s...) — folder \"%s\" — antes file_id=%s, ahora file_id=%s\n",
                    $s->share_id,
                    substr($s->token, 0, 14),
                    $s->folder_path,
                    $s->original_mirror_id,
                    $s->canonical_id_str
                );
            }

            try {
                $notificationService->send(
                    'share-repoint-canonicalization',
                    $group['email'],
                    [
                        'nombre_destinatario' => $group['email'],
                        'shares_detalle' => $shareLines,
                        'count_shares' => count($group['shares']),
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('shares:notify-repointed send failed', [
                    'creator_id' => $group['creator_id'],
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('  ! fallo enviando a creator #%s: %s', $group['creator_id'], $e->getMessage()));
                continue;
            }

            // Registrar en share_notification_log (UNIQUE constraint evita duplicados).
            $logRows = array_map(fn ($s) => [
                'share_id' => $s->share_id,
                'recipient_user_id' => $group['creator_id'],
                'kind' => 'repoint_share_canonicalization',
                'notified_at' => now(),
                'metadata' => json_encode([
                    'previous_file_id' => $s->original_mirror_id,
                    'canonical_file_id' => $s->canonical_id_str,
                ]),
            ], $group['shares']);

            try {
                DB::table('share_notification_log')->insert($logRows);
            } catch (\Throwable $e) {
                Log::warning('shares:notify-repointed log insert failed', [
                    'creator_id' => $group['creator_id'],
                    'error' => $e->getMessage(),
                ]);
            }

            $sent++;
            $this->info(sprintf('  ✓ enviado a %s (creator #%s, %d shares)',
                $group['email'], $group['creator_id'], count($group['shares'])));
        }

        $this->info('============================================================');
        $this->info(sprintf('Notificación completada: %d enviados, %d saltados.', $sent, $skipped));

        return self::SUCCESS;
    }
}