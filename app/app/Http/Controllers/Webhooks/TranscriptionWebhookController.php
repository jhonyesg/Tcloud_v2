<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Services\Ia\TranscriptionPollingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook entrante del transcriptor (Fase D, opt-in).
 *
 * Activacion: SystemSetting `submit_with_callback=true` en el orquestador.
 * Los envios al upstream llevan `callback_url=https://host/webhooks/transcription`.
 *
 * Contrato:
 * - Header `X-Tcloud-Webhook-Token`: HMAC-SHA256(secret, body en bytes),
 *   en hex. Si falla, 401.
 * - Header `X-Tcloud-Webhook-Ts`: unix timestamp. Rechaza ±5 min (anti-replay).
 * - Body JSON: {job_id, state, corrected?, srt_url?}
 *
 * El poller SIGUE corriendo como respaldo; el webhook acorta la latencia de
 * cierre pero no es la fuente de verdad. Si llega un webhook para un job que
 * ya esta `done` local, applyRemoteState es idempotente y no hace nada.
 *
 * Throttle: 60/min para mitigacion de abuse. Si el upstream se vuelve loco
 * el 429 lo frena.
 */
class TranscriptionWebhookController extends Controller
{
    private const ANTI_REPLAY_SECONDS = 300; // ±5 minutos

    public function __invoke(
        Request $request,
        TranscriptionPollingService $poller,
    ): Response {
        $body = $request->getContent();

        // 1. Verificar firma HMAC
        $secret = (string) env('TCLOUD_WEBHOOK_SECRET', '');
        $secret = $secret ?: (string) \App\Models\SystemSetting::get('transcriptor.webhook_secret', '');
        if (empty($secret)) {
            Log::warning('TranscriptionWebhook: TCLOUD_WEBHOOK_SECRET no configurado');
            return response()->json(['error' => 'webhook not configured'], 503);
        }

        $expected = hash_hmac('sha256', $body, $secret);
        $received = (string) $request->header('X-Tcloud-Webhook-Token', '');
        if (!hash_equals($expected, $received)) {
            Log::warning('TranscriptionWebhook: HMAC invalido', [
                'expected_prefix' => substr($expected, 0, 8),
                'received_prefix' => substr($received, 0, 8),
            ]);
            return response()->json(['error' => 'invalid signature'], 401);
        }

        // 2. Anti-replay
        $tsHeader = (string) $request->header('X-Tcloud-Webhook-Ts', '');
        if (!ctype_digit($tsHeader)) {
            return response()->json(['error' => 'missing/invalid timestamp'], 400);
        }
        $drift = abs(time() - (int) $tsHeader);
        if ($drift > self::ANTI_REPLAY_SECONDS) {
            return response()->json(['error' => 'timestamp out of window', 'drift_s' => $drift], 401);
        }

        // 3. Parsear payload
        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['job_id'])) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $jobId = (string) $payload['job_id'];

        // 4. Buscar la Transcription por job_id (unico)
        $tx = Transcription::where('job_id', $jobId)->first();
        if (!$tx) {
            // Webhook para un job desconocido — loguear y aceptar 200
            // para que el upstream no siga re-intentando.
            Log::info('TranscriptionWebhook: job_id sin match local', ['job_id' => $jobId]);
            return response()->json(['received' => true, 'matched' => false]);
        }

        // 5. Aplicar el estado con la misma logica que el poll
        $tally = [];
        try {
            $result = $poller->applyRemoteState($tx, $payload, null, null, $tally, 'webhook');
        } catch (\Throwable $e) {
            Log::error('TranscriptionWebhook: applyRemoteState fallo', [
                'job_id' => $jobId,
                'tx_id' => $tx->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'processing failed'], 500);
        }

        Log::info('TranscriptionWebhook: applied', [
            'job_id' => $jobId,
            'tx_id' => $tx->id,
            'result' => $result,
        ]);

        return response()->json(['received' => true, 'matched' => true, 'result' => $result]);
    }
}
