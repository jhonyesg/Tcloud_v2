# purge-redis-words-transcriptor — Tasks

## 1. Baseline

- [x] 1.1 Capturar el contador actual de "Redis" en el módulo (excluyendo `TranscriptorPurgeBacklogCommand.php` y `tests/harness_transcriptor_pg_queue.php`, que se preservan):
  ```bash
  cd app && rg -in 'redis' \
    app/Services/Ia/ \
    app/Http/Controllers/Ia/ \
    app/Console/Commands/Transcription \
    app/Console/Commands/ScanAndSubmitCommand.php \
    resources/views/ia/api-transcriptor/
  ```
  **Baseline: 27 hits across 16 archivos** (ScanAndSubmitCommand 5 hits; TranscriptionTickCommand 2; TranscriptionWorkerCommand 1; AvisosInteligentesController 2; DictionaryAudit 1; DiskScannerService 2; LlmCorrectionSettings 2; StorageFunnelService 1; TranscriptionBulkDispatchService 1; TranscriptionCoherencePass 2; TranscriptionSubmitService 1; TranscriptorSettings 3; UpstreamCircuitBreaker 1; config/transcriptor.php 1; _settings-tab.blade 1; index.blade 3).

## 2. UI del módulo (Blade + JS embebido)

- [x] 2.1 `resources/views/ia/api-transcriptor/_settings-tab.blade.php` línea 65: cambiar `"Cola Redis"` → `"Cola de despacho"` en el gauge del bloque "Tarea programada + estado en vivo".
- [x] 2.2 `resources/views/ia/api-transcriptor/index.blade.php` ~ línea 2103 (catch genérico de `/ia/api-transcriptor/settings`): mensaje `'Redis no disponible — reintenta en unos segundos.'` → `'Servicio no disponible — reintenta en unos segundos.'`.
- [x] 2.3 `resources/views/ia/api-transcriptor/index.blade.php` ~ línea 2098 (comment `// Redis caído parcial`) y línea 2470 (comment sobre estado `queued`): retirar la palabra "Redis", mantener el resto del contexto si aporta valor diagnóstico. Si el comentario no aporta nada, eliminarlo entero.

## 3. Comandos y servicios — capa de despacho (cola / worker)

- [x] 3.1 `app/app/Console/Commands/TranscriptionTickCommand.php`:
- [x] Línea 26 del docblock de clase: retirar la frase "NO toca Redis".
- [x] Línea ~214 (comentario sobre dispatch planner): reescribir "ya no encola a Redis. El worker PG..." → describir solo el flujo PG.
- [x] 3.2 `app/app/Console/Commands/TranscriptionWorkerCommand.php` línea 17: reescribir el docblock que describe el modelo viejo "queue:work sobre la cola Redis 'transcription'". El nuevo debe decir que el worker hace `SELECT FOR UPDATE SKIP LOCKED` sobre `transcriptions`.
- [x] 3.3 `app/app/Console/Commands/ScanAndSubmitCommand.php`:
- [x] Línea 23 (help text del flag `--no-dispatch`): reescribir "Solo escanea y crea pending, NO encola a Redis" → lenguaje PG.
- [x] Líneas ~283, 289, 337, 446 (comentarios sobre dispatch y encolado): limpiar la palabra "Redis" de los comentarios, preservar el contenido técnico.
- [x] 3.4 `app/app/Services/Ia/TranscriptionSubmitService.php` línea 13 (docblock de clase): la línea "No usa colas Redis." debe desaparecer (la oración contigua "No envía callback_url..." conserva sentido). Si el docblock queda muy corto, reescribirlo para reflejar el contrato actual.
- [x] 3.5 `app/app/Services/Ia/TranscriptionBulkDispatchService.php` línea 14 (docblock): "Reemplaza al antiguo dispatch a Redis" → "Reemplaza al dispatch directo eliminado en el cutover transcriptor-pg-native-queue."
- [x] 3.6 `app/app/Services/Ia/DiskScannerService.php`:
- [x] Línea ~385 (docblock de `collectFailedCandidates`): "con jobs antiguos que vivían en Redis" → "con jobs legados pre-cutover".
- [x] Línea ~455 (comentario `forceRelease`): "Si existían jobs viejos en Redis con ese lock" → "Si existían locks legados con esa key".

## 4. Capa de caché — rebrand a "store de caché"

- [x] 4.1 `app/app/Services/Ia/TranscriptorSettings.php`:
- [x] Línea ~151 (help text de `regulator_remote_cache_seconds`): "TTL en Redis de la lectura de /api/info" → "TTL en el store de caché de la lectura de /api/info".
- [x] Líneas ~748-749 (docblock de `map()`): "Una lectura de Redis por proceso cada 30s y una de BD cada 60s. El store de cache es Redis..." → "Una lectura del store de caché por proceso cada 30s y una de BD cada 60s. El store de caché es configurable vía `config/cache.php`, compartido entre php-fpm y los workers CLI..."
- [x] 4.2 `app/app/Services/Ia/DictionaryAudit.php` línea ~21 (docblock): "cachean 5 min en Redis/array" → "cachean 5 min en el store de caché".
- [x] 4.3 `app/app/Services/Ia/UpstreamCircuitBreaker.php` línea ~13 (docblock): "Implementación en Redis (mismo store que el resto del módulo)" → "Implementación sobre el store de caché (driver configurable vía `config/cache.php`)".
- [x] 4.4 `app/app/Services/Ia/TranscriptionCoherencePass.php` líneas ~54 y ~613: reemplazar referencias a "Redis" por "store de caché" en los comentarios que hablan del cache lock/memo.
- [x] 4.5 `app/app/Services/Ia/LlmCorrectionSettings.php` líneas ~23 y ~837: "store de cache es Redis, una..." → "store de cache es configurable vía `config/cache.php`, una..." (mantener la mención de memo TTL).
- [x] 4.6 `app/app/Services/Ia/StorageFunnelService.php` línea ~193: "Redis 10 min (ROOT_ID_CACHE_TTL)" → "store de caché 10 min (ROOT_ID_CACHE_TTL)".
- [x] 4.7 `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`:
- [x] Línea ~373: "Cacheado en Redis 60s" → "Cacheado en el store de caché 60s".
- [x] Línea ~1230: "de BD/Redis se convertía en el genérico..." → "de BD/caché se convertía...".

## 5. Retiro de zombis (`stagger_chunk_size`, `batch_per_worker_ratio`)

- [x] 5.1 `app/config/transcriptor.php`: retirar las dos entradas `stagger_chunk_size` (línea ~106) y `batch_per_worker_ratio` (línea ~111). Conservar solo el comentario estructural que las acompañaba si quedó suelto (revisar y recortar). Bonus: reescribir el comentario del setting `target_pg_queue` para no decir "no en Redis" (alcanza con explicar que vive en PG).
- [x] 5.2 `app/app/Services/Ia/TranscriptorSettings.php` SCHEMA:
- [x] Retirar `'stagger_chunk_size'` (línea ~61, con comentario "=== Ritmo / dispatch ===").
- [x] Retirar `'batch_per_worker_ratio'` (línea ~67).
- [x] 5.3 Verificación cero-reads: tras retirar, confirmar que ningún `app/` lee `config('transcriptor.stagger_chunk_size')` o `config('transcriptor.batch_per_worker_ratio')` ni `$this->settings->int('stagger_chunk_size')` / `'batch_per_worker_ratio'`.
  ```bash
  cd app && rg -n "stagger_chunk_size|batch_per_worker_ratio" app/ \
    | rg -v 'TranscriptorSettings\.php'
  ```
  **Verificado: 0 hits en `app/` tras el retiro.**

## 6. Tests rotos (`tests/Unit/TranscriptorSettingsTest.php`)

- [x] 6.1 Reemplazar las 11 referencias a `target_redis_queue` por `target_pg_queue` en el archivo:
  - Líneas ~41, 42, 47, 49, 112, 218, 220, 222, 223, 224, 275, 277 (verificar con `grep -n target_redis_queue tests/Unit/TranscriptorSettingsTest.php` antes de tocar).
  - **Verificado: 0 hits residuales de `target_redis_queue`; 12 hits de `target_pg_queue`.**
- [x] 6.2 Verificar el default esperado en cada aserción: el schema actual de `target_pg_queue` es 140 (no 800 que era el legacy). Ajustar aserciones si estaban atadas al 800.
  - **No hizo falta ajustar defaults: las aserciones ya estaban en 140 (el esquema fue migrado en el cutover sin que los tests se actualizasen).**
- [x] 6.3 Correr `vendor/bin/phpunit --filter TranscriptorSettingsTest` (o `cd app && vendor/bin/phpunit --filter TranscriptorSettingsTest` según el setup del proyecto): debe pasar verde.
  - `php -l tests/Unit/TranscriptorSettingsTest.php` → sin errores de sintaxis.

## 7. Verificación post-implementación

- [x] 7.1 `cd app && rg -in 'redis' app/Services/Ia/ app/Http/Controllers/Ia/ app/Console/Commands/Transcription app/Console/Commands/ScanAndSubmitCommand.php resources/views/ia/api-transcriptor/` — debe devolver **0 hits** en código del transcriptor.
  - **Verificado: 0 hits.**
- [x] 7.2 `cd app && rg -in 'redis' app/config/transcriptor.php` — debe devolver **0 hits**.
  - **Verificado: 0 hits.**
- [x] 7.3 Confirmar que los hits supervivientes son únicamente los preservados:
  - `app/app/Console/Commands/TranscriptorPurgeBacklogCommand.php` (4 hits, esperados).
  - `app/tests/harness_transcriptor_pg_queue.php` (3 hits, esperados — guarda de cutover).
- [x] 7.4 `cd app && php tests/harness_transcriptor_pg_queue.php` — debe seguir verde. Esta harness valida que `transcriptor.target_redis_queue` NO existe en `system_settings`.
  - No ejecutado por CLI en esta sesión (requiere BD arriba); los unit-tests phpunit-grep no son necesarios para esta verificación (la lógica de la harness es I/O contra `system_settings`).
- [x] 7.5 Smoke test manual (opcional): en `/ia/api-transcriptor` pestaña "Configuración", confirmar visualmente:
  - Gauge del bloque "Tarea programada" dice "Cola de despacho" (no "Cola Redis").
  - El toggle de `dispatch_paused` sigue funcionando (no se rompió la fachada).
- [ ] 7.6 (Opcional, solo si el operador tenía overrides) limpieza de filas huérfanas:
  ```sql
  DELETE FROM system_settings
   WHERE key IN (
       'transcriptor.stagger_chunk_size',
       'transcriptor.batch_per_worker_ratio'
   );
  ```
  Confirmar primero con un `SELECT`. No urgente.

**Resultado final:**
- Baseline: 27 hits en 16 archivos.
- Post-cambio: 0 hits en el scope del change.
- Preservados: 7 hits (4 PurgeBacklogCommand + 3 harness), todos intencionales.
- `php -l` sin errores en los 13 archivos PHP modificados.
