<?php

namespace App\Console\Commands;

use App\Services\Ia\CorrectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CorrectionsApplyRunCommand extends Command
{
    protected $signature = 'corrections:apply-run
                            {--run-id= : Cache key suffix para tracking del run (obligatorio)}
                            {--dry-run : Solo reporta, no escribe BD}
                            {--chunk=0 : Tamaño del chunk de segments por transacción (0 = usar corrections_chunk)}
                            {--days= : Filtrar a segments creados en últimos N días (omitir = todos los históricos)}
                            {--include-high-risk : Incluir correcciones con risk_level=high (default: omitir)}
                            {--sleep-ms=0 : Pausa en ms entre chunks. Freno para no saturar la BD en producción}
                            {--transcription-id=* : Limitar a estas transcripciones (repetible)}
                            {--from-id= : Id mínimo de segment. Trocea el histórico por PK sin full-scan}
                            {--to-id= : Id máximo de segment}
                            {--transcription-from= : Id mínimo de transcripción. Vía indexada para reparar por días}
                            {--transcription-to= : Id máximo de transcripción}';

    protected $description = 'Reaplica el diccionario de correcciones approved a todos los TranscriptionSegment. Diseñado para correr desacoplado vía runId + cache polling.';

    private const CACHE_TTL_HOURS = 4;

    public function handle(CorrectionService $service): int
    {
        // Antes el default era la cadena literal "required", que no valida nada:
        // sin el flag, el comando buscaba la clave de cache
        // "corrections_apply:required" y fallaba con un mensaje confuso.
        $runId = trim((string) $this->option('run-id'));
        if ($runId === '') {
            $this->error('Falta --run-id. Es obligatorio: identifica el run en cache para el polling de la UI.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeHighRisk = (bool) $this->option('include-high-risk');

        $chunkOption = (int) $this->option('chunk');
        $chunk = $chunkOption > 0 ? max(50, $chunkOption) : null;

        // Tope de 5s por lote: más que eso no es un freno, es un cuelgue.
        $sleepMs = max(0, min(5000, (int) $this->option('sleep-ms')));

        $transcriptionIds = array_values(array_filter(array_map(
            'intval',
            (array) $this->option('transcription-id')
        )));

        $intOption = fn (string $name) => $this->option($name) !== null && $this->option($name) !== ''
            ? (int) $this->option($name)
            : null;

        $fromId = $intOption('from-id');
        $toId = $intOption('to-id');
        $transcriptionFrom = $intOption('transcription-from');
        $transcriptionTo = $intOption('transcription-to');

        $daysOption = $this->option('days');
        $daysBack = null;
        if ($daysOption !== null && $daysOption !== '') {
            $daysBack = (int) $daysOption;
            if ($daysBack <= 0) {
                $this->error('--days debe ser un entero positivo. 0 o negativo = todos los históricos.');
                return self::FAILURE;
            }
        }

        $cacheKey = "corrections_apply:{$runId}";
        $state = Cache::get($cacheKey);

        if (!$state) {
            $this->error("Run id '{$runId}' no encontrado en cache. Lanzar desde el controller primero.");
            return self::FAILURE;
        }

        // Override daysBack con lo que esté en el cache (si el controller lo setea)
        if (isset($state['days_back']) && $state['days_back'] !== null) {
            $daysBack = (int) $state['days_back'];
        }

        // Override includeHighRisk desde cache (flag lanzado desde controller API).
        if (isset($state['include_high_risk'])) {
            $includeHighRisk = (bool) $state['include_high_risk'];
        }

        $state['status'] = 'running';
        $state['started_at'] = now()->toIso8601String();
        $state['updated'] = 0;
        $state['error_message'] = null;
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        $scopeParts = [];
        if ($daysBack !== null) {
            $scopeParts[] = "últimos {$daysBack} días";
        }
        if (!empty($transcriptionIds)) {
            $scopeParts[] = 'transcripciones ' . implode(',', $transcriptionIds);
        }
        if ($transcriptionFrom !== null || $transcriptionTo !== null) {
            $scopeParts[] = 'transcripciones ' . ($transcriptionFrom ?? 'inicio') . '..' . ($transcriptionTo ?? 'fin');
        }
        if ($fromId !== null || $toId !== null) {
            $scopeParts[] = 'ids ' . ($fromId ?? 'inicio') . '..' . ($toId ?? 'fin');
        }

        $scopeMsg = empty($scopeParts)
            ? 'Aplicando correcciones a TODOS los segments...'
            : 'Aplicando correcciones a segments: ' . implode(' + ', $scopeParts) . '...';
        $this->info($dryRun ? "Modo dry-run: no se modificará la BD. {$scopeMsg}" : $scopeMsg);

        // Pre-computar total para que el primer poll ya muestre "X / total"
        // en vez del "0 segmentos" engañoso mientras procesa el primer chunk.
        try {
            $totalSegments = \App\Models\TranscriptionSegment::query()
                ->when($daysBack !== null && $daysBack > 0,
                    fn ($q) => $q->where('created_at', '>=', now()->subDays($daysBack)))
                ->when(!empty($transcriptionIds),
                    fn ($q) => $q->whereIn('transcription_id', $transcriptionIds))
                ->when($transcriptionFrom !== null, fn ($q) => $q->where('transcription_id', '>=', $transcriptionFrom))
                ->when($transcriptionTo !== null, fn ($q) => $q->where('transcription_id', '<=', $transcriptionTo))
                ->when($fromId !== null, fn ($q) => $q->where('id', '>=', $fromId))
                ->when($toId !== null, fn ($q) => $q->where('id', '<=', $toId))
                ->count();
            $state['total'] = $totalSegments;
            Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
            $this->info("Total segments a procesar: {$totalSegments}");
        } catch (\Throwable $e) {
            // Si falla el pre-conteo, no bloqueamos el flujo; el callback
            // seguirá actualizando por chunk.
        }

        try {
            $updated = $service->applyRetroactively(
                function ($processed, $total, $updatedSoFar, $lastId) use ($cacheKey, &$state) {
                    $state['processed']        = $processed;
                    $state['total']            = $total;
                    $state['updated']          = $updatedSoFar;
                    $state['progress']         = $lastId;
                    $state['last_progress_at'] = now()->toIso8601String();
                    Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
                },
                $chunk,
                $dryRun,
                $daysBack,
                $includeHighRisk,
                $sleepMs,
                $transcriptionIds,
                $fromId,
                $toId,
                $transcriptionFrom,
                $transcriptionTo
            );
            $state['updated'] = $updated;
            $state['total'] = $state['total'] ?? 0;
            $state['status'] = 'done';
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['error_message'] = $e->getMessage();
            $this->error('Falló la corrida: ' . $e->getMessage());
        }

        $state['finished_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        // Limpiar el puntero de corrida activa: tanto done como error
        // liberan el slot para un nuevo apply-retroactive. Si este proceso
        // muere abruptamente (kill -9), el controller detecta huérfanos por
        // edad/heartbeat y limpia de todos modos.
        Cache::forget('corrections_apply:active');

        $this->info("Run {$runId}: status={$state['status']} updated={$state['updated']}");

        return $state['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
