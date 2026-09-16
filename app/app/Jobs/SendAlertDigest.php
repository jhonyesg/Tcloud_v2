<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ia\AlertDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Digest de avisos por usuario (mis-avisos-menciones).
 *
 * Un solo correo por ventana de cadencia con todos los matches agrupados.
 * Rate limiter GLOBAL del relay como último freno (protege a todos los
 * módulos de correo ante ráfagas). Corre en la cola Redis.
 */
class SendAlertDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;

    public function __construct(
        public int $userId,
        public string $batchId,
        public array $deliveryIds,
    ) {}

    public function handle(AlertDispatcher $dispatcher): void
    {
        // Rate limiter global del relay (configurable, default 20/min).
        $perMinute = (int) config('avisos.mail_rate_per_minute', 20);
        $executed = RateLimiter::attempt(
            'mail-relay',
            $perMinute,
            fn () => $this->send($dispatcher),
            60,
        );

        if ($executed === false) {
            // Reintentar en 60s sin quemar el intento: dosificación, no error.
            $this->release(60);
        }
    }

    private function send(AlertDispatcher $dispatcher): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $rows = DB::table('alert_deliveries as ad')
            ->join('segment_keyword_hits as h', 'h.id', '=', 'ad.hit_id')
            ->join('keywords as k', 'k.id', '=', 'h.keyword_id')
            ->join('transcription_segments as seg', 'seg.id', '=', 'h.segment_id')
            ->join('transcriptions as t', 't.id', '=', 'h.transcription_id')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->leftJoin('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
            ->whereIn('ad.id', $this->deliveryIds)
            ->orderBy('h.matched_at')
            ->select(
                'h.transcription_id',
                'f.name as filename',
                'f.id as file_id',
                'sp.name as storage_name',
                'k.text as keyword',
                'h.snippet',
                'seg.start_seconds',
            )
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Agrupar por transcripción para reusar el template por-media.
        $byTranscription = $rows->groupBy('transcription_id');
        $sentOk = true;

        foreach ($byTranscription as $transcriptionId => $matches) {
            $first = $matches->first();
            $payload = $matches->map(fn ($m) => [
                'keyword' => (string) $m->keyword,
                'segment_index' => 0,
                'minute_label' => $this->hms((float) $m->start_seconds),
                'snippet' => (string) $m->snippet,
                'storage' => (string) ($m->storage_name ?? ''),
            ])->values()->toArray();

            try {
                $result = $dispatcher->sendToDigest(
                    $user,
                    (int) $transcriptionId,
                    (string) $first->filename,
                    $first->file_id ? (int) $first->file_id : null,
                    $payload,
                    $this->batchId,
                );

                if (!($result['success'] ?? false)) {
                    $sentOk = false;
                }
            } catch (\Throwable $e) {
                $sentOk = false;
                Log::error('mentions.digest_send_failed', [
                    'user_id' => $this->userId,
                    'batch_id' => $this->batchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$sentOk) {
            Log::warning('mentions.digest_partial_failure', [
                'user_id' => $this->userId,
                'batch_id' => $this->batchId,
            ]);
        }
    }

    private function hms(float $seconds): string
    {
        $total = (int) floor($seconds);
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}