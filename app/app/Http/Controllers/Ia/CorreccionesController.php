<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Concerns\RunsBackgroundCommands;
use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Models\Transcription;
use App\Services\Ia\ContextShiftAuditor;
use App\Services\Ia\CorrectionContextFinder;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\DictionaryAudit;
use App\Services\Ia\TranscriptionReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class CorreccionesController extends Controller
{
    use RunsBackgroundCommands;

    private const CACHE_TTL_HOURS = 4;

    public function index()
    {
        $approved = Correction::approved()
            ->with('proposedBy', 'approvedBy')
            ->orderByDesc('applies_count')
            ->get();

        $pendingCount = Correction::pending()->count();
        $approvedCount = Correction::approved()->count();
        $rejectedCount = Correction::where('status', 'rejected')->count();
        $totalCount = $pendingCount + $approvedCount + $rejectedCount;

        return view('ia.correcciones.index', [
            'approved' => $approved,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * Lista una muestra pequeña de transcripciones terminadas para auditoría
     * humana, sin cargar el histórico completo en el navegador.
     */
    public function transcriptionReviewList(Request $request, TranscriptionReviewService $service)
    {
        $mode = $service->normalizeMode((string) $request->input('mode', TranscriptionReviewService::MODE_LATEST));

        return response()->json([
            'mode' => $mode,
            'items' => $service->list($mode),
        ]);
    }

    /**
     * Devuelve el detalle raw vs corregido de una transcripción terminada.
     */
    public function transcriptionReviewDetail(int $id, TranscriptionReviewService $service)
    {
        return response()->json($service->detail($id));
    }

    /**
     * Guarda la decisión humana sobre una transcripción. Este endpoint no
     * modifica reglas del diccionario.
     */
    public function transcriptionReviewUpdate(Request $request, int $id, TranscriptionReviewService $service)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,correct,needs_review,ignored',
            'notes' => 'nullable|string|max:5000',
        ]);

        $transcription = Transcription::where('state', Transcription::STATE_DONE)->findOrFail($id);
        $review = $service->updateReview(
            $transcription,
            $validated['status'],
            $validated['notes'] ?? null,
            (int) $this->adminUser()->id,
        );

        return response()->json([
            'ok' => true,
            'review' => [
                'status' => $review->status,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
                'notes' => $review->notes,
            ],
        ]);
    }

    public function pending()
    {
        $pending = Correction::pending()
            ->with('proposedBy', 'sourceSegment.transcription')
            ->latest()
            ->get();

        return response()->json($pending);
    }

    /**
     * Ejemplos reales de dónde dispara una corrección, para moderarla con
     * evidencia en vez de a ciegas.
     *
     * Se resuelve bajo demanda (al abrir el modal), nunca al pintar la tabla: la
     * búsqueda va contra transcription_segments y cuesta entre 0,4 s y 7 s según
     * lo frecuente que sea el término. CorrectionContextFinder la cachea y le
     * pone statement_timeout, así que este método no necesita guardas propias.
     *
     * Path: GET /ia/correcciones/{id}/contexto
     */
    public function contextExamples(int $id, CorrectionContextFinder $finder)
    {
        $correction = Correction::findOrFail($id);

        return response()->json($finder->examples($correction));
    }

    /**
     * Devuelve el segmento de transcripción origen de una corrección
     * (changes/2026-08-12-corrections-pending-segment-context). El admin
     * hace click en el snippet de la tabla y abre el modal "Contexto del
     * segmento" con el text_raw + text corregido del segmento, más el
     * timecode y link al detalle de la transcripción.
     *
     * Si la corrección no tiene `source_segment_id` (legacy o segmento
     * purgado) devuelve 404 con `{error: "no_segment"}`. La UI muestra un
     * mensaje explicativo sin cerrar el modal.
     *
     * Path: GET /ia/correcciones/{id}/source-segment
     */
    public function sourceSegment(int $id)
    {
        $correction = Correction::with('sourceSegment.transcription')->findOrFail($id);
        $segment = $correction->sourceSegment;

        if (!$segment) {
            return response()->json(['error' => 'no_segment'], 404);
        }

        return response()->json([
            'segment' => [
                'id' => $segment->id,
                'segment_index' => $segment->segment_index,
                'start_seconds' => $segment->start_seconds,
                'end_seconds' => $segment->end_seconds,
                'text_raw' => $segment->text_raw,
                'text' => $segment->text,
            ],
            'transcription' => $segment->transcription ? [
                'id' => $segment->transcription->id,
                'file_name' => $segment->transcription->file_name ?? basename((string) ($segment->transcription->path ?? '')),
            ] : null,
        ]);
    }

    /**
     * Devuelve todas las correcciones approved con relaciones, ordenadas por
     * applies_count desc. Alimenta la pestaña "Aprobadas" vía AJAX para soportar
     * búsqueda libre, filtro por source y bulk delete con checkboxes (reemplaza
     * el render server-side anterior que no escalaba con miles de reglas).
     *
     * Path: GET /ia/correcciones/approved
     */
    public function approved()
    {
        $approved = Correction::approved()
            ->with('proposedBy:id,username', 'approvedBy:id,username', 'sourceSegment.transcription')
            ->orderByDesc('applies_count')
            ->orderByDesc('id')
            ->get();

        return response()->json($approved);
    }

    /**
     * Endpoint consolidado para la sub-tab "AI Suggest Results":
     * resumen de las últimas 5 corridas AI suggest + lista de auto-aprobadas
     * con source=ai-suggest-% + lista de pendientes que quedaron del suggester
     * (ej: porque admin apagó auto_approve, o por rollback).
     *
     * Path: GET /ia/correcciones/ai-suggest-results
     */
    public function aiSuggestResults()
    {
        // Última auto-aprobación por source (correlaciona created_at today y Y-day).
        $approvedList = Correction::where('status', Correction::STATUS_APPROVED)
            ->where('source', 'LIKE', 'ai-suggest-%')
            ->with('proposedBy:id,username', 'approvedBy:id,username')
            ->orderByDesc('id')
            ->get();

        // Pendientes cuya source es AI Suggest (caso: auto_approve=false temporal).
        $pendingList = Correction::where('status', Correction::STATUS_PENDING)
            ->where('source', 'LIKE', 'ai-suggest-%')
            ->with('proposedBy:id,username')
            ->orderByDesc('id')
            ->get();

        // Resumen por source: agrupa auto-aprobadas de las últimas corridas.
        $runs = Correction::where('source', 'LIKE', 'ai-suggest-%')
            ->selectRaw('source, count(*) as total, max(created_at) as last_run_at, sum(case when status = ? then 1 else 0 end) as approved_count, sum(case when status = ? then 1 else 0 end) as pending_count, sum(case when status = ? then 1 else 0 end) as rejected_count',
                [Correction::STATUS_APPROVED, Correction::STATUS_PENDING, Correction::STATUS_REJECTED])
            ->groupBy('source')
            ->orderByRaw('max(created_at) desc')
            ->limit(5)
            ->get();

        return response()->json([
            'runs' => $runs,
            'approved_list' => $approvedList,
            'pending_list' => $pendingList,
        ]);
    }

    public function approve(int $id, CorrectionService $service)
    {
        $correction = Correction::findOrFail($id);
        $admin = $this->adminUser();
        $updated = $service->approve($correction, $admin);

        $response = $updated->load('proposedBy', 'approvedBy');
        $payload = ['correction' => $response];
        $warning = $this->buildContextWarning($updated);
        if ($warning !== null) {
            $payload['context_warning'] = $warning;
        }
        return response()->json($payload);
    }

    /**
     * Lista exclusiones dinámicas (activas + archivadas) para la UI.
     *
     * Path: GET /ia/correcciones/protected-terms
     */
    public function protectedTermsIndex(\App\Services\Ia\CorrectionProtectedTermsService $svc)
    {
        return response()->json([
            'items' => $svc->listAll(),
        ]);
    }

    /**
     * Agrega una exclusión dinámica. Body JSON: {term, category?, notes?}.
     * Valida unicidad entre activos (constraint UNIQUE parcial + check de app).
     *
     * Modo bulk (corrections-protected-terms-shortcut): body = {terms: [{term, category?, notes?, correction_id?}, ...]}.
     * Devuelve 201 si todos los términos se crearon, 207 si algunos fueron
     * omitidos por duplicado, 422 si todos fueron duplicados o vinieron vacíos.
     *
     * Side-effect: cuando un ítem se crea OK y trae `correction_id`, la corrección
     * asociada se archiva con motivo `moved_to_exclusion: <term>` (corrections-archive-on-exclude).
     * Esto se ejecuta SOLO si la exclusión se creó (no en duplicados/inválidos)
     * para evitar side-effects no deseados.
     *
     * Path: POST /ia/correcciones/protected-terms
     */
    public function protectedTermsStore(
        Request $request,
        \App\Services\Ia\CorrectionProtectedTermsService $svc,
        \App\Services\Ia\CorrectionService $correctionService
    ) {
        $admin = $this->adminUser();
        $archived = [];

        // Modo bulk.
        $bulkInput = $request->input('terms');
        if (is_array($bulkInput) && !empty($bulkInput)) {
            $created = [];
            $skipped = [];
            foreach ($bulkInput as $idx => $item) {
                if (!is_array($item)) continue;
                $term = trim((string) ($item['term'] ?? ''));
                if ($term === '') {
                    $skipped[] = ['term' => '', 'reason' => 'empty'];
                    continue;
                }
                $category = $item['category'] ?? null;
                $notes = $item['notes'] ?? null;
                try {
                    $row = $svc->add($term, $category, $notes, $admin);
                    $created[] = [
                        'id' => $row->id,
                        'term' => $row->term,
                        'category' => $row->category,
                    ];

                    // Archivado colateral: si la exclusión se creó OK y el
                    // body la asocia con una corrección, archivamos la corrección.
                    $correctionId = $item['correction_id'] ?? null;
                    if ($correctionId !== null && is_int($correctionId)) {
                        try {
                            $correction = \App\Models\Correction::find($correctionId);
                            if ($correction && $correction->status !== \App\Models\Correction::STATUS_REJECTED) {
                                $correctionService->reject($correction, $admin, 'moved_to_exclusion: ' . $row->term);
                                $archived[] = [
                                    'correction_id' => $correction->id,
                                    'term' => $row->term,
                                ];
                            }
                        } catch (\Throwable $e) {
                            // No rompemos el lote si una falla; logueamos.
                            \Illuminate\Support\Facades\Log::warning('Excluir+archive: fallo archivando corrección', [
                                'correction_id' => $correctionId,
                                'term' => $row->term,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    if (str_contains($e->getMessage(), 'ya existe')) {
                        $skipped[] = ['term' => mb_strtolower($term), 'reason' => 'duplicate'];
                    } else {
                        $skipped[] = ['term' => mb_strtolower($term), 'reason' => 'invalid'];
                    }
                }
            }

            if (empty($created)) {
                return response()->json([
                    'error' => 'No se creó ninguna exclusión (todas duplicadas o inválidas).',
                    'created' => $created,
                    'skipped' => $skipped,
                    'archived' => $archived,
                ], 422);
            }

            $status = empty($skipped) ? 201 : 207;
            return response()->json([
                'ok' => true,
                'created' => $created,
                'skipped' => $skipped,
                'archived' => $archived,
            ], $status);
        }

        // Modo single (compatibilidad con caller del subpanel Exclusiones).
        $term = (string) $request->input('term', '');
        $category = $request->input('category');
        $notes = $request->input('notes');
        $correctionId = $request->input('correction_id');

        try {
            $row = $svc->add($term, $category, $notes, $admin);

            // Archivado colateral (single shortcut): si la exclusión se creó OK
            // y hay correction_id, archivamos la corrección en la misma respuesta.
            if ($correctionId !== null && is_int($correctionId)) {
                try {
                    $correction = \App\Models\Correction::find($correctionId);
                    if ($correction && $correction->status !== \App\Models\Correction::STATUS_REJECTED) {
                        $correctionService->reject($correction, $admin, 'moved_to_exclusion: ' . $row->term);
                        $archived[] = [
                            'correction_id' => $correction->id,
                            'term' => $row->term,
                        ];
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Excluir+archive (single): fallo archivando', [
                        'correction_id' => $correctionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'ok' => true,
                'item' => [
                    'id' => $row->id,
                    'term' => $row->term,
                    'category' => $row->category,
                    'notes' => $row->notes,
                    'created_by_username' => $admin->username,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'archived_at' => null,
                ],
                'archived' => $archived,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'errors' => ['term' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Soft-archive de un término.
     *
     * Path: DELETE /ia/correcciones/protected-terms/{id}
     */
    public function protectedTermsArchive(
        int $id,
        \App\Services\Ia\CorrectionProtectedTermsService $svc
    ) {
        $ok = $svc->archive($id);
        return $ok ? response()->noContent() : response()->json(['error' => 'No encontrado'], 404);
    }

    /**
     * Restaurar un término archivado.
     *
     * Path: POST /ia/correcciones/protected-terms/{id}/restore
     */
    public function protectedTermsRestore(
        int $id,
        \App\Services\Ia\CorrectionProtectedTermsService $svc
    ) {
        $ok = $svc->restore($id);
        return $ok ? response()->noContent() : response()->json(['error' => 'No encontrado'], 404);
    }

    public function reject(Request $request, int $id, CorrectionService $service)
    {
        $request->validate(['rejected_reason' => 'nullable|string|max:1000']);

        $correction = Correction::findOrFail($id);
        $admin = $this->adminUser();
        $updated = $service->reject($correction, $admin, $request->input('rejected_reason'));

        return response()->json($updated->load('proposedBy'));
    }

    public function store(Request $request, CorrectionService $service)
    {
        $request->validate([
            'wrong' => 'required|string|max:500',
            'correct' => 'required|string|max:500',
        ]);

        $admin = $this->adminUser();
        $correction = $service->upsertApproved($request->wrong, $request->correct, $admin);

        $payload = ['correction' => $correction->load('proposedBy', 'approvedBy')];
        $warning = $this->buildContextWarning($correction);
        if ($warning !== null) {
            $payload['context_warning'] = $warning;
        }
        return response()->json($payload, 201);
    }

    public function destroy(int $id)
    {
        Correction::findOrFail($id)->delete();
        return response()->json(['message' => 'Corrección eliminada']);
    }

    /**
     * Edita una corrección pendiente. Permite corregir `wrong_text` y/o
     * `correct_text` antes de aprobar. Re-normaliza `wrong_normalized` y
     * resuelve colisiones con approved/pending del mismo normalized
     * siguiendo la misma semántica de propose() (merged/upsert).
     *
     * Solo aplica a status='pending'. Devuelve 409 si la corrección ya
     * fue aprobada, rechazada o mergeada.
     *
     * Path: PATCH /ia/correcciones/{id}
     */
    public function update(Request $request, int $id, CorrectionService $service)
    {
        $data = $request->validate([
            'wrong_text' => 'required|string|max:500',
            'correct_text' => 'required|string|max:500',
        ]);

        $correction = Correction::findOrFail($id);
        if ($correction->status !== Correction::STATUS_PENDING) {
            return response()->json([
                'error' => 'Solo se pueden editar correcciones pendientes.',
            ], 409);
        }

        $updated = $service->updatePending(
            $correction,
            $data['wrong_text'],
            $data['correct_text'],
        );

        return response()->json(['correction' => $updated->load('proposedBy')]);
    }

    /**
     * Lanza una corrida async de re-aplicación retroactiva del diccionario.
     * Retorna {runId} para que la UI haga polling.
     *
     * Parámetros:
     *   - dry_run (bool, default false): solo reporta, no escribe
     *   - chunk (int, default 500): tamaño del chunk de segments
     *   - days_back (int|null, default null): si != null, filtra segments
     *     a solo los creados en los últimos N días. Útil para aplicar
     *     correcciones nuevas solo a histórico reciente sin tocar 10M+.
     *   - include_high_risk (bool, default false): si true, incluye
     *     correcciones con risk_level='high' (muletillas, falsos amigos).
     *     Default false: omitir para preservar tono/contexto original.
     */
    public function applyRetroactive(Request $request)
    {
        $dryRun = (bool) $request->input('dry_run', false);
        $chunk = max(50, (int) $request->input('chunk', 500));
        $includeHighRisk = (bool) $request->input('include_high_risk', false);
        $daysBackInput = $request->input('days_back');
        $daysBack = null;
        if ($daysBackInput !== null && $daysBackInput !== '' && $daysBackInput !== 'all') {
            $daysBack = (int) $daysBackInput;
            if ($daysBack <= 0) {
                return response()->json([
                    'error' => 'days_back debe ser entero positivo o "all".',
                ], 422);
            }
            if ($daysBack > 365) {
                return response()->json([
                    'error' => 'days_back no puede ser > 365 (use --days en CLI para más).',
                ], 422);
            }
        }

        $runId = $this->generateRunId('correction_apply');
        $cacheKey = "corrections_apply:{$runId}";

        // Anti-duplicado: si ya hay un run sano (queued/running) apuntado
        // por el puntero `corrections_apply:active`, rechazamos con 409
        // devolviendo el runId vigente para que la UI se re-adjunte en vez
        // de lanzar un proceso paralelo. Huérfanos (run terminado, run
        // inexistente, o run en queued >5min sin started_at) se LIMPIAN
        // activamente y dejamos continuar (no retornamos 409).
        // (BEFORE-FIX: el primer check aceptaba huérfanos pero NO borraba el
        // puntero, y un segundo Cache::add atómico fallaba dando 409 fantasma.
        // Ref: sesión admin 2026-08-01 10:09 donde quedó un run queued
        // huérfano a las 10:04 del día anterior por muerte silenciosa del
        // proceso setsid.)
        $activePointer = Cache::get('corrections_apply:active');
        if (is_array($activePointer) && !empty($activePointer['runId'])) {
            $activeId = (string) $activePointer['runId'];
            $activeState = Cache::get("corrections_apply:{$activeId}");
            $orphan = !$activeState
                || in_array($activeState['status'] ?? null, ['done', 'error'], true)
                || (
                    ($activeState['status'] ?? null) === 'queued'
                    && empty($activeState['started_at'])
                    && Carbon::parse($activeState['queued_at'] ?? $activeState['updated_at'] ?? now())->lt(now()->subMinutes(5))
                );
            if ($orphan) {
                // Limpiar activamente el puntero huérfano + el state key
                // (este último expira solo pero lo borramos YA para que
                // 'running' del response al UI no mienta sobre el estado).
                Cache::forget('corrections_apply:active');
                if ($activeState && is_array($activeState)) {
                    Cache::forget("corrections_apply:{$activeId}");
                }
                Log::info('CorreccionesController: puntero huérfano limpiado', [
                    'old_run_id' => $activeId,
                    'orphan' => true,
                ]);
            } else {
                return response()->json([
                    'error'  => 'Ya hay una corrida en curso.',
                    'runId'  => $activeId,
                    'status' => $activeState['status'] ?? 'running',
                ], 409);
            }
        }

        // Pre-computar total en la UI para que el primer poll no muestre
        // "0 segmentos" engañoso. Si el pre-conteo falla, el comando async
        // sobreescribe el valor desde su callback de progreso.
        $preTotal = 0;
        try {
            $preTotal = \App\Models\TranscriptionSegment::query()
                ->when($daysBack !== null && $daysBack > 0,
                    fn ($q) => $q->where('created_at', '>=', now()->subDays($daysBack)))
                ->count();
        } catch (\Throwable $e) {
            // Si falla, dejamos 0 — el comando completará.
        }

        // Estado inicial con TTL generoso para una corrida real.
        // days_back se persiste en cache para que el comando async lo lea
        // aunque --days no se pase por CLI.
        Cache::put($cacheKey, [
            'status' => 'queued',
            'progress' => 0,
            'total' => $preTotal,
            'updated' => 0,
            'processed' => 0,
            'last_progress_at' => null,
            'started_at' => null,
            'queued_at' => now()->toIso8601String(),
            'finished_at' => null,
            'error_message' => null,
            'dry_run' => $dryRun,
            'chunk' => $chunk,
            'days_back' => $daysBack,
            'include_high_risk' => $includeHighRisk,
        ], now()->addHours(self::CACHE_TTL_HOURS));

        // Crear el puntero "active" de forma ATÓMICA (SET NX). Si Cache::add
        // falla es porque otro proceso lanzó una corrida entre nuestro check
        // de arriba y este put — en ese caso volvemos a leer el puntero y
        // rechazamos con 409 igual que arriba.
        $pointerCreated = Cache::add('corrections_apply:active', ['runId' => $runId], now()->addHours(self::CACHE_TTL_HOURS));
        if (!$pointerCreated) {
            $raced = Cache::get('corrections_apply:active');
            $racedId = is_array($raced) ? ($raced['runId'] ?? null) : null;
            if ($racedId && $racedId !== $runId) {
                Cache::forget($cacheKey); // limpiar el estado inicial que escribimos: nunca se usara
                return response()->json([
                    'error'  => 'Ya hay una corrida en curso.',
                    'runId'  => $racedId,
                    'status' => 'running',
                ], 409);
            }
        }

        // PATH ABSOLUTOS para que funcione bajo php-fpm (cuyo CWD no es
        // necesariamente el del proyecto). El bug que acabo de detectar:
        // `php artisan` relativo fallaba porque 'artisan' no se encuentra
        // en el CWD del worker, así que el comando moría al instante y la
        // UI quedaba en "queued" para siempre.
        //
        // NOTA: NO redirigimos dentro del $cmd — el wrapper
        // RunsBackgroundCommands::execBackground() ya redirige toda la salida
        // a /tmp/kilo_artisan_bg.log. Antes había un `> /tmp/kilo_artisan_apply.log`
        // aquí que era pisado por el redirect del wrapper, dejando ese log
        // siempre vacío y haciendo confuso el diagnóstico.
        $phpBin = PHP_BINARY;
        if (!$phpBin || !is_executable($phpBin)) {
            $phpBin = '/usr/bin/php';
        }
        $artisanPath = base_path('artisan');
        $cmd = sprintf(
            '%s %s corrections:apply-run --run-id=%s --chunk=%d%s%s%s',
            $phpBin,
            escapeshellarg($artisanPath),
            escapeshellarg($runId),
            $chunk,
            $dryRun ? ' --dry-run' : '',
            $daysBack !== null ? ' --days=' . escapeshellarg((string) $daysBack) : '',
            $includeHighRisk ? ' --include-high-risk' : ''
        );
        $this->execBackground($cmd);

        return response()->json([
            'runId' => $runId,
            'days_back' => $daysBack,
            'include_high_risk' => $includeHighRisk,
        ], 202);
    }

    /**
     * Polling de estado para una corrida async.
     */
    public function runStatus(string $runId)
    {
        $cacheKey = "corrections_apply:{$runId}";
        $state = Cache::get($cacheKey);

        if (!$state) {
            return response()->json(['error' => 'Run no encontrado o expirado'], 404);
        }

        return response()->json($state);
    }

    /**
     * Indica si hay una corrida vigente para que la UI se re-adjunte al
     * recargar la página. Devuelve 204 si no hay corrida activa o si la
     * que apunta el puntero ya terminó (done/error) — la UI limpia el
     * estado local en ambos casos y no muestra banner.
     */
    public function activeApplyRun()
    {
        $pointer = Cache::get('corrections_apply:active');
        if (!is_array($pointer) || empty($pointer['runId'])) {
            return response()->noContent();
        }
        $state = Cache::get("corrections_apply:{$pointer['runId']}");
        if (!$state || in_array($state['status'] ?? null, ['done', 'error'], true)) {
            return response()->noContent();
        }
        return response()->json(array_merge(
            ['runId' => (string) $pointer['runId']],
            $state
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // Dictionary atomicity + context-shift protection
    // (changes/2026-08-02-corrections-dictionary-atomicity)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Devuelve las sugerencias atómicas (unigramas + bigramas) extraídas
     * del wrong_text de una corrección aprobada, deduplicadas contra el
     * diccionario existente, con traducción tentativa basada en consenso.
     *
     * Path: GET /ia/correcciones/{id}/atomicity-suggestions
     */
    public function atomicitySuggestions(int $id, CorrectionService $service)
    {
        $correction = Correction::findOrFail($id);
        $suggestions = $service->extractAtomicitySuggestions($correction, 20);
        return response()->json([
            'correction_id' => $correction->id,
            'wrong_text' => $correction->wrong_text,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Crea correcciones nuevas a partir de un batch de sugerencias atómicas
     * seleccionadas por el admin. source='atomicity-from-{parentId}' para
     * trazabilidad.
     *
     * Path: POST /ia/correcciones/{id}/atomicity-suggestions/bulk-add
     */
    public function bulkCreateAtomicityFromCorrection(Request $request, int $id, CorrectionService $service)
    {
        $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.wrong' => 'required|string|max:100',
            'items.*.correct' => 'required|string|max:500',
        ]);

        $parent = Correction::findOrFail($id);
        $admin = $this->adminUser();
        $created = [];
        $skipped = [];

        foreach ($request->input('items') as $item) {
            $wrong = trim((string) $item['wrong']);
            $correct = trim((string) $item['correct']);
            if ($wrong === '' || $correct === '') {
                $skipped[] = ['wrong' => $wrong, 'reason' => 'empty'];
                continue;
            }
            try {
                $correction = $service->upsertApproved($wrong, $correct, $admin);
                // Tag source con referencia al parent (no pisar si ya tenía source custom)
                if (empty($correction->source) || str_starts_with($correction->source, 'atomicity-from-')) {
                    $correction->source = 'atomicity-from-' . $parent->id;
                    $correction->save();
                }
                $created[] = ['id' => $correction->id, 'wrong' => $wrong, 'correct' => $correct];
            } catch (\Throwable $e) {
                $skipped[] = ['wrong' => $wrong, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'parent_id' => $parent->id,
        ], empty($created) ? 422 : 201);
    }

    /**
     * Elimina en bulk las reglas inactivas (applies_count=0) creadas hace más de N días.
     * Cambios/2026-08-02-corrections-dictionary-atomicity.
     *
     * Body: { min_age_days?: int=30, max_count?: int=500 }
     * Path: POST /ia/correcciones/bulk-destroy-inactive
     */
    public function bulkDestroyInactive(Request $request, CorrectionService $service)
    {
        $data = $request->validate([
            'min_age_days' => 'nullable|integer|min:0|max:3650',
            'max_count' => 'nullable|integer|min:1|max:5000',
        ]);

        $admin = $this->adminUser();
        $result = $service->bulkDestroyInactive(
            (int) ($data['min_age_days'] ?? 30),
            (int) ($data['max_count'] ?? 500),
            $admin
        );

        return response()->json($result);
    }

    /**
     * Reporte del diccionario (totales, effectiveness, top n-gramas, dups, clusters).
     * Cambios/2026-08-02-corrections-dictionary-atomicity.
     *
     * Path: GET /ia/correcciones/dictionary-audit
     */
    public function auditReport(DictionaryAudit $audit)
    {
        return response()->json($audit->run());
    }

    /**
     * Dry-run del ContextShiftAuditor: retorna las sugerencias de risk_level
     * para que el admin las revise antes de aplicar.
     *
     * Path: GET /ia/correcciones/context-audit
     */
    public function contextAudit(ContextShiftAuditor $auditor)
    {
        $suggestions = $auditor->audit();
        $items = [];
        foreach ($suggestions as $id => $s) {
            $correction = Correction::find($id);
            // Excluir overrides manuales a 'low': el admin ya revisó esta fila y
            // decidió que NO es context-sensitive (override one-shot). Sin este
            // filtro, la fila reaparece tras setRiskLevel(id, 'low') porque el
            // auditor sigue sugiriendo el patrón; la UI la ignora porque no
            // aparece en la lista.
            if (!$correction || $correction->risk_level === Correction::RISK_LOW) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'suggested_risk' => $s['risk'],
                'risk_level' => $correction->risk_level,
                'matched' => $s['matched'] ?? null,
                'reason' => $s['reason'],
                'type' => $s['type'] ?? 'unknown',
                'safe_translations' => $s['safe_translations'] ?? [],
            ];
        }
        return response()->json([
            'total' => count($items),
            'suggestions' => $items,
        ]);
    }

    /**
     * Aplica las sugerencias del auditor a la BD (solo pisa risk_level='low').
     *
     * Path: POST /ia/correcciones/context-audit
     */
    public function contextAuditApply(ContextShiftAuditor $auditor)
    {
        $result = $auditor->applyToDb(false);
        return response()->json($result);
    }

    /**
     * Override manual de risk_level por parte del admin.
     * Cambios/2026-08-02-corrections-dictionary-atomicity.
     *
     * Body: { risk_level: 'low'|'medium'|'high' }
     * Path: PATCH /ia/correcciones/{id}/risk-level
     *
     * No re-ejecuta el auditor, solo cambia el flag manualmente. Si después se
     * corre `corrections:context-audit --apply` y la regla sigue matcheando la
     * blocklist, será sobreescrita (overrides one-shot, documentado en spec).
     */
    public function setRiskLevel(Request $request, int $id)
    {
        $data = $request->validate([
            'risk_level' => 'required|string|in:low,medium,high',
        ]);

        $correction = Correction::findOrFail($id);
        $correction->risk_level = $data['risk_level'];
        $correction->save();

        return response()->json([
            'ok' => true,
            'id' => $correction->id,
            'risk_level' => $correction->risk_level,
        ]);
    }

    /**
     * Helper compartido: evalúa una corrección contra el ContextShiftAuditor
     * y devuelve un array serializable si hay warning, o null.
     *
     * @return ?array{risk: string, matched: ?string, type: string, reason: string, safe_translations: array}
     */
    private function buildContextWarning(Correction $c): ?array
    {
        $warning = app(ContextShiftAuditor::class)->evaluateOne(
            (object) [
                'id' => $c->id,
                'wrong_text' => $c->wrong_text,
                'correct_text' => $c->correct_text,
                'risk_level' => $c->risk_level,
            ],
            config('corrections.context_sensitive')
        );
        if ($warning === null) {
            return null;
        }
        return [
            'risk' => $warning['risk'],
            'matched' => $warning['matched'] ?? null,
            'type' => $warning['type'] ?? 'unknown',
            'reason' => $warning['reason'],
            'safe_translations' => $warning['safe_translations'] ?? [],
        ];
    }

    private function adminUser()
    {
        $id = (int) Session::get('user_id');
        return \App\Models\User::findOrFail($id);
    }

    /**
     * Aprobación masiva de correcciones pendientes.
     * Body: { ids: [1,2,3,...] } (max config('corrections.bulk_max_ids'))
     * Respuesta: { approved, merged, errors, bulk_action_id, undo_expires_at }
     */
    public function bulkApprove(Request $request, CorrectionService $service)
    {
        $max = (int) config('corrections.bulk_max_ids', 500);
        $data = $request->validate([
            'ids' => "required|array|min:1|max:$max",
            'ids.*' => 'integer|min:1',
        ]);

        $admin = $this->adminUser();
        $result = $service->bulkApprove($data['ids'], $admin);

        return response()->json($result);
    }

    /**
     * Rechazo masivo con motivo común.
     * Body: { ids: [...], rejected_reason?: "..." }
     */
    public function bulkReject(Request $request, CorrectionService $service)
    {
        $max = (int) config('corrections.bulk_max_ids', 500);
        $data = $request->validate([
            'ids' => "required|array|min:1|max:$max",
            'ids.*' => 'integer|min:1',
            'rejected_reason' => 'nullable|string|max:1000',
        ]);

        $admin = $this->adminUser();
        $result = $service->bulkReject(
            $data['ids'],
            $data['rejected_reason'] ?? null,
            $admin
        );

        return response()->json($result);
    }

    /**
     * Eliminación masiva de correcciones aprobadas. NO reversible.
     * Body: { ids: [...] }
     */
    public function bulkDestroy(Request $request, CorrectionService $service)
    {
        $max = (int) config('corrections.bulk_max_ids', 500);
        $data = $request->validate([
            'ids' => "required|array|min:1|max:$max",
            'ids.*' => 'integer|min:1',
        ]);

        $admin = $this->adminUser();
        $result = $service->bulkDestroy($data['ids'], $admin);

        return response()->json($result);
    }

    /**
     * Eliminación masiva de correcciones PENDIENTES (ruido del miner/AI Suggest).
     * A diferencia de bulkDestroy (que solo acepta approved), este endpoint
     * acepta solo pending y borra sin snapshot (no es reversible, no hay undo).
     *
     * Body: { ids: [...] }
     * Path: POST /ia/correcciones/bulk-destroy-pending
     */
    public function bulkDestroyPending(Request $request, CorrectionService $service)
    {
        $max = (int) config('corrections.bulk_max_ids', 500);
        $data = $request->validate([
            'ids' => "required|array|min:1|max:$max",
            'ids.*' => 'integer|min:1',
        ]);

        $admin = $this->adminUser();
        $result = $service->bulkDestroyPending($data['ids'], $admin);

        return response()->json($result);
    }

    /**
     * Revierte una acción masiva dentro de la ventana de undo.
     * Path: POST /correcciones/undo/{bulkActionId}
     * Códigos de error:
     *   410: ventana expirada
     *   409: ya revertida, superseded, o bulk_destroy (no reversible)
     *   404: no encontrada
     */
    public function undoBulkAction(string $bulkActionId, CorrectionService $service)
    {
        try {
            $admin = $this->adminUser();
            $result = $service->undoBulkAction($bulkActionId, $admin);
            return response()->json($result);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'expiró')) {
                return response()->json(['error' => $msg], 410);
            }
            if (str_contains($msg, 'ya fue') || str_contains($msg, 'superada') || str_contains($msg, 'no es reversible')) {
                return response()->json(['error' => $msg], 409);
            }
            return response()->json(['error' => $msg], 404);
        }
    }

    /**
     * Triage en capas de correcciones pending. POST /ia/correcciones/triage-pending.
     * Cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage.
     *
     * Body:
     *   dry_run             (bool, default true)  — solo reporte, no escribe
     *   auto_approve_keep   (bool, default false) — auto-aprueba las KEEP vía bulkApprove
     *   max                 (int,  default 10000) — tope de candidatas por corrida
     *   days_back           (int?, optional)      — filtrar a últimos N días
     *
     * Retorna el estado del run (incluye run_id para polling) o el resultado
     * final si la corrida ya terminó sincrónicamente (corrida muy corta).
     */
    public function triagePending(Request $request, \App\Services\Ia\CorrectionTriageService $service)
    {
        $data = $request->validate([
            'dry_run' => 'sometimes|boolean',
            'auto_approve_keep' => 'sometimes|boolean',
            'max' => 'sometimes|integer|min:1|max:50000',
            'days_back' => 'sometimes|integer|min:1|max:365',
        ]);

        $admin = $this->adminUser();
        $dryRun = (bool) ($data['dry_run'] ?? true);
        $autoApproveKeep = !$dryRun && (bool) ($data['auto_approve_keep'] ?? false);

        try {
            $result = $service->run(
                dryRun: $dryRun,
                autoApproveKeep: $autoApproveKeep,
                max: (int) ($data['max'] ?? 10000),
                daysBack: $data['days_back'] ?? null,
                by: $admin,
            );

            return response()->json($result);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Ya hay un triage activo')) {
                return response()->json(['error' => $msg], 409);
            }
            return response()->json(['error' => $msg], 422);
        }
    }

    /**
     * Estado del run de triage (polling desde la UI). GET /ia/correcciones/triage-pending/{runId}.
     */
    public function triageRunStatus(string $runId, \App\Services\Ia\CorrectionTriageService $service)
    {
        $state = $service->getStatus($runId);
        if (!$state) {
            return response()->json(['error' => 'run_not_found', 'run_id' => $runId], 404);
        }
        return response()->json($state);
    }

    /**
     * Estado del miner EN↔ES para el badge del header. Retorna la fecha
     * del último lote minado (created_at más reciente entre pending con
     * source='mining-%') y el conteo de pendientes aún sin revisar.
     *
     * Path: GET /ia/correcciones/mining-status
     */
    public function miningStatus()
    {
        $lastMining = Correction::query()
            ->where('source', 'LIKE', 'mining-%')
            ->orderByDesc('created_at')
            ->first(['created_at']);

        $pendingFromMining = Correction::pending()
            ->where('source', 'LIKE', 'mining-%')
            ->count();

        return response()->json([
            'last_mining_at' => $lastMining?->created_at?->toIso8601String(),
            'pending_from_mining' => $pendingFromMining,
        ]);
    }

    /**
     * Estado del suggester LLM-powered EN↔ES para el segundo badge del
     * header. Análogo a miningStatus pero para source='ai-suggest-%'.
     *
     * Path: GET /ia/correcciones/ai-suggest-status
     */
    public function aiSuggestStatus()
    {
        $lastAi = Correction::query()
            ->where('source', 'LIKE', 'ai-suggest-%')
            ->orderByDesc('created_at')
            ->first(['created_at']);

        $pendingFromAi = Correction::pending()
            ->where('source', 'LIKE', 'ai-suggest-%')
            ->count();

        return response()->json([
            'last_ai_suggest_at' => $lastAi?->created_at?->toIso8601String(),
            'pending_from_ai_suggest' => $pendingFromAi,
        ]);
    }

    /**
     * Exporta todas las correcciones a CSV (original + corrección + metadatos)
     * para que el admin pueda validarlas fuera del navegador con más detenimiento.
     *
     * Query params (todos opcionales):
     *   - status: 'all' | 'pending' | 'approved' | 'rejected' (default 'all')
     *   - source: filtra por source exacto (ej: 'mining-2026-08-01')
     *   - q: búsqueda libre (case-insensitive) sobre wrong_text y correct_text
     *
     * El archivo se sirve como text/csv con nombre
     * `correcciones-<status>-<YYYYMMDD-HHMMSS>.csv`. Usa streaming
     * (streamDownload + fputcsv) para no agotar memoria con miles de filas.
     *
     * Path: GET /ia/correcciones/export
     */
    public function export(Request $request)
    {
        $statusFilter = (string) $request->input('status', 'all');
        if (!in_array($statusFilter, ['all', Correction::STATUS_PENDING, Correction::STATUS_APPROVED, Correction::STATUS_REJECTED], true)) {
            $statusFilter = 'all';
        }

        $source = trim((string) $request->input('source', ''));
        $q = trim((string) $request->input('q', ''));

        $query = Correction::query()
            ->with(['proposedBy:id,username', 'approvedBy:id,username'])
            ->orderByDesc('id');

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }
        if ($source !== '') {
            $query->where('source', $source);
        }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('wrong_text', 'like', $like)
                    ->orWhere('correct_text', 'like', $like);
            });
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="correcciones-' . $statusFilter . '-' . now()->format('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para que Excel detecte acentos/ñ correctamente.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id',
                'status',
                'source',
                'original',
                'correccion',
                'wrong_normalized',
                'applies_count',
                'proposed_by',
                'approved_by',
                'rejected_reason',
                'created_at',
                'approved_at',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->id,
                        $r->status,
                        $r->source ?? '',
                        $r->wrong_text,
                        $r->correct_text,
                        $r->wrong_normalized ?? '',
                        (int) $r->applies_count,
                        $r->proposedBy?->username ?? '',
                        $r->approvedBy?->username ?? '',
                        $r->rejected_reason ?? '',
                        $r->created_at?->toIso8601String() ?? '',
                        $r->approved_at?->toIso8601String() ?? '',
                    ]);
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Configuración del suggester: lee valores efectivos (BD > env > archivo)
     * junto con origen y schema para que la UI pueda pintar el formulario.
     *
     * La `api_key` se reporta solo como `has_key: true|false` — nunca el valor
     * (credencial sellada en .env).
     *
     * Path: GET /ia/correcciones/ai-suggest-settings
     */
    public function aiSuggestSettings(\App\Services\Ia\LlmCorrectionSettings $settings)
    {
        $effective = $settings->effective();
        return response()->json([
            'settings' => $effective,
            'has_api_key' => $settings->apiKey() !== '',
            'api_key_source' => $settings->apiKeySource(),
            'available_models' => $settings->availableModels(),
            'quick_action_windows' => $settings->quickActionWindows(),
        ]);
    }

    /**
     * Fuerza un refetch de la lista de modelos del gateway. Útil cuando
     * el admin sospecha que hay modelos nuevos y el cache aún no expira.
     *
     * Path: POST /ia/correcciones/ai-suggest-settings/refresh-models
     */
    public function aiSuggestSettingsRefreshModels(\App\Services\Ia\LlmCorrectionSettings $settings)
    {
        $models = $settings->refreshModels();
        return response()->json([
            'available_models' => $models,
            'count' => count($models),
        ]);
    }

    /**
     * Actualiza uno o más valores de configuración del suggester.
     * Valida con el schema del servicio y persiste; el cambio aplica en el
     * siguiente request (cache TTL 60s + memo 30s).
     *
     * Cuerpo JSON:
     *   - values: { enabled: bool, model: str, days_back: int, ... }
     *
     * Path: POST /ia/correcciones/ai-suggest-settings
     */
    public function aiSuggestSettingsUpdate(Request $request, \App\Services\Ia\LlmCorrectionSettings $settings)
    {
        $values = $request->input('values', []);
        if (!is_array($values) || $values === []) {
            return response()->json(['error' => 'No se recibieron valores.'], 422);
        }

        [$clean, $errors] = $settings->validate($values);
        if ($errors) {
            return response()->json([
                'error' => 'Hay valores inválidos.',
                'errors' => $errors,
            ], 422);
        }

        $settings->set($clean);

        \Illuminate\Support\Facades\Log::info('LlmCorrectionSettings: configuración modificada', [
            'user_id' => \Illuminate\Support\Facades\Session::get('user_id'),
            'keys' => array_keys($clean),
            'values' => array_map(fn($v) => is_scalar($v) ? $v : '<non-scalar>', $clean),
        ]);

        return response()->json([
            'ok' => true,
            'settings' => $settings->effective(),
        ]);
    }

    /**
     * Restaura valores a los defaults de .env (o al literal de config).
     * Borra las filas en system_settings para las claves indicadas.
     *
     * Cuerpo JSON:
     *   - keys: [str] (vacio = todas)
     *
     * Path: DELETE /ia/correcciones/ai-suggest-settings
     */
    public function aiSuggestSettingsReset(Request $request, \App\Services\Ia\LlmCorrectionSettings $settings)
    {
        $keys = $request->input('keys', []);
        if (!is_array($keys)) {
            $keys = [];
        }
        $values = $settings->reset(array_values($keys));
        return response()->json([
            'ok' => true,
            'settings' => $settings->effective(),
        ]);
    }

    /**
     * Setea la API key cifrada en SystemSetting (alternativa a .env).
     *
     * Body: { "api_key": "sk-..." }
     *
     * Vacío = borra la fila (vuelve a .env).
     *
     * Nunca se loguea el valor. Devuelve solo el origen post-save.
     *
     * Path: POST /ia/correcciones/ai-suggest-settings/api-key
     */
    public function aiSuggestSettingsApiKey(Request $request, \App\Services\Ia\LlmCorrectionSettings $settings)
    {
        $validated = $request->validate([
            'api_key' => 'required|string|max:500',
        ]);
        $trimmed = trim($validated['api_key']);

        $stored = $settings->setApiKey($trimmed);

        \Illuminate\Support\Facades\Log::info('LlmCorrectionSettings: API key actualizada', [
            'user_id' => \Illuminate\Support\Facades\Session::get('user_id'),
            'cleared' => !$stored,
        ]);

        return response()->json([
            'ok' => true,
            'cleared' => !$stored,
            'api_key_source' => $settings->apiKeySource(),
            'has_api_key' => $settings->apiKey() !== '',
        ]);
    }

    /**
     * Invoca el suggester LLM-powered EN↔ES de forma SÍNCRONA desde el
     * botón "AI Suggest" del admin. Retorna JSON con los candidatos
     * detectados. Si `insert=true`, los persiste como pending antes de
     * retornar.
     *
     * Cuerpo JSON:
     *   - days (int, default 1): ventana de análisis
     *   - sample (int, default 200): tamaño de muestra
     *   - insert (bool, default false): si true, persiste los candidatos
     *     aceptados como pending (corrección típica del flow admin).
     *
     * Path: POST /ia/correcciones/ai-suggest-now
     *
     * Diseñado para control de gasto: el admin decide cuándo correr el
     * LLM. El endpoint es síncrono porque el suggester típicamente
     * completa en 5-30 segundos; no necesita runId async como el
     * retroactivo.
     */
    public function aiSuggestNow(Request $request, CorrectionService $service, \App\Services\Ia\LlmCorrectionSettings $settings)
    {
        if (!$settings->bool('enabled')) {
            return response()->json([
                'error' => 'Suggest deshabilitado desde Configuración / IA Suggest.',
                'hint' => 'Activa el toggle "Habilitado" en el tab IA Suggest.',
            ], 503);
        }

        $apiKey = $settings->apiKey();
        if ($apiKey === '') {
            return response()->json([
                'error' => 'LLM_API_KEY no configurada.',
                'hint' => 'Pegala en el campo "API key" del tab IA Suggest → Guardar key.',
                'api_key_source' => $settings->apiKeySource(),
            ], 503);
        }

        $validated = $request->validate([
            // Rango alineado con quickActionWindows() y con la lógica de
            // `days_back` setting (que admite 1-14 por default). El admin
            // puede setear ventanas de 15d/30d/60d/90d vía Botones rápidos
            // del header, así que dejamos max más alto aquí.
            'days' => 'nullable|integer|min:1|max:90',
            'sample' => 'nullable|integer|min:10|max:1000',
            'insert' => 'nullable|boolean',
        ]);

        $days = $validated['days'] ?? $settings->int('days_back');
        $sample = $validated['sample'] ?? $settings->int('sample_size');
        $insert = (bool) ($validated['insert'] ?? false);

        try {
            if ($insert) {
                $admin = $this->adminUser();
                $result = $service->aiSuggestEnEsMix($days, $sample, $admin);
                return response()->json([
                    'inserted' => true,
                    'mined' => $result['mined'],
                    'inserted_count' => $result['inserted'],
                    'skipped_duplicate' => $result['skipped_duplicate'],
                    'rejected_by_filter' => $result['rejected_by_filter'],
                    'segments_processed' => $result['segments_processed'],
                    'cached_today' => $result['cached_today'],
                    'source' => $result['source'],
                ]);
            }

            // Dry-run path: solo retorna candidatos sin insertar.
            $suggester = new \App\Services\Ia\LlmCorrectionSuggester();
            $result = $suggester->suggest($days, $sample);

            if (isset($result['error'])) {
                return response()->json([
                    'inserted' => false,
                    'error' => $result['error'],
                ], 502);
            }

            return response()->json([
                'inserted' => false,
                'candidates' => $result['candidates'],
                'rejected_by_filter' => $result['rejected_by_filter'],
                'segments_processed' => $result['segments_processed'],
                'cached_today' => $result['cached_today'],
                'source' => $result['source'],
            ]);
        } catch (\Throwable $e) {
            // Diagnóstico estructurado por tipo de fallo:
            //   - 401/403 → 503 Service Unavailable: auth/credits son problema del
            //     setup local (key rota, sin saldo, modelo no disponible en la cuenta).
            //     NO es culpa del gateway como tal.
            //   - 5xx / timeout / parse → 502 Bad Gateway: el upstream falló.
            //   - Otros → 500: bug local.
            $msg = $e->getMessage();
            $httpCode = null;
            if (preg_match('/LLM HTTP (\d{3}):/', $msg, $m)) {
                $httpCode = (int) $m[1];
            }

            if ($httpCode === 401 || $httpCode === 403) {
                $status = 503;
                $userMsg = 'El gateway rechazó la autenticación o el modelo requiere créditos.';
                $hint = match (true) {
                    str_contains($msg, 'PAID_MODEL_AUTH_REQUIRED') => 'La cuenta no tiene créditos o el modelo MiniMax MiniMax requiere plan pago. Probá un modelo :free o agregá saldo en app.kilo.ai.',
                    str_contains($msg, 'Invalid API Key') || str_contains($msg, 'Invalid api_key') => 'API key inválida. Verificá en app.kilo.ai → Settings → API Keys.',
                    str_contains($msg, 'Forbidden') => 'Acceso denegado. ¿La organización tiene allow-list para ese modelo?',
                    default => 'Revisá tu API key y los créditos de la cuenta.',
                };
            } elseif ($httpCode !== null && $httpCode >= 500) {
                $status = 502;
                $userMsg = 'El gateway de Kilo tuvo un error interno.';
                $hint = 'Reintentá en unos minutos. Si persiste, abrí un ticket en kilo.ai.';
            } elseif (str_contains($msg, 'LLM HTTP 408') || str_contains($msg, 'timeout') || str_contains($msg, 'cURL error 28')) {
                $status = 504;
                $userMsg = 'Timeout al llamar al gateway.';
                $hint = 'Subí el timeout en AI Settings o reintentá más tarde.';
            } else {
                $status = 500;
                $userMsg = 'Error inesperado.';
                $hint = '';
            }

            return response()->json([
                'inserted' => false,
                'error' => $userMsg,
                'hint' => $hint,
                'detail' => $msg,
                'http_code' => $httpCode,
            ], $status);
        }
    }

    /**
     * Inserta candidatos YA previsualizados sin volver a llamar al LLM.
     *
     * Diseñado para el flujo "Insertar como pendiente" del modal del AI
     * Suggest: el admin corre el suggester en modo preview (5-30s con
     * LLM), ve la tabla de candidatos, hace click en "Insertar" y este
     * endpoint persiste los candidatos en O(milisegundos) usando SOLO
     * la BD — sin re-llamar al LLM ni samplear transcripciones.
     *
     * Body JSON:
     *   candidates: [{wrong: string, correct: string}, ...]
     *   source: string (opcional; default 'ai-suggest-YYYY-MM-DD')
     *
     * Path: POST /ia/correcciones/ai-suggest-save
     *
     * Respuesta:
     *   inserted, skipped_duplicate, skipped_empty, source
     */
    public function aiSuggestSave(Request $request, CorrectionService $service, \App\Services\Ia\LlmCorrectionSettings $settings)
    {
        if (!$settings->bool('enabled')) {
            return response()->json([
                'error' => 'Suggest deshabilitado desde Configuración / IA Suggest.',
                'hint' => 'Activa el toggle "Habilitado" en el tab IA Suggest.',
            ], 503);
        }

        $request->validate([
            'candidates' => 'required|array|min:1|max:500',
            'candidates.*.wrong' => 'required|string|max:500',
            'candidates.*.correct' => 'required|string|max:1000',
            'source' => 'nullable|string|max:120',
        ]);

        $candidates = $request->input('candidates', []);
        $source = $request->input('source', 'ai-suggest-' . now()->toDateString());

        try {
            $admin = $this->adminUser();
            $result = $service->saveAiSuggestedCandidates($candidates, $source, $admin);

            return response()->json([
                'inserted' => true,
                'inserted_count' => $result['inserted'],
                'skipped_duplicate' => $result['skipped_duplicate'],
                'skipped_empty' => $result['skipped_empty'],
                'source' => $result['source'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'inserted' => false,
                'error' => 'No se pudieron guardar los candidatos previsualizados.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }
}
