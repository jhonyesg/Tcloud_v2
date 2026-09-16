## Purpose

Define el comportamiento del pase de coherencia IA (`App\Services\Ia\TranscriptionCoherencePass`) que, después del diccionario de correcciones y antes de persistir el segmento, detecta spanglish residual (mezcla EN/ES o segmentos mayormente EN) y lo corrige con LLM. La política es **manual-only por defecto**: el pase arranca apagado en código, se activa únicamente cuando el admin lo prende explícitamente desde AI Settings o vía `.env`, y tolera fallos consecutivos del LLM sin gastar tokens en bucle.

## ADDED Requirements

### Requirement: Coherence pass defaults to OFF in code and DB

El pase de coherencia IA SHALL arrancar con `transcriptor.ai_coherence_enabled=0` en el default del schema (`LlmCorrectionSettings`/`TranscriptorSettings`), de modo que:

1. Un `migrate:fresh` o un seed limpio deja `ai_coherence_enabled=0` por default.
2. La transcripción nueva pasa SOLO por el diccionario de correcciones — el pase LLM nunca corre sin un admin que lo prenda explícitamente.
3. Si la fila de `system_settings` se borrara, el default del código evita la reactivación accidental.

#### Scenario: Fresh install con DB vacía
- **WHEN** un admin corre `php artisan migrate:fresh --seed`
- **THEN** `SELECT value FROM system_settings WHERE key='transcriptor.ai_coherence_enabled'` retorna `'0'`.
- **AND** los queue workers `tcloud-transcription-batch-*` no invocan el LLM en ninguna transcripción.

#### Scenario: Admin prende el pase desde AI Settings
- **WHEN** admin abre `/ia/correcciones` → AI Settings → toggle "Pase de coherencia IA" y lo prende
- **THEN** la fila `transcriptor.ai_coherence_enabled` queda en `'1'`.
- **AND** a partir de la próxima transcripción procesada por un worker reiniciado, el pase se invoca contra el LLM.

#### Scenario: Worker bootea con toggle prendido en DB
- **WHEN** un worker `tcloud-transcription-batch-N.service` arranca con `transcriptor.ai_coherence_enabled=1`
- **THEN** el pase corre como antes — el toggle enciende, no hace falta código nuevo.

### Requirement: Coherence pass tolerates consecutive LLM failures via circuit breaker

`TranscriptionCoherencePass::callWithRetry()` SHALL implementar una ventana móvil de circuit breaker por provider: si un provider falla `N` veces consecutivas (default `N=5`) en una ventana móvil de `X` segundos (default `X=600` = 10 min), ese provider queda excluido de la lista rotativa para el resto del job actual. El pase SHALL loguear un warning con `{provider, failures, last_error}` cuando excluye.

La cuenta de fallos se guarda en cache (Redis o APCu) con key `coherence_breaker:{provider}` y TTL = ventana móvil. La exclusion aplica solo dentro del job actual (no se persiste a través de jobs).

#### Scenario: Provider falla 5 veces y queda excluido
- **WHEN** el provider `tertiary` retorna HTTP 401 en las primeras 5 llamadas consecutivas dentro de una ventana de 10 minutos
- **THEN** la 6ª iteración del round-robin NO intenta `tertiary` y rota directo al siguiente provider disponible.
- **AND** se loguea `WARNING coherence_breaker: provider tertiary excluded after 5 consecutive failures (last error: HTTP 401 invalid api key)`.

#### Scenario: Provider vuelve a funcionar dentro de la ventana
- **WHEN** el provider `tertiary` retorna HTTP 401 tres veces, luego retorna 200 OK dentro de la misma ventana móvil
- **THEN** el contador de fallos consecutivos se resetea a 0.
- **AND** `tertiary` sigue siendo elegible en el round-robin.

#### Scenario: Todos los providers quedan excluidos
- **WHEN** todos los providers `enabled` quedan excluidos por circuit breaker durante un job
- **THEN** el pase sale con la excepción original del último provider intentado.
- **AND** el WARNING conserva los detalles del circuit breaker para que el admin diagnostique.

### Requirement: transcription:backfill-coherence respects the toggle

`php artisan transcription:backfill-coherence` SHALL chequear `transcriptor.ai_coherence_enabled` antes de empezar a procesar. Si el toggle está en `0`, el comando SHALL salir con código SUCCESS y un WARNING, sin gastar tokens ni tocar la BD.

#### Scenario: Admin corre backfill con toggle apagado
- **WHEN** admin corre `php artisan transcription:backfill-coherence --days=7`
- **AND** `transcriptor.ai_coherence_enabled=0`
- **THEN** el comando imprime `[WARNING] El pase de coherencia IA está deshabilitado. Activalo desde AI Settings antes de correr este comando.`
- **AND** retorna código de salida `0` (no es error de admin, solo modo seguro).
- **AND** no se hace ninguna llamada al LLM.

#### Scenario: Admin corre backfill con toggle prendido
- **WHEN** admin corre `php artisan transcription:backfill-coherence --days=7`
- **AND** `transcriptor.ai_coherence_enabled=1`
- **THEN** el comando procesa los lotes de transcripciones en chunks de `--batch` (default 5) con pausa `--sleep` segundos entre lotes.

### Requirement: AI Settings UI documents the manual-only policy

La pantalla AI Settings (`/ia/correcciones` → sub-tab AI Settings) SHALL mostrar, junto al toggle `Pase de coherencia IA`, un bloque de ayuda con el texto explícito:

> **Modo seguro por defecto**. Este pase llama al LLM para corregir spanglish residual. Solo actívalo cuando estés revisando resultados manualmente. Mientras esté apagado, el sistema usa solo el diccionario de correcciones (instantáneo, sin costo). Si lo activas, el sistema lo invoca automáticamente en cada transcripción nueva.

#### Scenario: Admin ve el bloque de ayuda
- **WHEN** admin abre AI Settings con el pase apagado
- **THEN** ve el bloque de ayuda con el texto de política manual-only y un badge "Modo seguro" en verde junto al toggle apagado.

#### Scenario: Admin prende el toggle
- **WHEN** admin activa el toggle
- **THEN** el badge cambia a "Modo activo" en ámbar, y el bloque de ayuda muestra un segundo párrafo explicando que ahora cada transcripción nueva invoca el LLM y el costo estimado por minuto de audio.
