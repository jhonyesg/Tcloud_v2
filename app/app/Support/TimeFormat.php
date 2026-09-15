<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Helpers de formato horario.
 *
 * El sistema corre en `America/Bogota` (config/app.php:32), y PHP runtime
 * + Carbon + Eloquent lo respetan. Pero las salidas a logs, dashboards y
 * comandos artisan NO etiquetaban la zona, lo cual ha sido foco de confusion
 * operacional: el operador ve "15:34" y no sabe si es Bogota o UTC.
 *
 * Regla del proyecto (a partir del 2026-09-14):
 *  - Logs y stdout de comandos: formato con sufijo explicito de zona.
 *  - UI: Carbon respeta app.timezone automaticamente; en el HTML se imprime
 *    fecha+corto o `formatDate()` devuelve `Y-m-d H:i` local sin offset
 *    cuando el contexto ya es claramente Bogota (dashboard, Mis Archivos, etc.).
 *  - Storage de BD: `timestamp with time zone` (UTC) + lectura en Bogota via Carbon.
 *
 * "bogota()" devuelve "2026-09-14 12:34:56 (-05)" o null-safe para $when nulo.
 */
class TimeFormat
{
    public const ZONE_BOGOTA = 'America/Bogota';
    public const ZONE_UTC = 'UTC';

    public static function bogota(CarbonInterface|string|null $when, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($when === null) {
            return null;
        }
        $c = $when instanceof CarbonInterface ? $when->copy() : Carbon::parse($when);
        return $c->setTimezone(self::ZONE_BOGOTA)->format($format) . ' (-05)';
    }

    public static function utc(CarbonInterface|string|null $when, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($when === null) {
            return null;
        }
        $c = $when instanceof CarbonInterface ? $when->copy() : Carbon::parse($when);
        return $c->setTimezone(self::ZONE_UTC)->format($format) . ' (UTC)';
    }

    /**
     * Para diagnostic panels: devuelve un string compacto "Hoy/Hace X min/Ayer/fecha".
     * Se usa en tablas del Transcriptor; ui deja la conversion automatica a timezone local.
     */
    public static function compactHuman(?CarbonInterface $when): string
    {
        if ($when === null) {
            return '—';
        }
        $now = now();
        $w = $when->copy()->setTimezone(self::ZONE_BOGOTA);
        $n = $now->copy()->setTimezone(self::ZONE_BOGOTA);
        $diffSeconds = $n->diffInSeconds($w, false);
        if ($w->isSameDay($n)) {
            return $w->format('H:i');
        }
        if ($w->isSameDay($n->copy()->subDay())) {
            return 'Ayer ' . $w->format('H:i');
        }
        if ($diffSeconds >= -7 * 86400 && $diffSeconds <= -1 * 86400) {
            return 'Hace ' . abs((int) ($diffSeconds / 86400)) . 'd';
        }
        return $w->format('Y-m-d H:i');
    }
}
