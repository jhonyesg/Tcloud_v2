<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Vía CLI para reaplicar el diccionario. `corrections:apply-run` exige una
 * entrada de cache pre-creada por CorreccionesController::applyRetroactive()
 * y aborta sin ella, así que este comando la crea antes de delegar: hasta
 * ahora generaba el runId pero NO la entrada, y por eso el alias siempre
 * fallaba con "Run id no encontrado en cache".
 *
 * Es la vía recomendada para correr reparaciones masivas fuera de la UI
 * (permite --sleep-ms para no ahogar la BD de producción).
 */
class ApplyCorrectionsCommand extends Command
{
    protected $signature = 'transcription:apply-corrections
                            {--dry-run : Solo reporta cambios sin tocar la BD}
                            {--chunk=0 : Tamaño del chunk de segments por transacción (0 = usar corrections_chunk)}
                            {--days= : Filtrar a segments creados en últimos N días (omitir = todos los históricos)}
                            {--include-high-risk : Incluir correcciones con risk_level=high (default: omitir)}
                            {--sleep-ms=0 : Pausa en ms entre chunks. Freno para no saturar la BD}
                            {--transcription-id=* : Limitar a estas transcripciones (repetible)}
                            {--from-id= : Id mínimo de segment. Trocea el histórico por PK sin full-scan}
                            {--to-id= : Id máximo de segment}
                            {--transcription-from= : Id mínimo de transcripción. Vía indexada para reparar por días}
                            {--transcription-to= : Id máximo de transcripción}
                            {--force : Ignorar el candado de corrida activa}';

    protected $description = 'Reaplica el diccionario de correcciones approved a los TranscriptionSegment existentes (vía CLI, con freno opcional).';

    private const CACHE_TTL_HOURS = 4;

    public function handle(): int
    {
        $runId = 'cli_' . time() . '_' . substr(md5((string) mt_rand()), 0, 8);

        $dryRun = (bool) $this->option('dry-run');
        $includeHighRisk = (bool) $this->option('include-high-risk');

        // 0 se pasa tal cual para que corrections:apply-run resuelva el default
        // desde corrections_chunk en vez de fijar 500 aqui.
        $chunkOption = (int) $this->option('chunk');
        $chunk = $chunkOption > 0 ? max(50, $chunkOption) : 0;

        $sleepMs = max(0, min(5000, (int) $this->option('sleep-ms')));

        $daysOption = $this->option('days');
        $daysBack = null;
        if ($daysOption !== null && $daysOption !== '') {
            $daysBack = (int) $daysOption;
            if ($daysBack <= 0) {
                $this->error('--days debe ser un entero positivo.');

                return self::FAILURE;
            }
        }

        // Mismo candado que usa la UI, para que una corrida CLI y una lanzada
        // desde el panel no se pisen sobre la misma tabla.
        if (!$dryRun && !$this->option('force')) {
            if (!Cache::add('corrections_apply:active', $runId, now()->addHours(self::CACHE_TTL_HOURS))) {
                $this->error('Ya hay una corrida activa (corrections_apply:active). Usa --force si estás seguro de que es huérfana.');

                return self::FAILURE;
            }
        }

        Cache::put("corrections_apply:{$runId}", [
            'run_id' => $runId,
            'status' => 'queued',
            'origin' => 'cli',
            'dry_run' => $dryRun,
            'days_back' => $daysBack,
            'include_high_risk' => $includeHighRisk,
            'processed' => 0,
            'total' => 0,
            'updated' => 0,
            'error_message' => null,
            'created_at' => now()->toIso8601String(),
        ], now()->addHours(self::CACHE_TTL_HOURS));

        $this->info("Run id: {$runId}");

        return $this->call('corrections:apply-run', array_filter([
            '--run-id' => $runId,
            '--chunk' => $chunk,
            '--dry-run' => $dryRun,
            '--days' => $daysBack,
            '--include-high-risk' => $includeHighRisk,
            '--sleep-ms' => $sleepMs,
            '--transcription-id' => (array) $this->option('transcription-id'),
            '--from-id' => $this->option('from-id'),
            '--to-id' => $this->option('to-id'),
            '--transcription-from' => $this->option('transcription-from'),
            '--transcription-to' => $this->option('transcription-to'),
        ], fn ($v) => $v !== null && $v !== false && $v !== []));
    }
}
