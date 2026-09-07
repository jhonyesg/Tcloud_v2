<?php

namespace App\Http\Controllers;

use App\Models\Keyword;
use App\Models\KeywordMatch;
use App\Models\Keyword as KeywordModel;
use App\Models\User;
use App\Services\Ia\AlertDeliveryService;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class MisAvisosController extends Controller
{
    public function index()
    {
        $userId = (int) Session::get('user_id');
        $user = User::with(['userKeywords', 'alertsInteligente'])->findOrFail($userId);

        $used = $user->userKeywords->count();
        $quota = $user->alertsInteligente?->keywords_quota ?? 0;
        $moduleEnabled = (bool) $user->alertsInteligente?->enabled && $quota > 0;

        $matches = $user->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->whereHas('transcription.file.storageProvider', function ($q) use ($user) {
                $q->whereHas('userStorages', function ($sq) use ($user) {
                    $sq->where('user_id', $user->id)
                        ->where('transcription_access', true);
                });
            })
            ->orderByDesc('matched_at')
            ->paginate(25);

        // Storages con acceso del cliente: hidrata el selector de alcance
        // keyword→store sin fetch extra al abrir la pestaña.
        $accessibleStorages = DB::table('storage_providers as sp')
            ->join('user_storages as us', function ($j) use ($user) {
                $j->on('us.storage_provider_id', '=', 'sp.id')
                    ->where('us.user_id', $user->id)
                    ->where('us.transcription_access', true);
            })
            ->where('sp.enabled', true)
            ->orderBy('sp.name')
            ->get(['sp.id', 'sp.name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->values()
            ->all();

        return view('mis-avisos.index', [
            'user' => $user,
            'used' => $used,
            'quota' => $quota,
            'moduleEnabled' => $moduleEnabled,
            'matches' => $matches,
            'accessibleStorages' => $accessibleStorages,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 01 Jan 2000 00:00:00 GMT',
        ]);
    }

    public function storeKeyword(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $request->validate(['text' => 'required|string|max:200']);

        $config = \App\Models\UserAlertsInteligente::where('user_id', $userId)->firstOrFail();
        $used = DB::table('user_keyword')->where('user_id', $userId)->count();

        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo alcanzado ({$used}/{$config->keywords_quota})",
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

        return response()->json([
            'keyword' => $keyword,
            'used' => $used + 1,
            'quota' => $config->keywords_quota,
        ], 201);
    }

    public function destroyKeyword(int $keywordId)
    {
        $userId = (int) Session::get('user_id');
        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->delete();

        return response()->json(['message' => 'Eliminada']);
    }

    // ─── mis-avisos-menciones: Fase 2 (feed, scope, preferencias) ─────────

    /**
     * Storages con acceso del cliente + scopes de sus keywords.
     * (La pestaña keywords hidrata el alcance de cada palabra desde aquí.)
     */
    public function storages(MentionsSearchService $search)
    {
        $user = User::findOrFail((int) Session::get('user_id'));
        $ids = $search->accessibleStorageIds($user);

        $storages = DB::table('storage_providers')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->values();

        $scopes = DB::table('user_keyword_storage')
            ->where('user_id', $user->id)
            ->get(['keyword_id', 'storage_provider_id'])
            ->groupBy('keyword_id')
            ->map(fn ($rows) => $rows->pluck('storage_provider_id')->map(fn ($v) => (int) $v)->values())
            ->all();

        return response()->json(['storages' => $storages, 'scopes' => $scopes]);
    }

    /**
     * Feed en vivo: coincidencias del día actual, respetando la intersección
     * de acceso (transcription_access ∩ alcance keyword→store). Polling ~20s.
     * Acepta filtros (q, storage_ids, keyword_id) y paginación server-side.
     */
    public function feed(Request $request, MentionsSearchService $search)
    {
        $user = User::findOrFail((int) Session::get('user_id'));

        $page = $search->todayHits($user, [
            'q' => $request->input('q', ''),
            'storage_ids' => (array) $request->input('storage_ids', []),
            'keyword_id' => $request->input('keyword_id'),
        ]);

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Alcance keyword→stores de una keyword del cliente. "Sin filas = todos
     * mis medios". Valida que los stores pertenezcan al cliente con acceso.
     */
    public function updateKeywordScope(Request $request, int $keywordId, MentionsSearchService $search)
    {
        $userId = (int) Session::get('user_id');
        $request->validate([
            'storage_ids' => 'present|array',
            'storage_ids.*' => 'integer',
        ]);

        $mine = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->exists();
        if (!$mine) {
            return response()->json(['error' => 'Esa palabra clave no es tuya'], 403);
        }

        // Solo storages con transcription_access del propio cliente.
        $allowed = $search->accessibleStorageIds(User::find($userId));
        $requested = array_unique(array_map('intval', $request->input('storage_ids')));
        $invalid = array_diff($requested, $allowed);
        if (!empty($invalid)) {
            return response()->json([
                'error' => 'Hay medios sin acceso concedido: ' . implode(', ', $invalid),
            ], 422);
        }

        DB::transaction(function () use ($userId, $keywordId, $requested) {
            DB::table('user_keyword_storage')
                ->where('user_id', $userId)
                ->where('keyword_id', $keywordId)
                ->delete();
            foreach ($requested as $sid) {
                DB::table('user_keyword_storage')->insert([
                    'user_id' => $userId,
                    'keyword_id' => $keywordId,
                    'storage_provider_id' => $sid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'keyword_id' => $keywordId,
            'storage_ids' => $requested,
            'scope' => empty($requested) ? 'todos' : 'custom',
        ]);
    }

    /**
     * Preferencias de avisos: cadencia elegible + proyección de impacto con
     * los matches reales de los últimos 7 días + pendientes de entrega.
     */
    public function preferences(Request $request)
    {
        $userId = (int) Session::get('user_id');

        if ($request->isMethod('PUT')) {
            $request->validate([
                'alert_frequency_minutes' => 'required|integer|in:' . implode(',', AlertDeliveryService::FREQUENCIES),
            ]);
            DB::table('user_alerts_inteligentes')
                ->where('user_id', $userId)
                ->update(['alert_frequency_minutes' => (int) $request->input('alert_frequency_minutes')]);
        }

        $config = DB::table('user_alerts_inteligentes')->where('user_id', $userId)->first();

        // Proyección: matches reales de los últimos 7 días → correos/semana.
        $hitsLastWeek = DB::table('segment_keyword_hits as h')
            ->where('h.matched_at', '>=', now()->subDays(7))
            ->whereExists(function ($q) use ($userId) {
                $q->selectRaw(1)
                    ->from('user_keyword as uk')
                    ->whereColumn('uk.keyword_id', 'h.keyword_id')
                    ->where('uk.user_id', $userId);
            })
            ->count();

        $weekly = [];
        foreach (AlertDeliveryService::FREQUENCIES as $minutes) {
            // Correos/semana = min(ventanas disponibles, matches a repartir).
            $windows = (int) floor((7 * 24 * 60) / max(1, $minutes));
            $weekly[$minutes] = min($windows, $hitsLastWeek);
        }

        $pending = DB::table('alert_deliveries')
            ->where('user_id', $userId)
            ->whereNull('delivered_at')
            ->where('reposition_for', '!=', null)
            ->count();

        return response()->json([
            'alert_frequency_minutes' => $config->alert_frequency_minutes ?? 30,
            'emails_quota' => $config->emails_quota ?? 0,
            'hits_last_7_days' => $hitsLastWeek,
            'projection' => $weekly,
            'pending_reposition' => $pending,
        ]);
    }

    // ─── mis-avisos-menciones: Fase 3 (histórico 60d + export) ────────────

    /**
     * Histórico: búsqueda sobre segmentos de SUS storages con acceso,
     * rango ≤60 días, filtros (q, fechas, emisoras, keyword), throttle
     * 10/min en la ruta. El mapeo de filas vive en la costura (hitRow).
     */
    public function history(Request $request, MentionsSearchService $search)
    {
        $user = User::findOrFail((int) Session::get('user_id'));

        $minLen = (int) config('avisos.exports.min_query_length', 3);
        $q = trim((string) $request->input('q', ''));
        if ($q !== '' && mb_strlen($q) < $minLen) {
            return response()->json(['error' => "Consulta mínima de {$minLen} caracteres"], 422);
        }

        $page = $search->searchHistory($user, [
            'q' => $q,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'storage_ids' => $request->input('storage_ids', []),
            'keyword_id' => $request->input('keyword_id'),
        ]);

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    // ─── visor de menciones (transcripción anclada + segmentos) ──────────

    /**
     * Metadatos de la transcripción + primera ventana de segmentos anclada
     * al segmento de la mención. 404 si la transcripción no es visible para
     * el cliente (no revela existencia).
     */
    public function transcription(Request $request, int $transcriptionId, MentionsSearchService $search)
    {
        $user = User::findOrFail((int) Session::get('user_id'));

        $meta = $search->visibleTranscription($user, $transcriptionId);
        if ($meta === null) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        $anchor = $request->input('anchor_segment_id');
        $window = $search->pageVisibleSegments(
            $user,
            $transcriptionId,
            $anchor !== null ? (int) $anchor : null
        );
        if ($window === null) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        return response()->json([
            'transcription' => $meta,
            'segments' => $window['segments'],
            'first_index' => $window['first_index'],
            'last_index' => $window['last_index'],
            'total_segments' => $window['total_segments'],
        ]);
    }

    /**
     * Expansión incremental de la ventana de segmentos (cursores por
     * segment_index). 404 si la transcripción no es visible.
     */
    public function transcriptionSegments(Request $request, int $transcriptionId, MentionsSearchService $search)
    {
        $user = User::findOrFail((int) Session::get('user_id'));

        $after = $request->input('after_index');
        $before = $request->input('before_index');
        if ($after === null && $before === null) {
            return response()->json(['error' => 'Se requiere after_index o before_index'], 422);
        }

        $window = $search->pageVisibleSegments(
            $user,
            $transcriptionId,
            null,
            $after !== null ? (int) $after : null,
            $before !== null ? (int) $before : null,
            $request->input('limit') !== null ? (int) $request->input('limit') : null,
        );
        if ($window === null) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        return response()->json([
            'segments' => $window['segments'],
            'first_index' => $window['first_index'],
            'last_index' => $window['last_index'],
            'total_segments' => $window['total_segments'],
        ]);
    }

    /**
     * Solicita un export: candados (1 activo, tope diario) y encola el job.
     */
    public function requestExport(Request $request, MentionsSearchService $search)
    {
        $userId = (int) Session::get('user_id');
        $maxActive = (int) config('avisos.exports.max_active_per_user', 1);
        $maxPerDay = (int) config('avisos.exports.max_per_day', 3);

        $active = DB::table('mentions_exports')
            ->where('user_id', $userId)
            ->whereIn('status', ['queued', 'processing'])
            ->count();
        if ($active >= $maxActive) {
            return response()->json(['error' => 'Ya tienes una exportación en proceso. Espera a que termine.'], 429);
        }

        $today = DB::table('mentions_exports')
            ->where('user_id', $userId)
            ->whereDate('created_at', today())
            ->whereIn('status', ['ready', 'failed'])
            ->count();
        if ($today >= $maxPerDay) {
            return response()->json(['error' => "Alcanzaste tu tope de {$maxPerDay} exportaciones por hoy."], 429);
        }

        $exportId = DB::table('mentions_exports')->insertGetId([
            'user_id' => $userId,
            'status' => 'queued',
            'filters' => json_encode([
                'q' => trim((string) $request->input('q', '')),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'storage_ids' => (array) $request->input('storage_ids', []),
                'keyword_id' => $request->input('keyword_id'),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Jobs\MentionsExportJob::dispatch($userId, $exportId, [
            'q' => trim((string) $request->input('q', '')),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'storage_ids' => (array) $request->input('storage_ids', []),
            'keyword_id' => $request->input('keyword_id'),
        ])->onQueue('default');

        return response()->json(['export_id' => $exportId, 'status' => 'queued'], 201);
    }

    /**
     * Estado del export para el polling del cliente.
     */
    public function exportStatus(int $exportId)
    {
        $userId = (int) Session::get('user_id');
        $export = DB::table('mentions_exports')
            ->where('id', $exportId)
            ->where('user_id', $userId)
            ->first();
        if (!$export) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
            'rows_count' => $export->rows_count,
            'download_url' => $export->status === 'ready' ? $export->download_url : null,
            'error_message' => $export->error_message,
        ]);
    }

    /**
     * Descarga del export (solo link firmado; la ruta exige firma).
     */
    public function downloadExport(int $export)
    {
        $row = DB::table('mentions_exports')->find($export);
        if (!$row || $row->status !== 'ready' || !$row->file_path) {
            abort(404);
        }
        $path = storage_path("app/{$row->file_path}");
        if (!is_file($path)) {
            abort(404);
        }

        return response()->download($path, basename($path), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Envío manual del link por correo (nunca automático). Reusa
     * Modules\Correo y respeta el rate limiter global del relay.
     */
    public function emailExport(int $exportId, \App\Services\Ia\AlertDispatcher $dispatcher)
    {
        $userId = (int) Session::get('user_id');
        $export = DB::table('mentions_exports')
            ->where('id', $exportId)
            ->where('user_id', $userId)
            ->where('status', 'ready')
            ->first();
        if (!$export) {
            return response()->json(['error' => 'Exportación no disponible'], 404);
        }

        $user = User::findOrFail($userId);
        $emails = $user->alertsInteligente?->emailsList() ?? [];
        if (empty($emails)) {
            return response()->json(['error' => 'No tienes correos configurados para avisos. Pide al admin registrarlos.'], 422);
        }

        $sent = 0;
        foreach ($emails as $to) {
            $result = app(\App\Modules\Correo\Services\NotificationService::class)
                ->send('mentions-export-link', $to, [
                    'user' => $user->username,
                    'export_id' => $export->id,
                    'rows_count' => $export->rows_count ?? 0,
                    'download_url' => $export->download_url,
                    'expires_note' => 'El enlace expira automáticamente; puedes generar otro desde Mis Avisos.',
                ]);
            if ($result['success'] ?? false) {
                $sent++;
            }
        }

        DB::table('mentions_exports')
            ->where('id', $exportId)
            ->update(['emailed_at' => now()]);

        return response()->json(['sent' => $sent, 'total' => count($emails)]);
    }
}