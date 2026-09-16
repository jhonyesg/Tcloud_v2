# transcriptor-pg-native-queue-tests-cleanup — Design

## Context

Ver `proposal.md` para motivación. El estado relevante para este design:

- `tests/Unit/TranscriptorSettingsTest.php` asume defaults de schema (`target_pg_queue=140`, `min_batch=10`, `max_batch=200`, `runway=5`) que NO se cumplen en el test-env actual porque `map()` recarga desde `system_settings` y la BD de producción tiene overrides del operador.
- `config/transcriptor.php` no declara `submit_with_idempotency_key`, pero el schema (introducido por el change `optimize-transcriptor-dispatch-throughput`) sí lo hace. Tests en rojo por la inconsistencia.

## Goals / Non-Goals

**Goals:**

- 4 tests rojos → verde, sin tocar overrides reales del operador en BD.
- +1 entry de config ausente que ya debería existir por la inconsistencia schema-vs-config.
- Mantener `git diff` pequeño (4 archivos máximo: 1 config + 1 test + 2 del change OpenSpec).

**Non-goals:**

- NO migrar overrides legacy en BD.
- NO introducir mecanismo de bypass-de-persistencia en `TranscriptorSettings` (sería overengineering para un problema de test).
- NO tocar production code del transcriptor.

## Decisions

### Decisión 1: tests con `seedOverrides` en vez de `Cache::forget` o bypass

**Elegido**: los 3 tests rojos se reescriben para llamar a `seedOverrides()` con los valores baseline que necesitan (los que asume el comentario del test), antes de evaluar el método bajo prueba.

**Por qué**: `Cache::forget(self::CACHE_KEY)` solo borra la key `transcriptor:settings` del cache store. Como `map()` usa `Cache::remember`, el siguiente acceso la recarga desde `system_settings`. Con overrides reales del operador en BD, esa recarga trae los overrides y rompe la asunción del test.

`seedOverrides()` escribe en la key de cache con `Cache::put(KEY, $map, 60)`, y como ya existe cuando `map()` la lee via `Cache::remember`, NO se ejecuta el callback de BD. Esto aísla el test del estado real de `system_settings`.

**Alternativa A**: limpiar todas las keys de cache al inicio del test con `Cache::flush()`. Descartada porque si hay futuras keys relacionadas, las borra también (acoplamiento entre tests).

**Alternativa B**: introducir un flag `TranscriptorSettings::setInMemoryModeForTesting()` que bypasse el cache. Descartada por ser overengineering y contaminar producción con código de test.

**Alternativa C**: usar SQLite en memoria para los tests de settings. Descartada porque toda la BD real persiste overrides; la sustitución no resuelve que `target_redis_queue=800` siga allí.

### Decisión 2: agregar 10 entries de config para alinearlo con el schema

**Elegido**: añadir las siguientes entries en `config/transcriptor.php` con el mismo `env_key` y default que el schema:

```php
'submit_with_idempotency_key' => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_IDEMPOTENCY_KEY', true),
'burst_max_upstream_queue'    => (int) env('TRANSCRIPTOR_BURST_MAX_UPSTREAM_QUEUE', 180),
'burst_batch_size'            => (int) env('TRANSCRIPTOR_BURST_BATCH_SIZE', 40),
'burst_parallel_ffmpeg'       => (int) env('TRANSCRIPTOR_BURST_PARALLEL_FFMPEG', 4),
'burst_poll_interval_seconds' => (int) env('TRANSCRIPTOR_BURST_POLL_INTERVAL_SECONDS', 10),
'max_backoff_seconds'         => (int) env('TRANSCRIPTOR_MAX_BACKOFF_SECONDS', 300),
'circuit_breaker_threshold'   => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_THRESHOLD', 3),
'circuit_breaker_open_seconds' => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_OPEN_SECONDS', 60),
'submit_with_callback'        => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_CALLBACK', false),
'webhook_secret'              => env('TCLOUD_WEBHOOK_SECRET', ''),
```

**Por qué**:

1. **Test guardrail**: `testTodaClaveDelEsquemaTieneRespaldoEnConfig` exige que cada key del schema tenga un `config('transcriptor.X')` no nulo. Sin estas entries, el test reporta cada faltante una por una.
2. **Runtime**: las 10 keys son **activas en producción** (cada una leída por `TranscriptorApiClient`, `UpstreamCircuitBreaker`, `TranscriptorBurstDispatchCommand`, `TranscriptionWebhookController`, etc.). El accessor `TranscriptorSettings::get()` cae al `$spec['default']` cuando `config()` retorna null, así que producción corre correctamente — pero el `effective()` reporta `default=null` en vez del default del schema (UI muestra defaults en columna incorrecta).
3. **Alignement**: el schema declara `env_key` para cada una. El config debe reflejar esa misma variable de entorno para que un override en `.env` se respete.

**Alternativa**: cambiar `'default' => true` a `'default' => null` en cada schema entry. Descartada porque el default semantic del schema es legítimo (idempotency_key se envía por default, webhooks NO, etc.) y ese default debe vivir en config para que la UI lo muestre como "default de archivo" en vez de "bd" confuso.

### Decisión 3: tests reescritos específicamente

Tres tests pasan a usar `seedOverrides`. Los mapeos baseline:

| Test | Asume | `seedOverrides` |
|---|---|---|
| `testElFrenoActuaJustoEnElLimite` | target=140, runway=5, min_batch=10, max_batch=200 | `['target_pg_queue' => 140, 'runway' => 5, 'min_batch' => 10, 'max_batch' => 200]` |
| `testConMargenAmplioSeAplicaElTechoMaxBatch` | target=140, runway=5; luego target=1000, max=200, runway=5 | el primero fija `['target_pg_queue' => 140, 'runway' => 5, 'min_batch' => 10, 'max_batch' => 200]`; el segundo agrega `'target_pg_queue' => 1000, 'max_batch' => 200` |
| `testRechazaMinBatchMayorQueMaxBatch` | min=300 falla contra max=200 | `['min_batch' => 300, 'max_batch' => 200]` antes del assert |

El test `testMinBatchCeroPermiteLotesPequenos` ya usa `seedOverrides(['min_batch' => 0])` correctamente — queda intacto. Es la guía de patrón.

### Decisión 4: el test #4 (`testTodaClaveDelEsquemaTieneRespaldoEnConfig`) queda arreglado por la decisión 2, sin tocar el test.

**Por qué**: ese test recorre todas las keys del schema y exige que cada una exista en `config('transcriptor.X')` no nulo. Agregando los 10 entries, ese test pasa sin cambios.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Operador lee los tests y piensa que los valores `seedOverrides` son los reales de producción | El comentario de cada test debe aclarar que `seedOverrides` es un "baseline de prueba", no producción. |
| En el futuro otro cambio agrega una key al schema sin tocar config, y este test vuelve a rojo | El test es actualmente el único guardrail contra esa clase de bug. Si queda fuera, pedir un pre-commit hook que compare schema-keys vs config-keys. (fuera de scope hoy) |
| Los 3 tests rojos hoy pasan con override fijo, pero un operador con overrides distintos en BD vería un comportamiento real distinto del test | Correcto. La responsabilidad del test es validar la lógica del regulador bajo condiciones controladas, no replicar exactamente la producción. Los tests de integración/harness cubren producción. |

## Migration Plan

**Deploy:** ninguno especial. Es un commit de test-fixture + 1 entry de config (que ya debería haber estado). Sin migración de BD.

**Pasos:**

1. `git pull` con el commit.
2. `vendor/bin/phpunit --filter TranscriptorSettingsTest` → debe pasar 24/24 verde.

**Rollback:** `git revert <commit>`. No hay estado persistente que limpiar.

## Open Questions

Ninguna.
