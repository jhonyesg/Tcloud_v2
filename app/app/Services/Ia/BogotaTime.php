<?php

namespace App\Services\Ia;

use Carbon\CarbonImmutable;

/**
 * Helper central para resolver momentos temporales en America/Bogota.
 *
 * El sistema TCloud opera exclusivamente en zona America/Bogota. Antes de este
 * helper, los call sites usaban `CarbonImmutable::today()` sin argumento, lo que
 * dependia implicitamente de `config('app.timezone')`. Si alguien cambiaba esa
 * config (test, docker, override de emergencia), todas las queries de "hoy"
 * se desfasaban silenciosamente.
 *
 * Con este helper el contrato es explicito: "Bogota" vive en una sola clase.
 * Cualquier cambio futuro de zona (multi-tenant, tests con tz distinta) se hace
 * tocando TIMEZONE y/o las firmas, no buscando 12 archivos en el repo.
 *
 * Uso:
 *   BogotaTime::todayStart()   -> CarbonImmutable a 00:00:00 Bogota del dia actual
 *   BogotaTime::now()          -> CarbonImmutable al instante actual Bogota
 *   BogotaTime::todayAsDateString() -> 'YYYY-MM-DD' del dia Bogota actual
 *
 * Los metodos son estaticos a proposito: Eloquent y Carbon ya tienen
 * `Carbon::setTestNow()` para mockear el reloj en tests, asi que no se
 * necesita contenedor ni facade.
 */
class BogotaTime
{
    public const TIMEZONE = 'America/Bogota';

    public static function todayStart(): CarbonImmutable
    {
        return CarbonImmutable::today(self::TIMEZONE);
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    public static function todayAsDateString(): string
    {
        return self::todayStart()->toDateString();
    }
}
