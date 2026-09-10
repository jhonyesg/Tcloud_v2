<?php

namespace App\Http\Controllers;

use App\Models\Keyword;
use App\Models\KeywordCategory;
use App\Models\KeywordMatch;
use App\Models\Keyword as KeywordModel;
use App\Models\User;
use App\Services\Ia\AlertDeliveryService;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        // Categorías (add-keyword-categories): se monta el listado merged
        // (admin base ∪ propias) con conteo por categoría en una sola query
        // agregada, sin N+1.
        $keywordCategories = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'keyword_id')
            ->toArray();
        $categories = $this->loadVisibleCategoriesFor($userId);

        return view('mis-avisos.index', [
            'user' => $user,
            'used' => $used,
            'quota' => $quota,
            'moduleEnabled' => $moduleEnabled,
            'matches' => $matches,
            'accessibleStorages' => $accessibleStorages,
            'categories' => $categories,
            'keywordCategories' => $keywordCategories,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 01 Jan 2000 00:00:00 GMT',
        ]);
    }

    public function storeKeyword(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $validated = $request->validate([
            'text' => 'required|string|max:200',
            'category_id' => 'nullable|integer|min:1',
        ]);
        $text = $validated['text'] ?? null;

        $config = \App\Models\UserAlertsInteligente::where('user_id', $userId)->firstOrFail();
        $used = DB::table('user_keyword')->where('user_id', $userId)->count();

        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo alcanzado ({$used}/{$config->keywords_quota})",
            ], 422);
        }

        // avisos-keyword-word-boundary: guardrail anti-abuso. Una keyword
        // demasiado corta ("el", "a") matchearía casi todo el corpus y
        // inflaría el coste computacional del scan compartido. El mínimo es
        // sobre la forma NORMALIZADA (sin espacios ni tildes).
        $normalized = Keyword::normalize((string) $text);
        if (mb_strlen($normalized) < 3) {
            return response()->json([
                'error' => 'La keyword debe tener al menos 3 caracteres (sin contar espacios)',
            ], 422);
        }

        $keyword = Keyword::firstOrCreate(
            ['normalized' => $normalized],
            ['text' => trim((string) $text)]
        );

        $categoryId = $this->resolveCategoryForCurrentUser(
            $request->input('category_id') === null ? null : (int) $request->input('category_id')
        );

        $existingPivot = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keyword->id)
            ->first();

        DB::table('user_keyword')->insertOrIgnore([
            'user_id' => $userId,
            'keyword_id' => $keyword->id,
            'category_id' => $categoryId,
            'created_at' => now(),
        ]);

        if ($categoryId !== null && $existingPivot !== null) {
            DB::table('user_keyword')
                ->where('user_id', $userId)
                ->where('keyword_id', $keyword->id)
                ->update(['category_id' => $categoryId]);
        }

        // change admin-matches-and-backfill (Fase 3): tras crear (o reusar)
        // la keyword, despachamos el backfill retroactivo en background. No
        // bloquea al cliente — recibe 201 inmediatamente y el matcher will
        // poblar hits en segment_keyword_hits desde el siguiente ciclo del
        // job queue 'default' (~1 min en prod). El UNIQUE constraint blinda
        // cualquier re-dispatch.
        \App\Jobs\BackfillKeywordMatches::dispatch($keyword->id)->onQueue('default');

        return response()->json([
            'keyword' => $keyword,
            'used' => $used + 1,
            'quota' => $config->keywords_quota,
            'category_id' => $categoryId,
            'backfill_queued' => true,
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

    /**
     * avisos-keyword-word-boundary: guardrail centralizado de creación de
     * keywords. Reutilizable por ambos controllers (cliente y admin).
     * Retorna true si la forma normalizada tiene al menos 3 caracteres.
     */
    public static function passesMinLength(string $text): bool
    {
        return mb_strlen(Keyword::normalize($text)) >= 3;
    }

    /**
     * PATCH para reasignar o quitar la categoría de una keyword propia.
     */
    public function updateKeyword(Request $request, int $keywordId)
    {
        $userId = (int) Session::get('user_id');
        $request->validate([
            'category_id' => 'nullable|integer|min:1',
        ]);

        $pivot = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->first();
        if (!$pivot) {
            return response()->json(['error' => 'Esa palabra clave no es tuya'], 403);
        }

        $raw = $request->input('category_id');
        $resolved = $this->resolveCategoryForCurrentUser($raw === null ? null : (int) $raw);

        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->update(['category_id' => $resolved]);

        return response()->json([
            'keyword_id' => $keywordId,
            'category_id' => $resolved,
            'unclassified' => $resolved === null,
        ]);
    }

    /* ===================== Categorías del cliente ===================== */

    private function loadVisibleCategoriesFor(int $userId): array
    {
        $counts = DB::table('user_keyword')
            ->select('category_id', DB::raw('COUNT(*) as cnt'))
            ->where('user_id', $userId)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        return KeywordCategory::query()
            ->visibleFor($userId)
            ->orderByRaw("CASE WHEN owner_scope = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'owner_scope', 'owner_id', 'name', 'slug', 'color_hex'])
            ->map(function (KeywordCategory $c) use ($counts) {
                return [
                    'id'           => (int) $c->id,
                    'name'         => $c->name,
                    'slug'         => $c->slug,
                    'color_hex'    => $c->color_hex,
                    'owner_scope'  => $c->owner_scope,
                    'owner_id'     => $c->owner_id === null ? null : (int) $c->owner_id,
                    'is_admin'     => $c->isAdminOwned(),
                    'keywords_count' => (int) ($counts[$c->id] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * category_id SOLO si es visible para el cliente autenticado (admin base ∪ propias).
     * Acepta null para limpiar la asignación; nunca deja la asignación si la categoría
     * no existe para este cliente.
     */
    private function resolveCategoryForCurrentUser(?int $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === 0) {
            return null;
        }
        $userId = (int) Session::get('user_id');
        $visible = KeywordCategory::visibleFor($userId)->where('id', $categoryId)->exists();
        return $visible ? $categoryId : null;
    }

    public function indexCategories(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $categories = $this->loadVisibleCategoriesFor($userId);
        $uncategorized = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->whereNull('category_id')
            ->count();

        return response()->json([
            'categories' => $categories,
            'uncategorized_count' => (int) $uncategorized,
        ]);
    }

    public function storeCategory(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $request->validate([
            'name' => 'required|string|max:80',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $name = trim((string) $request->input('name'));
        $slug = KeywordCategory::normalizeSlug($name);
        if ($slug === '') {
            return response()->json(['error' => 'Nombre inválido'], 422);
        }

        $quota = Cache::get('avisos_user_category_quota');
        if (is_int($quota) && $quota > 0) {
            $owned = KeywordCategory::ownedBy($userId)->count();
            if ($owned >= $quota) {
                return response()->json([
                    'error' => "Cupo de categorías alcanzado ({$owned}/{$quota})",
                ], 422);
            }
        }

        $alreadyOwnsSlug = KeywordCategory::ownedBy($userId)
            ->where('slug', $slug)
            ->exists();
        if ($alreadyOwnsSlug) {
            return response()->json(['error' => 'Ya existe una categoría tuya con ese nombre'], 422);
        }

        $color = $request->input('color_hex');
        if (!KeywordCategory::isValidHex($color)) {
            $color = '#4654a8';
        }

        $category = KeywordCategory::create([
            'owner_scope' => KeywordCategory::SCOPE_USER,
            'owner_id'    => $userId,
            'name'        => $name,
            'slug'        => $slug,
            'color_hex'   => $color,
        ]);

        return response()->json([
            'category' => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'color_hex' => $category->color_hex,
                'owner_scope' => $category->owner_scope,
                'owner_id' => (int) $category->owner_id,
                'is_admin' => false,
                'keywords_count' => 0,
            ],
        ], 201);
    }

    public function updateCategory(Request $request, int $categoryId)
    {
        $userId = (int) Session::get('user_id');
        $category = KeywordCategory::visibleFor($userId)->find($categoryId);
        if (!$category) {
            return response()->json(['error' => 'Categoría no encontrada'], 404);
        }
        if ($category->isAdminOwned()) {
            return response()->json(['error' => 'Solo el admin puede modificar categorías base'], 403);
        }
        if (!$category->isOwnedBy($userId)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'color_hex' => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));
            $slug = KeywordCategory::normalizeSlug($name);
            if ($slug === '') {
                return response()->json(['error' => 'Nombre inválido'], 422);
            }
            $category->name = $name;
            $category->slug = $slug;
        }
        if ($request->has('color_hex')) {
            $color = $request->input('color_hex');
            if ($color !== null && !KeywordCategory::isValidHex($color)) {
                return response()->json(['error' => 'Color inválido'], 422);
            }
            $category->color_hex = $color;
        }
        $category->save();

        return response()->json(['category' => $category->only(['id', 'name', 'slug', 'color_hex', 'owner_scope', 'owner_id'])]);
    }

    public function destroyCategory(Request $request, int $categoryId)
    {
        $userId = (int) Session::get('user_id');
        $category = KeywordCategory::visibleFor($userId)->find($categoryId);
        if (!$category) {
            return response()->json(['error' => 'Categoría no encontrada'], 404);
        }
        if ($category->isAdminOwned()) {
            return response()->json(['error' => 'Solo el admin puede eliminar categorías base'], 403);
        }
        if (!$category->isOwnedBy($userId)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $affected = DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('category_id', $category->id)
            ->count();

        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('category_id', $category->id)
            ->update(['category_id' => null]);
        $category->delete();

        return response()->json([
            'ok' => true,
            'affected_keywords' => (int) $affected,
        ]);
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

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 500]) ? (int) $request->input('per_page', 25) : 25;

        // change mis-avisos-media-kind-indicator (G3): misma whitelist que history().
        $allowedMedia = ['all', 'tv', 'radio'];
        $mediaType = (string) $request->input('media_type', 'all');
        if (!in_array($mediaType, $allowedMedia, true)) {
            return response()->json(['error' => 'media_type debe ser all, tv o radio'], 422);
        }

        $page = $search->todayHits($user, [
            'q' => $request->input('q', ''),
            'storage_ids' => (array) $request->input('storage_ids', []),
            'keyword_id' => $request->input('keyword_id'),
            'media_type' => $mediaType,
        ], $perPage);

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

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 500]) ? (int) $request->input('per_page', 25) : 25;

        // change mis-avisos-media-kind-indicator (G3): whitelist estricta para
        // el filtro de tipo de medio. Si no es uno de los 3 valores aceptados,
        // rechazamos con 422 (defensa contra inyección SQL en el LIKE del filtro).
        $allowedMedia = ['all', 'tv', 'radio'];
        $mediaType = (string) $request->input('media_type', 'all');
        if (!in_array($mediaType, $allowedMedia, true)) {
            return response()->json(['error' => 'media_type debe ser all, tv o radio'], 422);
        }

        $page = $search->searchHistory($user, [
            'q' => $q,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'storage_ids' => $request->input('storage_ids', []),
            'keyword_id' => $request->input('keyword_id'),
            'media_type' => $mediaType,
        ], $perPage);

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