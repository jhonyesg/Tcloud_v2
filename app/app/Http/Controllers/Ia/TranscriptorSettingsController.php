<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Concerns\RunsBackgroundCommands;
use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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

    public function __construct(private TranscriptorSettings $settings) {}

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
     * efecto sin esperar al scheduler. En modo simulacion no escribe en Redis.
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

        $this->execBackground($cmd);

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
        $queueDepth = $this->queueDepth();

        return [
            'queue_depth' => $queueDepth,
            'queue_target' => $this->settings->int('target_redis_queue'),
            // Lo que el regulador haria ahora mismo. Es la respuesta directa a
            // "cuanto va a enviar la proxima vez".
            'next_batch' => $queueDepth === null ? null : $this->settings->computeDispatchBatch($queueDepth),
            'paused' => $this->settings->bool('dispatch_paused'),
            'tick_interval_minutes' => $this->settings->int('tick_interval_minutes'),
            'tick_last_run' => Cache::get('transcriptor:tick:last_run'),
            'states' => $this->stateCounts(),
            'workers' => $this->workerState(),
            'tune_last' => $this->lastJsonLine(storage_path('logs/transcription-tune.log')),
            'tick_last' => $this->lastLine(storage_path('logs/transcription-tick.log')),
        ];
    }

    private function queueDepth(): ?int
    {
        try {
            return (int) Redis::llen('queues:transcription');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string,int> */
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
     * Workers realmente activos, contando el pool permitido y el prohibido por
     * separado. Que aparezcan huerfanos es justo lo que hay que poder ver.
     */
    private function workerState(): array
    {
        $installed = glob('/etc/systemd/system/tcloud-transcription-batch-*.service') ?: [];

        $active = 0;
        foreach ($installed as $path) {
            $unit = basename($path);
            if (trim((string) @shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) === 'active') {
                $active++;
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
            'active' => $active,
            'installed' => count($installed),
            'orphans' => $orphans,
            'override' => $this->settings->int('worker_override'),
        ];
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
