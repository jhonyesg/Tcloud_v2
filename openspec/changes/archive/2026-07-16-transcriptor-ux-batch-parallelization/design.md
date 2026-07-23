## Context

**Estado del módulo antes de este cambio** (ver `archive/2026-07-16-reformular-transcriptor-polling/`):

```
   pipeline actual (síncrono):
   
   transcription:scan-and-submit (cada 2 min, sin overlap)
     │
     ├─ DiskScannerService::scanStorage()  ← lee disco, crea File + Transcription pending
     │
     └─ loop secuencial:
         for $tx in Transcription::where(state=pending)->whereNull(job_id):
             TranscriptionSubmitService::submit($tx)
                 ├─ ffmpeg → /dev/shm/...opus        (~30s por archivo)
                 └─ POST /v1/transcribe             (~5-15s API externa)
        
   processBatch (UI "Escanear storages"):
     └─ exec("...transcription:scan-and-submit --days=0 &")
        └─ execBackground() usa exec() PHP — espera al stream del hijo
```

**Problemas observados**:

1. **UX confusa** — el badge plano "Hecho"/"Pendiente" + link implícito al nombre del archivo + botón "Enviar" gris inerte no comunican qué se puede hacer. El usuario no descubre la acción "Ver transcripción".

2. **UI congelada** — `ApiTranscriptorController::execBackground()` (línea 660) usa `exec($cmd)` de PHP. Aunque el comando termina en `&`, PHP `exec()` espera a que el proceso libere stdout/stderr. Si el hijo está escribiendo logs al `>> logFile`, la request HTTP se cuelga. El usuario ve el spinner indefinidamente.

3. **Pipeline secuencial** — con `TranscriptionSubmitService` corriendo en loop síncrono, un lote de 50 archivos usa 1 core a la vez. CPU 40-core infrautilizada.

**Infraestructura disponible** (ya presente, no requiere instalación):
- `Redis` corriendo en `127.0.0.1:6379` (`CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`).
- `/etc/supervisor/conf.d/tcloud-transcription-worker.conf` con 10 workers (`numprocs=10`) en queue `transcription-high,medium,low`, comando `php artisan queue:work redis --sleep=1 --tries=1 --timeout=120 --max-jobs=100`.
- `ConvertAndTranscribeJob` (en `app/app/Jobs/`) YA implementa `ShouldQueue` con `dispatchWithPriority()` y cálculo de prioridad. Marcado `@deprecated` por la reforma 2026-07-16, pero el código está intacto y la lógica `handle()` es correcta.
- 40 cores, 20GB tmpfs `/dev/shm` — capacidad de sobra.

**Decisión revertida**: el change archivado `2026-07-16-reformular-transcriptor-polling` decidió que el volumen (6 cortes/hora) no justificaba Redis + workers. La realidad operacional (lotes manuales, necesidad de throughput cuando se escanea backlog) ha cambiado. Reactivamos parcialmente la arquitectura de colas.

## Goals / Non-Goals

**Goals:**
- Cada archivo del modal "Ver archivos" muestra **una sola acción clara**: "Enviar" si se puede transcribir, "Ver transcripción" si ya hay `Transcription` asociada.
- El botón "Escanear storages" del modal devuelve respuesta HTTP en <500ms; la UI entra a polling de `batchStatus` sin esperar al scan-and-submit.
- Un lote de N archivos se procesa en paralelo por hasta 10 workers (los que estén vivos). El tiempo total ≈ `ceil(N/10) × max(ffmpeg_time, api_time)`.
- El job Laravel `ConvertAndTranscribeJob` queda **sin** la marca `@deprecated` y delega su lógica de envío a `TranscriptionSubmitService` (no duplica el código ffmpeg+POST).
- El comando `transcription:scan-and-submit` dispatcha en cola en lugar de ejecutar síncronamente.

**Non-Goals:**
- No se sube `numprocs` de 10 a 20 en supervisor (queda como tarea de infra posterior).
- No se introduce dashboard de workers individuales.
- No se modifica `transcription:poll-results` ni el modelo `Transcription`.
- No se cambia la API externa del transcriptor.

## Decisions

### Decisión 1: Reactivar `ConvertAndTranscribeJob` eliminando `@deprecated`, refactorizando `handle()` para delegar a `TranscriptionSubmitService`

**Por qué**: el código del job YA existe, es correcto, y la lógica ffmpeg+POST está duplicada entre el job y `TranscriptionSubmitService`. Refactorizar el job para que su `handle()` sea esencialmente `app(TranscriptionSubmitService::class)->submit($transcription)` elimina duplicación y unifica comportamiento (un solo lugar para arreglar bugs del pipeline).

**Alternativa descartada**: crear un nuevo job `BulkDispatchTranscriptionJob` separado. Descartada porque `ConvertAndTranscribeJob` ya tiene la infra de queue (`ShouldQueue`, prioridad, retry) probada.

### Decisión 2: `scan-and-submit` dispatcha en cola en lugar de ejecutar `submit()` síncronamente

**Por qué**: para que la paralelización tenga efecto real, el productor (scan) debe poner en cola y retornar, no esperar al consumidor. El loop secuencial actual en `ScanAndSubmitCommand` líneas 56-74 es el cuello.

**Cambio concreto**:
```php
// ANTES (líneas 56-74):
foreach ($pending as $tx) {
    $result = $submitter->submit($tx);  // bloquea
}

// DESPUÉS:
foreach ($pending as $tx) {
    ConvertAndTranscribeJob::dispatchWithPriority(
        $tx->file_id,
        $tx->generate_alerts,
        ConvertAndTranscribeJob::calculatePriority($storage->transcription_priority, true, true)
    )->onQueue('transcription-high');
}
```

**Trade-off**: el comando scan-and-submit termina en segundos (solo encola) pero los archivos tardan más en llegar a estado `queued` (esperan turno del worker). Esto está bien — el schedule es cada 2 min y los workers procesan continuamente.

### Decisión 3: `execBackground` se reemplaza por helper con `proc_open` + descriptors a `/dev/null`

**Por qué**: el bug del modal pegado es que `exec()` de PHP espera al hijo. `proc_open` permite pasar un array de streams (`pipe`) que el padre no necesita leer; el SO cierra la conexión padre-hijo inmediatamente.

**Implementación**:
```php
private function execBackground(string $cmd): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B ' . $cmd, 'r'));
        return;
    }
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],  // stdout del hijo → /dev/null
        2 => ['file', '/dev/null', 'w'],  // stderr del hijo → /dev/null
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        proc_close($proc);  // NO espera; solo cierra el handle del padre
    }
}
```

**Trade-off**: los logs del comando background ya no aparecen en stdout del proceso PHP (que es lo que queremos — no contaminar los logs de nginx/PHP-FPM). El comando redirige sus propios logs a `storage/logs/transcription-batch-{runId}.log` vía `>>`.

**Alternativa descartada**: `shell_exec('nohup ... >/dev/null 2>&1 &')` — funciona pero `nohup` requiere estar disponible y el patrón es más frágil en distintos sistemas.

### Decisión 4: Watchdog en `runBatch()` Vue handler

**Por qué**: aunque el backend ya no bloquee, si la red se cuelga o el servidor tarda en responder, el usuario espera sin feedback. Un timeout cliente de 5s asume "started" y entra a polling.

```js
async runBatch() {
    this.batchRunning = true;
    try {
        const res = await Promise.race([
            apiFetch('/ia/api-transcriptor/process-batch', { ... }),
            new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 5000))
        ]);
        // manejar respuesta
    } catch (e) {
        // asumir "started" y entrar a polling igualmente
        this.pollBatchStatus();
    } finally {
        // batchRunning se queda true mientras pollBatchStatus esté activo
    }
}
```

### Decisión 5: Botón contextual — "Enviar" o "Ver transcripción" según estado

**Por qué**: UX clara. La celda "Acción" reemplaza la celda "Enviar" actual. Lógica:

```html
<template x-if="!f.transcription_id">
    <button @click="openProgress(f)" class="...primary">Enviar</button>
</template>
<template x-if="f.transcription_id && f.transcription_state === 'done'">
    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id" class="...primary">
        Ver transcripción
    </a>
</template>
<template x-if="f.transcription_id && ['pending','queued','processing'].includes(f.transcription_state)">
    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id" class="...amber">
        En proceso…
    </a>
</template>
<template x-if="f.transcription_id && ['error','dead'].includes(f.transcription_state)">
    <a :href="'/ia/api-transcriptor/jobs/' + f.transcription_id" class="...red">
        Ver error
    </a>
</template>
```

**Alternativa descartada**: tooltip + ícono 🔗 sobre el badge. Descartada porque el usuario explícitamente pidió botones de acción explícitos.

## Risks / Trade-offs

- **[Riesgo] Workers Redis consumen ~1.5 GB RAM adicional** → Mitigación: el servidor tiene 78 GB RAM (`php-fpm-tuning` spec). Margen amplio. Documentar para monitoreo. Mitigación operativa: `pm.max_jobs=100` en supervisor recicla workers y libera memoria.

- **[Riesgo] Un job queda "stuck" si el worker muere durante ffmpeg** → Mitigación: Laravel marca el job como `failed` tras `timeout=120s` y Redis lo devuelve a la cola. Otro worker lo retoma. `Transcription::firstOrCreate(['file_id' => ...])` reutiliza la fila `pending` existente.

- **[Riesgo] Concurrencia en `/dev/shm`**: 10 workers pueden escribir/leer simultáneamente archivos temporales → Mitigación: el nombre del archivo opus usa `uniqid('', true)` (16 chars hex aleatorios) — colisión astronómicamente improbable. Verificado en código actual línea 94 del job.

- **[Riesgo] Si la cola Redis se llena, el schedule `scan-and-submit` se vuelve lento solo encola** → Mitigación: el schedule no espera por workers. Si la cola crece, eventualmente `transcription:scan-stale` (ya existe en archivados) puede ayudar. Monitorear con `redis-cli LLEN queues:transcription-high`.

- **[Riesgo] Revertir la reforma 2026-07-16 contradice decisión previa documentada** → Mitigación: documentar en `proposal.md` que la decisión se revierte por cambio de condiciones operativas. Mantener `archive/2026-07-16-...` como histórico.

- **[Trade-off] Tiempo de respuesta de "Escanear storages" sigue siendo "no inmediato"** → El botón responde en <500ms (HTTP rápido), pero la UI debe mostrar "Iniciando..." o "Escaneando..." con polling de `batchStatus` cada 2s. Aceptado: el patrón ya existe, solo hay que asegurar que se vea el progreso.

## Migration Plan

**Pre-deploy**:
1. Verificar que `supervisord` está corriendo: `supervisorctl status` debe mostrar el programa `tcloud-transcription-worker` (probablemente en estado `STOPPED` desde la reforma 2026-07-16).
2. Verificar Redis accesible: `redis-cli ping`.

**Deploy** (orden importa):
1. Aplicar código PHP (refactor de job, helper `execBackground`, watchdog Vue, columna Acción).
2. `composer dump-autoload` si cambió namespace.
3. `supervisorctl reread && supervisorctl update && supervisorctl start tcloud-transcription-worker:*`.
4. Verificar `supervisorctl status` muestre 10 workers `RUNNING`.
5. Verificar logs: `tail -f storage/logs/worker.log`.

**Smoke test**:
- Lote manual de 5 archivos desde UI "Escanear storages" → confirmar que responde <500ms.
- `supervisorctl status` → ver workers activos.
- `tail -f worker.log` → ver logs de ffmpeg+POST en paralelo.
- DB: `SELECT state, count(*) FROM transcriptions GROUP BY state` → confirmar transiciones `pending → queued → done`.

**Rollback** (orden inverso):
1. `supervisorctl stop tcloud-transcription-worker:*` → workers mueren, cola Redis se drena pero no procesa.
2. Revertir código PHP (`git revert` o restaurar archivos).
3. Opcional: `redis-cli DEL queues:transcription-high` para limpiar jobs huérfanos.

El refactor mantiene `TranscriptionSubmitService::submit()` intacto y usable síncronamente. Si se decide volver al modelo síncrono en el futuro, basta con detener supervisor y cambiar el `dispatchWithPriority` en `ScanAndSubmitCommand` por una llamada directa a `$submitter->submit($tx)`.

## Open Questions

- **¿Cuántos workers activos al momento del deploy?** Si supervisor tiene `numprocs=10` configurado pero `autorestart=true` y estado `STOPPED`, el `start` los arranca todos. Confirmar durante implementación que la memoria disponible tolera 10 simultáneos corriendo ffmpeg (cada uno ~150-300 MB peak).
- **¿El schedule `transcription:scan-and-submit` (cada 2 min) generará jobs duplicados si un lote manual también dispatcha?** El constraint `Transcription.file_id UNIQUE` previene duplicación de filas. Pero si un archivo está `queued` y el schedule lo re-dispatcha, el job de Redis es idempotente (consume el mismo `transcription_id`)? **Verificar**: el job hace `firstOrCreate(['file_id' => ...])` y luego `submit(transcription)`. Si la TX ya tiene `job_id`, ¿se reenvía? El código actual no chequea eso. **Decisión a tomar durante implementación**: agregar guard `if ($transcription->job_id) return;` al inicio de `handle()`.