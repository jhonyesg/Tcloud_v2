## Why

El sistema TCloud opera exclusivamente en zona horaria America/Bogota, pero la aplicación tiene 12 sitios donde se llama `CarbonImmutable::today()` sin pasar la zona explícitamente. Funciona HOY porque `config('app.timezone') === 'America/Bogota'`, pero ese contrato es implícito: cualquier futuro PR que cambie `app.timezone` (o un override de testing) rompe todas las queries de "hoy" silenciosamente. Adicionalmente, el operador hace queries de diagnóstico con `psql` directamente y la sesión nativa de PostgreSQL queda en UTC, lo que le devuelve timestamps desfasados -5h. Por último, el comando `transcription:fix-recorded-at-timezone` quedó documentado pero no se ha ejecutado de forma regular, dejando riesgo de residuales con `recorded_at` desfasado.

## What Changes

- **Helper central `BogotaTime`** en `app/app/Services/Ia/BogotaTime.php` que encapsula el inicio del día Bogota. Métodos: `todayStart(): CarbonImmutable`, `nowInBogota(): CarbonImmutable`, `todayAsIsoString(): string`. Una sola fuente de verdad para "qué es hoy en Bogota".
- **Refactor de 12 call sites** que hoy usan `CarbonImmutable::today()` sin zona → `BogotaTime::todayStart()` (o el wrapper que ya existe `TodayPendingService::todayStart()` si solo lo usa ese servicio).
- **`database.php`**: la conexión `pgsql` fija `'timezone' => 'America/Bogota'` sin depender de `env()`. Blindaje contra `.env` que se olvide.
- **`/root/.psqlrc`**: agregar `SET timezone = 'America/Bogota';` y `\set today_bogota ...` para que toda query de diagnóstico del operador arranque en Bogota sin recordar el `SET`.
- **Backfill auditable** de `recorded_at` desfasado vía el comando existente `transcription:fix-recorded-at-timezone --days=30 --dry-run` y luego `--apply`. Idempotente, ya aplicado el 2026-09-16 pero con residuales posibles.
- **Documentación inline**: queries SQL crudas con comparación contra `timestamptz` deben llevar comentario explícito `AT TIME ZONE 'America/Bogota'` para forzar la convención visible (no solo confiar en Carbon).

## Non-goals

- No tocar `app.php` (ya está en `America/Bogota`) ni `php.ini` (ya está Bogota).
- No migrar columnas `naive` (`created_at`, `dispatched_at`, `started_at`, `finished_at`, `last_polled_at`, `requeue_after_at`) — la decisión documentada en AGENTS.md (sección "Zona horaria de PostgreSQL") explica que esas son "hora de pared" intencionalmente.
- No mover el sistema operativo a otra zona.
- No agregar soporte multi-zona — sigue siendo single-tenant en Bogota.
- No crear endpoints UI nuevos para gestionar timezone (no es decisión del operador).

## Capabilities

### New Capabilities
- `transcriptor-bogota-time-helper`: helper central `BogotaTime` que encapsula el inicio del día Bogota, con tests unitarios y un único punto de cambio futuro.
- `transcriptor-timezone-session-default`: garantía operacional de que toda sesión PostgreSQL nacida de PHP-FPM arranca en `America/Bogota` (config explícita en `database.php`); y que las sesiones interactivas del operador (psql) también, vía `/root/.psqlrc`.

### Modified Capabilities
- `transcriptor-state-visibility`: el conteo "Pendientes (live)" ahora cita explícitamente "Bogota" en su etiqueta o tooltip para que el operador entienda la base horaria.
- `transcriptor-regulator-signals`: el regulador ya usaba Bogota implícito; ahora usa `BogotaTime` explícito y la decisión queda documentada en el código.

## Impact

- **Archivos PHP modificados**:
  - `app/app/Services/Ia/BogotaTime.php` (nuevo, ~30 líneas)
  - 12 call sites en `TranscriptionBulkDispatchService`, `TranscriptionSubmitService`, `TodayPendingService`, `TranscriptionBackfillLostCommand`, `TranscriptionPurgeStalePendingCommand`, `TranscriptionStageCommand`, `TranscriptionStorageSnapshotCommand`, `TranscriptionTickCommand` (×3), `TranscriptionWorkerCommand`.
  - `app/config/database.php` (1 línea: `'timezone' => 'America/Bogota'`).
  - Tests: `app/tests/Unit/BogotaTimeTest.php` (nuevo).
- **Archivos del sistema**:
  - `/root/.psqlrc` (nuevo, 2-3 líneas).
- **Datos**: backfill auditable de `recorded_at` para los últimos 30 días (idempotente, ya aplicado previamente, residuales = N filas).
- **Runtime**: cero impacto (solo cambia de dónde se lee la zona, no cuándo ni cómo).
- **Operacional**: el operador ahora hace `psql` y ve Bogota por defecto; las queries de auditoría serán más seguras.
