## Context

Ver `proposal.md` para motivación. Estado actual resumido:

- `config('app.timezone') = America/Bogota` ✓
- `config('database.connections.pgsql.timezone') = env('DB_TIMEZONE', 'America/Bogota')` ✓ (depende de env)
- 12 call sites con `CarbonImmutable::today()` sin argumento explícito — funcionan por convención, frágiles
- `/root/.psqlrc` no existe — operador arranca psql en UTC
- Backfill de `recorded_at` ya aplicado una vez el 2026-09-16; sin corrida regular
- Queries SQL crudas sin marca `AT TIME ZONE 'America/Bogota'` visible

## Goals / Non-Goals

**Goals:**
- Una sola fuente de verdad para "hoy en Bogota": `BogotaTime::todayStart()`.
- Conexión PHP-FPM/Postgres configurada explícitamente sin depender de `.env`.
- Operador arranca `psql` ya en Bogota.
- Documentación inline que haga visible el contrato de zona.
- Backfill auditable de residuales.

**Non-goals:**
- No migrar columnas naive a timestamptz (decisión de AGENTS.md).
- No cambiar `app.php` ni `php.ini`.
- No crear UI de gestión.
- No romper comportamiento existente (cero cambios funcionales observables para los módulos consumidores).

## Decisions

### D1. Helper `BogotaTime` como clase, no trait ni constant

**Por qué**: Una clase con métodos estáticos permite mocking fácil en tests, encapsula la dependencia de `config('app.timezone')`, y deja un único punto de cambio si en el futuro se necesita multi-zona. Un trait obligaría a importarlo en cada consumidor y un constant global sería demasiado rígido.

**Firma propuesta**:
```php
namespace App\Services\Ia;

use Carbon\CarbonImmutable;

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
        return self::todayStart()->toDateString();  // YYYY-MM-DD
    }
}
```

**Alternativa considerada**: Helper con instancia singleton (`app(BogotaTime::class)`) para poder mockearlo con `$this->app->instance()` en tests. Descartada porque los métodos estáticos también se mockean con `CarbonImmutable::setTestNow()` o con `Carbon::setTestNowAndTimezone()`, que es la convención Laravel. Mantener estático = menos ceremony.

### D2. `database.php` con `'timezone' => 'America/Bogota'` literal, no env()

**Por qué**: Blindaje contra un `.env` que se olvide o que defina `DB_TIMEZONE=UTC` por error. El default Bogota ya está documentado, pero quitar el `env()` lo hace contractual.

**Alternativa considerada**: Mantener el `env()` pero agregar `env_fail` o un custom validator que aborte el boot si `DB_TIMEZONE != America/Bogota`. Descartada por complejidad innecesaria — Bogota es la única zona soportada.

### D3. `/root/.psqlrc` con `SET timezone = 'America/Bogota'`

**Por qué**: El operador (jsuarez) hace queries de diagnóstico con psql directo. Hoy esas queries devuelven timestamps en UTC. El `.psqlrc` aplica en cada conexión interactiva sin requerir discipline.

**Contenido**:
```bash
\pset null '[NULL]'
\encoding UTF8
SET timezone = 'America/Bogota';
\set today_bogota `date +%Y-%m-%d`
```

**Por qué también `\pset null '[NULL]'` y `\encoding UTF8`**: standard operacionales menores que no son foco del change pero ayudan al diagnóstico.

**Riesgo**: otros usuarios del sistema operativo no tendrán ese `.psqlrc`. Si solo se aplica en `/root/.psqlrc`, queda acoplado al usuario root. Alternativa: ponerlo en `/etc/psqlrc` (global). Decisión propuesta: empezar por `/root/.psqlrc` (quien corre psql en el server es root), documentar la opción `/etc/psqlrc` en el design si se quiere escalar.

### D4. Backfill de `recorded_at` ejecutable pero gated por dry-run

**Por qué**: El comando `transcription:fix-recorded-at-timezone` ya existe y es idempotente. Re-correrlo es seguro. Pero aplicar un backfill sin dry-run previo es una mutación masiva que necesita aprobación humana por separado. El change NO aplica el `--apply` automáticamente — solo deja preparado el comando para que el operador lo corra cuando quiera.

**Alternativa considerada**: Backfill automático en una migración. Descartada — los backfills no van en migraciones porque no son schema changes.

### D5. Documentación inline en queries SQL crudas

**Por qué**: Las comparisons de Carbon contra timestamptz se traducen a `WHERE x >= $1::timestamptz`. Eso no deja rastro de "Bogota" en el SQL generado. Para queries con `DB::raw` o `DB::statement`, el contrato debe ser visible: agregar comentario `// Bogota: AT TIME ZONE 'America/Bogota'::timestamptz`. Es defensa en profundidad contra regresiones futuras.

**Alcance**: Solo las queries con `DB::raw` / `DB::statement` que comparan contra columnas `timestamptz`. Las queries con Eloquent + Carbon no necesitan (Carbon ya sabe).

## Risks / Trade-offs

- **[Helper sin uso de instancia]**: Los métodos estáticos son difíciles de extender si BogotaTime necesita estado (ej. múltiples zonas). Mitigación: el constant `TIMEZONE` permite monkey-patching en tests via `BogotaTime::TIMEZONE = 'UTC'`. Si en el futuro se necesita multi-zona, refactor a instancia es directo (los call sites serían un s/ en regex).
- **[`.psqlrc` solo aplica a root]**: Si un dev abre `psql` desde otro user, no aplica. Mitigación: documentar la opción `/etc/psqlrc` en `AGENTS.md` como alternativa.
- **[Cambio del nombre del helper]**: Si se introduce `BogotaTime` y dentro de 6 meses se quiere multi-zona, hay que migrar 12+ call sites. Mitigación: el constant `TIMEZONE` se puede cambiar en un solo lugar y el nombre de la clase sigue siendo `BogotaTime` mientras siga siendo la zona por defecto. Si pasa a multi-zona, refactor mayor pero aislado.
- **[Doble fuente de verdad transitoria]**: Durante el cambio coexisten `CarbonImmutable::today()` (viejo) y `BogotaTime::todayStart()` (nuevo). Mitigación: el refactor se aplica en un solo commit por archivo, no se mezclan.
- **[Operador no nota el cambio de `.psqlrc`]**: Mitigación: se documenta en AGENTS.md y se prueba con `psql -c "SHOW timezone;"` durante la verificación.

## Migration Plan

Sin migración de BD. Deploy normal:
1. `git merge` del branch del change.
2. `composer dump-autoload` (por el helper nuevo).
3. `php artisan config:cache` (la nueva línea en `database.php` debe quedar cacheada).
4. Crear `/root/.psqlrc`.
5. `systemctl reload php-fpm-84` para purgar opcode cache.
6. (Operador decide después, no en este deploy) Correr `transcription:fix-recorded-at-timezone`.

**Rollback**: `git revert <commit>` + reload PHP-FPM. Cero estado persistente que limpiar (helper es PHP, `.psqlrc` es texto plano).

**No requiere downtime**.

## Open Questions

*(Ninguna — el operador aprobó la propuesta con los criterios explícitos: helper central, código + psqlrc + database.php + backfill, máximo rigor en queries.)*
