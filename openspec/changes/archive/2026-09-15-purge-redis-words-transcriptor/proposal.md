# purge-redis-words-transcriptor

## Why

El módulo API Transcriptor dejó de usar Redis como cola en el cutover `transcriptor-pg-native-queue` (2026-09-15), pero el código, los comentarios, los docblocks, las etiquetas de UI y los tests aún contienen la palabra "Redis" aplicada a conceptos que ahora son PG (despacho, worker, jobs encolados, "Cola Redis" en la UI). Para una IA o un dev que abra este módulo en 2027, esos vestigios inducen a error: "veo `target_redis_queue` en SCHEMA, asumo que la cola vive en Redis". El propio proposal del cutover (§Nombres engañosos que confunden a otras IAs) ya advertía que esto iba a pasar; este change cierra la fuga.

## What Changes

- **UI del módulo Transcriptor** (`resources/views/ia/api-transcriptor/_settings-tab.blade.php`, `index.blade.php`): la etiqueta "Cola Redis" del gauge de configuración pasa a "Cola de despacho" porque lee `COUNT(*)` de `transcriptions`. El mensaje catch-all "Redis no disponible" se neutraliza a "Servicio no disponible" porque no hay forma de saber desde la UI si la caída es Redis, PG o el upstream.
- **Comentarios y docblocks de la capa de despacho** (`TranscriptionTickCommand`, `TranscriptionWorkerCommand`, `TranscriptionSubmitService`, `TranscriptionBulkDispatchService`, `ScanAndSubmitCommand`, `DiskScannerService`): se reescriben comentarios que dicen "encola a Redis", "NO toca Redis", "Reemplaza al antiguo dispatch a Redis" para que reflejen el estado real (todo va por PG vía `transcriptions`).
- **Rebranding de la capa de caché** (`TranscriptorSettings`, `DictionaryAudit`, `UpstreamCircuitBreaker`, `TranscriptionCoherencePass`, `LlmCorrectionSettings`, `StorageFunnelService`, `AvisosInteligentesController`): la palabra "Redis" en comentarios sobre el store de caché se reemplaza por "store de caché" porque el módulo no debe comprometerse con un backend específico (hoy es Redis por `config/cache.php`, mañana podría ser memcached o `array`).
- **Retiro de zombis en `config/transcriptor.php` + `TranscriptorSettings::SCHEMA`**: `stagger_chunk_size` y `batch_per_worker_ratio` (defaults 5 y "1.5"). Búsqueda exhaustiva confirma cero lecturas en runtime; eran del ramp-up pre-migración.
- **Tests rotos arreglados** (`tests/Unit/TranscriptorSettingsTest.php`): 11 referencias a `target_redis_queue` se migran a `target_pg_queue` (default 140). El archivo está roto desde el cutover.
- **Preservado intencionalmente**:
  - `TranscriptorPurgeBacklogCommand`: contiene los SELECT/UPSERT/DELETE sobre la key legacy; ES la herramienta de cutover.
  - `tests/harness_transcriptor_pg_queue.php`: valida que la key legacy NO existe en `system_settings`.

## Capabilities

### New Capabilities

Ninguna — los settings retirados (`stagger_chunk_size`, `batch_per_worker_ratio`) nunca tuvieron specs propios y nadie los lee.

### Modified Capabilities

Ninguna (sin cambio de comportamiento).

## Non-goals

- No migra la BD, no cambia `system_settings`, no cambia defaults observables para el operador.
- No toca `config/cache.php`, `config/session.php` ni `config/queue.php` (el store Redis real del caché vive fuera del scope del módulo).
- No toca los nombres de las variables de entorno legacy en `.env` (pueden quedar para historial).
- No altera el comportamiento del cron `transcription:tick`, del worker PG, del bulk dispatch, ni del watchdog.

## Impact

**Archivos modificados (estimado):**

- `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` — 1 string UI.
- `app/resources/views/ia/api-transcriptor/index.blade.php` — ~4 comentarios / mensajes JS.
- `app/app/Console/Commands/TranscriptionTickCommand.php` — 2 docblocks/comentarios.
- `app/app/Console/Commands/TranscriptionWorkerCommand.php` — 1 docblock.
- `app/app/Console/Commands/ScanAndSubmitCommand.php` — help text + ~4 comentarios.
- `app/app/Console/Commands/TranscriptorPurgeBacklogCommand.php` — **NO se toca**.
- `app/app/Services/Ia/TranscriptionSubmitService.php` — 1 docblock.
- `app/app/Services/Ia/TranscriptionBulkDispatchService.php` — 1 docblock.
- `app/app/Services/Ia/DiskScannerService.php` — 2 comentarios.
- `app/app/Services/Ia/TranscriptorSettings.php` — 1 help text + 1 docblock (`map()`).
- `app/app/Services/Ia/DictionaryAudit.php` — 1 docblock.
- `app/app/Services/Ia/UpstreamCircuitBreaker.php` — 1 docblock.
- `app/app/Services/Ia/TranscriptionCoherencePass.php` — ~2 comentarios.
- `app/app/Services/Ia/LlmCorrectionSettings.php` — 2 comentarios.
- `app/app/Services/Ia/StorageFunnelService.php` — 1 comentario.
- `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` — 2 comentarios.
- `app/config/transcriptor.php` — retiro de 2 entries (`stagger_chunk_size`, `batch_per_worker_ratio`).
- `app/tests/Unit/TranscriptorSettingsTest.php` — 11 referencias (migración al nuevo nombre).

**APIs / rutas / modelos**: ninguno cambia. Las rutas `/ia/api-transcriptor/settings` (POST), `/ia/api-transcriptor/storages/{id}/toggle`, etc. siguen idénticas.

**Migraciones de BD**: ninguna.

**Riesgo**: bajo. Cambios son redacción + retiro de 2 entries de `config/transcriptor.php` sin reads + actualización de tests.

**Rollback**: `git revert <commit>`. No hay estado persistente que limpiar.
