## Design: Resiliencia y reporte de errores en "Escanear storages"

### Problema end-to-end (antes del cambio)

```
clic "Iniciar procesamiento"
  └─ ApiTranscriptorController::processBatch()
       · Cache::put('transcription_batch:<runId>', status='starting')
       · proc_open(php artisan transcription:scan-and-submit --days=0 --batch=N --run-id=<runId>)
            └─ ScanAndSubmitCommand::handle()
                 └─ StorageProvider::transcriptionEnabled()->get()  → OK
                      └─ foreach storage → DiskScannerService::scanStorage()
                           └─ 1er storage con base_path anidado (ej. Blu_Digital id 133)
                                └─ computeExcludedSubpaths() → SELECT allow_parent_overlap
                                     └─ SQLSTATE 42703 💥 (columna no existe)
                                          · NO hay try/catch
                                          · NO se escribe status='error' a cache
                                          · cache queda con status='starting' para siempre
  └─ UI pollBatch() cada 2s
       · GET /batch-status/<runId> → status='starting' (nunca cambia)
       · Polling indefinido hasta que el operador cierre manualmente
       · UI muestra "error" genérico sin contexto
```

### Cambios concretos

#### 1. Migración idempotente (verificación previa)

Confirmar que la migración `2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php` no rompe si se ejecuta dos veces. En PostgreSQL 15 con Laravel 13, `Schema::table(...)->addColumn()` falla si la columna existe. Reforzar con verificación previa:

```php
if (!Schema::hasColumn('storage_providers', 'folder_layout')) {
    $table->string('folder_layout', 20)->default('flat')->after('transcription_enabled');
}
if (!Schema::hasColumn('storage_providers', 'allow_parent_overlap')) {
    $table->boolean('allow_parent_overlap')->default(false)->after('folder_layout');
}
```

Plus verificación del CHECK constraint (Postgres no permite recrear uno existente, hay que usar DROP IF EXISTS primero).

#### 2. Try/catch por storage en `ScanAndSubmitCommand::handle()`

```php
$perStorageErrors = [];

foreach ($storages as $storage) {
    try {
        $stats = $scanner->scanStorage($storage, $days, $all, $batchOverride);
        $totalFilesCreated += $stats['files_created'];
        $totalPendingCreated += $stats['transcriptions_created'];
        $this->info("Storage {$storage->name}: scanned={$stats['scanned']} candidates={$stats['candidates']} files_created={$stats['files_created']} tx_created={$stats['transcriptions_created']}");
    } catch (\Throwable $e) {
        $msg = "Storage {$storage->name} (id={$storage->id}): {$e->getMessage()}";
        $this->error($msg);
        Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
        $perStorageErrors[] = [
            'storage_id' => $storage->id,
            'storage_name' => $storage->name,
            'message' => $e->getMessage(),
        ];
    }
}
```

Luego, al final del handle, escribir `status='partial'` (con algunos storages fallidos) o `status='error'` (todos fallaron), preservando `per_storage_errors` en la cache.

#### 3. Try/catch global en `ScanAndSubmitCommand::handle()`

Envolver todo el `handle()`:

```php
public function handle(DiskScannerService $scanner): int
{
    try {
        // ... código existente ...
        return Command::SUCCESS;
    } catch (\Throwable $e) {
        Log::error("ScanAndSubmitCommand: error fatal: {$e->getMessage()}", ['exception' => $e]);
        if ($cacheKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => 'error',
                'message' => $e->getMessage(),
                'processed' => 0, 'errors' => 1,
                'total_candidates' => 0,
                'storages' => [],
                'files' => [],
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ], now()->addHours(2));
        }
        return Command::FAILURE;
    }
}
```

#### 4. UI error reporting (`index.blade.php` pollBatch)

En el handler de `status='error'` del polling:

```js
} else {
    // status === 'error' o 'not_found'
    this.batchResult = {
        processed: data.processed ?? 0,
        errors: data.errors ?? 1,
        total_candidates: data.total_candidates ?? 0,
        storages: data.storages ?? [],
        files: data.files ?? [],
        message: data.message || 'El lote terminó con errores. Revisa storage/logs/transcription-batch-' + this.batchRunId + '.log',
    };
}
```

Y en el template, mostrar `<div x-text="batchResult.message"></div>` en el panel de resultados cuando `batchResult.errors > 0`.

#### 5. Cobertura del tick automático

`TranscriptionTickCommand::handle()` llama a `transcription:scan-and-submit --no-dispatch` via `$consoleKernel->call(...)`. Como ese comando ahora tiene try/catch global, **no morirá el tick** y continuará a Phase 2. Si Phase 1 tuvo errores parciales, se loguean pero el tick sigue operativo.

### Estructura de cache final (extensión aditiva)

```json
{
  "status": "partial",         // 'starting'|'running'|'partial'|'queued'|'done'|'error'|'not_found'
  "batch": 100,
  "processed": 0,
  "errors": 1,
  "total_to_process": 0,
  "total_candidates": 0,
  "per_storage_errors": [
    {
      "storage_id": 133,
      "storage_name": "Blu_Digital",
      "message": "SQLSTATE[42703]: Undefined column: 7 ERROR: column \"allow_parent_overlap\" does not exist"
    }
  ],
  "message": "Lote completado con 1 storages con error. Ver per_storage_errors.",
  "started_at": "...",
  "finished_at": "...",
  "updated_at": "..."
}
```

### Preservación de invariantes

1. **Comportamiento de éxito intacto**: cuando no hay errores, el batch termina con `status='queued'` igual que antes.
2. **No se duplican transcripciones**: el try/catch por storage solo aísla errores, no cambia la lógica de dedup.
3. **No se cambia el schema de BD más allá de lo que la migración original ya define**.
4. **El tick automático sigue corriendo cada 2 minutos** sin cambios funcionales.

### Orden de aplicación

1. Aplicar migración (`php artisan migrate`) — resuelve la causa raíz.
2. Implementar try/catch por storage en `ScanAndSubmitCommand.php` — previene que un storage rompa el batch.
3. Implementar try/catch global — garantiza estado terminal en cache.
4. Actualizar UI para mostrar `batchResult.message` — feedback accionable al operador.
5. Verificar manualmente que el batch funciona end-to-end y que un fallo simulado (renombrando una tabla temporalmente) produce el `status='error'` esperado.