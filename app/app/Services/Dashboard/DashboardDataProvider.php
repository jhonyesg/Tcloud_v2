<?php

namespace App\Services\Dashboard;

use App\Models\MediaEditJob;
use App\Models\User;
use App\Models\UserSession;
use App\Services\BgJobRegistry;
use App\Services\Ia\CacheEpoch;
use App\Services\Ia\DashboardService as IaDashboardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * DashboardDataProvider: arma el payload de /dashboard aplicando cache por
 * tiers de volatilidad (ver openspec/changes/dashboard-tiered-cache/design.md).
 *
 *   frío   → Cache::flexible 15 min (+15 min stale, recálculo post-respuesta)
 *   tibio  → Cache::flexible 2 min (+8 min stale), key atada a CacheEpoch
 *   caliente → sin cache (RAM/SHM/sesiones/jobs cambian en segundos)
 *
 * Cada bloque se expone en un sobre uniforme:
 *   ['data' => <shape actual>, 'generated_at' => ISO8601, 'stale' => bool]
 *
 * El shape interno de `data` es exactamente el que documentan los headers de
 * resources/views/dashboard/partials/*.blade.php. El controller desembolsa
 * `data` antes de pasar a la vista.
 */
class DashboardDataProvider
{
    private const CACHE_KEY_CREATED_PREFIX = 'illuminate:cache:flexible:created:';

    public function buildAdmin(User $user): array
    {
        return [
            'media_editor' => $this->rememberCold(
                'dashboard:cold:media-editor',
                fn () => $this->buildAdminMediaEditorData()
            ),
            'stats' => $this->rememberCold(
                'dashboard:cold:stats',
                fn () => $this->buildAdminStatsData()
            ),
            'mis_avisos' => $this->rememberWarm(
                'dashboard:warm:mis-avisos:e' . CacheEpoch::get(),
                fn () => $this->buildAdminMisAvisosData()
            ),
            'bg_jobs' => $this->hot($this->buildAdminBgJobsData()),
            'active_sessions' => $this->hot($this->buildAdminActiveSessionsData()),
        ];
    }

    public function buildClient(User $user): array
    {
        return [
            'media_editor' => $this->hot($this->buildClientMediaEditorData($user)),
            'mis_avisos' => $this->hot($this->buildClientMisAvisosData($user)),
        ];
    }

    /** Desembolsa `data` de cada bloque para pasarlo a los partials. */
    public function dataOf(array $blocks): array
    {
        return array_map(fn ($block) => $block['data'] ?? [], $blocks);
    }

    /** Extrae la metadata de frescura de cada bloque (para el indicador). */
    public function freshnessOf(array $blocks): array
    {
        return array_map(fn ($block) => [
            'generated_at' => $block['generated_at'] ?? null,
            'stale' => (bool) ($block['stale'] ?? false),
        ], $blocks);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Frío — stats globales
    // ─────────────────────────────────────────────────────────────────────

    private function buildAdminStatsData(): array
    {
        $agg = DB::table('files')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(size), 0) AS s')
            ->first();

        return [
            'total_users' => (int) User::count(),
            'total_storages' => (int) DB::table('storage_providers')->count(),
            'total_files' => (int) ($agg->c ?? 0),
            'total_shares' => (int) DB::table('shares')->count(),
            'active_shares' => (int) DB::table('shares')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->count(),
            'storage_used' => (int) ($agg->s ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Frío — editor de medios (admin)
    // ─────────────────────────────────────────────────────────────────────

    private function buildAdminMediaEditorData(): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $clipsThisMonth = (int) DB::table('media_edit_jobs')
            ->where('status', 'done')
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->count();

        $usersWithEditor = (int) User::where('media_editor_enabled', true)->count();

        $topConsumers = DB::table('media_edit_jobs as j')
            ->join('users as u', 'u.id', '=', 'j.user_id')
            ->where('j.status', 'done')
            ->whereMonth('j.created_at', $currentMonth)
            ->whereYear('j.created_at', $currentYear)
            ->where(function ($q) {
                $q->where('u.role', 'admin')->orWhere('u.media_editor_enabled', true);
            })
            ->groupBy('u.id', 'u.username', 'u.email')
            ->selectRaw('u.id AS id, u.username AS username, u.email AS email, COUNT(*) AS clips_this_month')
            ->orderByDesc('clips_this_month')
            ->limit(3)
            ->get()
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'username' => $u->username,
                'email' => $u->email,
                'clips_this_month' => (int) $u->clips_this_month,
            ])
            ->all();

        // near_limit sin N+1: un solo agrupado por usuario.
        $editorUsers = User::where('media_editor_enabled', true)
            ->where('media_editor_clip_limit', '>', 0)
            ->get(['id', 'media_editor_clip_limit']);

        $usedByUser = $editorUsers->isEmpty()
            ? collect()
            : DB::table('media_edit_jobs')
                ->where('status', 'done')
                ->whereMonth('created_at', $currentMonth)
                ->whereYear('created_at', $currentYear)
                ->whereIn('user_id', $editorUsers->pluck('id'))
                ->groupBy('user_id')
                ->selectRaw('user_id, COUNT(*) AS c')
                ->pluck('c', 'user_id');

        $nearLimit = $editorUsers->filter(function ($u) use ($usedByUser) {
            $used = (int) ($usedByUser[$u->id] ?? 0);
            $limit = (int) $u->media_editor_clip_limit;
            return $used >= $limit - 1 && $used < $limit;
        })->count();

        return [
            'clips_this_month' => $clipsThisMonth,
            'users_with_editor' => $usersWithEditor,
            'near_limit' => $nearLimit,
            'top_consumers' => $topConsumers,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tibio — resumen de Mis Avisos (admin)
    // ─────────────────────────────────────────────────────────────────────

    private function buildAdminMisAvisosData(): array
    {
        try {
            return app(IaDashboardService::class)->coverageSummary();
        } catch (\Throwable $e) {
            Log::warning('dashboard.mis_avisos_summary_failed: ' . $e->getMessage());
            return [
                'pairs_total' => 0,
                'pairs_pending' => 0,
                'pairs_with_hits' => 0,
                'drift_negative' => 0,
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Caliente — sin cache
    // ─────────────────────────────────────────────────────────────────────

    private function buildAdminBgJobsData(): array
    {
        try {
            return app(BgJobRegistry::class)->discover();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function buildAdminActiveSessionsData(): array
    {
        $activeFilter = function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        };

        $total = (int) UserSession::where($activeFilter)->count();

        $topRows = UserSession::where($activeFilter)
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')
            ->orderByDesc('c')
            ->limit(5)
            ->get();

        $userIds = $topRows->pluck('user_id')->all();
        $users = empty($userIds)
            ? collect()
            : User::whereIn('id', $userIds)->get(['id', 'username', 'email'])->keyBy('id');

        $topUsers = $topRows->map(function ($r) use ($users) {
            $u = $users->get($r->user_id);
            return [
                'id' => (int) $r->user_id,
                'username' => $u?->username,
                'email' => $u?->email,
                'count' => (int) $r->c,
            ];
        })->all();

        return ['total' => $total, 'top_users' => $topUsers];
    }

    private function buildClientMediaEditorData(User $user): array
    {
        $enabled = $user->canUseMediaEditor();
        return [
            'enabled' => $enabled,
            'clips_used' => $enabled ? $user->mediaEditorClipsThisMonth() : 0,
            'limit' => (int) $user->media_editor_clip_limit,
        ];
    }

    private function buildClientMisAvisosData(User $user): array
    {
        $config = $user->alertsInteligente;
        $enabled = (bool) ($config?->enabled);
        $quota = (int) ($config?->keywords_quota ?? 0);
        $emails = $config?->emailsList() ?? [];
        $emailsQuota = (int) ($config?->emails_quota ?? 0);
        $cadence = (int) ($config?->alert_frequency_minutes ?? 30);

        return [
            'enabled' => $enabled && $quota > 0,
            'config_enabled' => $enabled,
            'keywords_used' => $user->userKeywords()->count(),
            'keywords_quota' => $quota,
            'emails' => $emails,
            'emails_quota' => $emailsQuota,
            'cadence_minutes' => $cadence,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sobres y cache
    // ─────────────────────────────────────────────────────────────────────

    private function rememberCold(string $key, callable $callback): array
    {
        $fresh = (int) config('dashboard.cold_ttl');
        $staleTotal = (int) config('dashboard.cold_stale_total');
        $value = Cache::flexible($key, [$fresh, $staleTotal], $callback);

        return $this->envelope($key, $fresh, is_array($value) ? $value : []);
    }

    private function rememberWarm(string $key, callable $callback): array
    {
        $fresh = (int) config('dashboard.warm_ttl');
        $staleTotal = (int) config('dashboard.warm_stale_total');
        $value = Cache::flexible($key, [$fresh, $staleTotal], $callback);

        return $this->envelope($key, $fresh, is_array($value) ? $value : []);
    }

    private function hot(array $data): array
    {
        return [
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
            'stale' => false,
        ];
    }

    private function envelope(string $key, int $freshSeconds, array $data): array
    {
        $created = Cache::get(self::CACHE_KEY_CREATED_PREFIX . $key);
        $createdTs = is_numeric($created) ? (int) $created : time();

        return [
            'data' => $data,
            'generated_at' => Carbon::createFromTimestamp($createdTs)->toIso8601String(),
            'stale' => ($createdTs + $freshSeconds) <= time(),
        ];
    }
}
