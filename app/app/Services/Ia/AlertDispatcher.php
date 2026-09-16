<?php

namespace App\Services\Ia;

use App\Models\AlertLog;
use App\Models\Transcription;
use App\Models\User;
use App\Modules\Correo\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Envia un email coalesced por (transcripcion, usuario) con todos los matches
 * encontrados, usando App\Modules\Correo. Persiste un AlertLog por destinatario.
 */
class AlertDispatcher
{
    public function __construct(private NotificationService $correo) {}

    /**
     * @param array $matches Estructura: [['keyword'=>string,'segment_index'=>int,'minute_label'=>string,'snippet'=>string], ...]
     */
    public function send(User $user, Transcription $transcription, array $matches): void
    {
        $alertsConfig = $user->alertsInteligente;
        $emails = $alertsConfig?->emailsList() ?? [];
        if (empty($emails) || empty($matches)) {
            return;
        }

        $filename = $transcription->file?->name ?? 'grabación desconocida';
        $subject = "Coincidencia en grabación: {$filename}";

        $data = [
            'user' => $user->username,
            'transcription_id' => $transcription->id,
            'filename' => $filename,
            'file_url' => $transcription->file_id ? "/files/{$transcription->file_id}/preview" : '#',
            'match_count' => count($matches),
            'matches' => $matches,
        ];

        foreach ($emails as $to) {
            $to = trim($to);
            if ($to === '') {
                continue;
            }
            try {
                $result = $this->correo->send('ia-alert-match', $to, $data);
                AlertLog::create([
                    'user_id' => $user->id,
                    'email_to' => $to,
                    'transcription_id' => $transcription->id,
                    'match_count' => count($matches),
                    'subject' => $subject,
                    'status' => ($result['success'] ?? false) ? AlertLog::STATUS_SENT : AlertLog::STATUS_FAILED,
                    'error_message' => ($result['success'] ?? false) ? null : ($result['message'] ?? 'unknown'),
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error("AlertDispatcher: fallo envio a {$to}: {$e->getMessage()}");
                AlertLog::create([
                    'user_id' => $user->id,
                    'email_to' => $to,
                    'transcription_id' => $transcription->id,
                    'match_count' => count($matches),
                    'subject' => $subject,
                    'status' => AlertLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'sent_at' => now(),
                ]);
            }
        }
    }

    /**
     * Digest de cadencia (mis-avisos-menciones): mismo template, subject
     * diferenciado y batch_id en el log para trazabilidad del digest.
     *
     * @param array $matches Estructura: [['keyword'=>string,'minute_label'=>string,'snippet'=>string,'storage'=>string], ...]
     */
    public function sendToDigest(User $user, int $transcriptionId, string $filename, ?int $fileId, array $matches, string $batchId): array
    {
        $emails = $user->alertsInteligente?->emailsList() ?? [];
        if (empty($emails) || empty($matches)) {
            return ['success' => false, 'message' => 'sin correos o matches'];
        }

        $subject = "Avisos de hoy: {$filename}";

        // El motor de plantillas de Modules\Correo solo interpola strings
        // (str_replace plano, sin loops): aplanar los matches a HTML aquí.
        $matchesHtml = implode('', array_map(function (array $m) {
            return '<div style="border-left:3px solid #4654a8;padding:8px 12px;margin:10px 0;background:#f8fafc;">'
                . '<div><strong>' . e($m['keyword'] ?? '') . '</strong>'
                . ' — minuto ' . e($m['minute_label'] ?? '')
                . (!empty($m['storage']) ? ' · ' . e($m['storage']) : '')
                . '</div>'
                . '<div style="color:#475569;font-size:13px;">' . e($m['snippet'] ?? '') . '</div>'
                . '</div>';
        }, $matches));

        $data = [
            'user' => $user->username,
            'transcription_id' => $transcriptionId,
            'filename' => $filename,
            'file_url' => $fileId ? "/files/{$fileId}/preview" : '#',
            'match_count' => count($matches),
            'matches' => $matchesHtml,
        ];

        $last = ['success' => false, 'message' => 'unknown'];
        foreach ($emails as $to) {
            $to = trim($to);
            if ($to === '') {
                continue;
            }
            try {
                $result = $this->correo->send('ia-alert-match', $to, $data);
                $last = $result;
                AlertLog::create([
                    'user_id' => $user->id,
                    'email_to' => $to,
                    'transcription_id' => $transcriptionId,
                    'match_count' => count($matches),
                    'subject' => $subject,
                    'status' => ($result['success'] ?? false) ? AlertLog::STATUS_SENT : AlertLog::STATUS_FAILED,
                    'error_message' => ($result['success'] ?? false) ? null : ($result['message'] ?? 'unknown'),
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error("AlertDispatcher: fallo digest a {$to}: {$e->getMessage()}");
                $last = ['success' => false, 'message' => $e->getMessage()];
                AlertLog::create([
                    'user_id' => $user->id,
                    'email_to' => $to,
                    'transcription_id' => $transcriptionId,
                    'match_count' => count($matches),
                    'subject' => $subject,
                    'status' => AlertLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'sent_at' => now(),
                ]);
            }
        }

        return $last;
    }

    /**
     * Email de prueba con datos ficticios.
     */
    public function sendTest(User $user, string $to): array
    {
        $data = [
            'user' => $user->username,
            'transcription_id' => 0,
            'filename' => '[TEST] grabación de ejemplo',
            'file_url' => '#',
            'match_count' => 1,
            'matches' => [[
                'keyword' => 'ejemplo',
                'segment_index' => 1,
                'minute_label' => '00:01:23',
                'snippet' => 'Esto es un email de prueba de alertas inteligentes.',
            ]],
        ];

        $result = $this->correo->send('ia-alert-match', $to, $data);

        AlertLog::create([
            'user_id' => $user->id,
            'email_to' => $to,
            'transcription_id' => null,
            'match_count' => 1,
            'subject' => '[TEST] Coincidencia en grabación de ejemplo',
            'status' => ($result['success'] ?? false) ? AlertLog::STATUS_SENT : AlertLog::STATUS_FAILED,
            'error_message' => ($result['success'] ?? false) ? null : ($result['message'] ?? 'unknown'),
            'sent_at' => now(),
        ]);

        return $result;
    }
}