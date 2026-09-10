<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\AlertDispatcher;
use App\Services\Ia\AvisosScanService;
use App\Services\Ia\MentionBackfillService;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class AvisosInteligentesController extends Controller
{
    use \App\Http\Controllers\Concerns\RunsBackgroundCommands;

    private const BG_CACHE_TTL_HOURS = 6;

    private function getUser(): ?User
    {
        $userId = Session::get('user_id');
        return $userId ? User::find($userId) : null;
    }

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            // Cobertura de canales del cliente: cuántos storages habilitados
            // tiene asignados. Con withCount se resuelve en la misma consulta
            // (evita el N+1 de recorrer userStorages.storageProvider en PHP).
            //
            // Qué se transcribe NO se cuenta aquí: es una decisión de API
            // Transcriptor sobre el storage, no un atributo del cliente.
            //
            // leftJoin a user_alerts_inteligentes + COALESCE expone
            // `module_enabled` (boolean derivado) en la misma fila, lo
            // permite ordenar server-side y distinguir "sin módulo" de
            // "módulo deshabilitado" (con withExists se confundirían).
            $query = User::query()
                ->select('users.*')
                ->selectRaw('COALESCE(uai.enabled, false) AS module_enabled')
                ->leftJoin('user_alerts_inteligentes as uai', 'uai.user_id', '=', 'users.id')
                ->with(['alertsInteligente'])
                ->withCount([
                    'userKeywords as keywords_count',
                    'storageProviders as storages_count' => fn ($q) => $q
                        ->where('storage_providers.enabled', true),
                    'storageProviders as storages_with_access' => fn ($q) => $q
                        ->where('user_storages.transcription_access', true),
                ]);

            if ($search = $request->input('q')) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $module = $request->input('module');
            if ($module === 'on') {
                $query->whereHas('alertsInteligente', fn ($q) => $q->where('enabled', true));
            } elseif ($module === 'off') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('alertsInteligente')
                      ->orWhereHas('alertsInteligente', fn ($sq) => $sq->where('enabled', false));
                });
            }

            // ─── Orden server-side (whitelist cerrada) ───
            $sortMap = [
                'username'             => 'users.username',
                'email'                => 'users.email',
                'module'               => 'module_enabled',
                'keywords_count'       => 'keywords_count',
                'storages_count'       => 'storages_count',
                'storages_with_access' => 'storages_with_access',
            ];
            $sort = (string) $request->input('sort', '');
            $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

            if ($sort === '' || !isset($sortMap[$sort])) {
                // Default: módulo activo primero, secundario estable por username.
                $query->orderByRaw('module_enabled DESC')
                      ->orderBy('users.username', 'asc');
            } elseif ($sort === 'module') {
                // Asc = activos primero (orden intuitivo: ↑ arriba los activos),
                // desc = inactivos primero.
                $directionSql = $direction === 'asc' ? 'DESC' : 'ASC';
                $query->orderByRaw("module_enabled {$directionSql}")
                      ->orderBy('users.username', 'asc');
            } else {
                $query->orderBy($sortMap[$sort], $direction)
                      ->orderBy('users.id', 'asc');
            }

            // ─── per-page whitelist ───
            $perPageAllowed = [25, 50, 100];
            $perPage = (int) $request->input('per_page', 25);
            $perPage = in_array($perPage, $perPageAllowed, true) ? $perPage : 25;

            $users = $query->paginate($perPage);
            return response()->json($users);
        }

        return view('ia.avisos-inteligentes.index');
    }

    public function show(int $userId, MentionsSearchService $search)
    {
        $user = User::with(['userKeywords', 'alertsInteligente'])->findOrFail($userId);

        // change admin-matches-and-backfill (Fase 1): los matches ahora vienen
        // de la tabla phase-1 `segment_keyword_hits` con las mismas reglas de
        // accesibilidad que el Histórico del cliente, y se agrupan server-side
        // por (transcripción, keyword) para mantener consistencia con la vista
        // del cliente.
        $perPage = in_array((int) request('per_page', 25), [25, 50, 100], true) ? (int) request('per_page', 25) : 25;
        $hitsPage = $search->visibleHitsQuery($user)
            ->select($search->hitSelect())
            ->orderByDesc('h.matched_at')
            ->paginate($perPage);

        $matches = $hitsPage; // paginator crudo para paginación server-side
        $matchGroups = MentionBackfillService::groupHits(
            $hitsPage->getCollection()->map(function ($r) {
                $r = (array) $r;
                $r['file_url'] = '/files/' . (int) ($r['id'] ? ($r['file_id'] ?? 0) : 0) . '/view?t=' . (int) ($r['start_seconds'] ?? 0);
                return $r;
            })
        );

        // Canales asignados al cliente. Aquí se concede acceso a los resultados
        // que api-transcriptor produce (transcripción_access); no se decide qué
        // se transcribe. Eso sigue siendo exclusivo de /ia/api-transcriptor y se
        // refleja aquí solo como dato informativo del storage.
        $storages = $user->storageProviders()
            ->where('storage_providers.enabled', true)
            ->orderBy('storage_providers.name')
            ->get(['storage_providers.id', 'storage_providers.name', 'storage_providers.type', 'storage_providers.transcription_enabled', 'user_storages.transcription_access'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'transcription_enabled' => (bool) $s->transcription_enabled,
                'transcription_access' => (bool) $s->pivot->transcription_access,
            ])
            ->values();

        $globalStorages = StorageProvider::where('enabled', true)->count();
        $globalTranscribing = StorageProvider::transcriptionEnabled()->count();

        return view('ia.avisos-inteligentes.user-detail', [
            'user' => $user,
            'matches' => $matches,
            'matchGroups' => $matchGroups,
            'storages' => $storages,
            'globalStorages' => $globalStorages,
            'globalTranscribing' => $globalTranscribing,
        ]);
    }

    /**
     * Lista los storages con transcription_enabled=true asignados al usuario
     * de la sesión. Alimenta los dropdowns de "Storage" en la sub-ventana
     * "Escaneo" y en la pestaña "Cobertura". Solo lectura: el módulo Avisos
     * Inteligentes no escribe transcription_enabled (ver transcription-api-orchestrator).
     */
    public function storages(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $storages = $user->storageProviders()
            ->where('storage_providers.enabled', true)
            ->where('storage_providers.transcription_enabled', true)
            ->orderBy('storage_providers.name')
            ->get(['storage_providers.id', 'storage_providers.name', 'storage_providers.type'])
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'transcription_enabled' => true,
            ])
            ->values();

        return response()->json(['storages' => $storages]);
    }

    public function toggleStorageAccess(Request $request, int $userId, int $storageId)
    {
        $request->validate([
            'access' => 'required|boolean',
        ]);

        $user = User::findOrFail($userId);
        $storage = StorageProvider::findOrFail($storageId);

        $pivot = DB::table('user_storages')
            ->where('user_id', $user->id)
            ->where('storage_provider_id', $storage->id)
            ->first();

        if (!$pivot) {
            return response()->json([
                'error' => 'Este storage no está asignado al cliente. Asígnalo primero en /admin/storages.',
            ], 422);
        }

        $access = $request->boolean('access');
        DB::table('user_storages')
            ->where('user_id', $user->id)
            ->where('storage_provider_id', $storage->id)
            ->update(['transcription_access' => $access]);

        return response()->json([
            'storage_id' => $storage->id,
            'transcription_access' => $access,
        ]);
    }

    public function updateUser(Request $request, int $userId)
    {
        $request->validate([
            'enabled' => 'nullable|boolean',
            'keywords_quota' => 'nullable|integer|min:0',
            'emails_quota' => 'nullable|integer|min:0',
        ]);

        $config = UserAlertsInteligente::firstOrNew(['user_id' => $userId]);
        $config->fill($request->only(['enabled', 'keywords_quota', 'emails_quota']));
        $config->save();

        return response()->json($config->fresh());
    }

    public function storeEmail(Request $request, int $userId)
    {
        $request->validate(['email' => 'required|email']);

        $config = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);
        $emails = $config->emailsList();

        if (in_array($request->email, $emails, true)) {
            return response()->json(['message' => 'Ya registrado'], 200);
        }

        if (count($emails) >= $config->emails_quota) {
            return response()->json([
                'error' => 'Cupo de correos excedido (quedan ' . max(0, $config->emails_quota - count($emails)) . ' cupos disponibles)',
            ], 422);
        }

        $emails[] = $request->email;
        $config->emails = $emails;
        $config->save();

        return response()->json(['emails' => $config->emailsList()], 201);
    }

    public function destroyEmail(int $userId, string $email)
    {
        $config = UserAlertsInteligente::where('user_id', $userId)->firstOrFail();
        $emails = array_values(array_filter($config->emailsList(), fn ($e) => $e !== $email));
        $config->emails = $emails;
        $config->save();

        return response()->json(['emails' => $config->emailsList()]);
    }

    public function storeKeyword(Request $request, int $userId)
    {
        $validated = $request->validate(['text' => 'required|string|max:200']);
        $text = $validated['text'] ?? null;
        $config = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);

        $used = DB::table('user_keyword')->where('user_id', $userId)->count();
        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo de keywords alcanzado ({$used}/{$config->keywords_quota})",
            ], 422);
        }

        // avisos-keyword-word-boundary: guardrail anti-abuso. Una keyword
        // demasiado corta ("el", "a") matchearía casi todo el corpus y
        // inflaría el coste computacional del scan compartido. El mínimo es
        // sobre la forma NORMALIZADA (sin espacios ni tildes).
        if (!\App\Http\Controllers\MisAvisosController::passesMinLength((string) $text)) {
            return response()->json([
                'error' => 'La keyword debe tener al menos 3 caracteres (sin contar espacios)',
            ], 422);
        }

        $normalized = Keyword::normalize((string) $text);
        $keyword = Keyword::firstOrCreate(
            ['normalized' => $normalized],
            ['text' => trim((string) $text)]
        );

        DB::table('user_keyword')->insertOrIgnore([
            'user_id' => $userId,
            'keyword_id' => $keyword->id,
            'created_at' => now(),
        ]);

        $used++;
        return response()->json([
            'keyword' => $keyword,
            'used' => $used,
            'quota' => $config->keywords_quota,
        ], 201);
    }

    public function destroyKeyword(int $userId, int $keywordId)
    {
        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->delete();

        return response()->json(['message' => 'Keyword eliminada']);
    }

    public function testEmail(Request $request, int $userId, string $email, AlertDispatcher $dispatcher)
    {
        $user = User::findOrFail($userId);
        $result = $dispatcher->sendTest($user, $email);
        return response()->json($result, $result['success'] ?? false ? 200 : 422);
    }

    public function matches(int $userId, Request $request, MentionsSearchService $search)
    {
        // change admin-matches-and-backfill: ahora devolvemos hits de
        // segment_keyword_hits (phase-1) agrupados por (transcripción, keyword),
        // con el mismo shape {groups: [...], total: N, current_page: ...} que la
        // vista admin server-side.
        $user = User::findOrFail($userId);
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100], true)
            ? (int) $request->input('per_page', 25)
            : 25;

        $hitsPage = $search->visibleHitsQuery($user)
            ->select($search->hitSelect())
            ->orderByDesc('h.matched_at')
            ->paginate($perPage);

        $groups = MentionBackfillService::groupHits(
            $hitsPage->getCollection()->map(fn ($r) => (array) $r)
        )->values();

        return response()->json([
            'groups' => $groups,
            'total' => $hitsPage->total(),
            'current_page' => $hitsPage->currentPage(),
            'last_page' => $hitsPage->lastPage(),
            'per_page' => $hitsPage->perPage(),
        ]);
    }

    // ─── Escaneo de menciones (avisos-scan-configuration) ─────────────────

    /** Estado del escaneo: settings + últimas corridas + pendientes estimados. */
    public function scanStatus(Request $request, AvisosScanService $service)
    {
        // Estimado con filtros explícitos (preset/rango/storage) si vienen en
        // la query: el modal de confirmación muestra el estimado real de la
        // corrida que se va a lanzar, no el de la ventana global.
        $filterOpts = $service->filterOptsFromRequest($request);

        $pending = $filterOpts !== null
            ? $service->estimate($filterOpts)
            : ($request->boolean('no_window')
                ? $service->estimate(['noWindow' => true])
                : $service->estimate());

        return response()->json([
            'settings' => $service->settings(),
            'last_run' => $service->lastRun(),
            'runs' => $service->recentRuns(10),
            'pending_estimate' => $pending,
            'total_pending_estimate' => $service->estimate(['noWindow' => true]),
        ]);
    }

    /** Guarda la configuración del escaneo automático. */
    public function saveScanSettings(Request $request, AvisosScanService $service)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'intervalMinutes' => 'required|integer|min:5|max:1440',
            'windowHours' => 'required|integer|min:1|max:720',
        ]);

        $settings = $service->saveSettings([
            'enabled' => $validated['enabled'],
            'intervalMinutes' => $validated['intervalMinutes'],
            'windowHours' => $validated['windowHours'],
        ]);

        return response()->json(['ok' => true, 'settings' => $settings]);
    }

    /** Corrida manual (sincrónica, acotada por lote). */
    public function runScan(Request $request, AvisosScanService $service)
    {
        $validated = $request->validate([
            'storageId' => 'nullable|integer|exists:storage_providers,id',
            // Rango con hora opcional: 'Y-m-d' o 'Y-m-d H:i[:s]' (datetime-local).
            'from' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'to' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'preset' => 'nullable|in:8h,24h,3d,7d,today',
            'limit' => 'nullable|integer|min:1|max:500',
            'force' => 'nullable|boolean',
            'confirmed' => 'nullable|boolean',
            'noWindow' => 'nullable|boolean',
            // Drenaje secuencial: IDs ya intentados en tandas previas.
            'excludeIds' => 'nullable|array|max:10000',
            'excludeIds.*' => 'integer',
        ]);

        $opts = [
            'origin' => 'manual',
            'storageId' => $validated['storageId'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'preset' => $validated['preset'] ?? null,
            'limit' => $validated['limit'] ?? 50,
            'force' => (bool) ($validated['force'] ?? false),
            'noWindow' => (bool) ($validated['noWindow'] ?? false),
            'excludeIds' => $validated['excludeIds'] ?? [],
        ];

        // Corrida masiva sin confirmación explícita: devuelve la estimación
        // y pide confirmación antes de ejecutar.
        $estimate = $service->estimate($opts);
        if ($estimate > (int) $opts['limit'] && !($validated['confirmed'] ?? false)) {
            return response()->json([
                'needs_confirmation' => true,
                'estimate' => $estimate,
                'limit' => (int) $opts['limit'],
                'message' => "La corrida afecta ~{$estimate} transcripciones (lote: {$opts['limit']}). Confirma para ejecutar de todas formas.",
            ], 409);
        }

        $result = $service->run($opts);

        return response()->json(['ok' => $result['status'] !== 'failed', 'result' => $result]);
    }

    /**
     * Corrida manual en BACKGROUND (avisos-scan-bg-runner): crea el estado en
     * cache, lanza avisos:scan-run con setsid y retorna el runId para polling.
     * El escaneo sobrevive recargas, cierre de navegador y cortes de red.
     */
    public function runScanBackground(Request $request, AvisosScanService $service)
    {
        // Envoltura defensiva (fix-avisos-scanlaunch-from-undefined-key): cualquier
        // excepción interna ahora devuelve un 5xx con `error` legible al operador,
        // en vez del genérico "Server Error" de Laravel. El admin ve la traza
        // completa en laravel.log via Log::error.
        try {
        $validated = $request->validate([
            'storageId' => 'nullable|integer|exists:storage_providers,id',
            'from' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'to' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'preset' => 'nullable|in:8h,24h,3d,7d,today',
            'limit' => 'nullable|integer|min:1|max:500',
            'force' => 'nullable|boolean',
            'noWindow' => 'nullable|boolean',
        ]);

        // Fix bug (fix-avisos-scanlaunch-from-undefined-key): el operador elige
        // "Histórico completo" → el body NO trae `from`/`to`. Extraemos a
        // variables locales con `??` para que el cálculo de window_label no
        // acceda a claves inexistentes (PHP 8.4 lanza ErrorException).
        $preset = $validated['preset'] ?? null;
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        // Anti-duplicado: un solo runner de escaneo activo a la vez.
        $pointer = \Illuminate\Support\Facades\Cache::get('avisos_scan_bg:active');
        if (is_array($pointer) && !empty($pointer['runId'])) {
            $activeState = \Illuminate\Support\Facades\Cache::get('avisos_scan_bg:' . $pointer['runId']);
            $orphan = !$activeState
                || in_array($activeState['status'] ?? null, ['done', 'error', 'stopped'], true);
            if ($orphan) {
                \Illuminate\Support\Facades\Cache::forget('avisos_scan_bg:active');
            } else {
                return response()->json([
                    'error' => 'Ya hay un escaneo en curso.',
                    'runId' => (string) $pointer['runId'],
                    'status' => $activeState['status'] ?? 'running',
                ], 409);
            }
        }

        $runId = $this->generateRunId('avisos_scan');
        $cacheKey = "avisos_scan_bg:{$runId}";

        $state = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'storageId' => $validated['storageId'] ?? null,
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'limit' => $validated['limit'] ?? 50,
            'force' => (bool) ($validated['force'] ?? false),
            'noWindow' => (bool) ($validated['noWindow'] ?? false),
            'window_label' => $preset ?: (($from || $to) ? 'custom' : 'global'),
            'scanned' => 0,
            'hits_new' => 0,
            'failed' => 0,
            'batches' => 0,
        ];
        \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(self::BG_CACHE_TTL_HOURS));

        if (!\Illuminate\Support\Facades\Cache::add('avisos_scan_bg:active', ['runId' => $runId], now()->addHours(self::BG_CACHE_TTL_HOURS))) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            $raced = \Illuminate\Support\Facades\Cache::get('avisos_scan_bg:active');
            return response()->json([
                'error' => 'Ya hay un escaneo en curso.',
                'runId' => is_array($raced) ? ($raced['runId'] ?? null) : null,
                'status' => 'running',
            ], 409);
        }

        // Detectar modo mensual (fix-avisos-scan-by-months-no-saturation):
        // cuando noWindow && force && !from && !to && !preset, el worker debe
        // iterar por fases mensuales para no saturar la BD con queries sobre
        // todo el historial.
        $isMonthly = !empty($state['noWindow'])
            && ($state['force'] ?? false)
            && empty($state['from'])
            && empty($state['to'])
            && empty($state['preset']);

        if ($isMonthly) {
            $plan = $service->planMonths();
            $state['mode'] = 'monthly';
            $state['month_plan'] = $plan;
            $state['months_total'] = count($plan);
            $state['months_done'] = 0;
            $state['current_month'] = null;
            \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(self::BG_CACHE_TTL_HOURS));
            $monthCount = count($plan);
            $firstMonth = $plan[0][0] ?? null;
            $lastMonth = $plan[$monthCount - 1][0] ?? null;
        } else {
            $state['mode'] = 'classic';
            \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(self::BG_CACHE_TTL_HOURS));
            $monthCount = 0;
            $firstMonth = null;
            $lastMonth = null;
        }

        // Lanzar el worker (mismo patrón probado que corrections:apply-run).
        $artisanPath = base_path('artisan');
        $cmd = sprintf(
            '%s %s avisos:scan-run --run-id=%s --limit=%d%s%s%s%s%s',
            $this->resolvePhpCli(),
            escapeshellarg($artisanPath),
            escapeshellarg($runId),
            (int) $state['limit'],
            !empty($state['storageId']) ? ' --storage=' . escapeshellarg((string) $state['storageId']) : '',
            !empty($state['from']) ? ' --from=' . escapeshellarg((string) $state['from']) : '',
            !empty($state['to']) ? ' --to=' . escapeshellarg((string) $state['to']) : '',
            !empty($state['preset']) ? ' --preset=' . escapeshellarg((string) $state['preset']) : '',
            !empty($state['noWindow']) ? ' --no-window' : ''
        );
        if (!empty($state['force'])) {
            $cmd .= ' --force';
        }
        if ($isMonthly) {
            $cmd .= ' --monthly';
        }
        $this->execBackground($cmd, 'avisos:scan-run');

        // Liveness ping: el worker debe pasar queued → running en 2s.
        usleep(2_000_000);
        $postState = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $postStatus = is_array($postState) ? ($postState['status'] ?? null) : null;
        if ($postStatus === null || $postStatus === 'queued') {
            if (is_array($postState)) {
                $postState['status'] = 'error';
                $postState['error_message'] = 'El worker no arrancó — revisá /tmp/kilo_artisan_bg.log (filtro: [avisos:scan-run])';
                $postState['finished_at'] = now()->toIso8601String();
                \Illuminate\Support\Facades\Cache::put($cacheKey, $postState, now()->addHours(self::BG_CACHE_TTL_HOURS));
            }
            \Illuminate\Support\Facades\Cache::forget('avisos_scan_bg:active');
            Log::warning('AvisosInteligentesController: worker de scan-run no pasó a running', [
                'run_id' => $runId,
                'observed_status' => $postStatus,
            ]);
            return response()->json([
                'error' => 'El proceso de escaneo no arrancó. Revisá el log en /tmp/kilo_artisan_bg.log (filtrá por [avisos:scan-run]).',
                'runId' => $runId,
            ], 500);
        }

        $response = [
            'runId' => $runId,
            'status' => $postStatus,
            'window_label' => $state['window_label'],
            'mode' => $isMonthly ? 'monthly' : 'classic',
        ];
        if ($isMonthly) {
            $response['month_count'] = $monthCount;
            $response['first_month'] = $firstMonth;
            $response['last_month'] = $lastMonth;
        }
        return response()->json($response, 202);
        } catch (\Throwable $e) {
            return $this->respondWithError($e, 'iniciar escaneo');
        }
    }

    /**
     * Preview del escaneo (fix-avisos-scan-by-months-no-saturation): ejecuta
     * el planning sin mutar nada. Devuelve {mode, month_count, first_month,
     * last_month} para que el frontend muestre la confirmación antes del
     * launch real. Para el caso "classic" devuelve {mode: 'classic',
     * message: 'No requiere preview'}.
     *
     * Crítico: NO llama a selectCandidates ni reproduce los joins pesados.
     * Solo hace la query barata de min/max(finished_at).
     */
    public function runScanBackgroundPreview(Request $request, AvisosScanService $service)
    {
        try {
            $validated = $request->validate([
                'storageId' => 'nullable|integer|exists:storage_providers,id',
                'from' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
                'to' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
                'preset' => 'nullable|in:8h,24h,3d,7d,today',
                'limit' => 'nullable|integer|min:1|max:500',
                'force' => 'nullable|boolean',
                'noWindow' => 'nullable|boolean',
            ]);

            $isMonthly = !empty($validated['noWindow'])
                && !empty($validated['force'])
                && empty($validated['from'])
                && empty($validated['to'])
                && empty($validated['preset']);

            if (!$isMonthly) {
                return response()->json([
                    'mode' => 'classic',
                    'message' => 'No requiere preview',
                ]);
            }

            $plan = $service->planMonths();
            $monthCount = count($plan);
            $firstMonth = $plan[0][0] ?? null;
            $lastMonth = $monthCount > 0 ? $plan[$monthCount - 1][0] : null;

            return response()->json([
                'mode' => 'monthly',
                'month_count' => $monthCount,
                'first_month' => $firstMonth,
                'last_month' => $lastMonth,
                'message' => $monthCount === 0
                    ? 'Sin candidatos en el historial. El escaneo terminaría inmediato.'
                    : "Vas a procesar {$monthCount} mes(es) ({$firstMonth} → {$lastMonth}).",
            ]);
        } catch (\Throwable $e) {
            return $this->respondWithError($e, 'previsualizar escaneo');
        }
    }

    /** Polling del estado de una corrida background. */
    public function scanRunStatus(string $runId)
    {
        $state = \Illuminate\Support\Facades\Cache::get("avisos_scan_bg:{$runId}");
        if (!$state) {
            return response()->json(['error' => 'Run no encontrado o expirado'], 404);
        }

        return response()->json($state);
    }

    /** ¿Hay un escaneo background vigente? Para re-adjuntar la UI al recargar. */
    public function scanRunActive()
    {
        $pointer = \Illuminate\Support\Facades\Cache::get('avisos_scan_bg:active');
        if (!is_array($pointer) || empty($pointer['runId'])) {
            return response()->noContent();
        }
        $state = \Illuminate\Support\Facades\Cache::get('avisos_scan_bg:' . $pointer['runId']);
        if (!$state || in_array($state['status'] ?? null, ['done', 'error', 'stopped'], true)) {
            return response()->noContent();
        }

        return response()->json(array_merge(['runId' => (string) $pointer['runId']], $state));
    }

    /** Cancelación cooperativa: marca stop_requested; el worker revisa entre tandas. */
    public function scanRunStop(string $runId)
    {
        $cacheKey = "avisos_scan_bg:{$runId}";
        $state = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (!$state) {
            return response()->json(['error' => 'Run no encontrado o expirado'], 404);
        }
        if (in_array($state['status'] ?? null, ['done', 'error', 'stopped'], true)) {
            return response()->json(['ok' => true, 'status' => $state['status']]);
        }

        $state['stop_requested'] = true;
        \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(self::BG_CACHE_TTL_HOURS));

        return response()->json(['ok' => true, 'status' => $state['status']]);
    }

    // ─── Cobertura de watermarks (avisos-keyword-storage-watermark) ──────

    /** Estado de cobertura por keyword para la UI, paginado y filtrable. */
    public function coverage(Request $request, AvisosScanService $service)
    {
        $storageId = $request->input('storageId');
        $q = trim((string) $request->input('q', ''));
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100], true)
            ? (int) $request->input('per_page', 25)
            : 25;
        $page = max(1, (int) $request->input('page', 1));

        $epoch = \App\Services\Ia\CacheEpoch::get();
        $cacheKey = sprintf(
            'coverage:%d:%s:%d:%d:%d',
            $epoch,
            $storageId ?: 0,
            md5($q),
            $perPage,
            $page,
        );

        $data = \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            60,
            fn () => $service->coveragePaginated($storageId ? (int) $storageId : null, $q, $perPage, $page),
        );

        $data['storages'] = $this->transcribingStoragesForCurrentUser();

        return response()->json($data);
    }

    /**
     * Lista de storages con transcription_enabled=true asignados al usuario
     * de la sesión. Cacheada por CacheEpoch (60s) para que un toggle en
     * API Transcriptor la invalide automáticamente en el siguiente refresh.
     *
     * Se invoca fuera del Cache::remember de coverage() para no cambiar la
     * cache key de los datos paginados.
     */
    private function transcribingStoragesForCurrentUser(): array
    {
        $user = $this->getUser();
        if (!$user) {
            return [];
        }

        $epoch = \App\Services\Ia\CacheEpoch::get();
        $userId = (int) $user->id;
        $cacheKey = sprintf('avisos:transcribing_storages:%d:%d', $epoch, $userId);

        return \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            60,
            fn () => $user->storageProviders()
                ->where('storage_providers.enabled', true)
                ->where('storage_providers.transcription_enabled', true)
                ->orderBy('storage_providers.name')
                ->get(['storage_providers.id', 'storage_providers.name'])
                ->map(fn ($s) => [
                    'id' => (int) $s->id,
                    'name' => $s->name,
                ])
                ->all(),
        );
    }

    /** Rewind del watermark. Soporta `?preview=true` para estimar candidatos sin mutar. */
    public function rewindWatermark(Request $request, AvisosScanService $service)
    {
        try {
            $validated = $request->validate([
                'keyword_id' => 'required|integer|exists:keywords,id',
                'storage_provider_id' => 'required|integer|exists:storage_providers,id',
            ]);
            $preview = $request->boolean('preview');

            if ($preview) {
                $service = app(\App\Services\Ia\AvisosScanService::class);
                $candidates = $service->selectCandidatesForPair(
                    (int) $validated['keyword_id'],
                    (int) $validated['storage_provider_id'],
                    5000,
                );
                return response()->json([
                    'preview' => true,
                    'candidates_count' => $candidates->count(),
                    'keyword_id' => (int) $validated['keyword_id'],
                    'storage_provider_id' => (int) $validated['storage_provider_id'],
                ]);
            }

            $actorId = (int) session('user_id');
            app(\App\Services\Ia\WatermarkReconciler::class)->rewindPair(
                (int) $validated['keyword_id'],
                (int) $validated['storage_provider_id'],
                $actorId,
            );

            return response()->json([
                'ok' => true,
                'message' => 'Watermark rewind: catch-up completo programado.',
            ]);
        } catch (\Throwable $e) {
            return $this->respondWithError($e, 'rewind watermark');
        }
    }

    /** Lanza un escaneo completo en background (barre todos los pares atrasados). */
    public function runFullScan(Request $request, AvisosScanService $service)
    {
        try {
            $validated = $request->validate([
                'storageId' => 'nullable|integer|exists:storage_providers,id',
                'keywordId' => 'nullable|integer|exists:keywords,id',
                'batch' => 'nullable|integer|min:50|max:2000',
                'maxRuntime' => 'nullable|integer|min:30|max:3600',
                'confirmed' => 'nullable|boolean',
            ]);

            $opts = array_filter([
                'storageId' => $validated['storageId'] ?? null,
                'keywordId' => $validated['keywordId'] ?? null,
                'batch' => $validated['batch'] ?? null,
                'maxRuntime' => $validated['maxRuntime'] ?? null,
                'dryRun' => true,
            ], fn ($v) => $v !== null);

            // Estimación: ¿vale la pena pedir confirmación?
            $dryResult = $service->runFullScan($opts);
            $candidatePairs = $dryResult['candidates'] ?? 0;

            if ($candidatePairs > 2000 && !($validated['confirmed'] ?? false)) {
                return response()->json([
                    'needs_confirmation' => true,
                    'candidates' => $candidatePairs,
                    'message' => "Full scan afecta ~{$candidatePairs} pares. Confirma para ejecutar.",
                ], 409);
            }

            unset($opts['dryRun']);
            $result = $service->runFullScan($opts);

            return response()->json([
                'ok' => $result['status'] !== 'failed',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->respondWithError($e, 'lanzar full scan');
        }
    }

    /** Lanza un full scan en background con runId. Polling via status endpoint. */
    public function runFullScanBackground(Request $request)
    {
        try {
            $validated = $request->validate([
                'storageId' => 'nullable|integer|exists:storage_providers,id',
                'keywordId' => 'nullable|integer|exists:keywords,id',
                'batch' => 'nullable|integer|min:50|max:2000',
                'maxRuntime' => 'nullable|integer|min:30|max:3600',
                'confirmed' => 'nullable|boolean',
            ]);

            // Anti-duplicado via pointer.
            $pointer = \Illuminate\Support\Facades\Cache::get('full_scan_bg:active');
            if (is_array($pointer) && !empty($pointer['runId'])) {
                $active = \Illuminate\Support\Facades\Cache::get('full_scan_bg:' . $pointer['runId']);
                $orphan = !$active || in_array($active['status'] ?? null, ['done', 'error', 'stopped'], true);
                if ($orphan) {
                    \Illuminate\Support\Facades\Cache::forget('full_scan_bg:active');
                } else {
                    return response()->json([
                        'error' => 'Ya hay un full scan en curso.',
                        'runId' => (string) $pointer['runId'],
                    ], 409);
                }
            }

            $runId = 'fullscan_' . bin2hex(random_bytes(6));
            $cacheKey = "full_scan_bg:{$runId}";

            $state = [
                'status' => 'queued',
                'queued_at' => now()->toIso8601String(),
                'storageId' => $validated['storageId'] ?? null,
                'keywordId' => $validated['keywordId'] ?? null,
                'batch' => $validated['batch'] ?? 200,
                'maxRuntime' => $validated['maxRuntime'] ?? 600,
                'scanned' => 0,
                'hits_new' => 0,
                'iterations' => 0,
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(6));

            if (!\Illuminate\Support\Facades\Cache::add('full_scan_bg:active', ['runId' => $runId], now()->addHours(6))) {
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
                return response()->json(['error' => 'Race: ya hay un full scan en curso.'], 409);
            }

            // Lanzar el worker en background via execBackground (mismo patrón que scanRun).
            $artisanPath = base_path('artisan');
            $cmd = sprintf(
                '%s %s avisos:full-scan-run --run-id=%s --batch=%d --max-runtime=%d%s%s',
                $this->resolvePhpCli(),
                escapeshellarg($artisanPath),
                escapeshellarg($runId),
                (int) $state['batch'],
                (int) $state['maxRuntime'],
                !empty($state['storageId']) ? ' --storage=' . escapeshellarg((string) $state['storageId']) : '',
                !empty($state['keywordId']) ? ' --keyword-id=' . escapeshellarg((string) $state['keywordId']) : '',
            );
            $this->execBackground($cmd, 'avisos:full-scan-run');

            return response()->json([
                'runId' => $runId,
                'status' => 'queued',
            ], 202);
        } catch (\Throwable $e) {
            return $this->respondWithError($e, 'lanzar full scan en background');
        }
    }

    /** Estado de un full scan en background. */
    public function fullScanStatus(string $runId)
    {
        $state = \Illuminate\Support\Facades\Cache::get("full_scan_bg:{$runId}");
        if (!$state) {
            return response()->json(['error' => 'Run no encontrado o expirado'], 404);
        }
        return response()->json($state);
    }

    /** Cancelación cooperativa de un full scan en background. */
    public function fullScanStop(string $runId)
    {
        $cacheKey = "full_scan_bg:{$runId}";
        $state = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (!$state) {
            return response()->json(['error' => 'Run no encontrado o expirado'], 404);
        }
        if (in_array($state['status'] ?? null, ['done', 'error', 'stopped'], true)) {
            return response()->json(['ok' => true, 'status' => $state['status']]);
        }
        $state['stop_requested'] = true;
        \Illuminate\Support\Facades\Cache::put($cacheKey, $state, now()->addHours(6));
        return response()->json(['ok' => true, 'status' => $state['status']]);
    }

    /** Dispara el reconciliador manualmente (admin only). */
    public function runReconcile(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'dry_run' => 'nullable|boolean',
            'fix_orphans' => 'nullable|boolean',
            'confirmed' => 'nullable|boolean',
        ]);
        $userId = $validated['user_id'] ?? null;
        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $fixOrphans = (bool) ($validated['fix_orphans'] ?? false);
        $confirmed = (bool) ($validated['confirmed'] ?? false);

        $reconciler = app(\App\Services\Ia\WatermarkReconciler::class);
        $report = $reconciler->driftReport($userId);

        $created = 0;
        $deleted = 0;
        if (!$dryRun && $report['summary']['missing'] > 0) {
            foreach ($report['missing'] as $miss) {
                $created += $reconciler->ensureForKeyword((int) $miss->keyword_id);
            }
        }

        // Fix orphans: requiere confirmación explícita.
        if (!$dryRun && $fixOrphans && count($report['orphan']) > 0) {
            if (!$confirmed) {
                return response()->json([
                    'needs_confirmation' => true,
                    'orphans_count' => count($report['orphan']),
                    'message' => "Confirma para borrar " . count($report['orphan']) . " pares huérfanos.",
                ], 409);
            }
            $deleted = $this->fixOrphans($report['orphan']);
        }

        // Auditoría.
        try {
            \Illuminate\Support\Facades\DB::table('watermark_audit_log')->insert([
                'actor_user_id' => (int) session('user_id'),
                'action' => 'reconcile',
                'metadata' => json_encode([
                    'scope_user_id' => $userId,
                    'missing_before' => $report['summary']['missing'],
                    'created' => $created,
                    'orphans_deleted' => $deleted,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // skip
        }

        return response()->json([
            'ok' => true,
            'dry_run' => $dryRun,
            'report' => $report,
            'created' => $created,
            'deleted' => $deleted,
        ]);
    }

    /** Borra pares huérfanos del drift report. */
    private function fixOrphans(array $orphans): int
    {
        $deleted = 0;
        foreach ($orphans as $o) {
            $rows = \Illuminate\Support\Facades\DB::table('keyword_scan_watermarks')
                ->where('keyword_id', (int) $o->keyword_id)
                ->where('storage_provider_id', (int) $o->storage_provider_id)
                ->delete();
            $deleted += $rows;
        }
        return $deleted;
    }

    /** Audit log paginado para la UI. */
    public function auditLog(Request $request, AvisosScanService $service)
    {
        $filters = [
            'actor' => $request->input('actor'),
            'action' => $request->input('action'),
            'keyword' => $request->input('keyword'),
            'storage' => $request->input('storage'),
            'since' => $request->input('since'),
            'until' => $request->input('until'),
        ];
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100], true)
            ? (int) $request->input('per_page', 25)
            : 25;
        $page = max(1, (int) $request->input('page', 1));

        return response()->json($service->auditLog($filters, $perPage, $page));
    }

    /** Exporta el audit log filtrado a CSV. */
    public function auditLogExport(Request $request, AvisosScanService $service)
    {
        $filters = [
            'actor' => $request->input('actor'),
            'action' => $request->input('action'),
            'keyword' => $request->input('keyword'),
            'storage' => $request->input('storage'),
            'since' => $request->input('since'),
            'until' => $request->input('until'),
        ];
        $rows = $service->auditLogAll($filters);

        $filename = 'audit-' . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para Excel.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['timestamp', 'actor', 'action', 'keyword', 'storage', 'before', 'after']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['created_at'],
                    $r['actor'],
                    $r['action'],
                    $r['keyword_text'] ?? '',
                    $r['storage_name'] ?? '',
                    $r['before'],
                    $r['after'],
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Exporta el audit log filtrado a XLSX (formato OOXML mínimo, sin librerías).
     */
    public function auditLogExportXlsx(Request $request, AvisosScanService $service)
    {
        $filters = [
            'actor' => $request->input('actor'),
            'action' => $request->input('action'),
            'keyword' => $request->input('keyword'),
            'storage' => $request->input('storage'),
            'since' => $request->input('since'),
            'until' => $request->input('until'),
        ];
        $rows = $service->auditLogAll($filters);

        $filename = 'audit-' . now()->format('Y-m-d') . '.xlsx';
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($rows) {
            // XLSX mínimo: spreadsheet XML 2003 (SpreadsheetML) que Excel y LibreOffice abren.
            // No usa librerías externas; el namespace ss es el estándar.
            $out = fopen('php://output', 'w');
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
            fwrite($out, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
            fwrite($out, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n");
            fwrite($out, '<Worksheet ss:Name="AuditLog"><Table>' . "\n");
            fwrite($out, '<Row>');
            foreach (['Timestamp', 'Actor', 'Action', 'Keyword', 'Storage', 'Before', 'After'] as $h) {
                fwrite($out, '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1) . '</Data></Cell>');
            }
            fwrite($out, '</Row>' . "\n");
            foreach ($rows as $r) {
                fwrite($out, '<Row>');
                foreach ([$r['created_at'], $r['actor'], $r['action'], $r['keyword_text'] ?? '', $r['storage_name'] ?? '', $r['before'], $r['after']] as $v) {
                    $vStr = (string) $v;
                    fwrite($out, '<Cell><Data ss:Type="String">' . htmlspecialchars($vStr, ENT_XML1) . '</Data></Cell>');
                }
                fwrite($out, '</Row>' . "\n");
            }
            fwrite($out, '</Table></Worksheet></Workbook>' . "\n");
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /** Métricas históricas de cobertura (evolución 30 días). */
    public function coverageStats(Request $request, \App\Services\Ia\CoverageStats $stats)
    {
        $days = max(1, min(365, (int) $request->input('days', \App\Services\Ia\CoverageStats::DAYS_DEFAULT)));
        return response()->json(['days' => $stats->lastDays($days), 'total' => count($stats->lastDays($days))]);
    }

    /** Dashboard con métricas clave del módulo (admin). */
    public function dashboard(Request $request, \App\Services\Ia\DashboardService $svc)
    {
        return response()->json($svc->build());
    }

    /** Heatmap del audit log por día (admin). */
    public function auditHeatmap(Request $request, \App\Services\Ia\DashboardService $svc)
    {
        $days = max(7, min(365, (int) $request->input('days', 90)));
        return response()->json(['days' => $svc->auditHeatmap($days), 'total' => $days]);
    }

    /** Configurar la política de retención del audit log. */
    public function setRetention(Request $request)
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:30|max:3650',
        ]);
        $days = \App\Services\Ia\RetentionPolicy::setDays((int) $validated['days']);
        return response()->json(['ok' => true, 'days' => $days]);
    }

    /**
     * Envoltorio defensivo para endpoints mutacionales (fix-avisos-scanlaunch-from-undefined-key):
     * traduce cualquier excepción interna en un 5xx con `error` legible para
     * el operador + `Log::error()` con la traza completa para el admin.
     *
     * Antes de este cambio, una excepción tipo `Undefined array key` o un error
     * de BD/Redis se convertía en el genérico `{"message":"Server Error"}`
     * que el frontend mostraba como "No se pudo iniciar el escaneo" sin que
     * el operador supiera qué falló. Ahora el operador ve el motivo y el
     * admin tiene la traza en laravel.log sin grep manual.
     *
     * @param string $verb verbo humano que describe la acción (e.g. 'iniciar escaneo',
     *                     'lanzar full scan', 'rewind watermark'). Sale en el mensaje
     *                     de error que ve el operador.
     */
    private function respondWithError(\Throwable $e, string $verb): \Illuminate\Http\JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::error("avisos.scan.{$verb}_failed", [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        } catch (\Throwable $logErr) {
            // Si hasta el log falla, no tumbe la respuesta: siga devolviendo 5xx
            // al cliente con un mensaje genérico. El admin puede revisar nginx/php-fpm logs.
        }

        // getMessage() es seguro de exponer: en PHP 8.x los errores estándar
        // (TypeError, Undefined array key, etc.) tienen mensajes sin paths
        // internos. Si en el futuro alguna excepción con mensaje sensible
        // (e.g. con credentials) entra por aquí, sanitizar antes de devolver.
        $msg = $e->getMessage();
        if (strlen($msg) > 500) {
            $msg = substr($msg, 0, 500) . '...';
        }

        return response()->json([
            'error'   => "Error al {$verb}: {$msg}",
            // Mantenemos `message` para backward-compat con consumidores existentes.
            'message' => "Error al {$verb}: {$msg}",
        ], 500);
    }
}