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