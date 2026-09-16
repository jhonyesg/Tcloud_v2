<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watchdog de filas en 'processing' que llevan demasiado sin resolverse.
 *
 * Caso tipico: el worker PG (transcription:worker) hizo COMMIT sobre el
 * UPDATE a state='processing' pero murio antes de que TranscriptionSubmitService
 * terminara (OOM, SIGKILL de supervisord, etc.). Sin este watchdog, esas filas
 * quedan zombies para siempre y el modulo acumula "pending" ocluidos.
 *
 * Reglas:
 *   state = 'processing'
 *   AND submission_committed_at IS NULL
 *   AND dispatched_at < now() - interval '{timeout} seconds'
 *
 * Accion: UPDATE state='pending', dispatched_at=NULL,
 * regulator_skip_reason='watchdog_recover', updated_at=NOW().
 *
 * Cadencia: cada 60 segundos (registrado en routes/console.php).
 *
 * Setting: transcriptor_processing_timeout_seconds (default 900).
 */
class TranscriptionWatchdogCommand extends Command
{
    protected $signature = 'transcriptor:watchdog-processing
                            {--limit=100 : Maximo de filas a recuperar por corrida}';

    protected $description = 'Recupera filas en processing mayores al timeout, marcandolas como pending con regulator_skip_reason=watchdog_recover.';

    public function handle(): int
    {
        $timeout = (int) SystemSetting::get('transcriptor_processing_timeout_seconds', '900');
        $timeout = max(60, $timeout);
        $limit = max(1, (int) $this->option('limit'));

        $candidates = DB::table('transcriptions')
            ->where('state', Transcription::STATE_PROCESSING)
            ->whereNull('submission_committed_at')
            ->where('dispatched_at', '<', now()->subSeconds($timeout))
            ->orderBy('dispatched_at', 'asc')
            ->limit($limit)
            ->get(['id', 'file_id', 'dispatched_at']);

        if ($candidates->isEmpty()) {
            return Command::SUCCESS;
        }

        $recovered = 0;
        foreach ($candidates as $row) {
            $affected = DB::table('transcriptions')
                ->where('id', $row->id)
                ->where('state', Transcription::STATE_PROCESSING)
                ->update([
                    'state' => Transcription::STATE_PENDING,
                    'dispatched_at' => null,
                    'regulator_skip_reason' => 'watchdog_recover',
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $recovered++;
                Log::warning('transcriptor.watchdog.recovered', [
                    'tx_id' => $row->id,
                    'file_id' => $row->file_id,
                    'dispatched_at' => $row->dispatched_at,
                    'timeout_seconds' => $timeout,
                ]);
            }
        }

        if ($recovered > 0) {
            $total = (int) (Cache::get('transcriptor:watchdog:recovered_total', 0));
            Cache::put(
                'transcriptor:watchdog:recovered_total',
                $total + $recovered,
                now()->addDays(7)
            );
        }

        return Command::SUCCESS;
    }
}