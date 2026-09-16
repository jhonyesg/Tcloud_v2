# Delta: transcriptor-regulator-signals

## ADDED Requirements

### Requirement: Decisión del regulador cita BogotaTime::todayStart() en código

El sistema SHALL usar `App\Services\Ia\BogotaTime::todayStart()` (no `CarbonImmutable::today()` ni `now()`-naive) en todos los puntos donde el regulador pregunta "¿qué es hoy?" para definir el conjunto de filas candidatas (`recorded_at >= hoy_bogota`).

Esta regla NO modifica los scenarios existentes del requirement "Modos del regulador configurables en caliente", los cuales siguen aplicando en su forma original. La razón de este requirement adicional es documentar el contrato de zona del helper para que un futuro PR no cambie el `recorded_at >= ...` del regulador a `CarbonImmutable::today()` sin zona explícita.

#### Scenario: Cambio de zona del proceso PHP no afecta al regulador
- **WHEN** el proceso PHP-FPM arranca con `TZ=UTC` (test, docker, etc.)
- **THEN** el regulador sigue contando las filas del día Bogota (no del día UTC), porque `BogotaTime::todayStart()` siempre devuelve el inicio del día Bogota

#### Scenario: Operador ve Bogota en el snapshot del regulador
- **WHEN** el regulador reporta `pg_queue_depth=N` en el panel de Configuración
- **THEN** el tooltip o la documentación cita explícitamente "Bogota (BogotaTime::todayStart)" para que el operador entienda la base horaria
