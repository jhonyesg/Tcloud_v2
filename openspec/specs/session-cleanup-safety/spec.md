## Purpose

Proteger al sistema contra purgas masivas accidentales del cron `sessions:cleanup`, exigir guardarraíles de seguridad (ratio máximo, métrica y dry-run) y dejar evidencia auditable de cuántas sesiones se escanearon vs cuántas se eliminaron en cada corrida.

## Requirements

### Requirement: Cron sessions:cleanup con guardarraíl de ratio máximo

El job programado `sessions:cleanup` SHALL abortar su operación de borrado y registrar un warning si la proporción de filas a borrar respecto al total escaneado supera un umbral configurable (default 50%). Esto evita que un bug futuro en `sessionExistsInRedis()` vuelva a borrar todas las sesiones de un solo golpe.

#### Scenario: cleanOrphans corre normal
- **WHEN** `cleanOrphans()` escanea N filas y borraría M, donde `M/N <= 0.5`
- **THEN** SHALL borrar las M filas huérfanas normalmente
- **AND** SHALL loguear `sessions.cleanup.completed` con `{scanned: N, deleted: M, ratio: M/N}`

#### Scenario: cleanOrphans detecta purga masiva y aborta
- **WHEN** `cleanOrphans()` escanea N filas y proyecta borrar M, donde `M/N > 0.5`
- **THEN** SHALL abortar la corrida SIN borrar ninguna fila
- **AND** SHALL loguear `sessions.cleanup.aborted_mass_delete` con `{scanned: N, would_delete: M, ratio: M/N, threshold: 0.5}`
- **AND** SHALL retornar `0`

#### Scenario: Umbral configurable
- **WHEN** `system_settings` define `sessions_cleanup_max_ratio` (default `0.5`)
- **THEN** el guardarraíl SHALL usar ese valor en lugar del hardcoded

### Requirement: Dry-run opcional para diagnóstico

El job `sessions:cleanup` SHALL soportar un parámetro `dry-run` que permite ejecutar la lógica de detección sin borrar ninguna fila, devolviendo cuántas habría borrado. Esto permite al admin auditar el efecto del cron antes de activarlo en producción.

#### Scenario: Admin corre dryRun desde consola
- **WHEN** se invoca `app(SessionService::class)->cleanOrphans(dryRun: true)`
- **THEN** SHALL retornar el conteo de filas que habría borrado
- **AND** SHALL NO eliminar ninguna fila de `user_sessions`
- **AND** SHALL loguear `sessions.cleanup.dry_run` con `{scanned, would_delete}`

### Requirement: Métrica mínima auditable

Cada ejecución de `cleanOrphans` o `cleanExpired` SHALL emitir al menos una línea de log estructurado (`sessions.cleanup.completed` o `sessions.cleanup.aborted_mass_delete`) con los campos `scanned`, `deleted`, `would_delete` (cuando aplique), `ratio`, `duration_ms` y `started_at`. Esto permite auditar post-mortem cualquier logout masivo.

#### Scenario: Corrida normal deja traza
- **WHEN** `cleanOrphans` completa una corrida exitosa
- **THEN** SHALL existir una entrada en `laravel.log` con `message=sessions.cleanup.completed`
- **AND** SHALL incluir `scanned`, `deleted`, `ratio`, `duration_ms`

#### Scenario: Corrida abortada deja traza
- **WHEN** el guardarraíl aborta una corrida
- **THEN** SHALL existir una entrada con `message=sessions.cleanup.aborted_mass_delete`
- **AND** SHALL incluir `scanned`, `would_delete`, `ratio`, `threshold`

### Requirement: Frecuencia del cron revisable

La frecuencia del cron `sessions:cleanup` SHALL ser revisable por el admin vía `system_settings` (`sessions_cleanup_interval_minutes`, default 30). Valores demasiado agresivos SHALL generar un warning la primera vez que se detecten (< 5 minutos).

#### Scenario: Admin baja el intervalo a 1 minuto (peligroso)
- **WHEN** `system_settings.sessions_cleanup_interval_minutes = 1`
- **THEN** SHALL loguear `sessions.cleanup.interval_too_aggressive` con el valor detectado
