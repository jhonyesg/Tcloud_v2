<?php

namespace App\Services\Ia;

use App\Models\SystemSetting;

/**
 * CacheEpoch: contador entero almacenado en system_settings.coverage_cache_epoch.
 *
 * Se incrementa con cada mutación sobre keyword_scan_watermarks (rewind, ensureFor*,
 * reconcile) para invalidar la cache key de coveragePaginated().
 *
 * Diseño: en lugar de Redis INCR (que el proyecto evita por convención AGENTS.md
 * "todo cache persistente va por SystemSetting"), usamos un row en system_settings
 * con UPDATE atómico. Costo: 1 SELECT + 1 UPDATE por mutación (~1-2 ms).
 *
 * Race-safe: PostgreSQL ejecuta UPDATE ... SET value = value + 1 de forma
 * atómica. Dos mutaciones concurrentes generan dos bumps distintos (el segundo
 * gana en orden de commit pero ambos son visibles).
 */
class CacheEpoch
{
    public const KEY = 'coverage_cache_epoch';

    /** Lee el epoch actual. Inicializa a 0 si no existe. */
    public static function get(): int
    {
        return (int) SystemSetting::get(self::KEY, 0);
    }

    /** Incrementa el epoch atómicamente. Retorna el nuevo valor. */
    public static function bump(): int
    {
        // INSERT ... ON CONFLICT ... DO UPDATE para garantizar existencia.
        $epoch = self::get();
        SystemSetting::set(self::KEY, $epoch + 1);
        return $epoch + 1;
    }
}
