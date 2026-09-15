<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Services\Ia\CacheEpoch;
use App\Services\Ia\StorageFunnelService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $descendantCounts[$s->id] = count($descendants);
            $parentScopeByStorage[$s->id] = empty($descendants) ? null : (int) $s->id;
            if (!empty($descendants)) {
                $descendantNames[$s->id] = StorageProvider::whereIn('id', $descendants)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();
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
