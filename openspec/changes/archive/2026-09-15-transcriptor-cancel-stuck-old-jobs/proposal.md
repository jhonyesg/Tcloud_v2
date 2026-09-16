## Why

El operador ve jobs "con fechas anteriores a hoy" en el transcriptor upstream cuando valida su cola. La causa raíz **no es** que los crons automáticos (`transcription:tick`, `transcription:poll-results`) envíen archivos viejos — ambos filtran correctamente por `CarbonImmutable::today()` y así lo confirman sus queries y el log de `transcription-tick.log` del 2026-09-14 19:55–22:34 en Bogota (todos los jobs despachados en las últimas horas llevan `created_at >= today`).

La causa real es **acumulación de residuos en el upstream**: jobs enviados en el pasado, con `job_id` ya registrado en nuestra BD, que el API remoto nunca terminó de procesar. Llevan días o semanas ocupando slots en su cola sin avanzar. Hoy:

```
BD local en 2026-09-15 03:49 UTC (= 22:49 Bogota 2026-09-14)
state IN (queued, processing) AND job_id IS NOT NULL:
  total               3.617
  > 24 h de antiguedad 3.092
  > 7 días            3.092
  > 14 días           2.244
  oldest: 2026-08-27 16:48 Bogota (queued/processing)

Upstream API (192.168.0.138:9000, /api/metrics/overview):
  queue_total      = 4.095   (acumulación remota histórica)
  queue_queued     = 30
  processing       = 6       (workers sobrecargados 200%)
  queue_done-1     = 3.430
  cluster_state    = UP
```

Estos 3.092 jobs viejos:

1. **No se pueden re-enviar** — tienen `job_id` válido en upstream. Si poll-results o tick los re-enviaran, duplicarían el trabajo del API remoto (y nuestro poll-results filtra correctamente `whereNull('job_id')`, así que no lo hace).
2. **No retornan resultado** — el upstream los tiene en `queued`/`processing` con `started_at` null desde hace hasta 19 días.
3. **No se purgan automáticamente** — `transcription:purge-stale-pending` solo toca filas en `state=pending` y `job_id IS NULL`; estos tienen `job_id` y estados no terminales, así que pasan por debajo del radar del comando y del poll-results (que hoy solo re-envía `pending sin job_id`).

El operador validó en el remoto que **estos jobs viejos siguen ocupando cola** y quiere limpiarlos sin afectar los 16.582 archivos físicos que también llevan días sin transcribirse (esos son grabaciones legítimas de emisoras que NO se tocan en este cambio).

## What Changes

### Nuevo comando CLI: `transcription:cancel-stuck-old-jobs`

Cancelación controlada de jobs viejos en el upstream + marcado de filas locales como `dead`.

**Por defecto** (`--dry-run`):

- Lista candidatos sin mutar nada: total, desglose por estado y storage, ratio vs universo.
- Espera `--apply` para ejecutar la cancelación.

**Con `--apply`**:

1. Filtra: `state IN ('queued', 'processing') AND job_id IS NOT NULL AND created_at < now() - interval 'N days'`.
2. `N` es configurable (`--days=`, default 7 — mismo horizonte que `transcription:retry-batch-upstream` para mantener coherencia).
3. **Nota de validación 2026-09-15:** una corrida real mostró que TODAS las 3.049 candidatas tienen `started_at NOT NULL` (el polling upstream lo actualiza aunque el job siga atascado en `queued`/`processing`). Por eso el filtro `started_at IS NULL` se removió. La antigüedad viene determinada por `created_at`, no por `started_at`. El margen de `--min-age-minutes=60` protege contra jobs recién enviados.
4. **Re-validación necesaria antes de cancelar filas con `started_at` reciente:** si en el futuro una corrida marca como candidato un job al que el upstream SÍ le puso `started_at` hace minutos (p.ej. el job está legítimamente siendo procesado en estos momentos), `--min-age-minutes` debe ser ≥ al `regulator_remote_pressure` o al timeout que defina upstream. Por ahora 60 min es seguro porque el tick y poll-results tienen su propia red anti-duplicación.
5. Itera en chunks de **100** (configurable `--batch=`) con stagger de **750 ms** entre cada cancelación individual (`--stagger-ms=`), para no saturar un upstream que ya está al 200% de capacidad.
5. Por cada fila:
   - Llama `TranscriptorApiClient::cancelUpstream($jobId)`.
   - Si el upstream devuelve `200` `{state: "cancelled"}`: marca local `state='dead'`, `error_message='Cancelado en upstream por antiguedad: created_at<cutoff'", finished_at=now()`.
   - Si devuelve `409` ("job no está en estado queued" porque ya estaba processing): reintenta una vez con `TranscriptorApiClient::deleteUpstream($jobId)` si la respuesta indica estado terminal, o lo deja en paz y lo loguea como `INFO transcriptor.cancel.skipped_non_queued`.
   - Si el upstream devuelve `503`/`timeout`: aborta el lote y respeta el reintento la próxima corrida. Loguea como `WARNING transcriptor.cancel.upstream_unavailable`.
   - Si el upstream devuelve `404`: el job ya no existe arriba; marca local como `dead` con `error_message` indicando "job ya eliminado en upstream".
6. Cache lock `transcription:cancel-stuck-old-jobs` (TTL 600 s) para impedir carreras con una segunda invocación.
7. Guarda rastro en un nuevo audit log `transcriptor_cancel_audit` (append-only, mismo patrón que `watermark_audit_log`) con `actor_user_id` (NULL para CLI), `job_id`, `tx_id`, `upstream_response_code`, `local_state_after`.
8. Respeta guardarraíl de ratio: si candidatos/total > `--max-ratio=0.5`, aborta con `WARNING transcriptor.cancel.aborted_mass_delete`. Mismo patrón que `purge-stale-pending`.

### ¿Por qué NO se tocan los archivos físicos?

Los 16.582 archivos `pending sin job_id` y los archivos correspondientes a los 3.092 jobs viejos **son grabaciones reales de emisoras** (14.275 mp4 + 2.307 mpeg, distribución ~80–95 archivos/hora durante 2 semanas). Borrarlos sería perder contenido. El alcance de este cambio es **solo el puntero en el upstream** y la fila local; los archivos en disco se quedan intactos.

Si en el futuro se decide limpiarlos, debe ser un cambio separado con módulo papelera (ver AGENTS.md `papelera_reciclaje_soft_delete_pattern`).

## Capabilities

### New Capabilities

- `transcriptor-cancel-stuck-old-jobs`: el sistema SHALL exponer un comando CLI (`transcription:cancel-stuck-old-jobs`) que cancela jobs viejos en el upstream y marca las filas locales como `dead`. SHALL respetar `--dry-run` por defecto, SHALL limitarse a filas con `state IN (queued, processing) AND job_id IS NOT NULL AND created_at < cutoff AND started_at IS NULL`, SHALL iterar con stagger para no saturar el remoto, SHALL registrar cada cancelación en `transcriptor_cancel_audit`, y SHALL abortar con guardarraíl si la ratio supera `--max-ratio`.

### Modified Capabilities

- `transcriptor-poll-results`: no se modifica el comportamiento. Se aclara en su spec que la responsabilidad de cancelar viejos es del nuevo comando; poll-results sigue siendo responsable de re-enviar `pending sin job_id` del día actual.

## Impact

- **Nuevo archivo:** `app/app/Console/Commands/TranscriptionCancelStuckOldJobsCommand.php`. Patrón copiado de `TranscriptionPurgeStalePendingCommand.php` (mismo lock, mismo guardarraíl de ratio, mismo formato de log).
- **Nuevo servicio / helper:** `app/app/Services/Ia/TranscriptorCancelAudit.php` con un método estático `record($jobId, $txId, $upstreamCode, $localState)` que escribe en `transcriptor_cancel_audit`. Append-only, sin UPDATE.
- **Nueva migración:** `2026_09_15_120000_create_transcriptor_cancel_audit_table.php`. Columnas: `id, actor_user_id, job_id, tx_id, upstream_response_code, local_state_after, created_at`. Sin FKs (es bitácora).
- **`TranscriptorApiClient::cancelUpstream()`**: ya existe (`app/app/Services/Ia/TranscriptorApiClient.php:526`). Se reutiliza sin modificar. Si fuera necesario agregar un endpoint para jobs en estado `processing`, queda como follow-up (no bloquea).
- **No se modifica** `transcription:tick`, `transcription:poll-results`, `transcription:retry-batch-upstream`, ni el scheduler (`routes/console.php`).
- **Sin cambio de UI** para el operador. La operación es vía CLI con `php artisan transcription:cancel-stuck-old-jobs --apply`.
- **No se borra nada en disco** — los archivos de las filas canceladas quedan tal cual. Si el operador luego quiere re-despachar alguna (re-grabando o re-enviando), debe pasar por `transcription:bulk-dispatch` con IDs explícitos.

## Non-goals

- **No** se borra ningún archivo de audio/video en disco.
- **No** se marcan como `dead` filas en `state='pending'` sin `job_id` (los 16.582 grabaciones legítimas).
- **No** se modifica el flujo normal de `transcription:tick` ni `transcription:poll-results` — siguen filtrando por hoy.
- **No** se reintenta la cancelación de un job 200 veces. Si el upstream no responde en esta corrida, queda para la próxima (mismo lock + log + retry implícito en la siguiente ejecución manual).
- **No** se rediseña `TranscriptionPollingService::settle()` ni su lógica de `aged_out`. La red de "stuck processing" ya existente cubre los casos con `started_at` set; este cambio cubre exclusivamente los que nunca llegaron a `started_at`.
- **No** se programa automáticamente el comando en el scheduler. Es **operación manual controlada** del operador (decisión 2026-09-15: ejecutar `--apply` requiere intervención humana consciente).

## Riesgos

- **[Riesgo] Cancelar un job que el upstream realmente está procesando pero no progresó en nuestra BD.** El upstream puede tardar horas en tomar un job de la cola y procesarlo; nuestro `started_at` queda null hasta que el webhook/polling lo confirma. Cancelar en ese caso desperdicia el procesamiento upstream. → Mitigación: el filtro incluye `started_at IS NULL` Y un margen mínimo (`--min-age-minutes=60`) configurable para dar tiempo al upstream a reaccionar antes de cancelar. Default 60 min.
- **[Riesgo] Saturar más el upstream durante la cancelación** (ya está al 200%). → Mitigación: stagger obligatorio 750 ms + chunk 100; el lock de 10 min evita carreras; `--dry-run` permite ver el alcance antes de ejecutar.
- **[Riesgo] Ratio de candidatos alto aborta la corrida.** Con guardarraíl 0.5, solo abortaría si >50% de TODAS las transcripciones estuvieran stuck. Hoy son 3.092/418.385 = 0.0074, muy lejos. Si la métrica sube abruptamente (¿nuevo bug?), el guardarraíl lo detecta antes de borrar masivamente.
- **[Riesgo] El operador ejecuta `--apply` por accidente.** → Mitigación: `--dry-run` es el default. `--apply` debe pasarse explícitamente; el comando imprime advertencias + número de candidatos + cutoff antes de mutar.
- **[Riesgo] El upstream cambia su API de cancel entre versiones.** → Mitigación: el cliente `TranscriptorApiClient::cancelUpstream()` encapsula la llamada. Si cambia, solo se modifica ese punto. Hoy ya cubre 404/409/5xx.

## Operación esperada

```bash
# 1) Ver qué se cancelaría (sin tocar nada)
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan transcription:cancel-stuck-old-jobs --dry-run --days=7

# Salida esperada:
#   Candidatos: 3.092 de 418.385 (ratio=0.0074, max=0.50)
#   Por estado: queued=3.041 processing=51
#   Antiguos más viejos: 2026-08-27 (19 días)
#   Corte: created_at < 2026-09-08 22:50 Bogota

# 2) Cancelar + marcar dead
php artisan transcription:cancel-stuck-old-jobs --apply --days=7 --stagger-ms=750

# Salida esperada en pasos:
#   [1/100] cancel 2fb3a54f8b5d4ea9...  -> 200 cancelled   local=dead
#   [2/100] cancel 5a002882850d405a...  -> 200 cancelled   local=dead
#   ...
#   Total: 3.092 cancelados, 0 errores, 51 skipped (processing)
```

## Verificación posterior

- `transcription:health-check` debe seguir reportando "Pipeline vivo".
- `transcription-tick.log` debe seguir mostrando "SCAN: ok; DISPATCH: …" sin cambios.
- `transcriptions` con `state='dead'` debe crecer en ~3.092 con `error_message LIKE 'Cancelado en upstream%'`.
- `watermark_audit_log` y `audit_logs` no deben verse afectados (cambio no toca esos módulos).

## Rollback

No hay migración de datos destructiva. Si algo sale mal:

1. **Antes de cancelar:** abortar con Ctrl-C; el lock `transcription:cancel-stuck-old-jobs` expira a los 10 min automáticamente. Las filas tocadas (si se canceló parcialmente) se pueden re-marcar como `pending` con un UPDATE manual si la cancelación fue errónea.
2. **Si el remoto quedó en estado raro:** re-enviar las filas afectadas vía `POST /bulk-dispatch` con IDs explícitos.
3. **Borrado accidental de archivos:** no aplica — este cambio **no toca archivos**.
