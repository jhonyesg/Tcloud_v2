<?php

namespace App\Console\Commands;

use App\Services\Ia\AvisosScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runner en background del escaneo manual de menciones (avisos-scan-bg-runner).
 *
 * Patrón idéntico a corrections:apply-run: el controller crea el estado en
 * cache bajo "avisos_scan_bg:{runId}", lanza este comando con setsid
 * (RunsBackgroundCommands) y la UI hace polling del runId — así el escaneo
 * sobrevive recargas, cierre de navegador y cortes de red.
 *
 * Drenaje completo: repite tandas de N hasta agotar candidatos, excluyendo
 * los IDs ya intentados (attemptedIds) para que las transcripciones sin hits
 * no se re-visiten en bucle. El progreso se escribe en cache tras cada tanda.
 */
class ScanMentionsRunCommand extends Command
{
    protected $signature = 'avisos:scan-run
        {--run-id= : Cache key suffix para tracking del run (obligatorio)}
        {--storage= : Filtrar por storage_provider_id}
        {--from= : Terminación desde (Y-m-d o Y-m-d H:i:s)}
        {--to= : Terminación hasta (Y-m-d o Y-m-d H:i:s)}
        {--preset= : Preset de ventana (8h|24h|3d|7d|today)}
        {--limit=50 : Tanda por corrida}
        {--force : Re-escanear borrando hits previos}
        {--no-window : Catch-up: sin límite de fechas}';

    protected $description = 'Drena el escaneo de menciones en background con progreso en cache (runId)';

    private const CACHE_TTL_HOURS = 6;

    public function handle(AvisosScanService $service): int
    {
        $runId = trim((string) $this->option('run-id'));
        if ($runId === '') {
            $this->error('Falta --run-id. Es obligatorio para el polling de la UI.');

            return self::FAILURE;
        }

        $cacheKey = "avisos_scan_bg:{$runId}";
        $state = Cache::get($cacheKey);
        if (!$state) {
            $this->error("Run id '{$runId}' no encontrado en cache. Lanzar desde el controller primero.");

            return self::FAILURE;
        }

        // Cancelación solicitada por la UI: el controller pone stop_requested.
        if (!empty($state['stop_requested'])) {
            $state['status'] = 'stopped';
            $state['finished_at'] = now()->toIso8601String();
            Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
            Cache::forget('avisos_scan_bg:active');

            return self::SUCCESS;
        }

        $state['status'] = 'running';
        $state['started_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        $attempted = [];
        $scanned = 0;
        $hitsNew = 0;
        $failed = 0;
        $errors = [];
        $batch = max(1, (int) ($state['limit'] ?? 50));
        $guard = 0;

        try {
            while ($guard < 20000) {
                $guard++;

                // Cancelación cooperativa: se revisa entre tandas.
                $state = Cache::get($cacheKey) ?: $state;
                if (!empty($state['stop_requested'])) {
                    $state['status'] = 'stopped';
                    break;
                }

                $opts = [
                    'origin' => 'manual',
                    'storageId' => $state['storageId'] ?? null,
                    'from' => $state['from'] ?? null,
                    'to' => $state['to'] ?? null,
                    'preset' => $state['preset'] ?? null,
                    'force' => (bool) ($state['force'] ?? false),
                    'noWindow' => (bool) ($state['noWindow'] ?? false),
                    'limit' => $batch,
                    'excludeIds' => $attempted,
                ];

                $r = $service->run($opts);

                $scanned += $r['scanned'] ?? 0;
                $hitsNew += $r['hitsNew'] ?? 0;
                $failed += $r['failed'] ?? 0;
                foreach (($r['error'] ?? []) as $e) {
                    $errors[] = is_array($e) ? ($e['error'] ?? json_encode($e)) : (string) $e;
                }
                foreach (($r['attemptedIds'] ?? []) as $id) {
                    $attempted[] = (int) $id;
                }

                $state['scanned'] = $scanned;
                $state['hits_new'] = $hitsNew;
                $state['failed'] = $failed;
                $state['batches'] = $guard;
                $state['last_progress_at'] = now()->toIso8601String();
                $state['errors'] = array_slice($errors, 0, 20);
                $state['attempted_count'] = count($attempted);
                Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

                // Drenado completo: la tanda no intentó ningún candidato nuevo
                // (todos los del rango ya están en $attempted).
                if (empty($r['attemptedIds'])) {
                    break;
                }
            }

            if (($state['status'] ?? '') !== 'stopped') {
                $state['status'] = 'done';
            }
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['error_message'] = mb_substr($e->getMessage(), 0, 500);
            $this->error('Falló la corrida: ' . $e->getMessage());
        }

        $state['finished_at'] = now()->toIso8601String();
        $state['scanned'] = $scanned;
        $state['hits_new'] = $hitsNew;
        $state['failed'] = $failed;
        $state['batches'] = $guard;
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
        Cache::forget('avisos_scan_bg:active');

        $this->info("Run {$runId}: status={$state['status']} scanned={$scanned} hits={$hitsNew}");

        return $state['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}