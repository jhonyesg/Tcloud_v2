<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Concerns\RunsBackgroundCommands;
use App\Http\Controllers\Controller;
use App\Jobs\ConvertAndTranscribeJob;
use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptionPollingService;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Predis\Connection\ConnectionException as PredisConnectionException;

class ApiTranscriptorController extends Controller
{
    use RunsBackgroundCommands;

    /** Tamaño de pagina por defecto del listado de Trabajos. */
    private const JOBS_PER_PAGE_DEFAULT = 50;

    /** Tope duro de tamaño de pagina, para que ?per_page= no sea un DoS. */
    private const JOBS_PER_PAGE_MAX = 100;

    /**
     * Cuantas filas como maximo se pueden recorrer con la paginacion.
     *
     * No es un limite de datos sino de navegacion: mas atras de 500 se busca,
     * no se pagina. Mantenerlo acotado permite contar el total escaneando
     * 501 filas en vez de la tabla entera (millones de segmentos detras).
     */
    private const JOBS_WINDOW_MAX = 500;

    /**
     * Sub-tabs de la pestaña Trabajos -> estados de BD que agrupan.
     *
     * 'completed' es SOLO done: mezclarlo con error/dead hacia que los fallos
     * se leyeran como exitos. Los terminales fallidos viven en 'failed'.
     */
    private const JOB_SCOPES = [
        'pending' => [Transcription::STATE_PENDING, Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING],
        'completed' => [Transcription::STATE_DONE],
        'failed' => [Transcription::STATE_ERROR, Transcription::STATE_DEAD],
        'all' => null,
    ];

    public function __construct(private TranscriptorSettings $settings) {}

    /**
     * Respuesta cuando el freno de emergencia esta activo.
     *
     * 423 Locked: el recurso existe y la peticion es valida, pero el envio esta
     * deliberadamente bloqueado. No es 503 (no hay fallo) ni 429 (no es rate).
     */
    private function pausedResponse()
    {
        return response()->json([
            'error' => 'El envio esta pausado (dispatch_paused). Reactivalo desde la pestaña Configuracion.',
            'paused' => true,
        ], 423);
    }

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json($this->indexData($request));
        }
        return view('ia.api-transcriptor.index', $this->indexData($request));
    }

    private function indexData(Request $request): array
    {
        // Scope = sub-tab activa. El filtrado se hace aqui y no en el cliente:
        // antes la vista cargaba las 200 filas mas recientes y las repartia con
        // x-show, asi que con backlog de pendientes la sub-tab Completados salia
        // vacia — los 'done' quedaban fuera del corte y nunca llegaban al navegador.
        $scope = (string) $request->input('scope', 'pending');
        if (!array_key_exists($scope, self::JOB_SCOPES)) {
            $scope = 'pending';
        }
        $scopeStates = self::JOB_SCOPES[$scope];

        $perPage = (int) $request->input('per_page', self::JOBS_PER_PAGE_DEFAULT);
        $perPage = max(1, min($perPage, self::JOBS_PER_PAGE_MAX));

        $query = Transcription::with('file:id,name,storage_provider_id');

        if ($scopeStates !== null) {
            $query->whereIn('state', $scopeStates);
        }

        // Los terminales se ordenan por cuando terminaron, que es el dato que
        // importa al revisarlos. NULLS LAST es sintaxis nativa de Postgres.
        if (in_array($scope, ['completed', 'failed'], true)) {
            $query->orderByRaw('finished_at DESC NULLS LAST')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        if ($search = trim((string) $request->input('q', ''))) {
            // original_name es lo que la tabla muestra realmente; buscar solo por
            // files.name dejaba fuera coincidencias visibles en pantalla.
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'ilike', "%{$search}%")
                    ->orWhereHas('file', function ($f) use ($search) {
                        $f->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        // El filtro explicito de estado se intersecta con el scope: pedir
        // ?state=done desde la sub-tab de fallidos no debe sacar filas done.
        $state = (string) $request->input('state', '');
        if ($state !== '' && ($scopeStates === null || in_array($state, $scopeStates, true))) {
            $query->where('state', $state);
        } else {
            $state = '';
        }

        // Conteo acotado: escanea 501 filas como mucho en vez de la tabla entera.
        // Los totales reales por estado ya los sirve stats() con un GROUP BY indexado.
        // reorder() quita el ORDER BY: ordenar por finished_at no usa indice y
        // obligaria a Postgres a ordenar todo el conjunto solo para contar.
        $total = (clone $query)->toBase()
            ->reorder()
            ->select(DB::raw('1'))
            ->limit(self::JOBS_WINDOW_MAX + 1)
            ->get()
            ->count();

        $capped = $total > self::JOBS_WINDOW_MAX;
        $navigable = min($total, self::JOBS_WINDOW_MAX);
        $totalPages = max(1, (int) ceil($navigable / $perPage));

        $page = (int) $request->input('page', 1);
        $page = max(1, min($page, $totalPages));

        $jobs = $query->forPage($page, $perPage)->get();

        $payload = [
            'jobs' => $jobs,
            'filters' => [
                'q' => $search,
                'state' => $state,
                'scope' => $scope,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $navigable,
                'total_pages' => $totalPages,
                'capped' => $capped,
                'window_max' => self::JOBS_WINDOW_MAX,
            ],
        ];

        // Paginar la tabla de Trabajos no necesita recalcular los storages, y ese
        // bloque cuesta ~430ms (resolveInheritedTranscriptionScope por storage).
        // Con ?only=jobs el navegador lo omite al cambiar de pagina o de sub-tab.
        if ($request->input('only') === 'jobs') {
            return $payload;
        }

        // Habilitados primero, luego por nombre. Adjuntamos el conteo de
        // descendientes con transcription_enabled=true para que la UI muestre
        // un badge "N hijos" en storages con scope heredado.
        $storages = StorageProvider::select(['id', 'name', 'type', 'transcription_enabled', 'base_path'])
            ->orderByRaw('transcription_enabled DESC')
            ->orderBy('name')
            ->get();

        $descendantCounts = [];
        $descendantNames = [];
        foreach ($storages as $s) {
            $inheritedScope = StorageProvider::resolveInheritedTranscriptionScope($s->id);
            $descendants = array_values(array_diff($inheritedScope, [$s->id]));
            $descendantCounts[$s->id] = count($descendants);
            if (!empty($descendants)) {
                $descendantNames[$s->id] = StorageProvider::whereIn('id', $descendants)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();
            }
        }

        $storages = $storages->map(function ($s) use ($descendantCounts, $descendantNames) {
            $s->descendant_count = $descendantCounts[$s->id] ?? 0;
            $s->descendant_names = $descendantNames[$s->id] ?? [];
            return $s;
        });

        $payload['storages'] = $storages;

        // Topes que la interfaz aplica del lado del navegador. Salen de la capa
        // de settings, NO de config(): la vista los leia del fichero y solo
        // adoptaba el override al abrir la pestana Configuracion, asi que quien
        // bajaba el tope y no entraba ahi seguia pudiendo pedir lotes que el
        // servidor luego clampeaba en silencio (processBatch usa este mismo
        // ui_batch_max).
        $payload['ui_limits'] = [
            'batch_max' => $this->settings->int('ui_batch_max'),
            'max_parallel_sends' => $this->settings->int('ui_max_parallel_sends'),
            'scan_batch' => $this->settings->int('scan_batch'),
        ];

        return $payload;
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

        // Camino rápido: re-encolar en el transcriptor reusando el .bin
        // (POST /v1/jobs/{id}/retry). Más eficiente que re-ffmpeg + re-upload.
        // Solo funciona si el .bin no fue purgado (BIN_RETENTION_HOURS, 24h).
        if (!empty($job->job_id) && !empty($job->node_url)) {
            try {
                $client = app(TranscriptorApiClient::class);
                $resp = $client->retryUpstream($job->job_id, $job->node_url);
                if (!empty($resp['job_id'])) {
                    $job->update([
                        'state' => Transcription::STATE_QUEUED,
                        'error_message' => null,
                        'finished_at' => null,
                        'corrected' => null,
                    ]);

                    return response()->json([
                        'message' => 'Job re-encolado en la API externa (sin re-subida)',
                        'transcription_id' => $job->id,
                        'state' => $job->state,
                        'job_id' => $job->job_id,
                        'upstream_retry' => true,
                    ], 200);
                }
            } catch (\Throwable $e) {
                // Falla upstream (404 = job no existe, 409 = estado cambió, o .bin purgado):
                // caemos al camino lento de re-subida. Logueamos para diagnostico.
                \Illuminate\Support\Facades\Log::info("retry: upstream retry no disponible (tx={$job->id}): {$e->getMessage()}");
            }
        }

        // Camino lento: borrar local y reenviar con ffmpeg + POST.
        $fileId = $job->file_id;
        $job->delete();

        set_time_limit(600);
        $transcription = Transcription::firstOrCreate(
            ['file_id' => $fileId],
            [
                'state' => Transcription::STATE_PENDING,
                'generate_alerts' => true,
                'language' => $this->settings->str('language'),
                'started_at' => now(),
            ]
        );
        $result = app(TranscriptionSubmitService::class)->submit($transcription);
        if (!$result['ok']) {
            return response()->json(['error' => $result['error']], 500);
        }

        $transcription = Transcription::where('file_id', $fileId)->first();

        return response()->json([
            'message' => 'Job reprocesado (re-subida completa)',
            'transcription_id' => $transcription?->id,
            'state' => $transcription?->state,
            'job_id' => $transcription?->job_id,
            'upstream_retry' => false,
        ], 200);
    }

    /**
     * Bulk dispatch: encola N `ConvertAndTranscribeJob` a Redis en una sola
     * request HTTP. Diseñado para reemplazar el patrón anterior donde se hacían
     * N POSTs a `/dispatch-now` (uno por job), lo cual saturaba el pool de
     * Postgres con N conexiones simultáneas durante el ffmpeg + curl POST
     * (30-60s por job).
     *
     * Body opcional: { ids?: int[] } — si se omite, auto-selecciona hasta 2000
     * `Transcription` en `pending|queued|processing` con `job_id IS NULL`.
     *
     * Responde 200 con { enqueued, skipped_queued, errors } siempre que la
     * request se procese. Si Redis no responde, responde 503 con mensaje claro.
     */
    public function bulkDispatch(Request $request)
    {

        $validated = $request->validate([
            'ids' => 'nullable|array|max:2000',
            'ids.*' => 'integer|min:1',
        ]);

        $ids = $validated['ids'] ?? null;

        // El freno solo bloquea el ENVIO. Con una seleccion explicita todavia
        // tiene sentido recoger resultados de lo ya enviado, que no genera
        // carga de transcripcion. Sin seleccion la accion es puro dispatch, y
        // ahi el freno sigue cortando de raiz.
        $dispatchPaused = $this->settings->bool('dispatch_paused');
        if ($dispatchPaused && empty($ids)) {
            return $this->pausedResponse();
        }

        if (empty($ids)) {
            $ids = Transcription::query()
                ->whereIn('state', [
                    Transcription::STATE_PENDING,
                    Transcription::STATE_QUEUED,
                    Transcription::STATE_PROCESSING,
                ])
                ->whereNull('job_id')
                ->orderBy('created_at')
                ->limit(2000)
                ->pluck('id')
                ->all();
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return response()->json([
                'enqueued' => 0,
                'skipped_queued' => 0,
                'errors' => 0,
                'message' => 'No hay transcripciones elegibles para encolar.',
            ], 200);
        }

        $rows = Transcription::with('file:id,storage_provider_id')
            ->whereIn('id', $ids)
            ->get();

        $dispatchableStates = [
            Transcription::STATE_PENDING,
            Transcription::STATE_QUEUED,
            Transcription::STATE_PROCESSING,
        ];

        $enqueued = 0;
        $skipped_queued = 0;
        $errors = 0;

        // Una fila ya enviada no se reenvia: lo que le falta es que alguien
        // RECOJA su resultado. Antes se descartaban todas como
        // skipped_queued, asi que seleccionar 50 filas queued y pulsar
        // "Procesar seleccionados" no hacia absolutamente nada — el boton
        // ofrecia procesar 50 y procesaba 0, sin explicar por que.
        $polling = app(TranscriptionPollingService::class);
        $pollBudget = self::BULK_POLL_MAX;
        $collected = 0;
        $lost = 0;
        $stillPending = 0;
        $notPolled = 0;

        foreach ($rows as $tx) {
            // Terminales (done/error/dead): nada que hacer aqui.
            if (!in_array($tx->state, $dispatchableStates, true)) {
                $skipped_queued++;
                continue;
            }

            if (!empty($tx->job_id)) {
                // Cada sondeo son 1-2 peticiones HTTP sincronas dentro de
                // php-fpm; sin tope una seleccion grande agotaria el timeout.
                if ($pollBudget <= 0) {
                    $notPolled++;
                    continue;
                }
                $pollBudget--;

                try {
                    match ($polling->pollOne($tx)) {
                        'done' => $collected++,
                        'lost', 'aged_out' => $lost++,
                        'error' => $errors++,
                        default => $stillPending++,
                    };
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('bulkDispatch poll tx=' . $tx->id . ': ' . $e->getMessage());
                }
                continue;
            }

            // Sin job_id: nunca salio hacia la API, hay que enviarla.
            if ($dispatchPaused) {
                $skipped_queued++;
                continue;
            }

            try {
                ConvertAndTranscribeJob::dispatch(
                    $tx->file_id,
                    (bool) $tx->generate_alerts
                );
                $enqueued++;
            } catch (PredisConnectionException $e) {
                Log::warning('bulkDispatch Redis error tx=' . $tx->id . ': ' . $e->getMessage());
                return response()->json([
                    'error' => 'Redis no disponible: ' . $e->getMessage(),
                    'enqueued' => $enqueued,
                    'collected' => $collected,
                    'lost' => $lost,
                    'still_pending' => $stillPending,
                    'skipped_queued' => $skipped_queued,
                    'errors' => ++$errors,
                    'partial' => true,
                ], 503);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('bulkDispatch tx=' . $tx->id . ': ' . $e->getMessage());
            }
        }

        return response()->json([
            'enqueued' => $enqueued,
            'collected' => $collected,
            'lost' => $lost,
            'still_pending' => $stillPending,
            'not_polled' => $notPolled,
            'skipped_queued' => $skipped_queued,
            'errors' => $errors,
            'dispatch_paused' => $dispatchPaused,
        ], 200);
    }

    /**
     * Tope de sondeos por peticion de bulk. Cada uno es HTTP sincrono contra
     * el transcriptor dentro de php-fpm; el resto se deja al poller de fondo,
     * que ya recorre toda la cola cada minuto.
     */
    private const BULK_POLL_MAX = 200;


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

        // Crear transcripción pending nueva y enviar síncronamente.
        set_time_limit(600);
        $transcription = Transcription::firstOrCreate(
            ['file_id' => $fileId],
            [
                'state' => Transcription::STATE_PENDING,
                'generate_alerts' => $generateAlerts,
                'language' => $this->settings->str('language'),
                'started_at' => now(),
            ]
        );
        $result = app(TranscriptionSubmitService::class)->submit($transcription);
        if (!$result['ok']) {
            return response()->json([
                'error' => $result['error'],
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

        if (!in_array($job->state, [Transcription::STATE_PENDING, Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)) {
            return response()->json(['error' => 'Solo se cancelan jobs pendientes o en cola'], 409);
        }

        // Rama para state='pending': borrar localmente sin tocar la API externa
        // (no hay job_id upstream que cancelar).
        if ($job->state === Transcription::STATE_PENDING) {
            $jobId = $job->id;
            $job->delete();
            return response()->json([
                'message' => 'Fila pendiente borrada (no fue enviada a la API externa)',
                'state' => 'deleted',
                'transcription_id' => null,
                'deleted_id' => $jobId,
            ], 200);
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

        // Tambien eliminar en la API externa si el job es terminal y tiene
        // job_id (libera SRT + .bin en el transcriptor). Best-effort: si la
        // API externa falla o el job no está terminal alli, la fila local
        // se borra de todas formas para no dejar registros huerfanos.
        if (!empty($job->job_id) && !empty($job->node_url)) {
            try {
                app(TranscriptorApiClient::class)->deleteUpstream($job->job_id, $job->node_url);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug("destroy: delete upstream tx={$job->id}: {$e->getMessage()}");
            }
        }

        $job->delete();
        return response()->json(['message' => 'Transcripción eliminada']);
    }

    /**
     * Enciende o apaga la transcripción de un storage.
     *
     * `storage_providers.transcription_enabled` es la bandera AUTORITATIVA del
     * pipeline: la leen DiskScannerService (qué recorrer), TranscriptionTune
     * (cuántos workers) y la UI de envío. Se escribe aquí y solo aquí.
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

            // Apagar un storage detiene su descubrimiento por completo, y ese
            // silencio es indistinguible de un fallo. Que quede en el log quién
            // y cuándo, para no repetir la investigación forense del 18 de agosto.
            Log::info('ApiTranscriptor: transcripción de storage cambiada', [
                'storage_id' => $storage->id,
                'storage' => $storage->name,
                'de' => $antes,
                'a' => $ahora,
                'user_id' => Session::get('user_id'),
            ]);
        }

        return response()->json($storage->only(['id', 'name', 'transcription_enabled']));
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

        // Resolver scope virtual: el storage pedido + sus descendientes con
        // transcription_enabled=true. La UI del operador ve la union de
        // archivos, pero las acciones de transcripcion siguen trabajando
        // contra el storage real (ver source_storage_id).
        $scopeIds = StorageProvider::resolveInheritedTranscriptionScope($id);
        $scopeInfo = StorageProvider::inheritedTranscriptionScopeInfo($id);

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
            $files = File::whereIn('storage_provider_id', $scopeIds)
                ->where('is_folder', false)
                ->where('name', 'ilike', "%{$search}%")
                ->orderByDesc('file_modified_at')
                ->limit($limit)
                ->get(['id', 'name', 'size', 'file_modified_at', 'parent_id', 'storage_provider_id']);
        } elseif ($mode === 'today' || $mode === 'yesterday') {
            // Carpeta del día (nombre = dmY, ej. "06072026"). Con scope
            // heredado, agregamos los archivos de TODAS las carpetas de día
            // presentes en el scope (cada storage hijo puede tener su propia
            // carpeta raiz con su nombre dmY). Cada archivo se anota con
            // source_storage_id para que la UI muestre de dónde viene.
            $date = $mode === 'today' ? now() : now()->subDay();
            $folderName = $date->format('dmY');

            $dayFolders = File::whereIn('storage_provider_id', $scopeIds)
                ->where('is_folder', true)
                ->where('name', $folderName)
                ->get(['id', 'parent_id', 'storage_provider_id']);

            if ($dayFolders->isNotEmpty()) {
                $dayFolderIds = $dayFolders->pluck('id');

                $files = File::whereIn('storage_provider_id', $scopeIds)
                    ->where('is_folder', false)
                    ->whereIn('parent_id', $dayFolderIds)
                    ->limit(2000)
                    ->get(['id', 'name', 'size', 'file_modified_at', 'parent_id', 'storage_provider_id']);

                $files = $files->sortByDesc(function ($f) {
                    return self::extractMilitaryTime((string) $f->name);
                })->take($limit)->values();
            }
        } else {
            // Navegación: subcarpetas + archivos de la carpeta actual
            // En modo browse con scope heredado, las carpetas raíz que el
            // cliente ve incluyen también las subcarpetas de los hijos
            // (porque las carpetas File raíz solo existen en el storage padre).
            $folders = File::whereIn('storage_provider_id', $scopeIds)
                ->where('is_folder', true)
                ->where('parent_id', $parentId)
                ->orderByDesc('file_modified_at')
                ->orderByDesc('name')
                ->limit($limit)
                ->get(['id', 'name', 'file_modified_at', 'storage_provider_id']);

            $files = File::whereIn('storage_provider_id', $scopeIds)
                ->where('is_folder', false)
                ->where('parent_id', $parentId)
                ->orderByDesc('file_modified_at')
                ->limit($limit)
                ->get(['id', 'name', 'size', 'file_modified_at', 'storage_provider_id']);

            // Construir breadcrumb subiendo por parent_id
            $cur = $parentId;
            while ($cur) {
                $folder = File::where('id', $cur)->where('is_folder', true)->first(['id', 'name', 'parent_id']);
                if (!$folder) break;
                array_unshift($breadcrumb, ['id' => $folder->id, 'name' => $folder->name]);
                $cur = $folder->parent_id;
            }
        }

        // Marca los archivos que ya tienen transcripción y trae el id/state de la
        // Transcription asociada para construir el link al detalle del job en la UI.
        // (Transcription.file_id tiene constraint UNIQUE en DB, así que cada File tiene
        // a lo sumo una fila aquí; keyBy es defensivo ante esa invariante.)
        $transcriptionsByFile = $files->isNotEmpty()
            ? Transcription::whereIn('file_id', $files->pluck('id'))
                ->orderByDesc('id')
                ->get(['id', 'file_id', 'state'])
                ->keyBy('file_id')
            : collect();

        // Resolver nombres de las carpetas padre (para agrupar en modos today/search).
        $parentIds = $files->pluck('parent_id')->filter()->unique()->values()->all();
        $parentNames = $parentIds ? File::whereIn('id', $parentIds)->pluck('name', 'id') : collect();

        $filesData = $files->map(function ($f) use ($transcriptionsByFile, $parentNames) {
            $tx = $transcriptionsByFile->get($f->id);
            return [
                'id' => $f->id,
                'name' => $f->name,
                'size' => (int) $f->size,
                'file_modified_at' => $f->file_modified_at?->toIso8601String(),
                'parent_id' => $f->parent_id,
                'folder_name' => $f->parent_id ? ($parentNames[$f->parent_id] ?? null) : null,
                'military_time' => self::extractMilitaryTime((string) $f->name),
                'has_transcription' => $tx !== null,
                'transcription_id' => $tx?->id,
                'transcription_state' => $tx?->state,
                'source_storage_id' => $f->storage_provider_id ?? null,
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
            'source_storage_id' => $f->storage_provider_id ?? null,
        ]);

        $response = [
            'storage' => $storage->only(['id', 'name', 'transcription_enabled']),
            'folders' => $foldersData,
            'files' => $filesData,
            'breadcrumb' => $breadcrumb,
            'current_parent' => $parentId,
            'mode' => $search !== '' ? 'search' : $mode,
            'files_total' => is_array($filesData) ? count($filesData) : $filesData->count(),
            'folders_total' => $foldersData->count(),
            'transcribed_count' => $this->countTranscribed($filesData),
        ];

        // Incluir info del scope solo si hay herencia real (storage_id != self solo).
        if (count($scopeIds) > 1 || $scopeInfo['descendants']->isNotEmpty()) {
            $response['scope'] = [
                'self' => $scopeInfo['self']?->only(['id', 'name', 'transcription_enabled']),
                'descendants' => $scopeInfo['descendants']->map(fn ($s) => $s->only(['id', 'name', 'transcription_enabled']))->values(),
                'storage_ids' => $scopeIds,
            ];
        }

        return response()->json($response);
    }

    /**
     * Escanea un storage: toma los últimos N archivos elegibles (sin transcripción,
     * con file_modified_at estable) y los procesa síncronamente. Devuelve cuántos procesó.
     */
    public function scanStorage(Request $request, int $id)
    {
        try {
            $storage = StorageProvider::findOrFail($id);

            // Sin default inline: el `5` que habia aqui contradecia el default
            // real de scan_batch (100) y ganaba cuando el request no traia batch.
            $batch = max(1, min($this->settings->int('ui_batch_max'), (int) $request->input('batch', $this->settings->int('scan_batch'))));
            $minAge = $this->settings->int('scan_min_age_seconds');
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
            $submitter = app(TranscriptionSubmitService::class);
            foreach ($files as $file) {
                try {
                    $transcription = Transcription::firstOrCreate(
                        ['file_id' => $file->id],
                        [
                            'state' => Transcription::STATE_PENDING,
                            'generate_alerts' => true,
                            'language' => $this->settings->str('language'),
                            'original_name' => $file->name,
                            'started_at' => now(),
                        ]
                    );
                    // Solo enviar si está pending sin job_id (evita reenviar done/queued).
                    if ($transcription->state === Transcription::STATE_PENDING && empty($transcription->job_id)) {
                        $result = $submitter->submit($transcription);
                        if ($result['ok']) {
                            $dispatched++;
                        } else {
                            $errors++;
                            \Illuminate\Support\Facades\Log::error("scanStorage file {$file->id}: {$result['error']}");
                        }
                    }
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
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("scanStorage({$id}) FATAL: " . $e->getMessage(), [
                'exception' => $e,
                'batch' => $request->input('batch'),
            ]);
            return response()->json([
                'error' => 'Error interno del servidor: ' . $e->getMessage(),
                'storage_id' => $id,
            ], 500);
        }
    }

    /**
     * Procesa todos los archivos sin transcripción de una carpeta específica.
     * Crea transcripciones pending que el schedule scan-and-submit enviará.
     */
    public function processFolder(Request $request, int $id)
    {
        $storage = StorageProvider::findOrFail($id);
        $generateAlerts = (bool) $request->input('generate_alerts', false);
        $parentId = $request->input('parent_id');
        if ($parentId === '' || $parentId === 'null') $parentId = null;
        $parentId = $parentId !== null ? (int) $parentId : null;

        // --- Archivos sin transcripción (candidatos a crear) ---
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
                $tx = Transcription::firstOrCreate(
                    ['file_id' => $file->id],
                    [
                        'original_name' => $file->name,
                        'state' => Transcription::STATE_PENDING,
                        'generate_alerts' => $generateAlerts,
                        'language' => $this->settings->str('language'),
                        'started_at' => now(),
                    ]
                );
                if ($tx->wasRecentlyCreated) $dispatched++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("processFolder file {$file->id}: {$e->getMessage()}");
            }
        }

        // --- Resumen de archivos que YA tienen transcripción ---
        $txStates = DB::table('files')
            ->select('transcriptions.state', DB::raw('COUNT(*) as cnt'))
            ->join('transcriptions', 'files.id', '=', 'transcriptions.file_id')
            ->where('files.storage_provider_id', $id)
            ->where('files.is_folder', false)
            ->where('files.parent_id', $parentId)
            ->groupBy('transcriptions.state')
            ->pluck('cnt', 'state')
            ->toArray();

        $summary = $txStates;

        // Contar pending sin job_id
        $pendingWithoutJobId = (int) DB::table('files')
            ->join('transcriptions', 'files.id', '=', 'transcriptions.file_id')
            ->where('files.storage_provider_id', $id)
            ->where('files.is_folder', false)
            ->where('files.parent_id', $parentId)
            ->where('transcriptions.state', Transcription::STATE_PENDING)
            ->whereNull('transcriptions.job_id')
            ->count();

        $message = $dispatched > 0
            ? "Pendientes creados: {$dispatched} de {$files->count()} candidatos."
            : 'No hay archivos nuevos sin transcripción en esta carpeta.';

        if ($pendingWithoutJobId > 0) {
            $message .= " {$pendingWithoutJobId} ya están pendientes (serán enviados automáticamente por el schedule).";
        }

        return response()->json([
            'storage_id' => $storage->id,
            'dispatched' => $dispatched,
            'candidates' => $files->count(),
            'already_summary' => $summary,
            'pending_without_job_id' => $pendingWithoutJobId,
            'generate_alerts' => $generateAlerts,
            'message' => $message,
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
                $tx = Transcription::firstOrCreate(
                    ['file_id' => $file->id],
                    [
                        'original_name' => $file->name,
                        'state' => Transcription::STATE_PENDING,
                        'generate_alerts' => $generateAlerts,
                        'language' => $this->settings->str('language'),
                        'started_at' => now(),
                    ]
                );
                if ($tx->wasRecentlyCreated) $dispatched++;
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
     * transcription:scan-and-submit en un proceso separado (nohup) y devuelve
     * inmediatamente un run_id. El frontend consulta /batch-status/{runId}
     * para ver el progreso en vivo. Así se puede cerrar/recargar la página.
     */
    public function processBatch(Request $request)
    {
        if ($this->settings->bool('dispatch_paused')) {
            return $this->pausedResponse();
        }

        // El tope sale de ui_batch_max, el MISMO valor con el que la vista pinta
        // el slider. Antes eran dos numeros independientes (500 en la UI, 200
        // aqui) y el exceso se truncaba en silencio.
        $batch = max(1, min($this->settings->int('ui_batch_max'), (int) $request->input('batch', 50)));
        $generateAlerts = (bool) $request->input('generate_alerts', false);
        $includeFailed = (bool) $request->input('include_failed', false);
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

        // Lanzar el NUEVO comando scan-and-submit en background con nohup.
        $artisan = base_path('artisan');
        $php = PHP_BINDIR . '/php';
        if (!is_file($php)) $php = 'php';
        $logFile = storage_path('logs/transcription-batch-' . $runId . '.log');
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($artisan)
             . ' transcription:scan-and-submit --days=0'
             . ' --batch=' . (int) $batch
             . ' --run-id=' . escapeshellarg($runId);
        if ($includeFailed) {
            $cmd .= ' --include-failed';
        }
        // generate_alerts se validaba arriba pero nunca llegaba al comando: el
        // checkbox de la UI prometia un comportamiento que no ocurria.
        if ($generateAlerts) {
            $cmd .= ' --alerts';
        }
        $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

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
            'message' => 'Lote iniciado en background (scan-and-submit)',
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
     * Envío manual de un archivo concreto a transcripción.
     * Crea el job (el job mismo evita duplicados vía firstOrCreate).
     */
    public function transcribeFile(Request $request, int $fileId)
    {

        if ($this->settings->bool('dispatch_paused')) {
            return $this->pausedResponse();
        }
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

        // Envío MANUAL: crear transcripción pending y enviar síncronamente.
        set_time_limit(600);
        $transcription = Transcription::firstOrCreate(
            ['file_id' => $file->id],
            [
                'state' => Transcription::STATE_PENDING,
                'generate_alerts' => true,
                'language' => $this->settings->str('language'),
                'original_name' => $file->name,
                'started_at' => now(),
            ]
        );
        $result = app(TranscriptionSubmitService::class)->submit($transcription);
        if (!$result['ok']) {
            return response()->json([
                'error' => $result['error'],
                'file_id' => $file->id,
                'file_name' => $file->name,
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
        ], 200);
    }

    /**
     * Ejecuta SÍNCRONAMENTE un job que está en cola (queued/processing sin job_id
     * o encolado en la API externa). Permite "Enviar ahora" desde la UI sin esperar
     * al worker. Devuelve el estado actualizado para el modal de progreso.
     */
    public function dispatchNow(Request $request, int $id)
    {

        if ($this->settings->bool('dispatch_paused')) {
            return $this->pausedResponse();
        }
        $job = Transcription::with('file')->findOrFail($id);

        if (!in_array($job->state, [Transcription::STATE_PENDING, Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING], true)) {
            return response()->json([
                'error' => "Solo se puede enviar ahora jobs en pending/queued/processing (estado actual: {$job->state})",
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

        $fileId = $file->id;

        // Rama específica para state='pending': la fila ya existe, no es necesario
        // borrarla y recrearla (evita ventana de carrera con el scheduler).
        if ($job->state === Transcription::STATE_PENDING) {
            set_time_limit(600);
            $result = app(TranscriptionSubmitService::class)->submit($job);
            if (!$result['ok']) {
                return response()->json([
                    'error' => $result['error'],
                    'file_id' => $fileId,
                ], 500);
            }

            $job->refresh();

            return response()->json([
                'message' => 'Job enviado a la API externa',
                'file_id' => $fileId,
                'file_name' => $file->name,
                'transcription_id' => $job->id,
                'state' => $job->state ?? Transcription::STATE_PENDING,
                'job_id' => $job->job_id,
            ], 200);
        }

        // Borrar la transcripción actual (sin job_id) y reenviar síncrono.
        $job->delete();

        set_time_limit(600);
        $transcription = Transcription::firstOrCreate(
            ['file_id' => $fileId],
            [
                'state' => Transcription::STATE_PENDING,
                'generate_alerts' => true,
                'language' => $this->settings->str('language'),
                'started_at' => now(),
            ]
        );
        $result = app(TranscriptionSubmitService::class)->submit($transcription);
        if (!$result['ok']) {
            return response()->json([
                'error' => $result['error'],
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
     * recuperar el estado real. No es un respaldo de nada: el polling es el
     * único camino por el que vuelve un resultado (no hay webhook entrante).
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

    /** Tope de segmentos servidos al modal (hay grabaciones de radio de horas). */
    private const TRANSCRIPT_SEGMENTS_MAX = 5000;

    /**
     * Contenido de una transcripcion para el modal "Ver transcripción".
     *
     * Sirve el texto ya corregido (transcription_segments.text) y el SRT crudo,
     * ambos en Postgres — no hay ficheros en disco. Las etiquetas HH:MM:SS se
     * resuelven aqui con los helpers del modelo en vez de formatear en JS.
     */
    public function transcript(int $id)
    {
        $job = Transcription::with('file:id,name')->findOrFail($id);

        $segments = $job->segments()
            ->orderBy('segment_index')
            ->limit(self::TRANSCRIPT_SEGMENTS_MAX + 1)
            ->get(['id', 'segment_index', 'start_seconds', 'end_seconds', 'text']);

        $truncated = $segments->count() > self::TRANSCRIPT_SEGMENTS_MAX;
        if ($truncated) {
            $segments = $segments->take(self::TRANSCRIPT_SEGMENTS_MAX);
        }

        $rows = $segments->map(fn ($s) => [
            'segment_index' => $s->segment_index,
            'start_seconds' => $s->start_seconds,
            'end_seconds' => $s->end_seconds,
            'start_label' => $s->getStartLabel(),
            'end_label' => $s->getEndLabel(),
            'text' => $s->text,
        ])->values();

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'file_name' => $job->original_name ?: ($job->file?->name ?? 'File #' . $job->file_id),
            'language' => $job->language,
            'duration_seconds' => $job->duration_seconds,
            'word_count' => $job->word_count,
            'finished_at' => $job->finished_at?->toIso8601String(),
            'error_message' => $job->error_message,
            'srt_content' => $job->srt_content,
            'plain_text' => trim(implode(' ', array_filter($rows->pluck('text')->all(), 'strlen'))),
            'segments' => $rows,
            'segments_truncated' => $truncated,
        ]);
    }

    /**
     * Consulta GET /v1/jobs/{job_id} en la API externa y sincroniza el estado
     * local. Si el job ya terminó (done/error/dead), procesa el resultado.
     * Silencioso: no lanza, solo loguea errores para no romper el polling.
     *
     * NO reenvia el audio: solo pregunta "¿ya terminaste?". Para volver a
     * mandar un archivo estan "Enviar ahora" (filas sin job_id) y
     * transcription:backfill-lost (filas cuyo resultado se perdio upstream).
     *
     * @return string done|error|lost|aged_out|pending, o '' si no hay job_id.
     */
    private function syncFromUpstream(Transcription $transcription): string
    {
        if (empty($transcription->job_id)) return '';

        // Delega en el poller en vez de reimplementarlo. La copia anterior
        // divergia en lo que mas importaba: si el job estaba done pero su SRT
        // ya no existia upstream, la excepcion moria en un Log::debug (que
        // LOG_LEVEL=warning descarta) y la fila se quedaba igual. El boton
        // "Refrescar estado" parecia no hacer nada, sin explicacion en pantalla.
        // Ademas usaba processDone() ignorando el srt_url que da la API.
        return app(TranscriptionPollingService::class)->pollOne($transcription);
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

        $outcome = $this->syncFromUpstream($job);
        $job->refresh();

        $segmentsCount = $job->state === Transcription::STATE_DONE
            ? $job->segments()->count()
            : 0;

        // Antes la respuesta no distinguia "sigue en cola" de "no se pudo
        // hacer nada": ambos devolvian el mismo JSON sin cambios y el boton
        // parecia inerte. `outcome` dice que ocurrio de verdad.
        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'outcome' => $outcome,
            'outcome_message' => self::OUTCOME_MESSAGES[$outcome] ?? null,
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
     * Que significa cada resultado de "Refrescar estado", en lenguaje del
     * operador. Ninguno implica reenviar audio: el boton solo consulta.
     */
    private const OUTCOME_MESSAGES = [
        'done' => 'Terminado: se descargó la transcripción y ya está disponible.',
        'error' => 'La API reporta que el job falló. Pasa a Fallidos.',
        'lost' => 'La API perdió el resultado (el SRT ya no existe). Se marcó como fallido; para recuperarlo hay que volver a enviar el audio.',
        'aged_out' => 'Lleva demasiado tiempo sin resolverse y se cerró como fallido. Para recuperarlo hay que volver a enviar el audio.',
        'pending' => 'Sigue en la API sin terminar. No hay nada que hacer: el resultado se recoge solo.',
    ];

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

            // fullSync esta serializado por storage. Dos clics seguidos ya no
            // lanzan dos recorridos completos concurrentes sobre ~23k carpetas.
            if (!empty($stats['skipped_locked'])) {
                return response()->json(['error' => 'Ya hay una sincronización en curso para este storage'], 409);
            }

            if (!empty($stats['disabled'])) {
                return response()->json(['error' => 'El sincronizado está desactivado (storage_sync.enabled)'], 423);
            }

            return response()->json([
                'message' => 'Sincronización completada',
                'storage_id' => $storage->id,
                'created' => $stats['created'] ?? 0,
                'deleted' => $stats['deleted'] ?? 0,
                'duplicate_folders_skipped' => $stats['duplicate_folders_skipped'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Sync falló: ' . $e->getMessage()], 500);
        }
    }

    public function health(TranscriptorApiClient $client)
    {
        return response()->json($client->getHealth());
    }

    /**
     * Estado de /dev/shm (tmpfs donde se escriben los WAVs intermedios).
     * El comando transcription:check-shm-health actualiza la cache cada 10 min;
     * aqui solo la servimos. Si la cache no existe (cold start) corremos el
     * calculo en proceso para no devolver un 503.
     */
    public function shmStatus(TranscriptorSettings $settings)
    {
        $cached = Cache::get('transcriptor:shm:status');
        if (!is_array($cached)) {
            $total = @disk_total_space('/dev/shm');
            $free  = @disk_free_space('/dev/shm');
            $used  = ($total !== false && $free !== false) ? ($total - $free) : null;
            $pct   = ($total !== false && $total > 0 && $used !== null)
                ? round($used * 100 / $total, 1)
                : null;
            $cached = [
                'total' => $total !== false ? $total : null,
                'used'  => $used,
                'free'  => $free !== false ? $free : null,
                'percent' => $pct,
                'dir_writable' => is_writable('/dev/shm/tcloud-transcription'),
                'checked_at' => now()->toIso8601String(),
            ];
            Cache::put('transcriptor:shm:status', $cached, 600);
        }
        $cached['threshold'] = max(50, min(99, $settings->int('shm_warn_percent')));
        $cached['status'] = ($cached['percent'] !== null && $cached['percent'] >= $cached['threshold'])
            ? 'warning'
            : 'ok';
        return response()->json($cached);
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

    /**
     * Novedad: storages con transcription_enabled que tienen subcarpetas
     * "hoja" sin archivos en absoluto. Escanea hasta 2 niveles bajo
     * base_path con cap por storage.
     *
     * Cache 5 min: el escaneo de filesystem por subdir es caro.
     * Cap `max_dirs` por storage protege contra explosiones.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function emptyFolders(Request $request)
    {
        $maxDirs = max(50, min(500, (int) $request->input('max_dirs', 200)));

        $cacheKey = 'transcriptor:empty_folders:max' . $maxDirs;
        $data = \Cache::remember($cacheKey, 300, function () use ($maxDirs) {
            $storages = DB::table('storage_providers')
                ->where('transcription_enabled', true)
                ->orderBy('id')
                ->get(['id', 'name', 'base_path']);

            $empty = [];
            foreach ($storages as $sp) {
                if (!is_dir($sp->base_path)) continue;
                $entries = @scandir($sp->base_path);
                if (!$entries) continue;

                $missing = [];
                $scanned = 0;
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    if (!is_dir($sp->base_path . '/' . $e)) continue;
                    if ($scanned >= $maxDirs) break;
                    $scanned++;
                    if ($this->dirHasNoFiles($sp->base_path . '/' . $e)) {
                        $missing[] = $e;
                        continue;
                    }
                    $subEntries = @scandir($sp->base_path . '/' . $e);
                    if (!$subEntries) continue;
                    foreach ($subEntries as $sub) {
                        if ($sub === '.' || $sub === '..') continue;
                        $subPath = $sp->base_path . '/' . $e . '/' . $sub;
                        if (!is_dir($subPath)) continue;
                        if ($scanned >= $maxDirs) break;
                        $scanned++;
                        if ($this->dirHasNoFiles($subPath)) {
                            $missing[] = $e . '/' . $sub;
                        }
                    }
                }
                if (!empty($missing)) {
                    $empty[] = [
                        'storage_id' => $sp->id,
                        'storage_name' => $sp->name,
                        'base_path' => $sp->base_path,
                        'missing_count' => count($missing),
                        'missing' => array_slice($missing, 0, 30),
                    ];
                }
            }

            return [
                'generated_at' => now()->toIso8601String(),
                'storages_with_empty' => count($empty),
                'total_missing_folders' => array_sum(array_column($empty, 'missing_count')),
                'items' => $empty,
            ];
        });

        return response()->json($data);
    }

    /**
     * Recorre un directorio y devuelve true si NO contiene ningun archivo
     * en ningun nivel. Cap de 200 subarchivos/subdirs para no morir en
     * filesystems gigantes.
     */
    private function dirHasNoFiles(string $dir, int $cap = 200): bool
    {
        $found = false;
        $count = 0;
        $stack = [$dir];
        while ($stack && !$found && $count < $cap) {
            $cur = array_pop($stack);
            $items = @scandir($cur);
            if (!$items) continue;
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') continue;
                $count++;
                if ($count >= $cap) break;
                $full = $cur . '/' . $it;
                if (is_file($full)) {
                    $size = @filesize($full);
                    if ($size !== false && $size > 0) {
                        $found = true;
                        break;
                    }
                } elseif (is_dir($full)) {
                    $stack[] = $full;
                }
            }
        }
        return !$found;
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

    /**
     * Cuenta archivos con has_transcription=true dentro de $filesData.
     * Soporta tanto Collection plana (modo browse/today) como Collection
     * agrupada (modo search: cada item es {folder, files: [...] }).
     */
    private function countTranscribed($filesData): int
    {
        if (is_array($filesData)) {
            $iter = $filesData;
        } elseif ($filesData instanceof \Illuminate\Support\Collection) {
            $iter = $filesData->all();
        } else {
            return 0;
        }

        $count = 0;
        foreach ($iter as $item) {
            if (isset($item['has_transcription']) && $item['has_transcription'] === true) {
                $count++;
            } elseif (isset($item['files']) && is_array($item['files'])) {
                foreach ($item['files'] as $sub) {
                    if (isset($sub['has_transcription']) && $sub['has_transcription'] === true) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }
}