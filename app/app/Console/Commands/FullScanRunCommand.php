<?php

namespace App\Console\Commands;

use App\Services\Ia\AvisosScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * avisos-scan-coverage-observability-and-ux: worker de background para
 * full scan con progreso en cache (runId). Llamado por
 * AvisosInteligentesController::runFullScanBackground vía execBackground.
 *
 * Actualiza la cache key full_scan_bg:{runId} después de cada iteración
 * para que la UI pueda hacer polling. Respeta stop_requested.
 */
class FullScanRunCommand extends Command
{
    protected $signature = 'avisos:full-scan-run
        {--run-id= : ID de la corrida (cache key)}
        {--batch=200 : Lote por iteración}
        {--max-runtime=600 : Tope de tiempo en segundos}
        {--storage= : Filtrar por storage}
        {--keyword-id= : Filtrar por keyword}';

    protected $description = 'Worker background para full scan de avisos con runId';

    public function handle(AvisosScanService $service): int
    {
        $runId = $this->option('run-id');
        if (!$runId) {
            $this->error('--run-id es obligatorio');
            return self::FAILURE;
        }
        $cacheKey = "full_scan_bg:{$runId}";
        $state = Cache::get($cacheKey);
        if (!$state) {
            $this->error("Run {$runId} no encontrado en cache");
            return self::FAILURE;
        }

        $state['status'] = 'running';
        $state['started_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $state, now()->addHours(6));

        $opts = [
            'origin' => 'manual',
            'limit' => (int) $this->option('batch'),
            'noWindow' => true,
        ];
        if ($this->option('storage')) {
            $opts['storageId'] = (int) $this->option('storage');
        }
        if ($this->option('keyword-id')) {
            $opts['keywordId'] = (int) $this->option('keyword-id');
        }

        $maxRuntime = (int) $this->option('max-runtime');
        $startedAt = microtime(true);
        $iterations = 0;
        $totalScanned = 0;
        $totalHits = 0;

        while ((microtime(true) - $startedAt) < $maxRuntime) {
            // Check stop_requested.
            $state = Cache::get($cacheKey);
            if (!$state || ($state['stop_requested'] ?? false)) {
                break;
            }

            $iterations++;
            $result = $service->run($opts);
            $totalScanned += (int) ($result['scanned'] ?? 0);
            $totalHits += (int) ($result['hitsNew'] ?? 0);

            // Update state en cache.
            $state = Cache::get($cacheKey) ?? [];
            $state['status'] = 'running';
            $state['iterations'] = $iterations;
            $state['scanned'] = $totalScanned;
            $state['hits_new'] = $totalHits;
            $state['duration_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
            Cache::put($cacheKey, $state, now()->addHours(6));

            if (($result['candidates'] ?? 0) < $opts['limit'] || ($result['status'] ?? null) === 'failed') {
                break;
            }
        }

        $finalState = Cache::get($cacheKey) ?? [];
        $finalState['status'] = ($finalState['stop_requested'] ?? false) ? 'stopped' : 'done';
        $finalState['finished_at'] = now()->toIso8601String();
        $finalState['iterations'] = $iterations;
        $finalState['scanned'] = $totalScanned;
        $finalState['hits_new'] = $totalHits;
        $finalState['duration_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
        Cache::put($cacheKey, $finalState, now()->addHours(6));

        // Liberar pointer.
        $pointer = Cache::get('full_scan_bg:active');
        if (is_array($pointer) && ($pointer['runId'] ?? null) === $runId) {
            Cache::forget('full_scan_bg:active');
        }

        // Auditoría.
        try {
            \Illuminate\Support\Facades\DB::table('watermark_audit_log')->insert([
                'actor_user_id' => null,
                'action' => 'full_scan',
                'metadata' => json_encode([
                    'runId' => $runId,
                    'status' => $finalState['status'],
                    'iterations' => $iterations,
                    'scanned' => $totalScanned,
                    'hits_new' => $totalHits,
                    'duration_ms' => $finalState['duration_ms'],
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('full_scan.audit_skip', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
