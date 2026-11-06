<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Conversion central de timestamps de filesystem (mtime) a instantes.
 *
 * POR QUE EXISTE
 * --------------
 * `Carbon::createFromTimestamp($ts)` SIN segundo argumento devuelve el Carbon
 * en **UTC** (Carbon 3). Eloquent serializa los bindings `DateTimeInterface`
 * con `format('Y-m-d H:i:s')` en la zona del Carbon, y PostgreSQL interpreta
 * ese texto naive en la zona de la SESION (`database.connections.pgsql.timezone`
 * = America/Bogota desde `fix-pg-session-timezone-bogota`). Resultado: la hora
 * de pared Bogota se guarda como si fuera UTC y el instante queda +5 h.
 *
 * Medido en produccion (2026-11-05):
 *   disco  : 2026-11-05 20:42:24 -05
 *   sin tz : 2026-11-06 01:42:24 -05  (+5 h)
 *   con tz : 2026-11-06 01:42:24 UTC  = 2026-11-05 20:42:24 -05  (correcto)
 *
 * REGLA
 * -----
 * Todo timestamp de filesystem (`filemtime`) que se escriba o serialice en el
 * modulo Mis Archivos DEBE pasar por este helper. Nunca llamar
 * `createFromTimestamp()` sin zona en ese camino.
 *
 * El helper devuelve un `CarbonImmutable` en `config('app.timezone')`, asi que
 * al persistirse el binding lleva la hora de pared correcta y al serializarse
 * (`toIso8601String()`) lleva offset explicito, que el navegador convierte solo.
 */
class FilesystemTime
{
    public const DEFAULT_ZONE = 'America/Bogota';

    /**
     * Zona efectiva. Se lee de `app.timezone` cuando el contenedor esta
     * disponible (runtime normal) y cae al default del proyecto cuando el
     * helper se usa fuera de Laravel (tests unitarios sin bootstrap).
     */
    public static function zone(): string
    {
        try {
            $tz = config('app.timezone');
        } catch (\Throwable) {
            return self::DEFAULT_ZONE;
        }

        return is_string($tz) && $tz !== '' ? $tz : self::DEFAULT_ZONE;
    }

    /**
     * Convierte un epoch de filesystem al instante correcto en la zona de la app.
     */
    public static function fromTimestamp(int|float|string|null $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '' || $timestamp === false) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $timestamp, self::zone());
    }

    /**
     * Lee el `mtime` de un path en disco y lo devuelve en la zona de la app.
     *
     * Devuelve `null` si el path no existe o `filemtime()` falla. Usa `@` porque
     * el caller ya decidio que el path deberia existir (archivo recien subido o
     * copiado) y un fallo de stat no debe romper la operacion.
     */
    public static function fromPath(?string $path): ?CarbonImmutable
    {
        if ($path === null || $path === '') {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime === false ? null : self::fromTimestamp($mtime);
    }
}
