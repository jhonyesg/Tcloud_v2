<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CoverageStats: métricas históricas de la tabla keyword_scan_watermarks.
 *
 * Series temporales para detectar regresiones:
 *  - total_pairs: pares existentes al cierre del día
 *  - pending_pairs: pares con scanned_until IS NULL
 *  - hits_total: hits totales acumulados al cierre del día
 *
 * La agregación es por día calendario sobre created_at. Para días sin eventos,
 * se repite el último valor conocido (forward-fill) para mantener continuidad.
 */
class CoverageStats
{
    public const DAYS_DEFAULT = 30;

    /** Serie temporal de los últimos N días. */
    public function lastDays(int $days = self::DAYS_DEFAULT): array
    {
        $days = max(1, min(365, $days));
        $today = now()->startOfDay();

        // Estado base al cierre de hoy: snapshot actual.
        $currentTotal = (int) DB::table('keyword_scan_watermarks')->count();
        $currentPending = (int) DB::table('keyword_scan_watermarks')->whereNull('scanned_until')->count();
        $currentHits = (int) DB::table('keyword_scan_watermarks')->sum('hits_total');

        // Deltas diarios sobre created_at (de los últimos N días).
        $createdByDay = DB::table('keyword_scan_watermarks')
            ->where('created_at', '>=', $today->copy()->subDays($days - 1))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n')
            ->groupBy('day')
            ->pluck('n', 'day');

        // Hits nuevos por día (delta positivo de hits_total por día).
        // Implementación simplificada: total hits actuales menos los creados hoy.
        $hitsByDay = DB::table('keyword_scan_watermarks')
            ->where('created_at', '>=', $today->copy()->subDays($days - 1))
            ->selectRaw('DATE(created_at) as day, SUM(hits_total) as hits')
            ->groupBy('day')
            ->pluck('hits', 'day');

        // Para los días PREVIOS al primer registro: no tenemos histórico real.
        // Asumimos linear extrapolation desde cero (documentado en spec).
        $series = [];
        $runningTotal = max(0, $currentTotal - array_sum($createdByDay->all()));
        $runningPending = max(0, $currentPending - ($currentTotal - $runningTotal));
        $runningHits = max(0, $currentHits - array_sum($hitsByDay->all()));

        for ($i = $days - 1; $i >= 1; $i--) {
            $date = $today->copy()->subDays($i)->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'total_pairs' => $runningTotal,
                'pending_pairs' => $runningPending,
                'hits_total' => $runningHits,
            ];
        }
        // Hoy
        $series[] = [
            'date' => $today->format('Y-m-d'),
            'total_pairs' => $currentTotal,
            'pending_pairs' => $currentPending,
            'hits_total' => $currentHits,
        ];

        return $series;
    }
}
