# Runbook: Transcripción atascada (jobs fallan en <1s)

**Síntomas típicos** (uno o varios):

- `tcloud_queues:transcription` no decrece a pesar de tener 12 workers.
- `worker-batch-*.log` muestra `App\Jobs\ConvertAndTranscribeJob FAIL` con
  duraciones <1 s, seguidas de otro RUNNING/FAIL en bucle.
- `php artisan transcriptor:diagnose-pending` muestra 80+ transcriptions
  pendientes que no avanzan.
- El admin reporta "llego un lote y después nada" en la UI de trabajos.
- `df -h /dev/shm` reporta 100% de uso.

**Causa raíz conocida (2026-08-12):** fuga de file descriptors en
`TranscriptorApiClient::submitRequest()` donde `fopen($audioPath, 'r')` se
pasaba a `->attach(...)` sin `fclose()` explícito. Combinado con los retries
de Laravel HTTP (`throw: false`) y workers de larga vida, cada job dejaba
un fd abierto apuntando a un `.wav` ya `unlink()`-eado. tmpfs no libera
RAM hasta que se cierran los fds, así que `/dev/shm` se llenaba hasta el
100% y `ffmpeg` abortaba con `EINVAL` en ~250 ms.

Ver `openspec/changes/2026-08-12-fix-transcription-shm-fd-leak/` para el
análisis completo y el fix de raíz.

---

## Diagnóstico (30 segundos)

```bash
# 1. ¿Está /dev/shm al 100%?
df -h /dev/shm
# Esperado si roto: Use% 100%, Avail 0 (o muy pocos MB)

# 2. ¿Cuántos fds apuntan a WAVs huérfanos en /dev/shm?
find /proc/*/fd -lname "/dev/shm/tcloud-transcription/*" 2>/dev/null | wc -l
# Esperado si roto: > 50 (en producción vimos 1499)

# 3. ¿Los jobs fallan rápido?
tail -30 app/storage/logs/worker-batch-1.log | grep -E "FAIL|RUNNING" | tail -10
# Esperado si roto: bucle FAIL → RUNNING → FAIL con duraciones ~200-340ms

# 4. ¿Cuántos jobs en cola?
redis-cli -a "$REDIS_PASSWORD" -p "$REDIS_PORT" LLEN tcloud_queues:transcription
# Esperado si roto: > 100 y creciendo (o estable si tick está pausado)
```

Si los 4 checks apuntan al patrón "FAIL rápido + /dev/shm 100% + fds
huérfanos" → sigue a **Mitigación inmediata**.

---

## Mitigación inmediata (1 minuto de indisponibilidad)

```bash
# 1. PAUSAR el dispatcher (frena el tick, NO los workers en vuelo)
redis-cli -a "$REDIS_PASSWORD" -p "$REDIS_PORT" \
    HSET transcriptor:settings dispatch_paused true
# Verificar:
redis-cli -a "$REDIS_PASSWORD" -p "$REDIS_PORT" \
    HGET transcriptor:settings dispatch_paused
# → "true"

# 2. Liberar tmpfs (los workers en mitad de un job abortan, los jobs se reencolan)
# Opción A: remount no destructivo (kernel 5.x+, requiere size en fstab)
sudo mount -o remount /dev/shm

# Opción B: si el remount no libera lo suficiente (es lo habitual porque los
# fds huérfanos siguen en el kernel), reiniciar los workers. Sin el fix del
# change 2026-08-12 la fuga vuelve, pero al menos vaciamos la basura actual.
sudo systemctl restart tcloud-transcription-workers    # ajustar al init real

# 3. Verificar espacio libre
df -h /dev/shm
# Esperado: > 30 GB libres

# 4. Confirmar que los fds están limpios
find /proc/*/fd -lname "/dev/shm/tcloud-transcription/*" 2>/dev/null | wc -l
# Esperado: 0 (o <= 12 si los workers acaban de re-spawnear)
```

**No reactivar `dispatch_paused=false` hasta que el código del fix esté
desplegado.** Si se reactiva sin el fix, la fuga vuelve en minutos.

---

## Verificación rápida tras aplicar el fix completo

```bash
# 1. Confirmar que el fix está en producción
grep -c "openAudioStream\|closeStream" app/app/Services/Ia/TranscriptorApiClient.php
# Esperado: >= 4 (definiciones + usos)

# 2. Confirmar que la migración está aplicada
php artisan migrate:status | grep requeue_after_at
# Esperado: "Ran" (no "Pending")

# 3. Confirmar que los comandos están registrados
php artisan list | grep -E "transcription:cleanup-orphan-wav|transcription:check-shm-health"
# Esperado: ambos listados

# 4. Probar el endpoint de salud
curl -s -H "Cookie: tcloud_session=..." \
    https://tu-host/ia/api-transcriptor/shm-status | jq .
# Esperado: {total, used, free, percent, dir_writable, threshold, status}
```

Tras 30 min de tráfico normal:

```bash
# /dev/shm debe seguir con >30 GB libres
df -h /dev/shm

# El conteo de fds debe estar acotado al número de workers
find /proc/*/fd -lname "/dev/shm/tcloud-transcription/*" 2>/dev/null | wc -l
# Esperado: <= 12 (uno por worker activo)

# El log de workers NO debe tener "Could not seek" recientes
find app/storage/logs/worker-batch-*.log -mmin -30 -exec \
    grep -l "Could not seek" {} \;
# Esperado: vacío
```

---

## Cuándo escalar

Si tras la mitigación + deploy del fix el problema reaparece en <30 min:

1. **Captura evidencia:**
   ```bash
   tar czf /tmp/shm-evidence-$(date +%s).tgz \
       --exclude='*.tar.gz' \
       /proc/$(pgrep -f queue:work | head -1)/fd/ \
       app/storage/logs/worker-batch-*.log
   ```
2. **Escala al equipo backend** con:
   - Output de los 4 comandos de diagnóstico.
   - El tarball anterior.
   - Confirmación de que el fix está desplegado (`grep -c openAudioStream ...`).
3. **Workaround temporal** mientras se investiga: dispatch manual por
   lotes pequeños:
   ```bash
   php artisan transcription:process-batch --limit=20
   ```
   `--limit=20` evita llenar /dev/shm de golpe; cada lote se procesa y los
   WAVs se liberan antes del siguiente.

---

## Cómo NO se reproduce

- El **fix raíz** (`TranscriptorApiClient::openAudioStream/closeStream` con
  `try/finally`) garantiza que cada submit cierra su fd antes de retornar.
- El **pre-flight** (`min_shm_free_bytes` en `TranscriptionSubmitService`)
  evita invocar ffmpeg si tmpfs está bajo el umbral.
- El **cleanup defensivo** (`transcription:cleanup-orphan-wav` cada 15 min)
  borra WAVs con mtime > 30 min y sin fd abierto.
- El **centinela** (`transcription:check-shm-health` cada 10 min) emite
  `Log::warning` cuando `/dev/shm` supera `shm_warn_percent` (default 80%),
  mucho antes de llegar al 100%.