<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\AlertDispatcher;
use App\Services\Ia\AvisosScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AvisosInteligentesController extends Controller
{
    use \App\Http\Controllers\Concerns\RunsBackgroundCommands;

    private const BG_CACHE_TTL_HOURS = 6;

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            // Cobertura de canales del cliente: cuántos storages habilitados
            // tiene asignados. Con withCount se resuelve en la misma consulta
            // (evita el N+1 de recorrer userStorages.storageProvider en PHP).
            //
            // Qué se transcribe NO se cuenta aquí: es una decisión de API
            // Transcriptor sobre el storage, no un atributo del cliente.
            $query = User::with(['alertsInteligente'])
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

            $users = $query->orderBy('username')->paginate(25);
            return response()->json($users);
        }

        return view('ia.avisos-inteligentes.index');
    }

    public function show(int $userId)
    {
        $user = User::with(['userKeywords', 'alertsInteligente'])->findOrFail($userId);
        $matches = $user->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->orderByDesc('matched_at')
            ->paginate(25);

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
            'storages' => $storages,
            'globalStorages' => $globalStorages,
            'globalTranscribing' => $globalTranscribing,
        ]);
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
        $request->validate(['text' => 'required|string|max:200']);
        $config = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);

        $used = DB::table('user_keyword')->where('user_id', $userId)->count();
        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo de keywords alcanzado ({$used}/{$config->keywords_quota})",
            ], 422);
        }

        $normalized = Keyword::normalize($request->text);
        $keyword = Keyword::firstOrCreate(
            ['normalized' => $normalized],
            ['text' => trim($request->text)]
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

    public function matches(int $userId)
    {
        $matches = User::findOrFail($userId)
            ->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->orderByDesc('matched_at')
            ->paginate(25);

        return response()->json($matches);
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
        $validated = $request->validate([
            'storageId' => 'nullable|integer|exists:storage_providers,id',
            'from' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'to' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/'],
            'preset' => 'nullable|in:8h,24h,3d,7d,today',
            'limit' => 'nullable|integer|min:1|max:500',
            'force' => 'nullable|boolean',
            'noWindow' => 'nullable|boolean',
        ]);

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
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'preset' => $validated['preset'] ?? null,
            'limit' => $validated['limit'] ?? 50,
            'force' => (bool) ($validated['force'] ?? false),
            'noWindow' => (bool) ($validated['noWindow'] ?? false),
            'window_label' => $validated['preset'] ?? ($validated['from'] || $validated['to'] ? 'custom' : 'global'),
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

        return response()->json([
            'runId' => $runId,
            'status' => $postStatus,
            'window_label' => $state['window_label'],
        ], 202);
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
}