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
                            {--chunk=0 : Tamaño del chunk de segments por transacción (0 = usar corrections_chunk)}';

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

        $chunkOption = (int) $this->option('chunk');
        $chunk = $chunkOption > 0 ? max(50, $chunkOption) : null;

        $cacheKey = "corrections_apply:{$runId}";
        $state = Cache::get($cacheKey);

        if (!$state) {
            $this->error("Run id '{$runId}' no encontrado en cache. Lanzar desde el controller primero.");
            return self::FAILURE;
        }

        $state['status'] = 'running';
        $state['started_at'] = now()->toIso8601String();
        $state['updated'] = 0;
        $state['error_message'] = null;
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        $this->info($dryRun ? 'Modo dry-run: no se modificará la BD.' : 'Aplicando correcciones...');

        try {
            $service->applyRetroactively(
                function ($lastId) use ($cacheKey, &$state) {
                    $state['progress'] = $lastId;
                    Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
                },
                $chunk,
                $dryRun
            );
            $state['status'] = 'done';
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['error_message'] = $e->getMessage();
            $this->error('Falló la corrida: ' . $e->getMessage());
        }

        $state['finished_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        $this->info("Run {$runId}: status={$state['status']} updated={$state['updated']}");

        return $state['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
