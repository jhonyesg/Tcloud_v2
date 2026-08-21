## Design: Reintento automático de transcripciones fallidas

### Flujo end-to-end

```
┌─────────────────────────────────────────────────────────────────────┐
│  Usuario: clic "Escanear storages" + marca "Reintentar fallidos"    │
│  POST /ia/api-transcriptor/process-batch                            │
│    body: { batch: 100, generate_alerts: false, include_failed: true }│
└─────────────────────────────────┬───────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  ApiTranscriptorController::processBatch()                          │
│  · genera runId                                                     │
│  · Cache::put('transcription_batch:ID', {status: starting})         │
│  · proc_open(php artisan transcription:scan-and-submit              │
│              --days=0 --batch=100 --run-id=ID --include-failed)     │
└─────────────────────────────────┬───────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  ScanAndSubmitCommand::handle()                                     │
│                                                                     │
│  Fase 1 — DISCO (igual que hoy)                                     │
│    foreach storage habilitado:                                      │
│       scanStorage() → crea Files + Transcriptions(pending) nuevas   │
│                                                                     │
│  Fase 1.5 — FALLIDOS (NUEVO, solo si --include-failed)             │
│    foreach storage habilitado:                                      │
│       collectFailedCandidates(storage, maxRetries=3)                │
│       foreach Transcription(state=error, retries<3, archivo legible):│
│          → reset state=pending, error_message=null, job_id=null,    │
│               node_url=null, node_id=null                           │
│          → retries++                                                │
│       foreach Transcription(state=error, retries>=3):               │
│          → skip (no se incrementa retries)                          │
│       foreach Transcription(state=error, archivo NO legible):       │
│          → state='dead', error_message="Archivo no accesible..."    │
│                                                                     │
│  Fase 2 — ENCOLAR (igual que hoy, extended)                         │
│    pending (estado pendiente) sin job_id, FIFO                       │
│    → ConvertAndTranscribeJob::dispatch(fileId, alerts)              │
└─────────────────────────────────┬───────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Workers supervisord → ffmpeg + POST transcriptor                   │
│    · éxito → state=done (igual que hoy)                              │
│    · fallo → state=error + retries++ (vía TranscriptionSubmitService)│
│    · si retries >= 3 → promotion a dead con mensaje                 │
└─────────────────────────────────────────────────────────────────────┘
```

### Estructura del nuevo método `collectFailedCandidates`

```php
// DiskScannerService.php
public function collectFailedCandidates(
    StorageProvider $storage,
    int $maxRetries = 3
): array {
    $stats = [
        'candidates' => 0,
        'reset_to_pending' => 0,
        'skipped_unreadable' => 0,
        'skipped_max_retries' => 0,
        'promoted_to_dead' => 0,
    ];

    // 1. Buscar transcriptions error de este storage con retries < max
    $candidates = Transcription::where('state', Transcription::STATE_ERROR)
        ->where('retries', '<', $maxRetries)
        ->whereHas('file', function ($q) use ($storage) {
            $q->where('storage_provider_id', $storage->id);
        })
        ->with('file.storageProvider')
        ->get();

    foreach ($candidates as $tx) {
        $stats['candidates']++;
        $file = $tx->file;
        $srcPath = rtrim((string) $file->storageProvider->base_path, '/')
                 . '/' . ltrim((string) $file->path, '/');

        // Archivo no legible → promover a dead
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            $tx->update([
                'state' => Transcription::STATE_DEAD,
                'error_message' => "Archivo no accesible en disco ({$srcPath}). No se reintentará automáticamente.",
                'finished_at' => now(),
            ]);
            $stats['promoted_to_dead']++;
            continue;
        }

        // Reset a pending (mantiene id, file_id, original_name, language)
        $tx->update([
            'state' => Transcription::STATE_PENDING,
            'error_message' => null,
            'job_id' => null,
            'node_url' => null,
            'node_id' => null,
            'retries' => $tx->retries + 1,
        ]);
        $stats['reset_to_pending']++;
    }

    // 2. Transcriptions con retries >= max → contar pero no tocar
    $stats['skipped_max_retries'] = Transcription::where('state', Transcription::STATE_ERROR)
        ->where('retries', '>=', $maxRetries)
        ->whereHas('file', function ($q) use ($storage) {
            $q->where('storage_provider_id', $storage->id);
        })
        ->count();

    return $stats;
}
```

### Cambios en el comando artisan

```php
// ScanAndSubmitCommand.php — agregar opción
{--include-failed : Incluir transcripciones en estado error con archivo accesible}

// En runHandle(), después de Fase 1:
$failedStats = ['reset_to_pending' => 0, 'skipped_unreadable' => 0,
                'promoted_to_dead' => 0, 'skipped_max_retries' => 0];

if ($this->option('include-failed')) {
    foreach ($storages as $storage) {
        try {
            $stats = $scanner->collectFailedCandidates($storage, $maxRetries);
            foreach ($stats as $k => $v) $failedStats[$k] += $v;
        } catch (\Throwable $e) {
            // ... mismo try/catch que Fase 1
        }
    }
}

// En el bloque final que escribe el cache, agregar:
'failed_recovered' => $failedStats['reset_to_pending'],
'failed_skipped_unreadable' => $failedStats['promoted_to_dead'], // renombrar
'failed_promoted_to_dead' => $failedStats['promoted_to_dead'],
'failed_skipped_max_retries' => $failedStats['skipped_max_retries'],
```

### Cambios en el frontend

```blade
{{-- index.blade.php — modal "Escanear storages" --}}
{{-- debajo del checkbox "Generar alertas" --}}
<label class="flex items-center gap-2 cursor-pointer">
    <input type="checkbox" x-model="batchIncludeFailed" class="...">
    <span class="text-sm font-medium text-slate-700">Reintentar fallidos</span>
    <i class="fas fa-info-circle text-slate-400 text-xs"
       title="Reencola transcripciones en estado 'error' cuyo archivo sigue accesible. Max 3 reintentos."></i>
</label>
```

```js
// Alpine data
batchIncludeFailed: false,

// runBatch() — agregar al body:
body: JSON.stringify({
    batch: this.batchSize,
    generate_alerts: this.batchAlerts,
    include_failed: this.batchIncludeFailed,
}),
```

### Preservación de invariantes

1. **No se duplican Transcriptions** — el método usa UPDATE, no INSERT.
2. **El campo `retries` se incrementa antes del reintento, no después del fallo** — esto significa que la primera vez que un archivo falla y entra a `error`, ya tiene `retries=0`. Cuando se reencola vía `--include-failed`, pasa a `retries=1`. Si falla otra vez, el worker lo deja en `error` con `retries=1`. Cuando se reencola de nuevo, pasa a `retries=2`. Y así hasta 3. Al 4º intento fallido (que ya no será automático), el worker debería promoverlo a `dead`.

   **Ajuste necesario en `TranscriptionSubmitService::markError()`**: después de marcar error, verificar si `retries >= max_retries` y promover a `dead`.

3. **El tick automático NO se ve afectado** — solo opera con `--no-dispatch` y sigue mirando solo `state='pending'`. Los fallidos reencolados pasan a `pending` y serán recogidos por el tick en el próximo ciclo (con su propio límite de cola).

4. **El worker de Redis sigue siendo idempotente** — si dos workers toman el mismo job (improbable pero posible), `ConvertAndTranscribeJob::handle()` verifica `if (!empty($transcription->job_id)) return;`.

5. **El campo `error_message` se sobreescribe** — el mensaje histórico se pierde, pero el campo documenta el último error que es lo que importa para debugging.

### Promoción automática a `dead` en `TranscriptionSubmitService::markError()`

```php
// TranscriptionSubmitService.php — modificar markError()
private function markError(Transcription $t, string $message): void
{
    $maxRetries = (int) config('transcriptor.max_retries', 3);
    $newRetries = $t->retries + 1;

    $state = Transcription::STATE_ERROR;
    if ($newRetries >= $maxRetries) {
        $state = Transcription::STATE_DEAD;
        $message = "[Auto] Max retries ({$maxRetries}) alcanzado. {$message}";
    }

    $t->update([
        'state' => $state,
        'error_message' => $message,
        'finished_at' => now(),
        'retries' => $newRetries,
    ]);
}
```

Esto garantiza que cualquier fallo futuro (incluso vía reprocess manual) respeta el límite.

### Orden de aplicación

1. Agregar `--include-failed` al comando + método `collectFailedCandidates()` en el scanner service.
2. Agregar promoción automática a `dead` en `TranscriptionSubmitService::markError()`.
3. Agregar checkbox UI + envío de `include_failed` en el body del POST.
4. Extender `processBatch()` controller para aceptar el flag.
5. Extender estructura del cache con campos `failed_*`.
6. Verificar manualmente con un archivo de prueba.