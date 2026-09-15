## Context

Ver `proposal.md` (Why / What Changes) para la motivación y el alcance. Este documento describe **cómo** se implementa el contrato.

Estado actual:
- `ApiTranscriptorController::processBatch` (línea 1380) hace `$activeList[] = $runId` → string plano.
- `ScanAndSubmitCommand::handle` (líneas 354-364) busca el runId en `$activeList`, si está lo considera `alreadyListed` y NO agrega la entrada con `finishedAt`. El `finishedAt` solo se escribe en `Cache::put($cacheKey, [...], ...)` (cache individual del run).
- `TranscriptorBatchJobScanner::scan` (líneas 47-61) descarta entradas con `finishedAt` pasado más de `TERMINAL_TTL_SECONDS` (300s). Para entradas-string ese condicional es no-op → la entrada vive hasta que la cache individual expire (2h).
- `bg-job-indicator.blade.php` (líneas 84-90) corre `setInterval` cada 10 min que limpia `dismissed[runId]` con `ts < Date.now() - 3600_000` (1h).

Restricciones del proyecto:
- Cache driver: Redis (DB compartida con sesiones y otros namespaces, prefijo `tcloud_tcloud_cache_`).
- Frontend sin build step: Alpine.js CDN, vanilla JS, Blade templates.
- El contrato del endpoint `GET /bg-jobs/active` (devuelve `{jobs: [...]}`) NO cambia.

## Goals / Non-Goals

**Goals:**
- Garantizar que TODO job que termine (por cualquier estado terminal) quede con `finishedAt` poblado en `transcription_batch:active_runs`, sea cual sea el formato de entrada inicial.
- Hacer que el scanner descarte entradas obsoletas independientemente de su forma (string legacy o array moderno).
- Extender el TTL efectivo del mapa `dismissed` para que un descarte manual sobreviva al menos 24h.

**Non-Goals:**
- Cambiar el endpoint `/bg-jobs/active` ni su shape JSON.
- Cambiar `TERMINAL_TTL_SECONDS` (5 min sigue siendo el SLA de visibilidad del resumen final).
- Migrar entradas-string legacy en bloque al deploy (se drenan vía el fallback `updated_at`/`finished_at`).
- Tocar la lógica de scan-and-submit en sí (queries, regulator, dispatch).
- Tocar la lógica de otros scanners (`AvisosScanJobScanner`).

## Decisions

### Decisión 1: Reescritura idempotente de la entrada al terminar

**Elegido**: Cuando `ScanAndSubmitCommand::handle` termina un run con `$cacheKey`, itera `$activeList` y si encuentra el `$runId` (sea string o array), **sobrescribe** esa posición con `['runId' => $runId, 'finishedAt' => $finishedAtIso]`. Si no lo encuentra, lo appendea. Luego persiste la lista completa con el TTL existente (`now()->addHours(2)`).

**Por qué sobre append-only**: La rama actual "if (!alreadyListed) append" deja las entradas-string sin upgrade. El síntoma exacto que estamos arreglando viene de esa rama.

**Alternativas consideradas**:
- *Eliminar la entrada al terminar* (volver al contrato pre-fix-A). Descartado: el operador pierde el resumen final de "✓ N pendientes · M encolados" si recarga entre el final y los 5 min. Eso ya estaba arreglado por `fix-A` documentado en `ScanAndSubmitCommand.php:349`.
- *Cambiar el controller para que registre como array desde el inicio*. Descartado: el path de error temprano (línea 1385-1390 del controller) escribe cache con `status=error` y `finished_at` pero NO actualiza `active_runs`. Mover la responsabilidad al controller requiere coordinar dos lugares; centralizar en el comando (único punto de terminación) es más simple y testeable.
- *Hacer el controller NO agregar el runId y dejar que solo el comando lo haga*. Descartado: si el comando falla antes de empezar (ej. error de import), el runId nunca aparece y el modal del UI se queda mudo. El controller debe garantizar visibilidad temprana.

### Decisión 2: Fallback de `updated_at` para entradas-string legacy

**Elegido**: En `TranscriptorBatchJobScanner::scan`, si `$finishedAtIso` es null (entrada-string), leer `$state['updated_at'] ?? $state['finished_at'] ?? null` de la cache individual `transcription_batch:{runId}` y usarlo como timestamp terminal. Si tampoco existe, descartar la entrada como huérfana.

**Por qué**: No queremos esperar 2h para que las entradas-string pre-deploy se drenen. La cache individual siempre tiene `updated_at` (escrito por `Cache::put` al final del comando, línea 386).

**Alternativas consideradas**:
- *Migración batch al deploy que reescriba todas las entradas-string*. Descartado: requiere un comando artisan nuevo, complica el deploy, y el fallback es más limpio.
- *Ignorar entradas-string y mostrarlas siempre*. Descartado: es exactamente el bug que estamos arreglando.

### Decisión 3: TTL de `dismissed` extendido a 24h + clave versionada

**Elegido**: En `bg-job-indicator.blade.php`, cambiar el cutoff de `Date.now() - 3600_000` a `Date.now() - 86_400_000` (24h). Versionar la `localStorage` key: `bg_jobs:dismissed` → `bg_jobs:dismissed:v2`. Si en el futuro hace falta invalidar masivamente (ej. cambio de esquema), basta con bumpear la versión.

**Por qué 24h**: Coincide con la realidad operativa — un job no debería correr más de unos minutos; si a las 24h sigue figurando como activo, es un bug del backend y el operador debe verlo.

**Alternativas consideradas**:
- *Sin expiración*. Descartado: si el operador descarta un runId y luego el mismo runId arranca una corrida nueva (poco probable pero posible), el widget no lo mostraría. La spec exige que **jobs activos en ejecución sigan apareciendo**. El check `if (!active.has(rid)) delete this.dismissed[rid]` (líneas 124-127) ya cubre ese caso, pero queremos defensa en profundidad.
- *Storage en backend (tabla o cache Redis por usuario)*. Descartado: complica el deploy, requiere endpoint nuevo, y el caso de uso real es local al navegador del operador.

### Decisión 4: Sin cambios en el endpoint `/bg-jobs/active`

**Elegido**: El shape del JSON devuelto por el scanner no cambia. El campo `finishedAt` ya está presente en cada job (`TranscriptorBatchJobScanner.php:95`).

**Por qué**: El widget ya ignora jobs cuyo runId está en `dismissed`. La nueva lógica vive en backend (cleanup) y frontend (TTL). Cero cambio de contrato.

## Risks / Trade-offs

- **[Risk] Una entrada-string con cache individual ya expirada se descarta silenciosamente como huérfana, sin pasar por el resumen final de 5 min** → Mitigación: aceptable. Esa entrada ya estaba en estado zombie (sin `finishedAt` y sin cache individual útil); descartarla es lo correcto. El operador que la estuviera mirando ya la descartó manualmente con (×).

- **[Risk] El bump de versión de `localStorage` key invalida todos los descartes existentes al deploy** → Mitigación: aceptable y deseable. El síntoma del bug es precisamente que reaparecen tarjetas; invalidar el estado local al deploy limpia la vista de inmediato. Si el operador tenía descartes válidos, los vuelve a hacer (un click por tarjeta).

- **[Risk] Race condition: el comando termina justo cuando el scanner está leyendo la lista** → Mitigación: existe y es aceptable. `Cache::get` + `Cache::put` no son atómicos. En el peor caso, el scanner lee la lista antes del `put` final, devuelve la entrada-string, y en el próximo poll (≤5s) ya la ve con `finishedAt`. No hay corrupción, solo un poll extra con la versión vieja.

- **[Risk] Operador con muchos runIds descartados acumula entries en `localStorage` por 24h** → Mitigación: el cleanup periódico (cada 10 min, ahora con cutoff 24h) los borra. Carga máxima esperada: <100 entradas, <5KB. Sin riesgo de quota.

- **[Trade-off] El fallback de `updated_at` significa que entradas-string con `status=error` tempranas (controller línea 1424 escribe `finished_at` pero la entrada en `active_runs` queda string) también se descartan correctamente** → Esto es un side-benefit, no un riesgo.

## Migration Plan

**Deploy**: Un solo commit con los tres cambios (backend comando + backend scanner + frontend widget). Sin migración de BD, sin redeploy de workers supervisord (la cache se regenera sola en cada `Cache::put`).

**Verificación post-deploy** (smoke manual, 10 min):
1. Lanzar un escaneo desde el botón UI → confirmar que la tarjeta aparece con progreso.
2. Esperar a que termine → confirmar que muestra el resumen "✓ N pendientes · M encolados".
3. Esperar 5 min → confirmar que la tarjeta desaparece.
4. Hacer click en (×) sobre otra tarjeta → recargar la página 30 min después → confirmar que NO reaparece.
5. Verificar en Redis que `transcription_batch:active_runs` contiene `['runId'=>..., 'finishedAt'=>...]` (no string plano) para escaneos nuevos.

**Rollback**:
- `git revert <commit>` → un revert limpio, los tres archivos quedan en estado anterior.
- Sin datos que limpiar en Redis: la próxima vez que un run termine, el comportamiento viejo vuelve (entradas-string persistentes). No queda estado zombie permanente.

**Freno de emergencia** (sin deploy): si por alguna razón el scanner descartara algo que no debería, el operador puede limpiar `localStorage.removeItem('bg_jobs:dismissed:v2')` desde devtools. No hay flag de `.env` para esta lógica por ser local al frontend.

## Open Questions

*(ninguna)*
