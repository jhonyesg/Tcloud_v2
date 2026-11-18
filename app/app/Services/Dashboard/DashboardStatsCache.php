<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agregado global de `files` (`COUNT(*)` + `SUM(size)`) para el dashboard admin.
 *
 * Por qué existe: ese agregado es un `Parallel Seq Scan` sobre 1,2 M filas /
 * 2,5 GB (medido 2026-11-18: 237-737 ms por recálculo). Con `Cache::flexible`
 * el costo se pagaba dentro del request que encontraba la entrada fría — es
 * decir, justo el que más se notaba.
 *
 * Ahora el número se calcula en background (`dashboard:refresh-stats`, cada
 * 10 min) y `/dashboard` solo lo lee. Si la entrada no existe todavía (primer
 * deploy, cache limpio) se calcula en línea y se guarda: el dashboard nunca
 * muestra un cero inventado por falta de cache.
 *
 * Frescura: `captured_at` viaja en el payload y la vista ya muestra "Datos
 * actualizados hace X" para el bloque de stats. Un agregado de 10 minutos de
 * antigüedad es correcto para un contador informativo; el operador puede
 * forzar el recálculo con `php artisan dashboard:refresh-stats`.
 */
class DashboardStatsCache
{
    public const KEY = 'dashboard:stats:aggregate';

    /**
     * TTL de respaldo. El refresco real lo hace el comando programado; el TTL
     * solo evita que una key huérfana (comando caído) viva para siempre.
     */
    private const TTL_SECONDS = 7 * 86400;

    /**
     * Lee el agregado cacheado. Devuelve `null` cuando todavía no existe.
     *
     * @return array{total_files: int, storage_used: int, captured_at: string}|null
     */
    public function read(): ?array
    {
        $value = Cache::get(self::KEY);

        if (! is_array($value) || ! array_key_exists('total_files', $value) || ! array_key_exists('storage_used', $value)) {
            return null;
        }

        return [
            'total_files' => (int) $value['total_files'],
            'storage_used' => (int) $value['storage_used'],
            'captured_at' => (string) ($value['captured_at'] ?? ''),
        ];
    }

    /**
     * Recalcula el agregado y lo publica.
     *
     * @return array{total_files: int, storage_used: int, captured_at: string}
     */
    public function refresh(): array
    {
        $agg = DB::table('files')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(size), 0) AS s')
            ->first();

        $payload = [
            'total_files' => (int) ($agg->c ?? 0),
            'storage_used' => (int) ($agg->s ?? 0),
            'captured_at' => now()->toIso8601String(),
        ];

        Cache::put(self::KEY, $payload, self::TTL_SECONDS);

        return $payload;
    }

    /**
     * Devuelve el agregado, calculándolo si no está cacheado.
     *
     * @return array{total_files: int, storage_used: int, captured_at: string}
     */
    public function get(): array
    {
        $cached = $this->read();
        if ($cached !== null) {
            return $cached;
        }

        try {
            return $this->refresh();
        } catch (\Throwable $e) {
            Log::warning('dashboard.stats_aggregate_failed: ' . $e->getMessage());

            return [
                'total_files' => 0,
                'storage_used' => 0,
                'captured_at' => '',
            ];
        }
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
