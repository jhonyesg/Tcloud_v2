# purge-redis-words-transcriptor — Design

## Context

El cutover `transcriptor-pg-native-queue` (2026-09-15) movió la cola del transcriptor a la tabla `transcriptions` con `SELECT FOR UPDATE SKIP LOCKED`. Pero el vocabulario del módulo (UI, comentarios de comandos, docblocks de servicios, default de tests) sigue diciendo "Redis" en zonas donde ya no aplica. Esto es deuda de cutover que el propio proposal del cambio original identificó como confusores para IAs futuras (sección "Nombres engañosos que confunden a otras IAs").

Adicionalmente, dos settings —`stagger_chunk_size` y `batch_per_worker_ratio`— quedaron en `config/transcriptor.php` y en `TranscriptorSettings::SCHEMA` sin ningún reader en runtime; son del ramp-up + funnel pre-migración.

Y `tests/Unit/TranscriptorSettingsTest.php` quedó roto: usa `target_redis_queue` como key de override, pero el schema actual expone `target_pg_queue`. Cualquier corrida de phpunit marca rojo ahí desde el 2026-09-15.

Ver `proposal.md` para motivación y scope.

## Goals / Non-Goals

**Goals:**

- Cero apariciones de "Redis" aplicadas a la COLA en UI, comentarios, docblocks y mensajes JS del módulo `ia/api-transcriptor`.
- Cero settings zombies en `TranscriptorSettings::SCHEMA` con reads=0.
- Tests unitarios del módulo en verde.
- Comentarios sobre la capa de CACHÉ redactados en lenguaje backend-agnóstico ("store de caché"), no comprometido con Redis como driver único.

**Non-Goals:**

- No tocar `config/cache.php`, `config/session.php`, `config/queue.php`.
- No renombrar variables de entorno legacy que vivan en `.env` (pueden quedar).
- No migrar filas en `system_settings` ni en `transcriptions`.
- No reintroducir ningún fork de la decisión del cutover previo.

## Decisions

### Decisión 1: distinción "queue/dispatch" vs "caché" en el vocabulario del módulo

**Elegido**: dos categorías de tratamiento.

| Categoría | Tratamiento | Razón |
|---|---|---|
| Cola / despacho / worker / jobs encolados | Eliminar toda mención de "Redis". Texto que diga "worker PG", "el worker toma la fila", o simplemente borrar el comentario. | Post-cutover la cola ES la tabla. |
| Capa de caché | Reemplazar "Redis" por "store de caché" en docblocks / help texts. No eliminar la mención de Redis absoluta — añadir nota de "configurable vía `config/cache.php`". | El store de caché SÍ es Redis hoy (`CACHE_DRIVER=redis`); mentir en el docblock es peor que ser genérico. Una futura migración a `memcached` o `array` debe poder leerse del propio `config/cache.php` sin contradicción. |

**Alternativa considerada (Opción 1 purista)**: borrar TODO "Redis". Descartada porque deja docblocks que contradicen `config/cache.php` y obligan a futura migración.

### Decisión 2: retiro de `stagger_chunk_size` y `batch_per_worker_ratio`

**Elegido**: retiro completo (no `deprecated`, no `noop`).

`stagger_chunk_size`: post-migración no existe el ramp-up — el worker PG es continuo, no hay "primera dosis de batch" a espaciar. 0 reads en `app/`.

`batch_per_worker_ratio`: era para el middleware `LimitTranscriptionConcurrency` (eliminado en cutover). 0 reads en `app/`. El cálculo del regulador (`TranscriptorSettings::computeDispatchBatch`) usa `target_pg_queue + runway` clampeado a `[min_batch, max_batch]`, no toca este setting.

**Alternativa considerada**: marcarlos `deprecated` y dejar que el operador los siga viendo en la UI. Descartada porque el nombre ("stagger", "ratio") induce al operador a tocar valores esperando efectos que no ocurrirán.

### Decisión 3: `_settings-tab.blade.php` "Cola Redis" → "Cola de despacho"

**Elegido**: "Cola de despacho". Mantiene el dominio (es sobre el módulo transcriptor) sin prometer implementación.

**Alternativa**: "Cola del transcriptor" (igual de neutro, pero redundante porque ya estamos en la pestaña "Config" del módulo API Transcriptor).

### Decisión 4: mensaje JS "Redis no disponible" → "Servicio no disponible"

**Elegido**: mensaje genérico. El `catch` actual no distingue entre caída de Redis, PG, o upstream — decir "Redis" miente sobre la causa y desorienta al operador (puede intentar `redis-cli MONITOR` cuando la BD está caída).

**Alternativa**: enumerar las causas posibles. Descartada porque haría la cadena más larga que el contexto del modal que la contiene.

### Decisión 5: tests rotos

**Elegido**: migración in-place de `target_redis_queue` → `target_pg_queue` en las 11 referencias de `tests/Unit/TranscriptorSettingsTest.php`. El default actual del schema es 140, no 800 como en la versión vieja del test.

**Alternativa**: dejar los tests deshabilitados o con skip. Descartada porque el archivo testea exactamente la lógica de override que el módulo usa en producción — deshabilitar es peor que migrar.

### Decisión 6: preservación de `TranscriptorPurgeBacklogCommand` y del harness

**Elegido**: no se tocan. Son los anclas que validan el cutover previo.

- `TranscriptorPurgeBacklogCommand`: contiene los `SELECT / UPSERT / DELETE` sobre la key legacy `target_redis_queue`. Es la herramienta de cutover; las refs a "redis" SON el contrato de la herramienta.
- `tests/harness_transcriptor_pg_queue.php`: busca la key legacy vía `SystemSetting::get('transcriptor.target_redis_queue', null)` como guard — si retorna != null después del cutover, falla. Esa negación es parte del contrato.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Operador que tenía `TRANSCRIPTOR_STAGGER_CHUNK_SIZE` o `TRANSCRIPTOR_BATCH_PER_WORKER_RATIO` en `.env` ve cambios de comportamiento al desplegar | El operador no ve cambios: los settings nunca se leían. Las líneas en `.env` quedan como comentario histórico, no se borran automáticamente. |
| Operador busca `dispatch_paused` o `target_pg_queue` por el nombre viejo `target_redis_queue` | Documentado en propuesta + la fachada de settings sigue tolerante (un override "viejo" persiste como row sin efecto). |
| Una IA en 2028 busca "Redis queue transcriptor" para debuggear y no encuentra nada | Intencional. La cola no está en Redis. Si Redis está caído, el síntoma es distinto (cache miss, no jobs perdidos). |
| Dev lee el docblock de `map()` esperando "lectura de Redis" y busca `Redis::get` en el código | El docblock ya redactado dice "store de caché"; se mantiene la pista `config/cache.php` para que sepa dónde mirar. |

## Migration Plan

**Pasos de deploy:**

1. `git pull` del branch con el change.
2. `php artisan config:clear` (para regenerar `bootstrap/cache/config.php` sin las 2 keys retiradas). **No es estrictamente necesario** porque Laravel tolera que `config('transcriptor.stagger_chunk_size')` retorne `null` — pero deja limpio el cache de config.
3. `vendor/bin/phpunit --filter TranscriptorSettingsTest` debe pasar.
4. (Opcional) smoke test manual en `/ia/api-transcriptor`:
   - Pestaña Configuración → ver "Cola de despacho" en vez de "Cola Redis".
   - Toggle `dispatch_paused` desde la UI → confirmar que el diff se persiste.

**Pasos de rollback:**

- `git revert <commit>`.
- `php artisan config:clear`.
- Los override `transcriptor.stagger_chunk_size` y `transcriptor.batch_per_worker_ratio` que el operador tuviera en `system_settings` quedan como filas sin efecto (no se borran automáticamente).
- Si el rollback debe ser instantáneo por incidente de UI: `git revert` + `php artisan config:clear` (no se toca BD, no se reinician workers).

**Estado persistente que limpiar tras revertir (opcional):**

```sql
DELETE FROM system_settings WHERE key IN (
    'transcriptor.stagger_chunk_size',
    'transcriptor.batch_per_worker_ratio'
);
```

Solo si en `.env` o `system_settings` hay overrides creados por el operador que ahora son no-op. No urgente.

## Open Questions

Ninguna pendiente. Si durante la implementación surge una duda que cambia la dirección del cambio, se reabre este documento.
