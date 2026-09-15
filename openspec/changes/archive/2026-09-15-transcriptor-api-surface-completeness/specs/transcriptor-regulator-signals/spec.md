# Spec Delta — transcriptor-regulator-signals

## ADDED Requirements

### Requirement: El cliente respeta `Retry-After` en 429 y 503
El sistema SHALL, ante respuesta HTTP 429 o 503 del upstream, leer el header `Retry-After` (entero o RFC-7231 HTTP-date), normalizarlo a segundos enteros, y propagarlo como `int $retryAfter` en `UpstreamRateLimitException` o `UpstreamUnavailableException` respectivamente. Si el header está ausente o mal formado en 503, SHALL usar `SystemSetting('max_backoff_seconds')` (default 300).

#### Scenario: 429 con Retry-After numérico
- **WHEN** `GET /v1/transcribe` responde `429` y `Retry-After: 12`
- **THEN** `TranscriptorApiClient` lanza `UpstreamRateLimitException($retryAfter=12)`
- **AND** `TranscriptionSubmitService::submit()` captura y llama `markRequeueable($tx, now()->addSeconds(12))`
- **AND** `last_run` del tick y la fila NO se marcan como `dead`

#### Scenario: 503 con Retry-After HTTP-date
- **WHEN** `503` con `Retry-After: Wed, 21 Oct 2026 07:28:00 GMT`
- **THEN** el sistema calcula diff con `now()` y propaga `UpstreamUnavailableException(294)` (por ejemplo)

### Requirement: Circuit breaker corta el tick tras strikes consecutivos
El sistema SHALL contar strikes upstream en Redis bajo `transcriptor:upstream:strikes:{minute}` (TTL 5 min) y abrir el break cuando `count >= SystemSetting('circuit_breaker_threshold')` (default 3) en una ventana móvil de 5 min. El break abierto SHALL persistir `circuit_breaker_open_until = now() + SystemSetting('circuit_breaker_open_seconds')` (default 60). Mientras el break esté abierto, el regulador SHALL evaluar `upstream_circuit_open` y frenar con `reason=upstream_circuit_open`, `batch_computed=0`.

#### Scenario: 3 strikes en 5 min abre el break
- **WHEN** 3 respuestas 4xx/5xx transitorias en menos de 5 min
- **THEN** `UpstreamCircuitBreaker::isOpen()` retorna true
- **AND** el siguiente tick sale con `decision=skipped, reason=upstream_circuit_open`
- **AND** `regulator_skip_reason='upstream_circuit_open'` se persiste en filas pendientes

#### Scenario: Tras 60 s sin strikes, half-open permite encolar
- **WHEN** `circuit_breaker_open_until < now()` y no hay strikes nuevos
- **THEN** el tick reanuda encolo normal
- **AND** si llega una nueva respuesta transitoria, vuelve a contar y reabre

### Requirement: El regulador expone causa upstream en endpoint
El sistema SHALL extender `GET /ia/api-transcriptor/regulator-cause` para incluir `signals_evaluated[]` la cadena `'upstream_circuit'` cuando el break esté abierto, y SHALL traducir el valor a texto humano en el panel de diagnóstico ("API externa no responde tras N strikes").

#### Scenario: Diagnóstico muestra estado del break
- **WHEN** el admin abre el panel de diagnóstico y el break está abierto
- **THEN** la sección "Causa actual" muestra "API externa no responde tras N strikes (próximo intento en Xs)"

#### Scenario: Sin break, la señal no aparece
- **WHEN** el break nunca se ha abierto desde el último reset
- **THEN** `signals_evaluated` no contiene `'upstream_circuit'`
- **AND** el endpoint no muestra la sección de texto humano de break

### Requirement: Configuración del break y backoff por env/system setting
El sistema SHALL leer las nuevas claves `max_backoff_seconds`, `circuit_breaker_threshold`, `circuit_breaker_open_seconds` desde la capa `TranscriptorSettings` (con fallback al env y a config) y SHALL NO requerir config nueva de supervisor ni reinicio del servicio.

#### Scenario: Ajuste sin redeploy
- **WHEN** el admin guarda `SystemSetting('circuit_breaker_threshold') = 5`
- **THEN** el siguiente tick usa el nuevo valor sin tocar nada más
- **AND** `php artisan tinker` confirmando lectura ve `'circuit_breaker_threshold' => '5'`

#### Scenario: Setting vacío o inválido no rompe el regulador
- **WHEN** un setting no existe o es no-numérico
- **THEN** `TranscriptorSettings::int('circuit_breaker_threshold')` devuelve el default `3`
- **AND** el regulador no lanza excepciones por configuración
