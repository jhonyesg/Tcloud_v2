<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranscriptionPurgeStalePendingCommand extends Command
{
    protected $signature = 'transcription:purge-stale-pending
                            {--days= : Antiguedad minima en dias para considerar stale (default config transcriptor.purge_stale_days)}
                            {--batch= : Tamano del chunk de UPDATE (default 500)}
                            {--max-ratio= : Ratio maxima candidatos/total (default 0.5)}
                            {--dry-run : Cuenta candidatos sin modificar BD}';

    protected $description = 'Promueve a dead las transcripciones pending mas viejas que N dias. Respeta guardarrail de ratio. --dry-run cuenta sin modificar.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('transcriptor.purge_stale_days', 15));
        $batch = (int) ($this->option('batch') ?? 500);
        $maxRatio = (float) ($this->option('max-ratio') ?? 0.5);
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            "transcription:purge-stale-pending starting (days=%d, batch=%d, max_ratio=%s%s)",
            $days, $batch, $maxRatio, $dryRun ? ', DRY-RUN' : ''
        ));

        $lock = Cache::lock('transcription:purge-stale-pending', 600);
        if (!$lock->get()) {
            $this->warn('Otra instancia en curso; abortando.');
            Log::info('transcription.purge.skipped_locked');
            return self::SUCCESS;
        }

        try {
            $cutoff = now()->subDays($days);

            $candidates = Transcription::where('state', Transcription::STATE_PENDING)
                ->where('created_at', '<', $cutoff)
                ->count();
            $total = Transcription::count();

            if ($total === 0) {
                $this->info('Sin transcripciones registradas.');
                return self::SUCCESS;
            }

            $ratio = $candidates / $total;
            $this->line(sprintf(
                'Candidatos (pending created_at < %s): %d de %d (ratio=%.4f, max=%.2f)',
                $cutoff->toIso8601String(), $candidates, $total, $ratio, $maxRatio
            ));

            if ($ratio > $maxRatio) {
                Log::warning('transcription.purge.aborted_mass_delete', [
                    'candidates' => $candidates,
                    'total' => $total,
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                    'days' => $days,
                    'cutoff' => $cutoff->toIso8601String(),
                ]);
                $this->error(sprintf(
                    'ABORTADO: ratio %.4f excede max_ratio %.2f. Probablemente estas viendo la BD completa de transcriptions, no solo el universo a purgar. Sube --max-ratio o filtra el universo con otra precondicion.',
                    $ratio, $maxRatio
                ));
                return self::SUCCESS;
            }

            if ($dryRun) {
                Log::info('transcription.purge.dry_run', [
                    'candidates' => $candidates,
                    'cutoff' => $cutoff->toIso8601String(),
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                    'days' => $days,
                ]);
                $this->info(sprintf('DRY-RUN: %d serian promovidos a dead.', $candidates));
                return self::SUCCESS;
            }

            $promoted = 0;
            Transcription::where('state', Transcription::STATE_PENDING)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->chunkById($batch, function ($rows) use (&$promoted, $cutoff) {
                    foreach ($rows as $row) {
                        $row->update([
                            'state' => Transcription::STATE_DEAD,
                            'error_message' => "Purgado por antiguedad: pending sin job_id con created_at anterior a {$cutoff->toDateString()}. transcription:purge-stale-pending.",
                            'finished_at' => now(),
                        ]);
                        $promoted++;
                    }
                });

            Log::info('transcription.purge.completed', [
                'promoted' => $promoted,
                'candidates' => $candidates,
                'cutoff' => $cutoff->toIso8601String(),
                'days' => $days,
            ]);

            $this->info(sprintf('Promovidos a dead: %d', $promoted));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('transcription.purge.unhandled_exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
