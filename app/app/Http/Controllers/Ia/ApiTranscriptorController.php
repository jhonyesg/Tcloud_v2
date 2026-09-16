<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Concerns\RunsBackgroundCommands;
use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\CacheEpoch;
use App\Services\Ia\DiskScannerService;
use App\Services\Ia\StorageFunnelService;
use App\Services\Ia\TranscriptionBulkDispatchService;
use App\Services\Ia\TranscriptorSettings;
use App\Services\Ia\TranscriptorWorkEstimator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * API Transcriptor — módulo UI simplificado a Storages + Configuración.
 *
 * Responsabilidades que conserva este controller:
 *  - Servir la vista principal `ia.api-transcriptor.index` con dos pestañas.
 *  - Toggle por storage de `transcription_enabled` (única escritura del módulo).
 *  - Invalidar caches que SÍ tienen consumidores fuera de este módulo
 *    (admin:storages:index y CacheEpoch) tras un toggle.
 *
 * NO conserva (todo lo absorbieron los comandos `transcription:*` o el widget
 * global de bg-jobs):
 *  - Listado de Trabajos, sub-tabs, paginación, búsqueda, filtro de estado.
 *  - "Escanear storages" / openBatchModal / estimateScan / batchStatus.
 *  - Operaciones por job: retry, bulkDispatch, dispatchNow, refreshStatus,
 *    reprocess, cancelJob, unstick, deleteUpstream, destroy, transcribeFile.
 *  - Navegación de archivos por storage (`storageFiles`, `processFolder`,
 *    `processDay`), syncStorage manual.
 *  - Endpoints de observabilidad: stats, health, shmStatus, latency,
 *    regulatorCause, liveConsumption, emptyFolders.
 *  - Sincronización de estado upstream (`syncFromUpstream`): la sigue haciendo
 *    `TranscriptionPollingService` desde el cron `transcription:poll-results`.
 *  - Detalle de un job concreto (`show`, `transcript`): la página
 *    `/ia/api-transcriptor/jobs/{id}` ya no existe.
 *
 * Contrato cross-module (ver `proposal.md` del change
 * `simplify-api-transcriptor-to-storage-and-config`): NINGÚN módulo externo
 * importa este controller ni invoca sus endpoints. La materia prima que
 * consumen Avisos Inteligentes y Correcciones (`Transcription`,
 * `TranscriptionSegment`, `TranscriptionReview`, los servicios
 * `TranscriptionProcessor`/`TranscriptionPollingService`/...) sigue escrita
 * desde el backend, NO desde aquí.
 */
class ApiTranscriptorController extends Controller
{
    use RunsBackgroundCommands;

    public function __construct(
        private TranscriptorSettings $settings,
        private StorageFunnelService $funnel,
    ) {}

    /**
     * GET /ia/api-transcriptor — vista principal con dos pestañas.
     * GET /ia/api-transcriptor (wantsJson) — payload usado por el
     * primer render del cliente (cf. `is:html=false` en el JSON del módulo).
     */
    public function index(Request $request)
    {
        $data = $this->indexData();

        if ($request->wantsJson()) {
            return response()->json($data);
        }
        return view('ia.api-transcriptor.index', $data);
    }

    /**
     * Datos del tab Storages: lista de storages con su scope heredado
     * y el funnel agregado por scope (pending/done) que sirve de
     * resumen visual sin necesidad de un endpoint separado.
     *
     * El tab Configuración sigue leyendo sus propios datos de
     * `TranscriptorSettingsController::index` (panel independiente).
     */
    private function indexData(): array
    {
        $storages = StorageProvider::select(['id', 'name', 'type', 'transcription_enabled', 'base_path', 'allow_parent_overlap', 'transcription_priority'])
            ->orderByRaw('transcription_enabled DESC')
            ->orderBy('name')
            ->get();

        $cantidadByStorage = [];
        foreach ($storages as $s) {
            $cantidadByStorage[$s->id] = $this->funnel->cantidadFor((int) $s->id);
        }

        $descendantCounts = [];
        $descendantNames = [];
        $parentScopeByStorage = [];
        $funnelByStorage = [];
        $processedRoots = [];
        foreach ($storages as $s) {
            $inheritedScope = StorageProvider::resolveInheritedTranscriptionScope($s->id);
            $descendants = array_values(array_diff($inheritedScope, [$s->id]));
            $isRoot = !empty($descendants);
            $descendantCounts[$s->id] = count($descendants);
            if ($isRoot) {
                $rootScopeId = (int) $s->id;
                $parentScopeByStorage[$s->id] = $rootScopeId;
                foreach ($descendants as $descId) {
                    if (!isset($parentScopeByStorage[$descId])) {
                        $parentScopeByStorage[$descId] = $rootScopeId;
                    }
                }
                $descendantNames[$s->id] = StorageProvider::whereIn('id', $descendants)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();
            } else {
                if (!isset($parentScopeByStorage[$s->id])) {
                    $parentScopeByStorage[$s->id] = null;
                }
            }
            $rootId = $s->transcription_enabled ? $this->funnel->resolveRootIdFor((int) $s->id) : null;
            if ($rootId !== null && !isset($processedRoots[$rootId])) {
                $scopeCounts = $this->funnel->countsForScope($rootId);
                $funnelByStorage = $funnelByStorage + $scopeCounts;
                $processedRoots[$rootId] = true;
            }
        }

        foreach ($storages as $s) {
            $scope = StorageProvider::resolveInheritedTranscriptionScope((int) $s->id);
            if (count($scope) <= 1) {
                continue;
            }
            $agg = ['pending' => 0, 'done' => 0];
            foreach ($scope as $sid) {
                if (isset($funnelByStorage[$sid])) {
                    $agg['pending'] += (int) ($funnelByStorage[$sid]['pending'] ?? 0);
                    $agg['done'] += (int) ($funnelByStorage[$sid]['done'] ?? 0);
                }
            }
            $funnelByStorage[$s->id] = $agg;
        }

        $overlapParents = [];
        $overlapRoots = StorageProvider::query()
            ->where('allow_parent_overlap', true)
            ->where('transcription_enabled', true)
            ->pluck('id')
            ->all();
        foreach ($overlapRoots as $rid) {
            $scope = StorageProvider::resolveInheritedTranscriptionScope((int) $rid);
            if (count($scope) > 1) {
                $overlapParents[(int) $rid] = true;
            }
        }

        $storages = $storages->map(function ($s) use ($descendantCounts, $descendantNames, $parentScopeByStorage, $funnelByStorage, $overlapParents, $cantidadByStorage) {
            $s->descendant_count = $descendantCounts[$s->id] ?? 0;
            $s->descendant_names = $descendantNames[$s->id] ?? [];
            $s->parent_scope_id = $parentScopeByStorage[$s->id] ?? null;
            $s->funnel = $funnelByStorage[$s->id] ?? ['pending' => 0, 'done' => 0];
            $s->overlap_warning = isset($overlapParents[(int) $s->id]);
            $s->cantidad = $cantidadByStorage[$s->id] ?? 1;
            return $s;
        });

        return [
            'storages' => $storages,
        ];
    }

    /**
     * Enciende o apaga la transcripción de un storage.
     *
     * `storage_providers.transcription_enabled` es la bandera AUTORITATIVA del
     * pipeline: la leen DiskScannerService (qué recorrer), TranscriptionTune
     * (cuántos workers) y el panel de Configuración. Se escribe aquí y solo aquí.
     *
     * Historia: entre el 2026-08-18 y el 2026-08-20 esta bandera fue un valor
     * derivado de `user_storages.transcription_enabled` y el control se mudó a
     * Avisos Inteligentes. Fue un error de acoplamiento — API Transcriptor es un
     * módulo independiente; Avisos y Correcciones consumen el contenido que este
     * produce, no deciden qué se produce — y además costó una caída de 44 horas
     * cuando el pivote quedó vacío. La derivación se retiró.
     */
    public function toggleStorage(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $request->validate([
            'transcription_enabled' => 'required|boolean',
        ]);

        $antes = (bool) $storage->transcription_enabled;
        $ahora = $request->boolean('transcription_enabled');

        if ($antes !== $ahora) {
            $storage->update(['transcription_enabled' => $ahora]);

            Log::info('ApiTranscriptor: transcripción de storage cambiada', [
                'storage_id' => $storage->id,
                'storage' => $storage->name,
                'de' => $antes,
                'a' => $ahora,
                'user_id' => Session::get('user_id'),
            ]);

            $rootId = $this->funnel->resolveRootIdFor((int) $storage->id);
            $this->funnel->invalidate($rootId);

            StorageProvider::forgetInheritedTranscriptionScope((int) $rootId);

            // Caches con consumidores fuera de este módulo (admin storages + Avisos):
            Cache::forget('admin:storages:index');
            CacheEpoch::bump();
        }

        return response()->json($storage->only(['id', 'name', 'transcription_enabled']));
    }

    /**
     * POST /ia/api-transcriptor/jobs/bulk-dispatch
     *
     * Body: { "ids": [int, int, ...] } (opcional). Si vacio, auto-selecciona
     * hasta 2000 pendientes sin dispatched_at con recorded_at >= hoy.
     *
     * Respuesta: { "enqueued": int, "skipped_queued": int, "errors": int }.
     *
     * Mantiene la firma externa del spec `transcriptor-bulk-pg-dispatch`
     * (transcriptor-pg-native-queue).
     */
    public function bulkDispatch(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'sometimes|array',
            'ids.*' => 'integer|min:1',
        ]);

        $ids = $validated['ids'] ?? [];

        $stats = app(TranscriptionBulkDispatchService::class)->dispatch($ids);

        return response()->json($stats);
    }

    /**
     * POST /ia/api-transcriptor/scan/estimate
     *
     * Cuenta el trabajo disponible en un alcance para el modal de
     * "Procesamiento personalizado". SOLO LEE: no crea filas ni encola nada.
     *
     * Body: { scope: 'today'|'range'|'all', from?: 'YYYY-MM-DD', to?: 'YYYY-MM-DD',
     *         storage_ids?: int[] }
     */
    public function estimateScan(Request $request)
    {
        $validated = $request->validate([
            'scope' => 'required|in:today,range,all',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'storage_ids' => 'sometimes|array',
            'storage_ids.*' => 'integer|min:1',
        ]);

        try {
            $scope = $this->resolveScanScope(
                $validated['scope'],
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $estimator = app(TranscriptorWorkEstimator::class);
        $estimate = $estimator->estimate($scope, $validated['storage_ids'] ?? []);

        return response()->json($estimate);
    }

    /**
     * POST /ia/api-transcriptor/scan/run
     *
     * Lanza el descubrimiento + encolado en background y devuelve un runId para
     * que la UI haga polling. Cubre los tres alcances (hoy / rango / histórico)
     * y los tres tipos de trabajo del modal:
     *   - `--include-failed`  reencola las que están en error
     *   - `--include-done`    reprocesa las ya finalizadas
     *   - (por defecto)       descubre archivos SIN fila del alcance
     *
     * El envío sigue regulado por el tick/worker PG: este botón DESCUBRE y
     * ENCOLA, no salta el regulador.
     */
    public function runScan(Request $request)
    {
        $validated = $request->validate([
            'scope' => 'required|in:today,range,all',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'batch' => 'nullable|integer|min:1|max:200',
            'include_failed' => 'boolean',
            'include_done' => 'boolean',
            'generate_alerts' => 'boolean',
            'purge_queue' => 'boolean',
        ]);

        $scopeMode = $validated['scope'];
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        try {
            $this->resolveScanScope($scopeMode, $from, $to);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Guard de concurrencia: dos corridas simultáneas sobre el histórico
        // duplicarían ffmpeg y saturarían el host (incidente 2026-09-16).
        $lockKey = 'transcriptor:scan_run:lock';
        if (Cache::has($lockKey)) {
            return response()->json([
                'error' => 'Ya hay un procesamiento en curso. Espera a que termine antes de lanzar otro.',
            ], 409);
        }

        $runId = 'scan_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
        $cacheKey = 'transcription_batch:' . $runId;
        $batch = (int) ($validated['batch'] ?? 0);

        $args = ['transcription:scan-and-submit'];
        if ($scopeMode === 'today') {
            $args[] = '--days=0';
        } elseif ($scopeMode === 'range') {
            $args[] = '--from=' . $this->toDmY((string) $from);
            $args[] = '--to=' . $this->toDmY((string) ($to ?? $from));
        } else {
            $args[] = '--all';
        }
        if ($batch > 0) {
            $args[] = '--batch=' . $batch;
        }
        if (($validated['include_failed'] ?? false) === true) {
            $args[] = '--include-failed';
        }
        if (($validated['include_done'] ?? false) === true) {
            $args[] = '--include-done';
        }
        if (array_key_exists('generate_alerts', $validated)) {
            $args[] = '--alerts=' . ($validated['generate_alerts'] ? '1' : '0');
        }
        $args[] = '--run-id=' . $runId;

        $cmd = implode(' ', array_map('escapeshellarg', $args));

        // Estado inicial para que la UI muestre algo de inmediato.
        Cache::put($cacheKey, [
            'status' => 'starting',
            'scan_scope' => $scopeMode,
            'batch' => $batch,
            'processed' => 0,
            'errors' => 0,
            'total_to_process' => 0,
            'total_candidates' => 0,
            'pending_created' => 0,
            'dispatched' => 0,
            'storages' => [],
            'files' => [],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        // TTL del lock: la corrida del comando es acotada (MAX_BATCHES_PER_RUN
        // del stager y batch por storage del scanner), pero el histórico puede
        // tardar. 2 h es el mismo TTL del resultado.
        Cache::put($lockKey, $runId, now()->addHours(2));

        $logFile = storage_path('logs/transcription-scan-' . $runId . '.log');
        $ok = $this->execBackground($cmd, 'transcriptor:scan', $logFile, $cacheKey);

        if (!$ok) {
            Cache::forget($lockKey);
            Cache::put($cacheKey, [
                'status' => 'error',
                'message' => 'No se pudo iniciar el proceso (sintaxis del comando inválida).',
                'processed' => 0,
                'errors' => 1,
                'storages' => [],
                'files' => [],
                'finished_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ], now()->addHours(2));

            return response()->json(['error' => 'No se pudo iniciar el proceso.'], 500);
        }

        Log::info('ApiTranscriptor: procesamiento personalizado lanzado', [
            'run_id' => $runId,
            'scope' => $scopeMode,
            'from' => $from,
            'to' => $to,
            'include_failed' => (bool) ($validated['include_failed'] ?? false),
            'include_done' => (bool) ($validated['include_done'] ?? false),
            'user_id' => Session::get('user_id'),
        ]);

        return response()->json([
            'run_id' => $runId,
            'scope' => $scopeMode,
            'message' => 'Procesamiento iniciado en background.',
        ], 202);
    }

    /**
     * GET /ia/api-transcriptor/scan/status/{runId}
     *
     * Estado en vivo de una corrida lanzada por `runScan` (polling cada 2 s).
     */
    public function scanStatus(string $runId)
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $runId);
        $data = Cache::get('transcription_batch:' . $safe);

        if (!$data) {
            // Sin estado: la corrida expiró o crasheó. Liberar el candado para
            // que el operador no quede bloqueado 2 h por una corrida muerta.
            Cache::forget('transcriptor:scan_run:lock');

            return response()->json(['status' => 'not_found'], 404);
        }

        // Estado terminal: el comando ya terminó, liberar el candado de
        // concurrencia para permitir la siguiente corrida.
        if (in_array($data['status'] ?? '', ['queued', 'partial', 'error', 'done', 'completed'], true)) {
            Cache::forget('transcriptor:scan_run:lock');
        }

        return response()->json($data);
    }

    /**
     * Traduce el alcance del modal a la estructura que entiende el scanner.
     *
     * @throws \InvalidArgumentException rango inválido o fechas faltantes
     */
    private function resolveScanScope(string $mode, ?string $fromIso, ?string $toIso): array
    {
        if ($mode === 'all') {
            return DiskScannerService::scopeAll();
        }

        if ($mode === 'today') {
            return DiskScannerService::scopeToday();
        }

        if ($fromIso === null || $toIso === null) {
            throw new \InvalidArgumentException('El alcance por rango requiere fecha inicial y final.');
        }

        return DiskScannerService::scopeRange(
            $this->toDmY($fromIso),
            $this->toDmY($toIso),
        );
    }

    /** YYYY-MM-DD (input date del navegador) -> DDMMYYYY (nombre de carpeta). */
    private function toDmY(string $isoDate): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);
        if (!$d || $d->format('Y-m-d') !== $isoDate) {
            throw new \InvalidArgumentException("Fecha inválida: {$isoDate} (se espera YYYY-MM-DD)");
        }

        return $d->format('dmY');
    }

    /**
     * GET /ia/api-transcriptor/storages/{id}/snapshot
     *
     * Devuelve el ultimo snapshot del storage + delta vs el anterior.
     * Respeta privacidad: cliente solo ve campos propios.
     */
    public function storageSnapshot(int $id)
    {
        $storage = StorageProvider::find($id);
        if (!$storage) {
            return response()->json(['error' => 'storage_not_found'], 404);
        }

        $isCliente = (Session::get('user')['role'] ?? null) === 'cliente';
        if ($isCliente && !$this->clientePuedeVer($storage)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $current = DB::table('transcription_storage_snapshots')
            ->where('storage_provider_id', $id)
            ->orderByDesc('captured_at')
            ->first();

        $previous = DB::table('transcription_storage_snapshots')
            ->where('storage_provider_id', $id)
            ->where('captured_at', '<', $current->captured_at ?? '1970-01-01')
            ->orderByDesc('captured_at')
            ->first();

        $payload = [
            'storage_provider_id' => $id,
            'current' => $current ?: null,
            'previous' => $previous ?: null,
            'delta' => ($current && $previous)
                ? ['pending_count' => (int) ($current->pending_count - $previous->pending_count), 'since' => 'snapshot anterior']
                : null,
        ];

        if ($isCliente) {
            // Privacidad: cliente solo ve campos publicos.
            $payload = [
                'storage_provider_id' => $id,
                'current' => $current ? [
                    'captured_at' => $current->captured_at,
                    'pending_count' => (int) $current->pending_count,
                    'inflight_count' => (int) $current->inflight_count,
                ] : null,
            ];
        }

        return response()->json($payload);
    }

    private function clientePuedeVer(StorageProvider $storage): bool
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return false;
        }

        return DB::table('user_storages')
            ->where('user_id', $userId)
            ->where('storage_provider_id', $storage->id)
            ->exists();
    }

    public static function stateClass(string $state): string
    {
        return [
            'pending' => 'bg-slate-200 text-slate-700',
            'queued' => 'bg-slate-100 text-slate-600',
            'processing' => 'bg-blue-100 text-blue-700',
            'done' => 'bg-green-100 text-green-700',
            'error' => 'bg-red-100 text-red-700',
            'dead' => 'bg-red-900 text-red-100',
        ][$state] ?? 'bg-slate-100 text-slate-600';
    }

    public static function stateDot(string $state): string
    {
        return [
            'pending' => 'bg-slate-500',
            'queued' => 'bg-slate-400',
            'processing' => 'bg-blue-500',
            'done' => 'bg-green-500',
            'error' => 'bg-red-500',
            'dead' => 'bg-red-300',
        ][$state] ?? 'bg-slate-400';
    }
}
