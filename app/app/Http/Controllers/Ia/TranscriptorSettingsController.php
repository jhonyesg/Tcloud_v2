<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Concerns\RunsBackgroundCommands;
use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Services\Ia\BogotaTime;
use App\Services\Ia\TodayPendingService;
use App\Services\Ia\TranscriptorSettings;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Configuracion en caliente del pipeline de transcripcion.
 *
 * Separado de ApiTranscriptorController (que ya pasa de 1200 lineas) porque es
 * una preocupacion distinta: aquel opera trabajos, este regula el motor.
 *
 * Auth: el grupo de rutas ya lleva ['auth','admin']. El usuario se toma de
 * session('user') — este proyecto usa auth por sesion, nunca auth()->user().
 */
class TranscriptorSettingsController extends Controller
{
    use RunsBackgroundCommands;

    public function __construct(
        private TranscriptorSettings $settings,
        private TodayPendingService $todayPending,
        private TranscriptionSubmitService $submitService,
    ) {}

    /**
     * Estado efectivo + contexto en vivo.
     *
     * El contexto es lo que permite regular con criterio en vez de a ciegas:
     * profundidad de cola contra objetivo, workers activos, y —lo importante—
     * el lote que el regulador calcularia AHORA con los valores actuales.
     */
    public function index()
    {
        return response()->json([
            'groups' => $this->settings->effective(),
            'runtime' => $this->runtime(),
        ]);
    }

    public function update(Request $request)
    {
        $values = $request->input('values', []);
        if (!is_array($values) || $values === []) {
            return response()->json(['error' => 'No se recibio ningun valor.'], 422);
        }

        [$clean, $errors] = $this->settings->validate($values);

        if ($errors) {
            return response()->json([
                'error' => 'Hay valores invalidos.',
                'errors' => $errors,
            ], 422);
        }

        // Diff antes de aplicar, para la traza de auditoria.
        $before = $this->settings->effectiveValues();

        $this->settings->set($clean);

        $after = $this->settings->effectiveValues();

        $diff = [];
        foreach ($clean as $key => $_) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $diff[$key] = ['de' => $before[$key] ?? null, 'a' => $after[$key] ?? null];
            }
        }

        if ($diff) {
            Log::info('TranscriptorSettings: configuracion modificada', [
                'user_id' => Session::get('user_id'),
                'user' => Session::get('user')['username'] ?? null,
                'diff' => $diff,
            ]);

            // Un cambio de umbrales invalida el pestillo del freno: si el
            // operador movio target/resume, la decision vieja ya no aplica.
            app(\App\Services\Ia\RemoteQueueBrake::class)->forget();
        }

        return response()->json([
            'message' => $diff ? 'Configuracion actualizada.' : 'Sin cambios.',
            'changed' => array_keys($diff),
            'groups' => $this->settings->effective(),
            'runtime' => $this->runtime(),
        ]);
    }

    public function reset(Request $request)
    {
        $keys = $request->input('keys', []);
        $keys = is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];

        foreach ($keys as $key) {
            if (!$this->settings->has($key)) {
                return response()->json(['error' => "Clave desconocida: {$key}"], 422);
            }
        }

        $this->settings->reset($keys);

        Log::info('TranscriptorSettings: valores restaurados', [
            'user_id' => Session::get('user_id'),
            'keys' => $keys ?: 'todas',
        ]);

        app(\App\Services\Ia\RemoteQueueBrake::class)->forget();

        return response()->json([
            'message' => $keys ? 'Valor restaurado.' : 'Todos los valores restaurados.',
            'groups' => $this->settings->effective(),
            'runtime' => $this->runtime(),
        ]);
    }

    /**
     * Ejecuta la tarea programada bajo demanda.
     *
     * Es la contraparte de "necesito verlo": permite disparar el ciclo y ver el
     * efecto sin esperar al scheduler. En modo simulacion no escribe en BD.
     */
    public function runTick(Request $request)
    {
        $dryRun = (bool) $request->input('dry_run', false);

        $artisan = base_path('artisan');
        $php = PHP_BINDIR . '/php';
        if (!is_file($php)) {
            $php = 'php';
        }

        $logFile = storage_path('logs/transcription-tick-manual.log');

        // El autolimitado por intervalo haria salir al comando en silencio si el
        // scheduler acaba de correr. Una ejecucion manual es una orden explicita,
        // asi que se limpia la marca.
        if (!$dryRun) {
            Cache::forget('transcriptor:tick:last_run');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' transcription:tick';
        if ($dryRun) {
            $cmd .= ' --dry-run';
        }
        $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        $this->execBackground($cmd, 'transcriptor:settings');

        Log::info('TranscriptorSettings: tick lanzado manualmente', [
            'user_id' => Session::get('user_id'),
            'dry_run' => $dryRun,
        ]);

        return response()->json([
            'message' => $dryRun
                ? 'Simulacion lanzada. El resultado aparece en el log en unos segundos.'
                : 'Tarea lanzada.',
            'log' => 'storage/logs/transcription-tick-manual.log',
        ]);
    }

    // ---------------------------------------------------------------- runtime

    private function runtime(): array
    {
        $today = $this->todayPending->global();
        $queueDepth = $this->queueDepth();

        return [
            // `queue_depth` sigue siendo la cola tomable por el worker PG
            // (pending sin dispatched_at del dia). Se conserva el nombre por
            // compatibilidad con la UI y el regulador.
            // La lista de pendientes del dia NO tiene tope: es "todo lo de hoy".
            // `queue_depth` se conserva como la profundidad de la cola PG local
            // (senal del regulador en modo local_only), pero `next_batch` ya NO
            // se presenta como "cuanto va a enviar": el ritmo real lo fija el
            // headroom de la cola remota (`target_remote_queue`) y el inventario
            // del stager. Se expone aparte para diagnostico.
            'queue_depth' => $queueDepth,
            'queue_target' => $this->settings->int('target_pg_queue'),
            'next_batch' => $queueDepth === null ? null : $this->settings->computeDispatchBatch($queueDepth),
            'paused' => $this->settings->bool('dispatch_paused'),
            'tick_interval_minutes' => $this->settings->int('tick_interval_minutes'),
            'tick_last_run' => Cache::get('transcriptor:tick:last_run'),
            'states' => $this->stateCounts(),
            // Desglose por estado de HOY (no historico). Es la fuente unica de
            // "pendientes de hoy": archivos del dia en storages habilitados sin
            // transcripcion done, incluyendo los que NO tienen fila de
            // transcripcion (missing) que antes eran invisibles.
            'today' => $today,
            // Estados historicos vs. estados de hoy: la UI mostraba 320k done
            // bajo la etiqueta "Hechos hoy" porque usaba el group-by global.
            'states_today' => $this->stateCountsToday(),
            'workers' => $this->workerState(),
            'tune_last' => $this->lastJsonLine(storage_path('logs/transcription-tune.log')),
            'tick_last' => $this->lastLine(storage_path('logs/transcription-tick.log')),
            'regulator_mode' => $this->settings->str('regulator_mode'),
            'target_remote_queue' => $this->settings->int('target_remote_queue'),
            // Histéresis del freno: la UI muestra en que punto esta el latch
            // (frenado/reanudado), el umbral de reanudo y cuando se revalida.
            'remote_brake' => $this->remoteBrakeState(),
            // Fases del pipeline: dan al operador visibilidad de donde estan los
            // audios en cualquier momento. La cola local "Cola de despacho"
            // muestra solo los pendientes sin agarrar; estas fases complementan
            // mostrando los que ya fueron tomados pero esperan requeue por
            // cola remota llena, estan en ffmpeg, o ya estan en la GPU remota.
            'requeueable_count' => $this->requeueableCount(),
            'remote_status' => $this->remoteStatus(),
            'throughput_per_min' => $this->throughputPerMin(),
            // Fase 1 del pipeline: inventario convertido y listo para enviar.
            // Es el amortiguador real entre la CPU local y la cola remota.
            'staging' => $this->stagingState(),
        ];
    }

    /**
     * Estado del freno con histéresis de la cola remota, para el panel.
     *
     * @return array{braked:bool, queue:?int, target:int, resume:int, recheck_seconds:int, requeue_seconds:int}
     */
    private function remoteBrakeState(): array
    {
        $brake = app(\App\Services\Ia\RemoteQueueBrake::class);
        $latch = Cache::get(\App\Services\Ia\RemoteQueueBrake::CACHE_KEY);
        $latch = is_array($latch) ? $latch : [];

        return [
            'braked' => (bool) ($latch['braked'] ?? false),
            'queue' => isset($latch['queue']) ? (int) $latch['queue'] : null,
            'target' => $this->settings->int('target_remote_queue'),
            'resume' => $this->settings->int('resume_remote_queue'),
            'recheck_seconds' => $this->settings->int('remote_queue_recheck_seconds'),
            'requeue_seconds' => $this->settings->int('remote_queue_requeue_seconds'),
        ];
    }

    /**
     * Inventario del staging local (fase 1) + presupuesto del tmpfs.
     *
     * La lista de pendientes del dia NO tiene tope; lo que se acota es este
     * inventario (`staging_target_inventory`) y los bytes en RAM disk
     * (`staging_budget_bytes`). Es lo que permite sostener el goteo de CPU.
     *
     * @return array{files:int, bytes:int, bytes_mb:float, target:int, budget_mb:float, enabled:bool, ttl_minutes:int, pace_seconds:int}
     */
    private function stagingState(): array
    {
        $inventory = $this->submitService->stagedInventory();
        $budgetBytes = max(0, $this->settings->int('staging_budget_bytes'));

        return [
            'files' => $inventory['files'],
            'bytes' => $inventory['bytes'],
            'bytes_mb' => round($inventory['bytes'] / 1048576, 1),
            'target' => $this->settings->int('staging_target_inventory'),
            'budget_mb' => round($budgetBytes / 1048576, 1),
            'enabled' => $this->settings->bool('staging_enabled'),
            'ttl_minutes' => $this->settings->int('staging_ttl_minutes'),
            'pace_seconds' => $this->settings->int('staging_pace_seconds'),
        ];
    }

    /**
     * Filas state=pending que ya fueron tomadas por un worker (dispatched_at set)
     * pero estan esperando reintento porque el guard detuvo el envio (remota
     * saturada o /dev/shm bajo). Son el "pool dormido" que se reactivara cuando
     * el guard libere.
     */
    private function requeueableCount(): int
    {
        try {
            return (int) DB::table('transcriptions')
                ->where('state', \App\Models\Transcription::STATE_PENDING)
                ->whereNotNull('dispatched_at')
                ->where(function ($q) {
                    $q->where('requeue_after_at', '>', now())
                      ->orWhereNotNull('regulator_skip_reason');
                })
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Estado del API remoto: queue.queued + queue.processing + metricas de
     * salud accionables del nodo (RAM, ramdisk y disco).
     *
     * La UI del modulo solo muestra las señales que gobiernan el envio; no se
     * transportan CPU ni GPU aunque /api/metrics/overview las exponga, porque
     * en este panel no accionan un freno y agregan ruido operativo.
     *
     * Lectura cacheada por el regulador (misma key `transcriptor:remote_stats:info`,
     * TTL configurable). Si la cache esta vacia (ej: burst daemon no esta
     * corriendo) hace una lectura fresca con TTL corto para que el siguiente
     * page load sea rapido. Si la API tampoco responde, devuelve null y la UI
     * muestra "remota no reachable".
     */
    private function remoteStatus(): ?array
    {
        try {
            $cacheKey = 'transcriptor:remote_stats:info';
            $val = Cache::get($cacheKey);

            if (!is_array($val)) {
                // Cache miss: refrescar con TTL corto (2s) para amortiguar
                // el costo del HTTP en el page load sin quedarnos stale.
                $info = app(\App\Services\Ia\TranscriptorApiClient::class)->getRemoteInfo();
                if (!is_array($info)) {
                    return null;
                }
                Cache::put($cacheKey, $info, 2);
                $val = $info;
            }

            return [
                // --- Cola de trabajo del nodo ASR ---
                'queue_queued' => (int) ($val['queue_queued'] ?? 0),
                'queue_processing' => (int) ($val['processing'] ?? 0),
                'queue_total' => (int) ($val['queue_total'] ?? 0),
                'queue_done_failed' => (int) ($val['queue_done_failed'] ?? 0),
                'queue_done_pending_corrector' => (int) ($val['queue_done_pending_corrector'] ?? 0),
                // --- Salud del nodo remoto (documentado en /api/metrics/overview) ---
                'ram_pct' => (float) ($val['ram_pct'] ?? 0),
                'ram_used_gb' => (float) ($val['ram_used_gb'] ?? 0),
                'ram_total_gb' => (float) ($val['ram_total_gb'] ?? 0),
                'ram_available_gb' => (float) ($val['ram_available_gb'] ?? 0),
                'swap_pct' => (float) ($val['swap_pct'] ?? 0),
                'ramdisk_pct' => (float) ($val['ramdisk_pct'] ?? 0),
                'ramdisk_used_gb' => (float) ($val['ramdisk_used_gb'] ?? 0),
                'ramdisk_free_gb' => (float) ($val['ramdisk_free_gb'] ?? 0),
                'ramdisk_total_gb' => (float) ($val['ramdisk_total_gb'] ?? 0),
                'ramdisk_path' => (string) ($val['ramdisk_path'] ?? ''),
                'ramdisk_ok' => (bool) ($val['ramdisk_ok'] ?? false),
                'disk_pct' => (float) ($val['disk_pct'] ?? 0),
                'disk_free_gb' => (float) ($val['disk_free_gb'] ?? 0),
                'disk_total_gb' => (float) ($val['disk_total_gb'] ?? 0),
                'workers' => (int) ($val['workers'] ?? 0),
                'circuit_open' => (bool) ($val['circuit_open'] ?? false),
                'uptime_seconds' => (int) ($val['uptime_seconds'] ?? 0),
                'node_id' => (string) ($val['node_id'] ?? ''),
                'cluster_state' => (string) ($val['cluster_state'] ?? ''),
                'fetched_at' => $val['fetched_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tasa de submission_committed_at en los ultimos 60 s. Es la metrica mas
     * cercana a "trabajos/segundo que estan llegando a la API remota".
     */
    private function throughputPerMin(): int
    {
        try {
            return (int) DB::table('transcriptions')
                ->whereNotNull('submission_committed_at')
                ->where('submission_committed_at', '>=', now()->subSeconds(60))
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function queueDepth(): ?int
    {
        try {
            return (int) DB::table('transcriptions')
                ->where('state', \App\Models\Transcription::STATE_PENDING)
                ->whereNull('dispatched_at')
                ->where('created_at', '>=', BogotaTime::todayStart())
                ->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Conteo historico por estado (toda la tabla). Se conserva porque varias
     * vistas y specs lo consumen como "estado por BD (resumen)".
     *
     * NO usar para "hechos hoy" ni "pendientes de hoy": para eso estan
     * `today` (disco + done) y `stateCountsToday()` (fila creada hoy).
     *
     * @return array<string,int>
     */
    private function stateCounts(): array
    {
        try {
            return Transcription::selectRaw('state, count(*) as count')
                ->groupBy('state')
                ->pluck('count', 'state')
                ->map(fn ($v) => (int) $v)
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Conteo por estado de las transcripciones CREADAS hoy (America/Bogota).
     *
     * Diferencia con `today`: este mira la fila de `transcriptions.created_at`
     * (lo que el discovery registro hoy) y no el archivo en disco. Sirve para
     * responder "de lo que descubri hoy, cuanto ya esta listo" sin arrastrar
     * el historico de 320k filas `done` que la UI mostraba como de hoy.
     *
     * @return array<string,int>
     */
    private function stateCountsToday(): array
    {
        try {
            $todayStart = BogotaTime::todayStart();

            return Transcription::selectRaw('state, count(*) as count')
                ->where('created_at', '>=', $todayStart)
                ->groupBy('state')
                ->pluck('count', 'state')
                ->map(fn ($v) => (int) $v)
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Workers realmente activos, contando el pool permitido y el prohibido por
     * separado. Que aparezcan huerfanos es justo lo que hay que poder ver.
     */
    /**
     * Estado del pool de workers del pipeline.
     *
     * Desde el cutover a la cola nativa PG, los que DRENAN trabajo son los
     * procesos supervisord `transcription:worker` (polean `transcriptions` con
     * FOR UPDATE SKIP LOCKED). Las units systemd
     * `tcloud-transcription-batch-*` siguen existiendo pero corren
     * `queue:work --queue=transcription` sobre la cola Redis legacy, que quedo
     * vacia: sus procesos viven pero no procesan nada.
     *
     * La UI mostraba "12/12 activos" leyendo solo las units legacy, dando a
     * entender un pool sano cuando el pool real era de 2. Se reportan los dos
     * por separado y `active`/`installed` apuntan al pool REAL (PG).
     */
    private function workerState(): array
    {
        $pg = $this->pgWorkerProcesses();

        $legacyInstalled = glob('/etc/systemd/system/tcloud-transcription-batch-*.service') ?: [];
        $legacyActive = 0;
        foreach ($legacyInstalled as $path) {
            $unit = basename($path);
            if (trim((string) @shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) === 'active') {
                $legacyActive++;
            }
        }

        $orphans = 0;
        $out = @shell_exec("systemctl list-units 'tcloud-transcription-worker@*.service' --all --plain --no-legend 2>/dev/null");
        if ($out) {
            foreach (preg_split('/\R/', trim($out)) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (($cols[2] ?? '') === 'active') {
                    $orphans++;
                }
            }
        }

        return [
            'active' => $pg['running'],
            'installed' => max(1, $this->settings->int('supervisor_numprocs')),
            'pg_running' => $pg['running'],
            'pg_total' => $pg['total'],
            'orphans' => $orphans,
            'override' => $this->settings->int('worker_override'),
            // Legacy (Redis): informativo. Si hay unidades activas pero
            // `legacy_pending_jobs` es 0, son procesos sin trabajo.
            'legacy_active' => $legacyActive,
            'legacy_installed' => count($legacyInstalled),
            'legacy_pending_jobs' => $this->legacyQueueDepth(),
        ];
    }

    /**
     * Cuenta los procesos `transcription:worker` vivos y cuantos declara el
     * supervisor. Se usa `ps` y no `supervisorctl` porque el proyecto no
     * expone el socket de supervisor al usuario de PHP-FPM.
     *
     * @return array{running:int, total:int}
     */
    private function pgWorkerProcesses(): array
    {
        $out = @shell_exec("ps -eo args 2>/dev/null | grep -c '[t]ranscription:worker'");

        return [
            'running' => max(0, (int) trim((string) $out)),
            'total' => max(1, $this->settings->int('supervisor_numprocs')),
        ];
    }

    /**
     * Profundidad de la cola Redis legacy (`queues:transcription`). Sirve para
     * evidenciar que las units legacy ya no tienen trabajo en la cola nativa PG.
     */
    private function legacyQueueDepth(): ?int
    {
        try {
            $len = \Illuminate\Support\Facades\Redis::connection('default')
                ->llen('queues:transcription');

            return is_int($len) ? $len : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function lastLine(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return null;
        }

        return (string) end($lines);
    }

    private function lastJsonLine(string $path): ?array
    {
        $line = $this->lastLine($path);
        if ($line === null) {
            return null;
        }

        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : null;
    }
}
