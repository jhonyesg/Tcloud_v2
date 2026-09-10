<?php

namespace App\Console\Commands;

use App\Services\Ia\AvisosScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runner en background del escaneo manual de menciones (avisos-scan-bg-runner).
 *
 * Patrón idéntico a corrections:apply-run: el controller crea el estado en
 * cache bajo "avisos_scan_bg:{runId}", lanza este comando con setsid
 * (RunsBackgroundCommands) y la UI hace polling del runId — así el escaneo
 * sobrevive recargas, cierre de navegador y cortes de red.
 *
 * DOS MODOS DE OPERACIÓN (fix-avisos-scan-by-months-no-saturation):
 *   - "classic": una sola corrida con la ventana completa. Apto para
 *     presets, from/to explícito, catch-up de keyword con storage acotado.
 *     Drena tandas de N hasta agotar candidatos.
 *   - "monthly": cuando noWindow=true && force=true && !from && !to && !preset.
 *     Plan mensual generado con min/max(finished_at). Itera cada mes
 *     secuencialmente con yielding entre meses (DB::disconnect + sleep).
 *     Cada mes conserva el drenaje interno por tandas de ≤50.
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
        {--no-window : Catch-up: sin límite de fechas}
        {--monthly : Forzar modo mensual (override del detector automático)}';

    protected $description = 'Drena el escaneo de menciones en background con progreso en cache (runId)';

    private const CACHE_TTL_HOURS = 6;
    private const MONTHLY_YIELD_SECONDS = 1;

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
            $this->writeStateSafe($cacheKey, $state);
            Cache::forget('avisos_scan_bg:active');

            return self::SUCCESS;
        }

        $state['status'] = 'running';
        $state['started_at'] = now()->toIso8601String();
        // Captura cutoff para que el plan mensual no incluya transcripciones
        // creadas durante la corrida (recomendación del proveedor).
        $state['scan_cutoff_at'] = $state['scan_cutoff_at'] ?? now()->toIso8601String();

        // Decidir modo: si el controller ya marcó `mode` (lo cual ocurre cuando
        // detectó el caso mensual y pobló `month_plan`), respetarlo. Si no,
        // autodetectar.
        $isMonthly = ($state['mode'] ?? null) === 'monthly'
            || $this->option('monthly')
            || (
                !empty($state['noWindow'])
                && ($state['force'] ?? false)
                && empty($state['from'])
                && empty($state['to'])
                && empty($state['preset'])
            );
        $state['mode'] = $isMonthly ? 'monthly' : 'classic';

        if ($isMonthly && empty($state['month_plan'])) {
            // Primera vez: generar plan desde planning.
            $plan = $service->planMonths();
            $state['month_plan'] = $plan;
            $state['months_total'] = count($plan);
            $state['months_done'] = 0;
            $state['current_month'] = null;
        }

        $this->writeStateSafe($cacheKey, $state);

        if ($isMonthly) {
            return $this->runMonthly($service, $cacheKey, $state);
        }

        return $this->runClassic($service, $cacheKey, $state);
    }

    /**
     * Modo clásico: drenaje de tandas hasta agotar candidatos.
     */
    private function runClassic(AvisosScanService $service, string $cacheKey, array $state): int
    {
        $runId = str_replace('avisos_scan_bg:', '', $cacheKey);
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

                $state = $this->readState($cacheKey, $state);
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
                // run() puede devolver `error` como string (catch) o array.
                if (!empty($r['error'])) {
                    if (is_string($r['error'])) {
                        $errors[] = $r['error'];
                    } elseif (is_array($r['error'])) {
                        foreach ($r['error'] as $e) {
                            $errors[] = is_array($e) ? ($e['error'] ?? json_encode($e)) : (string) $e;
                        }
                    }
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
                $this->writeStateSafe($cacheKey, $state);

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
        $this->writeStateSafe($cacheKey, $state);
        Cache::forget('avisos_scan_bg:active');

        $this->info("Run {$runId}: status={$state['status']} scanned={$scanned} hits={$hitsNew}");

        return $state['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Modo mensual: itera cada mes del plan secuencialmente.
     *
     * Entre meses: DB::disconnect() + sleep(1) para liberar conexiones
     * de BD. Si stop_requested=true, no empieza el siguiente mes.
     *
     * Cancelación: las tandas DENTRO de un mes usan el cooperative cancel
     * que ya implementa AvisosScanService::run(); entre meses revisamos
     * explícitamente stop_requested.
     */
    private function runMonthly(AvisosScanService $service, string $cacheKey, array $state): int
    {
        $runId = str_replace('avisos_scan_bg:', '', $cacheKey);
        $plan = $state['month_plan'] ?? [];
        $total = count($plan);

        if ($total === 0) {
            $state['status'] = 'done';
            $state['finished_at'] = now()->toIso8601String();
            $this->writeStateSafe($cacheKey, $state);
            Cache::forget('avisos_scan_bg:active');
            $this->info("Run {$runId}: sin datos — done inmediato.");
            return self::SUCCESS;
        }

        // Acumuladores globales.
        $scanned = (int) ($state['scanned'] ?? 0);
        $hitsNew = (int) ($state['hits_new'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);
        $batches = (int) ($state['batches'] ?? 0);
        $monthsDone = 0;
        $currentMonth = $state['current_month'] ?? null;
        $errors = (array) ($state['errors'] ?? []);

        try {
            foreach ($plan as $idx => [$yearMonth, $planStatus]) {
                // Si ya está done (reanudación), saltar.
                if ($planStatus === 'done') {
                    $monthsDone++;
                    continue;
                }

                // Checkpoint cancelación cooperativa entre meses.
                $state = $this->readState($cacheKey, $state);
                if (!empty($state['stop_requested'])) {
                    $state['status'] = 'stopped';
                    break;
                }

                // Marcar mes como running.
                $plan[$idx][1] = 'running';
                $state['month_plan'] = $plan;
                $state['current_month'] = $yearMonth;
                $state['last_progress_at'] = now()->toIso8601String();
                $this->writeStateSafe($cacheKey, $state);

                // Correr el mes: delega en service->runMonth (internamente
                // hace el drenaje por tandas).
                $opts = [
                    'origin' => 'manual',
                    'storageId' => $state['storageId'] ?? null,
                    'force' => (bool) ($state['force'] ?? false),
                    'limit' => max(1, (int) ($state['limit'] ?? 50)),
                ];

                $r = $service->runMonth($yearMonth, $opts);

                $scanned += (int) ($r['scanned'] ?? 0);
                $hitsNew += (int) ($r['hitsNew'] ?? 0);
                $failed += (int) ($r['failed'] ?? 0);
                $batches++;
                // run() puede devolver `error` como string (catch) o array
                // (lista de errores parciales). Normalizamos.
                if (!empty($r['error'])) {
                    if (is_string($r['error'])) {
                        $errors[] = $r['error'];
                    } elseif (is_array($r['error'])) {
                        foreach ($r['error'] as $e) {
                            $errors[] = is_array($e) ? ($e['error'] ?? json_encode($e)) : (string) $e;
                        }
                    }
                }

                // Marcar mes como done. El estado `running` sin completar
                // se trata como no-done al recuperar.
                $plan[$idx][1] = 'done';
                $monthsDone++;

                $state['month_plan'] = $plan;
                $state['current_month'] = $yearMonth;
                $state['months_done'] = $monthsDone;
                $state['months_total'] = $total;
                $state['scanned'] = $scanned;
                $state['hits_new'] = $hitsNew;
                $state['failed'] = $failed;
                $state['batches'] = $batches;
                $state['errors'] = array_slice($errors, 0, 20);
                $state['last_progress_at'] = now()->toIso8601String();
                $this->writeStateSafe($cacheKey, $state);

                $this->info("Run {$runId}: mes {$yearMonth} done ({$monthsDone}/{$total}) scanned={$scanned} hits={$hitsNew}");

                // Yielding entre meses: liberar conexión de BD y dar espacio.
                DB::disconnect();
                sleep(self::MONTHLY_YIELD_SECONDS);
            }

            if (($state['status'] ?? '') !== 'stopped' && $monthsDone === $total) {
                $state['status'] = 'done';
            }
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['error_message'] = mb_substr($e->getMessage(), 0, 500);
            // Marcar el mes actual como failed si lo sabemos.
            if (!empty($state['current_month'])) {
                foreach ($plan as $idx => [$ym, $st]) {
                    if ($ym === $state['current_month'] && $st === 'running') {
                        $plan[$idx][1] = 'failed';
                        $state['month_plan'] = $plan;
                        break;
                    }
                }
            }
            $this->error('Falló la corrida mensual: ' . $e->getMessage());
        }

        $state['scanned'] = $scanned;
        $state['hits_new'] = $hitsNew;
        $state['failed'] = $failed;
        $state['batches'] = $batches;
        $state['months_done'] = $monthsDone;
        $state['finished_at'] = now()->toIso8601String();
        $this->writeStateSafe($cacheKey, $state);
        Cache::forget('avisos_scan_bg:active');

        $this->info("Run {$runId}: status={$state['status']} months={$monthsDone}/{$total} scanned={$scanned} hits={$hitsNew}");

        return $state['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Lee el state actual del cache con fallback seguro.
     */
    private function readState(string $cacheKey, array $fallback): array
    {
        $current = Cache::get($cacheKey);
        return is_array($current) ? $current : $fallback;
    }

    /**
     * Escribe el state con guard de race vs endpoint de stop.
     *
     * Si el state externo tiene stop_requested=true y el interno no,
     * no permitimos que stop_requested vuelva a false.
     */
    private function writeStateSafe(string $cacheKey, array $state): void
    {
        $current = Cache::get($cacheKey);
        if (is_array($current) && !empty($current['stop_requested']) && empty($state['stop_requested'])) {
            $state['stop_requested'] = true;
        }
        Cache::put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
    }
}