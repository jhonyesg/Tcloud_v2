<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\BogotaTime;
use App\Services\Ia\DiskScannerService;
use App\Services\Ia\TranscriptionBulkDispatchService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanAndSubmitCommand extends Command
{
    protected $signature = 'transcription:scan-and-submit
                            {--days=-1 : Dias hacia atras ademas de hoy (-1 = usar scan_days_back)}
                            {--all : Escanear recursivamente todas las carpetas}
                            {--from= : Inicio del rango DDMMYYYY (transcriptor-scan-scope-selector)}
                            {--to= : Fin del rango DDMMYYYY (transcriptor-scan-scope-selector)}
                            {--dry-run : Contar candidatos SIN crear filas ni encolar}
                            {--batch=0 : Maximo archivos por storage por ciclo (0 = usar config scan_batch)}
                            {--run-id= : Identificador para reportar progreso en cache (opcional)}
                            {--no-dispatch : Solo escanea y crea pendientes en BD; los workers PG los recogen via la propia tabla transcriptions}
                            {--alerts= : Entrar al matching global de menciones (KeywordMatcher) para las transcripciones creadas (1=si default, 0=opt-out explicito)}
                            {--include-failed : Incluir transcripciones en estado error con archivo accesible (max retries configurable)}
                            {--include-done : Incluir transcripciones en estado done con archivo accesible (reprocesar finalizados, transcriptor-rescan-completed)}';

    protected $description = 'Escanea el disco de storages habilitados y crea transcripciones pendientes; el worker PG las consume directo de la tabla.';

    public function handle(DiskScannerService $scanner, TranscriptorSettings $settings): int
    {
        try {
            return $this->runHandle($scanner, $settings);
        } catch (\Throwable $e) {
            $runId = $this->option('run-id');
            $cacheKey = $runId ? 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId) : null;

            Log::error("ScanAndSubmitCommand: error fatal: {$e->getMessage()}", ['exception' => $e]);

            if ($cacheKey) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'batch' => 0,
                    'processed' => 0,
                    'errors' => 1,
                    'total_to_process' => 0,
                    'total_candidates' => 0,
                    'per_storage_errors' => [],
                    'failed_recovered' => 0,
                    'failed_promoted_to_dead' => 0,
                    'failed_skipped_max_retries' => 0,
                    'storages' => [],
                    'files' => [],
                    'started_at' => now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(2));
            }

            // Pase lo que pase (exito, salida temprana o excepcion), el candado
            // de concurrencia del modal "Procesar historicos" debe liberarse; si
            // no, el operador queda bloqueado hasta el TTL de 2 h.
            \Illuminate\Support\Facades\Cache::forget('transcriptor:scan_run:lock');

            return Command::FAILURE;
        } finally {
            // Cubre TODAS las salidas de runHandle (las tres exitosas y la de
            // error): el candado se libera siempre al terminar el proceso.
            \Illuminate\Support\Facades\Cache::forget('transcriptor:scan_run:lock');
        }
    }

    private function runHandle(DiskScannerService $scanner, TranscriptorSettings $settings): int
    {
        // -1 = no especificado; se usa scan_days_back, que hasta ahora estaba
        // definido en config y no lo leia nadie.
        $days = (int) $this->option('days');
        $days = $days < 0 ? $settings->int('scan_days_back') : $days;

        $all = (bool) $this->option('all');
        $batch = (int) $this->option('batch');
        $batchOverride = $batch > 0 ? $batch : null;
        $runId = $this->option('run-id');
        $cacheKey = $runId ? 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId) : null;
        $includeFailed = (bool) $this->option('include-failed');
        // transcriptor-rescan-completed: nuevo flag espejado de --include-failed.
        $includeDone = (bool) $this->option('include-done');
        $dryRun = (bool) $this->option('dry-run');

        // transcriptor-scan-scope-selector: construir el alcance. --from/--to
        // manda sobre --days/--all (un rango explícito es más específico).
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $scope = null;
        if ($fromOpt || $toOpt) {
            try {
                $scope = DiskScannerService::scopeRange((string) $fromOpt, (string) $toOpt);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return Command::FAILURE;
            }
            $folders = $scope['folders'];
            $this->info("Alcance: rango {$fromOpt}..{$toOpt} (" . count($folders) . " carpetas: {$folders[0]}.." . end($folders) . ')');
        } elseif ($all) {
            $scope = DiskScannerService::scopeAll();
        }

        // Invariante del modulo de avisos: toda transcripcion nueva entra al
        // matching global de menciones; el filtrado per-user ocurre aguas abajo
        // en avisos:deliver-alerts (user_keyword + user_alerts_inteligentes).
        // Omitir --alerts (o pasar --alerts) = opt-in; --alerts=0 = opt-out
        // explicito del operador. Ver Transcription model docblock.
        $alertsOpt = $this->option('alerts');
        $generateAlerts = ($alertsOpt === null || $alertsOpt === '') ? true : (bool) $alertsOpt;

        $maxRetries = $settings->int('max_retries');

        if ($cacheKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, array_merge(
                \Illuminate\Support\Facades\Cache::get($cacheKey, []),
                ['status' => 'running', 'updated_at' => now()->toIso8601String()]
            ), now()->addHours(2));
        }

        $storages = StorageProvider::transcriptionEnabled()->get();
        if ($storages->isEmpty()) {
            $this->info('No hay storages con transcripción habilitada.');
            return Command::SUCCESS;
        }

        $totalPendingCreated = 0;
        $totalFilesCreated = 0;
        $perStorageErrors = [];
        $failedStorages = 0;

        // Stats de fallidos (solo si --include-failed)
        $failedStats = [
            'candidates' => 0,
            'reset_to_pending' => 0,
            'promoted_to_dead' => 0,
            'skipped_max_retries' => 0,
        ];

        // transcriptor-rescan-completed: stats de reproceso de completados
        // (solo si --include-done). Shape análogo a failedStats para consistencia.
        $doneStats = [
            'candidates' => 0,
            'reset_to_pending' => 0,
            'promoted_to_dead' => 0,
            'skipped_no_file' => 0,
        ];

        // Fase 1: escanear disco y crear pendientes (o solo contar con --dry-run).
        if ($dryRun) {
            $this->line('[DRY-RUN] Contando candidatos por storage (nada se crea):');
            $totalMissing = 0;
            foreach ($storages as $storage) {
                try {
                    $stats = $scanner->scanStorage($storage, $days, $all, $batchOverride, $generateAlerts, $scope);
                    $this->line("  {$storage->name} (id={$storage->id}): candidates={$stats['candidates']} files_created=0 tx_created=0");
                    $totalMissing += $stats['candidates'];
                } catch (\Throwable $e) {
                    $this->error("  {$storage->name} (id={$storage->id}): {$e->getMessage()}");
                }
            }
            $this->info("[DRY-RUN] Total candidatos (cupo batch {$batch} por storage aplicado): {$totalMissing}");
            return Command::SUCCESS;
        }

        $storageIndex = 0;
        foreach ($storages as $storage) {
            $storageIndex++;
            try {
                $stats = $scanner->scanStorage($storage, $days, $all, $batchOverride, $generateAlerts, $scope);
                $totalFilesCreated += $stats['files_created'];
                $totalPendingCreated += $stats['transcriptions_created'];
                $this->info("Storage {$storage->name}: scanned={$stats['scanned']} candidates={$stats['candidates']} files_created={$stats['files_created']} tx_created={$stats['transcriptions_created']}");

                // bg-job-indicator-widget: progreso por storage para que el
                // widget flotante (esquina inferior derecha) muestre avance
                // real en vez del eterno "0/0 archivos". Antes solo se
                // acumulaban storages en el cache; nunca se escribían
                // processed/total_to_process durante el loop, así que el
                // scanner los dejaba en 0 hasta el final.
                if ($cacheKey) {
                    $cache = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
                    $storagesList = $cache['storages'] ?? [];
                    $storagesList[] = [
                        'id' => $storage->id,
                        'name' => $storage->name,
                        'scanned' => $stats['scanned'],
                        'files_created' => $stats['files_created'],
                        'tx_created' => $stats['transcriptions_created'],
                    ];
                    $cache['storages'] = $storagesList;
                    $cache['scan_scope'] = $scope['mode'] ?? 'today';
                    $cache['processed'] = $storageIndex;
                    $cache['total_to_process'] = $storages->count();
                    $cache['updated_at'] = now()->toIso8601String();
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $cache, now()->addHours(2));
                }
            } catch (\Throwable $e) {
                $failedStorages++;
                $msg = "Storage {$storage->name} (id={$storage->id}): {$e->getMessage()}";
                $this->error($msg);
                Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
                $perStorageErrors[] = [
                    'storage_id' => $storage->id,
                    'storage_name' => $storage->name,
                    'message' => $e->getMessage(),
                ];
                // bg-job-indicator-widget: actualizar contador igual aunque
                // el storage haya fallado (sigue contando como "procesado"
                // para la barra de progreso; los errores van aparte).
                if ($cacheKey) {
                    $cache = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
                    $cache['processed'] = $storageIndex;
                    $cache['total_to_process'] = $storages->count();
                    $cache['updated_at'] = now()->toIso8601String();
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $cache, now()->addHours(2));
                }
            }
        }

        // Fase 1.5: reintentar transcripciones en estado error (solo si --include-failed).
        // transcriptor-scan-scope-selector: con rango elegido, el reintento se
        // acota a transcripciones creadas dentro de ese rango.
        $retryFromIso = null;
        $retryToIso = null;
        if ($scope !== null && ($scope['mode'] ?? '') === 'range') {
            $parseIso = function (string $dmY): ?string {
                if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', trim($dmY), $m)) {
                    return null;
                }
                return $m[3] . '-' . $m[2] . '-' . $m[1];
            };
            $retryFromIso = $parseIso((string) $fromOpt);
            $retryToIso = $parseIso((string) $toOpt);
        }

        if ($includeFailed) {
            foreach ($storages as $storage) {
                try {
                    $stats = $scanner->collectFailedCandidates($storage, $maxRetries, $retryFromIso, $retryToIso);
                    foreach ($stats as $k => $v) {
                        $failedStats[$k] = ($failedStats[$k] ?? 0) + $v;
                    }
                    if (($stats['candidates'] ?? 0) > 0) {
                        $this->info("Storage {$storage->name} (retry): candidates={$stats['candidates']} reset={$stats['reset_to_pending']} dead={$stats['promoted_to_dead']} skipped_max={$stats['skipped_max_retries']}");
                    }
                } catch (\Throwable $e) {
                    $msg = "Storage {$storage->name} (collect-failed): {$e->getMessage()}";
                    $this->error($msg);
                    Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
                    $perStorageErrors[] = [
                        'storage_id' => $storage->id,
                        'storage_name' => $storage->name,
                        'message' => 'collect-failed: ' . $e->getMessage(),
                    ];
                }
            }
            $this->info("Retry-failed resumen: candidates={$failedStats['candidates']} reset_to_pending={$failedStats['reset_to_pending']} promoted_to_dead={$failedStats['promoted_to_dead']} skipped_max_retries={$failedStats['skipped_max_retries']}");
        }

        // Fase 1.6: reprocesar transcripciones en estado done (transcriptor-rescan-completed).
        // Espejo de la Fase 1.5 pero filtrando state='done' y por finished_at (no created_at).
        if ($includeDone) {
            foreach ($storages as $storage) {
                try {
                    $stats = $scanner->collectDoneCandidates($storage, $retryFromIso, $retryToIso);
                    foreach ($stats as $k => $v) {
                        $doneStats[$k] = ($doneStats[$k] ?? 0) + $v;
                    }
                    if (($stats['candidates'] ?? 0) > 0) {
                        $this->info("Storage {$storage->name} (rescan-done): candidates={$stats['candidates']} reset={$stats['reset_to_pending']} dead={$stats['promoted_to_dead']}");
                    }
                } catch (\Throwable $e) {
                    $msg = "Storage {$storage->name} (collect-done): {$e->getMessage()}";
                    $this->error($msg);
                    Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
                    $perStorageErrors[] = [
                        'storage_id' => $storage->id,
                        'storage_name' => $storage->name,
                        'message' => 'collect-done: ' . $e->getMessage(),
                    ];
                }
            }
            $this->info("Rescan-done resumen: candidates={$doneStats['candidates']} reset_to_pending={$doneStats['reset_to_pending']} promoted_to_dead={$doneStats['promoted_to_dead']} skipped_no_file={$doneStats['skipped_no_file']}");
        }

        // Fase 2: el worker PG (transcription:worker) toma las filas state='pending'
        // directamente de la tabla `transcriptions` con FOR UPDATE SKIP LOCKED.
        // El "dispatch" ya no es un paso distinto del cron.
        $noDispatch = (bool) $this->option('no-dispatch');

        if ($noDispatch) {
            $this->info("Scan-and-submit (modo --no-dispatch) completado. Pendientes creados en BD: {$totalPendingCreated}. Los workers PG los recogerán cuando arranquen.");
            if ($cacheKey) {
                $status = ($failedStorages > 0 && $failedStorages < $storages->count()) ? 'partial' : 'queued';
                $message = null;
                if ($failedStorages > 0) {
                    $message = "{$failedStorages} de {$storages->count()} storages fallaron durante el scan. Ver per_storage_errors.";
                    if ($failedStorages === $storages->count()) {
                        $status = 'error';
                        $message = "Todos los storages ({$storages->count()}) fallaron durante el scan. Ver per_storage_errors.";
                    }
                }
                \Illuminate\Support\Facades\Cache::put($cacheKey, [
                    'status' => $status,
                    'batch' => 0,
                    'processed' => $storages->count(),
                    'errors' => $failedStorages,
                    'total_to_process' => $storages->count(),
                    'total_candidates' => $totalPendingCreated + $failedStats['reset_to_pending'] + $doneStats['reset_to_pending'],
                    'pending_created' => $totalPendingCreated,
                    'dispatched' => 0,
                    'per_storage_errors' => $perStorageErrors,
                    'failed_recovered' => $failedStats['reset_to_pending'],
                    'failed_promoted_to_dead' => $failedStats['promoted_to_dead'],
                    'failed_skipped_max_retries' => $failedStats['skipped_max_retries'],
                    'done_rescan' => $doneStats,
                    'message' => $message,
                    'started_at' => now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(2));
            }
            return Command::SUCCESS;
        }

        // Tope de encolado por ciclo. NO se multiplica por el numero de storages:
        // con 31 storages y scan_batch=100 eso eran 3100 jobs en un bucle apretado,
        // saltandose el regulador por completo. Es ademas la ruta que usa el boton
        // "Escanear storages" de la UI, asi que tambien inundaba con disparo manual.
        // Freno de emergencia: como en el tick, se comprueba despues del
        // descubrimiento — las filas pending ya estan creadas, solo no se encolan.
        if ($settings->bool('dispatch_paused')) {
            $this->warn('Dispatch omitido: dispatch_paused activo.');
            Log::info('ScanAndSubmitCommand: skip dispatch, dispatch_paused activo');
            $submitBatch = 0;
        } else {
            $submitBatch = $this->computeSubmitBatch($settings, $batchOverride);

            if ($submitBatch <= 0) {
                $this->info('Dispatch omitido: la cola ya esta en/sobre target.');
                Log::info('ScanAndSubmitCommand: skip dispatch, queue at target');
            }
        }

        $pending = $submitBatch > 0
            ? Transcription::where('state', Transcription::STATE_PENDING)
                ->whereNull('job_id')
                ->limit($submitBatch)
                ->orderBy('created_at')
                ->get()
            : collect();

        $dispatched = 0;
        $errors = 0;
        // Cola nativa PG: el bulk-dispatch ahora marca `state='processing'` y
        // `dispatched_at` directo en BD. El worker PG NO las re-toma (filtra
        // `state='pending'`). Procesado sincrónico via TranscriptionBulkDispatchService
        // — un reintento manual sigue siendo sincrónico como antes.
        foreach ($pending as $tx) {
            try {
                $stats = app(TranscriptionBulkDispatchService::class)->dispatch([(int) $tx->id]);
                $dispatched += (int) ($stats['enqueued'] ?? 0);
                $errors += (int) ($stats['errors'] ?? 0);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Transcription {$tx->id}: {$e->getMessage()}");
                Log::error("ScanAndSubmitCommand: error encolando tx {$tx->id}: {$e->getMessage()}");
            }
        }

        $this->info("Scan-and-submit completado. Pendientes creados: {$totalPendingCreated}. Marcados como processing: {$dispatched}. Errores: {$errors}. El worker PG tomara las filas marcadas como processing.");

        if ($cacheKey) {
            $status = ($failedStorages > 0 && $failedStorages < $storages->count()) ? 'partial' : 'queued';
            $message = null;
            if ($failedStorages > 0) {
                $message = "{$failedStorages} de {$storages->count()} storages fallaron durante el scan. Ver per_storage_errors.";
                if ($failedStorages === $storages->count()) {
                    $status = 'error';
                    $message = "Todos los storages ({$storages->count()}) fallaron durante el scan. Ver per_storage_errors.";
                }
            }

            $finishedAtIso = now()->toIso8601String();

            // Preservar el started_at que el comando escribió al arrancar
            // (línea 109); si por algun motivo no esta, usar finished_at
            // como fallback (el widget solo muestra "hace X" relativo).
            $existingCache = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
            $startedAtIso = $existingCache['started_at'] ?? $finishedAtIso;

            // El candado de concurrencia del modal "Procesar históricos" se
            // libera AQUI, al terminar la corrida. No se espera al polling de
            // la UI: si el operador cierra el navegador, el candado debe caer
            // igual o quedaria bloqueado hasta su TTL de 2 h.
            \Illuminate\Support\Facades\Cache::forget('transcriptor:scan_run:lock');

            // bg-job-indicator-widget (fix-A + bg-job-indicator-hide-completed):
            // Garantizamos que la entrada quede con finishedAt poblado al terminar,
            // sea cual sea el formato inicial (string plano del controller o array
            // de una corrida previa). Esto permite que el scanner descarte el job
            // pasados TERMINAL_TTL_SECONDS (5 min) en lugar de mantenerlo visible
            // hasta que expire la cache individual (2h).
            $activeKey = 'transcription_batch:active_runs';
            $activeList = \Illuminate\Support\Facades\Cache::get($activeKey, []);
            $alreadyListed = false;
            foreach ($activeList as $i => $entry) {
                $eRunId = is_array($entry) ? ($entry['runId'] ?? null) : $entry;
                if ($eRunId === $runId) {
                    $activeList[$i] = ['runId' => $runId, 'finishedAt' => $finishedAtIso];
                    $alreadyListed = true;
                    break;
                }
            }
            if (!$alreadyListed) {
                $activeList[] = ['runId' => $runId, 'finishedAt' => $finishedAtIso];
            }
            \Illuminate\Support\Facades\Cache::put($activeKey, $activeList, now()->addHours(2));

            // bg-job-indicator-widget (fix-B): processed refleja el trabajo
            // real hecho, no 0. processed = storages escaneados (cubre los
            // que fallaron tambien, ya se contaron arriba). La barra llega a
            // 100% y el widget muestra el resumen final.
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => $status,
                'batch' => 0,
                'processed' => $storages->count(),
                'errors' => $errors + $failedStorages,
                'total_to_process' => $storages->count(),
                'total_candidates' => $totalPendingCreated + $failedStats['reset_to_pending'] + $doneStats['reset_to_pending'],
                'pending_created' => $totalPendingCreated,
                'dispatched' => $dispatched,
                'per_storage_errors' => $perStorageErrors,
                'failed_recovered' => $failedStats['reset_to_pending'],
                'failed_promoted_to_dead' => $failedStats['promoted_to_dead'],
                'failed_skipped_max_retries' => $failedStats['skipped_max_retries'],
                'done_rescan' => $doneStats,
                'message' => $message,
                'started_at' => $startedAtIso,
                'finished_at' => $finishedAtIso,
                'updated_at' => $finishedAtIso,
            ], now()->addHours(2));
        }

        return Command::SUCCESS;
    }

    /**
     * Tope de jobs a encolar en este ciclo.
     *
     * Antes esto era scan_batch * count($storages) — con 31 storages, 3100 jobs
     * de golpe sin consultar al regulador. Ahora se acota por dos vias:
     *  1. scan_max_dispatch_per_cycle: techo absoluto por ejecucion.
*  2. El deficit del regulador (misma aritmetica que TranscriptionTickCommand):
      *     si la cola ya esta en/sobre target, devuelve 0.
     *
     * Asi el camino manual (boton "Escanear storages") nunca puede exceder al
     * automatico.
     */
    private function computeSubmitBatch(TranscriptorSettings $settings, ?int $batchOverride): int
    {
        $cap = $batchOverride ?? $settings->int('scan_max_dispatch_per_cycle');

        try {
            $current = $this->countPendingToday();
        } catch (\Throwable $e) {
            // Sin visibilidad de la cola preferimos el techo conservador antes
            // que abortar el ciclo entero.
            Log::warning('ScanAndSubmitCommand: no se pudo contar pendientes PG: ' . $e->getMessage());
            return $cap;
        }

        // Misma aritmetica que el tick: el camino manual nunca puede exceder al
        // automatico.
        return min($cap, $settings->computeDispatchBatch($current));
    }

    private function countPendingToday(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('transcriptions')
            ->where('state', Transcription::STATE_PENDING)
            ->whereNull('dispatched_at')
            ->where('created_at', '>=', BogotaTime::todayStart())
            ->count();
    }
}