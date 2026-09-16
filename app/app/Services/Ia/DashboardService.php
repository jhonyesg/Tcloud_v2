<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DashboardService: agregador de métricas para el dashboard admin.
 *
 * Una sola llamada retorna TODOS los contadores que necesita la pestaña
 * Dashboard. Cache 10s para evitar queries repetidas en refreshes rápidos.
 *
 * No muta nada. Es puramente lectura.
 */
class DashboardService
{
    private const CACHE_TTL = 10;

    private const PARTITION_THRESHOLDS = [
        'watermark_audit_log' => 100_000,
        'segment_keyword_hits' => 10_000_000,
    ];

    /**
     * Resumen acotado para el dashboard: SOLO los KPIs que consumen los
     * partials (`pairs_*`, `drift_negative`). Evita pagar auditRecent,
     * scansRecent, readiness (count de tablas grandes) ni driftReport completo
     * en la ruta caliente del dashboard.
     *
     * El cacheo por tier lo aplica DashboardDataProvider.
     */
    public function coverageSummary(): array
    {
        $pairsTotal = (int) DB::table('keyword_scan_watermarks')->count();
        $pairsPending = (int) DB::table('keyword_scan_watermarks')->whereNull('scanned_until')->count();
        $pairsWithHits = (int) DB::table('keyword_scan_watermarks')->where('hits_total', '>', 0)->count();

        $driftNegative = 0;
        try {
            $report = app(WatermarkReconciler::class)->driftReport();
            $driftNegative = (int) ($report['summary']['missing'] ?? 0);
        } catch (\Throwable $e) {
            $driftNegative = 0;
        }

        return [
            'pairs_total' => $pairsTotal,
            'pairs_pending' => $pairsPending,
            'pairs_with_hits' => $pairsWithHits,
            'drift_negative' => $driftNegative,
        ];
    }

    public function build(): array
    {
        return Cache::remember('coverage:dashboard', self::CACHE_TTL, function () {
            $pairsTotal = (int) DB::table('keyword_scan_watermarks')->count();
            $pairsPending = (int) DB::table('keyword_scan_watermarks')->whereNull('scanned_until')->count();
            $pairsWithHits = (int) DB::table('keyword_scan_watermarks')->where('hits_total', '>', 0)->count();

            $reconciler = app(WatermarkReconciler::class);
            $report = $reconciler->driftReport();
            $driftNegative = $report['summary']['missing'];
            $driftOrphan = $report['summary']['orphan'];

            $auditRecent = DB::table('watermark_audit_log as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
                ->leftJoin('keywords as k', 'k.id', '=', 'a.keyword_id')
                ->leftJoin('storage_providers as sp', 'sp.id', '=', 'a.storage_id')
                ->orderByDesc('a.created_at')
                ->limit(5)
                ->get([
                    'a.id', 'a.action', 'a.created_at', 'a.actor_user_id',
                    'u.username as actor_username',
                    'k.text as keyword_text',
                    'sp.name as storage_name',
                ])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'action' => $r->action,
                    'created_at' => (string) $r->created_at,
                    'actor' => $r->actor_username ?: 'sistema',
                    'keyword_text' => $r->keyword_text,
                    'storage_name' => $r->storage_name,
                ])->all();

            $scansRecent = DB::table('avisos_scan_runs')
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'origin' => $r->origin,
                    'status' => $r->status,
                    'scanned' => (int) $r->transcriptions_scanned,
                    'hits_new' => (int) $r->hits_new,
                    'duration_ms' => (int) $r->duration_ms,
                    'started_at' => (string) $r->started_at,
                    'finished_at' => $r->finished_at ? (string) $r->finished_at : null,
                ])->all();

            $readiness = [];
            foreach (self::PARTITION_THRESHOLDS as $table => $threshold) {
                $count = (int) DB::table($table)->count();
                $pct = $threshold > 0 ? round(($count / $threshold) * 100, 1) : 0;
                $status = $pct >= 100 ? 'CRITICAL' : ($pct >= 70 ? 'WARNING' : 'OK');
                $readiness[$table] = [
                    'count' => $count,
                    'threshold' => $threshold,
                    'pct' => $pct,
                    'status' => $status,
                ];
            }

            return [
                'pairs_total' => $pairsTotal,
                'pairs_pending' => $pairsPending,
                'pairs_with_hits' => $pairsWithHits,
                'drift_negative' => $driftNegative,
                'drift_orphan' => $driftOrphan,
                'audit_recent' => $auditRecent,
                'scans_recent' => $scansRecent,
                'readiness' => $readiness,
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Heatmap del audit log últimos N días. Día sin actividad = total_actions=0.
     * Retorna array indexado por fecha (Y-m-d) para lookup O(1) desde el cliente.
     */
    public function auditHeatmap(int $days = 90, ?int $userId = null): array
    {
        $days = max(1, min(365, $days));
        $since = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('watermark_audit_log')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, action, COUNT(*) as n')
            ->groupBy('day', 'action')
            ->get();

        $indexed = [];
        foreach ($rows as $r) {
            $day = (string) $r->day;
            if (!isset($indexed[$day])) {
                $indexed[$day] = ['date' => $day, 'total_actions' => 0, 'by_action' => []];
            }
            $indexed[$day]['total_actions'] += (int) $r->n;
            $indexed[$day]['by_action'][$r->action] = (int) $r->n;
        }

        // Rellenar días sin actividad (para mantener grid continuo).
        $start = $since->copy();
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            if (!isset($indexed[$date])) {
                $indexed[$date] = ['date' => $date, 'total_actions' => 0, 'by_action' => []];
            }
        }
        ksort($indexed);

        return array_values($indexed);
    }
}
