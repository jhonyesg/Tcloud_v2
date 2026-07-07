<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Services\Ia\TranscriptionProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TranscriptionCallbackController extends Controller
{
    public function handle(Request $request, TranscriptionProcessor $processor): Response
    {
        $token = $request->header('X-Webhook-Token');
        $expected = (string) config('transcriptor.webhook_token');

        if ($expected === '' || !hash_equals($expected, (string) $token)) {
            Log::warning('Webhook transcripción: token inválido.');
            return response('Unauthorized', 401);
        }

        $data = $request->json()->all();
        $jobId = $data['job_id'] ?? null;
        $state = $data['state'] ?? ($data['status'] ?? null);

        if (!$jobId || !$state) {
            return response('Missing job_id or state', 422);
        }

        /** @var Transcription|null $transcription */
        $transcription = Transcription::where('job_id', $jobId)->first();
        if (!$transcription) {
            Log::warning("Webhook transcripción: job_id {$jobId} no encontrado.");
            // 200 para que el transcriptor no reintente contra un job ya purgado.
            return response('ok', 200);
        }

        try {
            // Si la API externa manda el nombre original del MP4, lo preservamos
            // por si la Transcription se creó sin él.
            if (!empty($data['original_name'])) {
                $transcription->update(['original_name' => $data['original_name']]);
            }

            if ($state === Transcription::STATE_DONE) {
                $processor->processDone($transcription->fresh());
            } elseif (in_array($state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
                $processor->markError($transcription, $state, $data['error_message'] ?? 'upstream error');
            } elseif ($state === Transcription::STATE_PROCESSING) {
                $transcription->update(['state' => Transcription::STATE_PROCESSING]);
            } else {
                $transcription->update(['state' => $state]);
            }
        } catch (\Throwable $e) {
            Log::error("Webhook transcripción {$jobId}: {$e->getMessage()}");
            return response('Error procesando webhook', 500);
        }

        return response('ok', 200);
    }
}