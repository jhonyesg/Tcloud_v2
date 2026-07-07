<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Jobs\ConvertAndTranscribeJob;
use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class ApiTranscriptorController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json($this->indexData($request));
        }
        return view('ia.api-transcriptor.index', $this->indexData($request));
    }

    private function indexData(Request $request): array
    {
        $query = Transcription::with('file:id,name,storage_provider_id')
            ->orderByDesc('created_at');

        if ($search = trim((string) $request->input('q', ''))) {
            $query->whereHas('file', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
            });
        }

        if ($state = $request->input('state')) {
            $query->where('state', $state);
        }

        $jobs = $query->limit(200)->get();

        // Habilitados primero, luego por prioridad descendente y nombre.
        $storages = StorageProvider::select(['id', 'name', 'type', 'transcription_enabled', 'transcription_priority'])
            ->orderByRaw('transcription_enabled DESC')
            ->orderByDesc('transcription_priority')
            ->orderBy('name')
            ->get();

        return ['jobs' => $jobs, 'storages' => $storages, 'filters' => [
            'q' => $search,
            'state' => $state ?? '',
        ]];
    }

    public function show(int $id)
    {
        $job = Transcription::with('file', 'segments')->findOrFail($id);
        return view('ia.api-transcriptor.job-detail', ['job' => $job]);
    }

    public function retry(int $id)
    {
        $job = Transcription::findOrFail($id);

        if (!in_array($job->state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
            return response()->json(['error' => 'Solo se reintentan jobs en error/dead'], 409);
        }

        $fileId = $job->file_id;
        $job->delete();

        // Ejecutar síncronamente (sin depender de queue worker).
        set_time_limit(600);
        try {
            $jobInstance = new ConvertAndTranscribeJob($fileId);
            $reflection = new \ReflectionMethod($jobInstance, 'handle');
            $reflection->setAccessible(true);
            $reflection->invoke($jobInstance,
                app(\App\Services\Ia\AudioConverter::class),
                app(\App\Services\Ia\TranscriptorApiClient::class)
            );
        } catch (\Throwable $e) {
            $t = Transcription::where('file_id', $fileId)->first();
            if ($t) {
                $t->update([
                    'state' => Transcription::STATE_ERROR,
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $transcription = Transcription::where('file_id', $fileId)->first();

        return response()->json([
            'message' => 'Job reprocesado',
            'transcription_id' => $transcription?->id,
            'state' => $transcription?->state,
            'job_id' => $transcription?->job_id,
        ]);
    }

    /**
     * Reprocesa un job de forma INMEDIATA (síncrona) sin importar su estado.
     * Borra la transcripción actual (y sus segmentos) y ejecuta el job completo
     * (ffmpeg + envío a la API) devolviendo el nuevo estado. Usado por el botón
     * "Reprocesar" de la UI tanto en pendientes como en completados.
     */
    public function reprocess(Request $request, int $id)
    {
        $job = Transcription::with('file')->findOrFail($id);
        $file = $job->file;
        $generateAlerts = (bool) $request->input('generate_alerts', $job->generate_alerts ?? true);

        if (!$file) {
            return response()->json(['error' => 'Archivo asociado no existe'], 422);
        }

        // Si el job ya tiene job_id en la API externa, intentar cancelarlo allí
        // para no dejar jobs zombies (best-effort).
        if (!empty($job->job_id) && in_array($job->state, [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)) {
            try {
                $client = app(TranscriptorApiClient::class);
                Http::timeout(10)->withHeaders($client->authHeadersPublic())
                    ->post($client->getBaseUrl() . '/v1/jobs/' . $job->job_id . '/cancel');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug("reprocess cancel upstream {$job->id}: {$e->getMessage()}");
            }
        }

        // Borrar la transcripción actual y sus segmentos.
        $fileId = $file->id;
        $job->segments()->delete();
        $job->delete();

        // Ejecutar el job síncrono.
        set_time_limit(600);
        $progressKey = null;
        try {
            $jobInstance = new ConvertAndTranscribeJob($fileId, $generateAlerts);
            $reflection = new \ReflectionMethod($jobInstance, 'handle');
            $reflection->setAccessible(true);
            $reflection->invoke($jobInstance,
                app(\App\Services\Ia\AudioConverter::class),
                app(\App\Services\Ia\TranscriptorApiClient::class)
            );
        } catch (\Throwable $e) {
            $t = Transcription::where('file_id', $fileId)->first();
            if ($t) {
                $t->update([
                    'state' => Transcription::STATE_ERROR,
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }
            return response()->json([
                'error' => $e->getMessage(),
                'file_id' => $fileId,
            ], 500);
        }

        $transcription = Transcription::where('file_id', $fileId)->first();

        return response()->json([
            'message' => 'Job reprocesado',
            'file_id' => $fileId,
            'file_name' => $file->name,
            'transcription_id' => $transcription?->id,
            'state' => $transcription?->state,
            'job_id' => $transcription?->job_id,
        ], 200);
    }

    /**
     * Cancela un job pendiente: cancela en la API externa (si tiene job_id y
     * está queued) y marca la transcripción local como error/cancelado.
     */
    public function cancelJob(int $id)
    {
        $job = Transcription::findOrFail($id);

        if (!in_array($job->state, [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)) {
            return response()->json(['error' => 'Solo se cancelan jobs pendientes'], 409);
        }

        $errorMsg = 'Cancelado manualmente';

        // Cancelar en la API externa si tiene job_id y está queued.
        if (!empty($job->job_id) && $job->state === Transcription::STATE_QUEUED) {
            try {
                $client = app(TranscriptorApiClient::class);
                $resp = Http::timeout(10)->withHeaders($client->authHeadersPublic())
                    ->post($client->getBaseUrl() . '/v1/jobs/' . $job->job_id . '/cancel');
                if ($resp->ok()) {
                    $errorMsg = 'Cancelado en la API externa';
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug("cancelJob upstream {$job->id}: {$e->getMessage()}");
            }
        }

        $job->update([
            'state' => Transcription::STATE_ERROR,
            'error_message' => $errorMsg,
            'finished_at' => now(),
        ]);

        return response()->json(['message' => 'Job cancelado', 'state' => $job->state]);
    }

    public function destroy(int $id)
    {
        $job = Transcription::findOrFail($id);
        $job->delete();
        return response()->json(['message' => 'Transcripción eliminada']);
    }

    public function toggleStorage(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $request->validate([
            'transcription_enabled' => 'nullable|boolean',
            'transcription_priority' => 'nullable|integer|min:0',
        ]);

        $data = [];
        if ($request->has('transcription_enabled')) {
            $data['transcription_enabled'] = $request->boolean('transcription_enabled');
        }
        if ($request->has('transcription_priority')) {
            $data['transcription_priority'] = (int) $request->input('transcription_priority');
        }

        $storage->update($data);

        return response()->json($storage->only(['id', 'name', 'transcription_enabled', 'transcription_priority']));
    }

    /**
     * Navega el árbol de carpetas de un storage.
     *  - Sin parent y sin mode=today: lista carpetas raíz + archivos raíz.
     *  - parent=<id>: lista el contenido de esa carpeta (subcarpetas + archivos).
     *  - mode=today: lista los archivos modificados hoy (recursivo, sin navegación).
     *  - q=<texto>: búsqueda global de archivos en el storage por nombre.
     */
    public function storageFiles(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $mode = $request->input('mode', 'browse');
        $parentId = $request->input('parent'); // null = raíz
        if ($parentId === '' || $parentId === 'null') $parentId = null;
        $parentId = $parentId !== null ? (int) $parentId : null;
        $search = trim((string) $request->input('q', ''));
        $limit = max(1, min(500, (int) $request->input('limit', 500)));

        $breadcrumb = [];
        $folders = collect();
        $files = collect();

        // Búsqueda global por nombre (agrupado por carpeta)
        if ($search !== '') {
            $files = File::where('storage_provider_id', $id)
                ->where('is_folder', false)
                ->where('name', 'ilike', "%{$search}%")
                ->orderByDesc('file_modified_at')
                ->limit($limit)
                ->get(['id', 'name', 'size', 'file_modified_at', 'parent_id']);
        } elseif ($mode === 'today' || $mode === 'yesterday') {
            // Carpeta del día (nombre = dmY, ej. "06072026"). Selecciona la carpeta
            // principal del día: la que tiene el archivo modificado más recientemente
            // (la activa, la que usa el grabador ahora).
            $date = $mode === 'today' ? now() : now()->subDay();
            $folderName = $date->format('dmY');

            // 1) Listar carpetas con ese nombre en el storage
            $dayFolders = File::where('storage_provider_id', $id)
                ->where('is_folder', true)
                ->where('name', $folderName)
                ->get(['id', 'parent_id']);

            if ($dayFolders->isNotEmpty()) {
                // 2) Escoger la carpeta que tenga el archivo con file_modified_at más reciente
                $topFile = File::where('storage_provider_id', $id)
                    ->where('is_folder', false)
                    ->whereIn('parent_id', $dayFolders->pluck('id'))
                    ->orderByDesc('file_modified_at')
                    ->first(['parent_id']);

                $dayFolderId = $topFile?->parent_id ?? $dayFolders->first()->id;

                $files = File::where('storage_provider_id', $id)
                    ->where('is_folder', false)
                    ->where('parent_id', $dayFolderId)
                    ->limit(2000)
                    ->get(['id', 'name', 'size', 'file_modified_at', 'parent_id']);

                $files = $files->sortByDesc(function ($f) {
                    return self::extractMilitaryTime((string) $f->name);
                })->take($limit)->values();
            }
        } else {
            // Navegación: subcarpetas + archivos de la carpeta actual
            $folders = File::where('storage_provider_id', $id)
                ->where('is_folder', true)
                ->where('parent_id', $parentId)
                ->orderByDesc('file_modified_at')
                ->orderByDesc('name')
                ->limit($limit)
                ->get(['id', 'name', 'file_modified_at']);

            $files = File::where('storage_provider_id', $id)
                ->where('is_folder', false)
                ->where('parent_id', $parentId)
                ->orderByDesc('file_modified_at')
                ->limit($limit)
                ->get(['id', 'name', 'size', 'file_modified_at']);

            // Construir breadcrumb subiendo por parent_id
            $cur = $parentId;
            while ($cur) {
                $folder = File::where('id', $cur)->where('is_folder', true)->first(['id', 'name', 'parent_id']);
                if (!$folder) break;
                array_unshift($breadcrumb, ['id' => $folder->id, 'name' => $folder->name]);
                $cur = $folder->parent_id;
            }
        }

        // Marca los archivos que ya tienen transcripción.
        $transcribedIds = $files->isNotEmpty()
            ? Transcription::whereIn('file_id', $files->pluck('id'))->pluck('file_id')->toArray()
            : [];

        // Resolver nombres de las carpetas padre (para agrupar en modos today/search).
        $parentIds = $files->pluck('parent_id')->filter()->unique()->values()->all();
        $parentNames = $parentIds ? File::whereIn('id', $parentIds)->pluck('name', 'id') : collect();

        $filesData = $files->map(function ($f) use ($transcribedIds, $parentNames) {
            return [
                'id' => $f->id,
                'name' => $f->name,
                'size' => (int) $f->size,
                'file_modified_at' => $f->file_modified_at?->toIso8601String(),
                'parent_id' => $f->parent_id,
                'folder_name' => $f->parent_id ? ($parentNames[$f->parent_id] ?? null) : null,
                'military_time' => self::extractMilitaryTime((string) $f->name),
                'has_transcription' => in_array($f->id, $transcribedIds, true),
            ];
        });

        // En modo search, agrupar archivos por folder_name (encabezados).
        // today/yesterday devuelven lista plana (ya ordenada por hora militar).
        if ($search !== '') {
            $sorted = $filesData->sortByDesc('file_modified_at')->values();
            $groupedFiles = [];
            foreach ($sorted as $f) {
                $key = $f['folder_name'] ?? '(sin carpeta)';
                $groupedFiles[$key][] = $f;
            }
            $groupOrder = [];
            foreach ($groupedFiles as $key => $items) {
                $groupOrder[$key] = $items[0]['file_modified_at'] ?? '';
            }
            arsort($groupOrder);
            $ordered = [];
            foreach (array_keys($groupOrder) as $key) {
                $ordered[] = ['folder' => $key, 'files' => $groupedFiles[$key]];
            }
            $filesData = $ordered;
        }

        $foldersData = $folders->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'is_folder' => true,
            'file_modified_at' => $f->file_modified_at?->toIso8601String(),
        ]);

        return response()->json([
            'storage' => $storage->only(['id', 'name', 'transcription_enabled']),
            'folders' => $foldersData,
            'files' => $filesData,
            'breadcrumb' => $breadcrumb,
            'current_parent' => $parentId,
            'mode' => $search !== '' ? 'search' : $mode,
            'files_total' => is_array($filesData) ? count($filesData) : $filesData->count(),
            'folders_total' => $foldersData->count(),
            'transcribed_count' => (is_array($filesData) ? collect($filesData) : $filesData)
                ->flatten(1)->where('has_transcription', true)->count(),
        ]);
    }

    /**
     * Escanea un storage: toma los últimos N archivos elegibles (sin transcripción,
     * con file_modified_at estable) y los procesa síncronamente. Devuelve cuántos procesó.
     */
    public function scanStorage(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        $batch = max(1, min(100, (int) $request->input('batch', (int) config('transcriptor.scan_batch', 5))));
        $minAge = (int) config('transcriptor.scan_min_age_seconds', 60);
        $cutoff = now()->subSeconds($minAge);

        $files = File::where('storage_provider_id', $id)
            ->where('is_folder', false)
            ->where('file_modified_at', '<', $cutoff)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transcriptions')
                  ->whereColumn('transcriptions.file_id', 'files.id');
            })
            ->orderByDesc('file_modified_at')
            ->limit($batch)
            ->get();

        $dispatched = 0;
        $errors = 0;
        set_time_limit(max(600, $batch * 120));
        foreach ($files as $file) {
            try {
                $jobInstance = new ConvertAndTranscribeJob($file->id);
                $reflection = new \ReflectionMethod($jobInstance, 'handle');
                $reflection->setAccessible(true);
                $reflection->invoke($jobInstance,
                    app(\App\Services\Ia\AudioConverter::class),
                    app(\App\Services\Ia\TranscriptorApiClient::class)
                );
                $dispatched++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("scanStorage file {$file->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        return response()->json([
            'storage_id' => $storage->id,
            'dispatched' => $dispatched,
            'errors' => $errors,
            'candidates' => $files->count(),
        ]);
    }

    /**
     * Procesa todos los archivos sin transcripción de una carpeta específica.
     * Encola jobs con dispatch() + prioridad manual.
     */
    public function processFolder(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);
        $generateAlerts = (bool) $request->input('generate_alerts', false);
        $parentId = $request->input('parent_id');
        if ($parentId === '' || $parentId === 'null') $parentId = null;
        $parentId = $parentId !== null ? (int) $parentId : null;

        $files = File::where('storage_provider_id', $id)
            ->where('is_folder', false)
            ->where('parent_id', $parentId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transcriptions')
                  ->whereColumn('transcriptions.file_id', 'files.id');
            })
            ->orderByDesc('file_modified_at')
            ->limit(500)
            ->get(['id', 'name']);

        $dispatched = 0;
        foreach ($files as $file) {
            try {
                $priority = ConvertAndTranscribeJob::calculatePriority(
                    (int) $storage->transcription_priority, false, true
                );
                ConvertAndTranscribeJob::dispatchWithPriority($file->id, $generateAlerts, $priority);
                $dispatched++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("processFolder file {$file->id}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'storage_id' => $storage->id,
            'dispatched' => $dispatched,
            'candidates' => $files->count(),
            'generate_alerts' => $generateAlerts,
        ]);
    }

    /**
     * Procesa todos los archivos visibles en modo HOY o AYER.
     */
    public function processDay(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);
        $generateAlerts = (bool) $request->input('generate_alerts', false);
        $mode = $request->input('mode', 'today');

        $date = $mode === 'yesterday' ? now()->subDay() : now();
        $folderName = $date->format('dmY');

        // Encontrar la carpeta del día (misma lógica que storageFiles).
        $dayFolders = File::where('storage_provider_id', $id)
            ->where('is_folder', true)
            ->where('name', $folderName)
            ->get(['id']);

        if ($dayFolders->isEmpty()) {
            return response()->json([
                'storage_id' => $storage->id,
                'dispatched' => 0,
                'candidates' => 0,
                'message' => 'No hay carpeta para ' . $mode,
            ]);
        }

        $topFile = File::where('storage_provider_id', $id)
            ->where('is_folder', false)
            ->whereIn('parent_id', $dayFolders->pluck('id'))
            ->orderByDesc('file_modified_at')
            ->first(['parent_id']);
        $dayFolderId = $topFile?->parent_id ?? $dayFolders->first()->id;

        $files = File::where('storage_provider_id', $id)
            ->where('is_folder', false)
            ->where('parent_id', $dayFolderId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transcriptions')
                  ->whereColumn('transcriptions.file_id', 'files.id');
            })
            ->orderByDesc('file_modified_at')
            ->limit(500)
            ->get(['id', 'name']);

        $isToday = $mode === 'today';
        $dispatched = 0;
        foreach ($files as $file) {
            try {
                $priority = ConvertAndTranscribeJob::calculatePriority(
                    (int) $storage->transcription_priority, $isToday, true
                );
                ConvertAndTranscribeJob::dispatchWithPriority($file->id, $generateAlerts, $priority);
                $dispatched++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("processDay file {$file->id}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'storage_id' => $storage->id,
            'dispatched' => $dispatched,
            'candidates' => $files->count(),
            'mode' => $mode,
            'generate_alerts' => $generateAlerts,
        ]);
    }

    /**
     * Procesamiento por lotes BACKGROUND: lanza el comando artisan
     * transcription:process-batch en un proceso separado (nohup) y devuelve
     * inmediatamente un run_id. El frontend consulta /batch-status/{runId}
     * para ver el progreso en vivo. Así se puede cerrar/recargar la página.
     */
    public function processBatch(Request $request)
    {
        $batch = max(1, min(200, (int) $request->input('batch', 50)));
        $generateAlerts = (bool) $request->input('generate_alerts', false);
        $runId = 'batch_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
        $cacheKey = 'transcription_batch:' . $runId;

        // Estado inicial antes de lanzar el proceso.
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'status' => 'starting',
            'batch' => $batch,
            'processed' => 0,
            'errors' => 0,
            'total_to_process' => 0,
            'total_candidates' => 0,
            'current_index' => 0,
            'current_file' => null,
            'current_storage' => null,
            'storages' => [],
            'files' => [],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        // Lanzar el comando en background con nohup.
        $artisan = base_path('artisan');
        $php = PHP_BINDIR . '/php';
        if (!is_file($php)) $php = 'php';
        $logFile = storage_path('logs/transcription-batch-' . $runId . '.log');
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($artisan)
             . ' transcription:process-batch --batch=' . (int) $batch
             . ' --alerts=' . ($generateAlerts ? 1 : 0)
             . ' --run-id=' . escapeshellarg($runId)
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        try {
            $this->execBackground($cmd);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => 'error',
                'message' => 'No se pudo iniciar el proceso: ' . $e->getMessage(),
                'processed' => 0, 'errors' => 0, 'storages' => [], 'files' => [],
            ], now()->addHours(2));
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'run_id' => $runId,
            'batch' => $batch,
            'message' => 'Lote iniciado en background',
        ], 200);
    }

    /**
     * Estado en vivo de un lote background (polling cada 2s).
     */
    public function batchStatus(string $runId)
    {
        $cacheKey = 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId);
        $data = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$data) {
            return response()->json(['status' => 'not_found', 'message' => 'Lote no encontrado o expirado'], 404);
        }

        return response()->json($data);
    }

    /**
     * Ejecuta un comando en background portable (Linux).
     */
    private function execBackground(string $cmd): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd);
        }
    }

    /**
     * Envío manual de un archivo concreto a transcripción.
     * Crea el job (el job mismo evita duplicados vía firstOrCreate).
     */
    public function transcribeFile(Request $request, int $fileId)
    {
        $file = File::findOrFail($fileId);

        if ($file->is_folder) {
            return response()->json(['error' => 'No se puede transcribir una carpeta'], 422);
        }

        $existing = Transcription::where('file_id', $file->id)->first();
        if ($existing && !in_array($existing->state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
            return response()->json([
                'error' => "Ya existe una transcripción para este archivo (estado: {$existing->state})",
                'transcription_id' => $existing->id,
            ], 409);
        }

        // Si quedó en error/dead, borramos la anterior para reencolar limpio.
        if ($existing) {
            $existing->delete();
        }

        // Envío MANUAL: ejecutar el job SÍNCRONAMENTE para que el modal de progreso
        // muestre los pasos reales (ffmpeg → API) sin esperar al worker.
        // Aumentamos el timeout HTTP del job para esta ejecución.
        set_time_limit(600);
        $progressKey = null;
        try {
            $job = new ConvertAndTranscribeJob($file->id);
            // Invocar el job directamente para recuperar el progress_key
            $reflection = new \ReflectionMethod($job, 'handle');
            $reflection->setAccessible(true);
            $result = $reflection->invoke($job,
                app(\App\Services\Ia\AudioConverter::class),
                app(\App\Services\Ia\TranscriptorApiClient::class)
            );
            $progressKey = $result['progress_key'] ?? null;
        } catch (\Throwable $e) {
            // Registrar el error en la Transcription si existe
            $t = Transcription::where('file_id', $file->id)->first();
            if ($t) {
                $t->update([
                    'state' => Transcription::STATE_ERROR,
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }
            return response()->json([
                'error' => $e->getMessage(),
                'file_id' => $file->id,
                'file_name' => $file->name,
                'progress_key' => $progressKey,
            ], 500);
        }

        $transcription = Transcription::where('file_id', $file->id)->first();

        return response()->json([
            'message' => 'Archivo procesado',
            'file_id' => $file->id,
            'file_name' => $file->name,
            'transcription_id' => $transcription?->id,
            'state' => $transcription?->state,
            'job_id' => $transcription?->job_id,
            'progress_key' => $progressKey,
        ], 200);
    }

    /**
     * Ejecuta SÍNCRONAMENTE un job que está en cola (queued/processing sin job_id
     * o encolado en la API externa). Permite "Enviar ahora" desde la UI sin esperar
     * al worker. Devuelve el estado actualizado para el modal de progreso.
     */
    public function dispatchNow(Request $request, int $id)
    {
        $job = Transcription::with('file')->findOrFail($id);

        if (!in_array($job->state, [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)) {
            return response()->json([
                'error' => "Solo se puede enviar ahora jobs en cola/processing (estado actual: {$job->state})",
            ], 409);
        }

        $file = $job->file;
        if (!$file) {
            return response()->json(['error' => 'Archivo asociado no existe'], 422);
        }

        // Si ya tiene job_id en la API externa, solo refrescar estado (no reenviar).
        if (!empty($job->job_id)) {
            return response()->json([
                'message' => 'El job ya fue enviado a la API externa',
                'transcription_id' => $job->id,
                'state' => $job->state,
                'job_id' => $job->job_id,
                'already_submitted' => true,
            ]);
        }

        // Borrar la transcripción actual (sin job_id) y ejecutar el job síncrono.
        $fileId = $file->id;
        $job->delete();

        set_time_limit(600);
        $progressKey = null;
        try {
            $jobInstance = new ConvertAndTranscribeJob($fileId);
            $reflection = new \ReflectionMethod($jobInstance, 'handle');
            $reflection->setAccessible(true);
            $reflection->invoke($jobInstance,
                app(\App\Services\Ia\AudioConverter::class),
                app(\App\Services\Ia\TranscriptorApiClient::class)
            );
        } catch (\Throwable $e) {
            $t = Transcription::where('file_id', $fileId)->first();
            if ($t) {
                $t->update([
                    'state' => Transcription::STATE_ERROR,
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }
            return response()->json([
                'error' => $e->getMessage(),
                'file_id' => $fileId,
            ], 500);
        }

        $transcription = Transcription::where('file_id', $fileId)->first();

        return response()->json([
            'message' => 'Job enviado a la API externa',
            'file_id' => $fileId,
            'file_name' => $file->name,
            'transcription_id' => $transcription?->id,
            'state' => $transcription?->state,
            'job_id' => $transcription?->job_id,
        ], 200);
    }

    /**
     * Estado en vivo de una transcripción. Usado por el modal de progreso
     * para polling cada 2s.
     *
     * Si el estado local sigue pendiente (queued/processing) y la transcripción
     * ya tiene job_id en la API externa, consulta GET /v1/jobs/{job_id} para
     * recuperar el estado real. Esto cubre el caso en que el webhook no llega
     * (callback_host no alcanzable desde el nodo del transcriptor).
     */
    public function jobStatus(int $id)
    {
        $job = Transcription::with('file:id,name')->findOrFail($id);

        // Polling de respaldo: si seguimos pendientes y hay job_id, consultar la API.
        if (
            in_array($job->state, [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)
            && !empty($job->job_id)
        ) {
            $this->syncFromUpstream($job);
            $job->refresh();
        }

        $segmentsCount = $job->state === Transcription::STATE_DONE
            ? $job->segments()->count()
            : 0;

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'original_name' => $job->original_name ?: ($job->file?->name ?? 'File #' . $job->file_id),
            'job_id' => $job->job_id,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'duration_seconds' => $job->duration_seconds,
            'word_count' => $job->word_count,
            'segments_count' => $segmentsCount,
            'error_message' => $job->error_message,
            'elapsed_seconds' => $job->started_at ? max(0, now()->diffInSeconds($job->started_at, false)) : null,
        ]);
    }

    /**
     * Consulta GET /v1/jobs/{job_id} en la API externa y sincroniza el estado
     * local. Si el job ya terminó (done/error/dead), procesa el resultado.
     * Silencioso: no lanza, solo loguea errores para no romper el polling.
     */
    private function syncFromUpstream(Transcription $transcription): void
    {
        if (empty($transcription->job_id)) return;

        try {
            $client = app(TranscriptorApiClient::class);
            $remote = $client->getJob($transcription->job_id, $transcription->node_url ?? '');
            $state = $remote['state'] ?? $remote['status'] ?? null;

            if ($state === null) return;

            if ($state === Transcription::STATE_DONE) {
                $processor = app(\App\Services\Ia\TranscriptionProcessor::class);
                $processor->processDone($transcription->fresh());
            } elseif (in_array($state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
                $transcription->update([
                    'state' => $state,
                    'error_message' => $remote['error'] ?? ('Estado final: ' . $state),
                    'finished_at' => now(),
                ]);
            } elseif ($state === 'cancelled') {
                $transcription->update([
                    'state' => Transcription::STATE_ERROR,
                    'error_message' => 'Job cancelado en la API externa',
                    'finished_at' => now(),
                ]);
            } elseif ($state === Transcription::STATE_PROCESSING) {
                // Reflejar processing si el nodo ya empezó.
                if ($transcription->state !== Transcription::STATE_PROCESSING) {
                    $transcription->update(['state' => Transcription::STATE_PROCESSING]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug("jobStatus syncFromUpstream {$transcription->id}: {$e->getMessage()}");
        }
    }

    /**
     * Refresca el estado local de un job consultando el transcriptor externo.
     * Pensado para bulk-action de jobs "stuck" (tienen job_id pero el webhook
     * se perdió). Si upstream responde 'done', dispara processDone() que descarga
     * el SRT y popula segments + keyword matching (vía syncFromUpstream).
     */
    public function refreshStatus(int $id)
    {
        $job = Transcription::with('file:id,name')->findOrFail($id);

        if (empty($job->job_id)) {
            return response()->json([
                'error' => 'Este job no tiene job_id asignado; nunca fue enviado al transcriptor. Usá "Enviar ahora" en su lugar.',
            ], 422);
        }

        $this->syncFromUpstream($job);
        $job->refresh();

        $segmentsCount = $job->state === Transcription::STATE_DONE
            ? $job->segments()->count()
            : 0;

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'original_name' => $job->original_name ?: ($job->file?->name ?? 'File #' . $job->file_id),
            'job_id' => $job->job_id,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'duration_seconds' => $job->duration_seconds,
            'word_count' => $job->word_count,
            'segments_count' => $segmentsCount,
            'error_message' => $job->error_message,
            'elapsed_seconds' => $job->started_at ? max(0, now()->diffInSeconds($job->started_at, false)) : null,
        ]);
    }

    /**
     * Progreso en vivo (ffmpeg + submit) de una operación manual.
     * Lee el archivo de cache que escribe AudioConverter.
     */
    public function transcribeProgress(string $key)
    {
        $data = \App\Services\Ia\AudioConverter::readProgress($key);
        if ($data === null) {
            return response()->json(['phase' => 'unknown', 'percent' => 0]);
        }
        return response()->json($data);
    }

    /**
     * Ejecuta el sync del storage local (StorageSyncService) en background para
     * que aparezcan los archivos que el grabador está escribiendo ahora. Solo
     * aplica a storages locales (type=local).
     */
    public function syncStorage(int $id)
    {
        $storage = StorageProvider::findOrFail($id);

        if ($storage->type !== 'local') {
            return response()->json(['error' => 'Solo storages locales soportan sync'], 422);
        }

        try {
            $syncService = app(\App\Services\StorageSyncService::class);
            $stats = $syncService->fullSync($storage, 1);
            return response()->json([
                'message' => 'Sincronización completada',
                'storage_id' => $storage->id,
                'created' => $stats['created'] ?? 0,
                'deleted' => $stats['deleted'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Sync falló: ' . $e->getMessage()], 500);
        }
    }

    public function health(TranscriptorApiClient $client)
    {
        return response()->json($client->getHealth());
    }

    public function stats(TranscriptorApiClient $client)
    {
        $stats = $client->getStats();
        // Contadores locales por estado
        $stats['local'] = Transcription::selectRaw("state, count(*) as count")
            ->groupBy('state')
            ->pluck('count', 'state')
            ->toArray();
        return response()->json($stats);
    }

    public static function stateClass(string $state): string
    {
        return [
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
            'queued' => 'bg-slate-400',
            'processing' => 'bg-blue-500',
            'done' => 'bg-green-500',
            'error' => 'bg-red-500',
            'dead' => 'bg-red-300',
        ][$state] ?? 'bg-slate-400';
    }

    /**
     * Extrae la hora militar HHMMSS del nombre de archivo (los últimos 6 dígitos
     * antes de la extensión). Si no hay match, devuelve un string vacío.
     * Ej: "canal12valle_06072026_180002.mp4" → "180002".
     */
    public static function extractMilitaryTime(string $filename): string
    {
        if (preg_match('/(\d{6})(?=\.\w+$)/', $filename, $m)) {
            return $m[1];
        }
        // Fallback: buscar cualquier secuencia de 6 dígitos cerca del final
        if (preg_match_all('/(\d{6})/', $filename, $ms)) {
            return end($ms[1]);
        }
        return '';
    }
}