<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TranscriptionConsumptionSnapshot extends Command
{
    protected $signature = 'transcription:consumption-snapshot';

    protected $description = 'Captura un punto por minuto del estado del cluster para la serie temporal del panel Consumo.';

    public function handle(): int
    {
        $bucket = 60;
        $t = (int) (floor(now()->timestamp / $bucket) * $bucket);

        $local = Transcription::query()
            ->selectRaw("state, COUNT(*) AS n")
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('state')
            ->pluck('n', 'state');

        $info = app(TranscriptorApiClient::class)->getRemoteInfo();

        $payload = [
            'fetched_at' => now()->toIso8601String(),
            'local' => [
                'pending'    => (int) ($local['pending'] ?? 0),
                'queued'     => (int) ($local['queued'] ?? 0),
                'processing' => (int) ($local['processing'] ?? 0),
                'done_24h'   => (int) ($local['done'] ?? 0),
                'error_24h'  => (int) ($local['error'] ?? 0),
                'dead_24h'   => (int) ($local['dead'] ?? 0),
            ],
            'remote' => $info ? [
                'workers'      => $info['workers'] ?? null,
                'processing'   => $info['processing'] ?? null,
                'usage_pct'    => $info['usage_pct'] ?? null,
                'gpu_util_pct' => $info['gpu_util_pct'] ?? null,
                'gpu_vram_pct' => $info['gpu_vram_pct'] ?? null,
                'ramdisk_pct'  => $info['ramdisk_pct'] ?? null,
                'cpu_pct'      => $info['cpu_pct'] ?? null,
            ] : ['unreachable' => true],
        ];

        Cache::put('transcriptor:consumption:series:' . $t, $payload, now()->addMinutes(70));

        $this->line(sprintf(
            '[consumption-snapshot %s] bucket=%d pending=%d queued=%d processing=%d ramdisk=%s gpu_util=%s',
            now()->toIso8601String(),
            $t,
            $payload['local']['pending'],
            $payload['local']['queued'],
            $payload['local']['processing'],
            $payload['remote']['ramdisk_pct'] ?? '?',
            $payload['remote']['gpu_util_pct'] ?? '?',
        ));

        return self::SUCCESS;
    }
}
