<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Verifica la salud de /dev/shm (tmpfs donde viven los WAVs intermedios).
 *
 * Escribe el estado en Cache (10 min) para que el endpoint
 * `GET /ia/api-transcriptor/shm-status` lo sirva sin recalcular, y emite
 * Log::warning cuando supera el umbral configurable (default 80%).
 *
 * La deteccion temprana (umbral 80% sobre tmpfs 40 GB = 32 GB) da margen
 * para reaccionar antes de que ffmpeg empiece a fallar. El fix raiz del
 * fd leak esta en TranscriptorApiClient (Task 1); este comando es el
 * centinela que avisa si vuelve a pasar.
 */
class TranscriptionCheckShmHealthCommand extends Command
{
    protected $signature = 'transcription:check-shm-health';

    protected $description = 'Chequea uso de /dev/shm y registra WARNING si supera el umbral.';

    public function handle(): int
    {
        $total = @disk_total_space('/dev/shm');
        $free  = @disk_free_space('/dev/shm');

        $used = ($total !== false && $free !== false) ? ($total - $free) : null;
        $pct  = ($total !== false && $total > 0 && $used !== null)
            ? round($used * 100 / $total, 1)
            : null;

        $status = [
            'total' => $total !== false ? $total : null,
            'used'  => $used,
            'free'  => $free !== false ? $free : null,
            'percent' => $pct,
            'dir_writable' => is_writable('/dev/shm/tcloud-transcription'),
            'checked_at' => now()->toIso8601String(),
        ];

        Cache::put('transcriptor:shm:status', $status, 600);

        $threshold = $this->getWarnPercent();
        $status['threshold'] = $threshold;

        if ($pct !== null && $pct >= $threshold) {
            Log::warning("transcriptor: /dev/shm al {$pct}% ({$used}/{$total})", [
                'free_bytes' => $free,
                'used_bytes' => $used,
                'total_bytes' => $total,
                'percent' => $pct,
                'threshold' => $threshold,
                'dir_writable' => $status['dir_writable'],
            ]);
            $status['status'] = 'warning';
            Cache::put('transcriptor:shm:status', $status, 600);
        } else {
            $status['status'] = 'ok';
            Cache::put('transcriptor:shm:status', $status, 600);
        }

        $this->line(sprintf(
            "/dev/shm: %s%% usado (%s / %s), dir_writable=%s, threshold=%s%%",
            $pct ?? '?',
            $used !== null ? round($used / 1024 / 1024) . ' MB' : '?',
            $total !== false ? round($total / 1024 / 1024) . ' MB' : '?',
            $status['dir_writable'] ? 'yes' : 'no',
            $threshold,
        ));

        return Command::SUCCESS;
    }

    private function getWarnPercent(): int
    {
        try {
            $settings = app(\App\Services\Ia\TranscriptorSettings::class);
            return max(50, min(99, $settings->int('shm_warn_percent')));
        } catch (\Throwable $e) {
            return 80;
        }
    }
}