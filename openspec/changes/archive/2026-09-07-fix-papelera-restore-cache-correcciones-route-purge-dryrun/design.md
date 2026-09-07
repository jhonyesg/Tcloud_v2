## Context

Ver `proposal.md` para motivación. Tres fixes de scope pequeño:

### Fix #1: Cache invalidation en PapeleraController::restore

```
Estado actual:
  public function restore(int $file, Request $request)
  {
      // ... checks ...
      $restored = $this->service->restore($item, $user->id);
      return response()->json([...]);
      // ↑ NO invalida folder cache → restore visible después de TTL
  }

Estado deseado:
  public function restore(int $file, Request $request)
  {
      // ... checks ...
      $originalParentId = $item->parent_id;          // null durante trash
      $originalStorageId = (int) $item->storage_provider_id;
      $restored = $this->service->restore($item, $user->id);
      $newParentId = $restored->parent_id;            // puede ser el padre original o root
      $syncService = app(\App\Services\StorageSyncService::class);
      if ($newParentId !== null) {
          $syncService->invalidateFolderCache($originalStorageId, $newParentId);
      } else {
          $syncService->invalidateFolderCache($originalStorageId, null);
      }
      return response()->json([...]);
  }
```

Mismo patrón que `FileController@destroy`.

### Fix #2: whereNumber('id') en correcciones destroy

```
web.php línea 269:
- Route::delete('/correcciones/{id}', [..., 'destroy']);
+ Route::delete('/correcciones/{id}', [..., 'destroy'])->whereNumber('id');
```

Una palabra. Consistencia con la línea 277.

### Fix #3: --dry-run en trash:purge

```
TrashPurgeCommand:
- $signature = 'trash:purge {--batch=} {--max-ratio=}';
+ $signature = 'trash:purge {--batch=} {--max-ratio=} {--dry-run : cuenta candidatos sin borrar}';

public function handle(PapeleraService $service): int
{
    $batch = (int) ($this->option('batch') ?? config('trash.purge_batch_size', 500));
    $maxRatio = (float) ($this->option('max-ratio') ?? config('trash.purge_max_ratio', 0.5));
    $dryRun = (bool) $this->option('dry-run');

    $this->info("trash:purge starting (batch={$batch}, max_ratio={$maxRatio}, retention=" . config('trash.retention_days', 15) . "d" . ($dryRun ? ', DRY-RUN' : '') . ")");

    try {
        $deleted = $service->purgeExpired($batch, $maxRatio, $dryRun);
    } catch (\Throwable $e) {
        // ...
    }

    $this->info("trash:purge completed: " . ($dryRun ? "would_delete={$deleted}" : "deleted={$deleted}"));
    return self::SUCCESS;
}
```

PapeleraService::purgeExpired agrega un parámetro bool $dryRun = false. Cuando es true, no llama hardDelete — solo cuenta y retorna el conteo.

```
public function purgeExpired(int $batchSize = 500, float $maxRatio = 0.5, bool $dryRun = false): int
{
    // ... lock + cutoff + ratio guard (igual que antes) ...

    if ($dryRun) {
        Log::info('papelera.purge.dry_run', ['candidates' => $candidates, 'cutoff' => ...]);
        return $candidates;
    }

    // ... chunkById + hardDelete loop (igual que antes) ...
}
```

El lock SÍ se adquiere/release igual porque protege contra carreras entre dry-run y purge real concurrentes.

## Goals / Non-Goals

**Goals:**
- #1: restore visible inmediatamente en el browser sin esperar TTL.
- #2: consistência de validación en la ruta DELETE.
- #3: flag CLI estándar para validar antes de purgar.

**Non-goals:**
- Endpoint HTTP equivalente a `--dry-run`.
- Auditar TODAS las rutas DELETE sin `whereNumber`.
- Cambiar lógica de restore cuando parent está trashed.

## Decisions

### D1. Restore captura parentId antes y después

```php
$originalParentId = $item->parent_id;          // null durante trash (no se usa)
$originalStorageId = (int) $item->storage_provider_id;
$restored = $this->service->restore($item, $user->id);
$newParentId = $restored->parent_id;            // destino real
```

**Por qué no usar `$restored->getOriginal('parent_id')`:** mismo bug que aprendimos en el fix de cache invalidation — `getOriginal` después de `update()` devuelve el valor POST-update. Capturar `$restored->parent_id` directamente (que es la propiedad en memoria sincronizada con la última lectura) es seguro aquí porque `restore` usa `find()` después del update implícito vía `update()`.

Wait, actually let me verify. `$restored->parent_id` after `$this->service->restore($item, ...)` returns the updated model. The `parent_id` property reflects the post-update value. That's what we want.

Actually, let me check more carefully. The service does `$file->update([...])`. After that, `$file->parent_id` is the new value. The `$item` reference passed to the service and the `$restored` returned are the same object. So `$restored->parent_id` IS the new value. Good.

### D2. `--dry-run` no salta el lock

El lock `Cache::lock('trash:purge', ...)` se adquiere incluso en dry-run porque:
- Evita race entre dry-run que cuenta y un purge real que ejecuta.
- El dry-run tarda <1s; el lock TTL (600s default) no es problema.

## Risks / Trade-offs

- **[Riesgo bajo] Restore failure** → si `invalidateFolderCache` falla (Redis down), el restore igual tiene éxito pero el browser sigue viendo el cache viejo. Mismo riesgo que `FileController@destroy`; aceptable.
- **[Trade-off] Correcciones route** → agregar `whereNumber` rechaza DELETE con ids no numéricos con 404 (antes: 500). El cliente que mandaba string ahora recibe 404 limpio, lo cual es semánticamente correcto.
- **[Riesgo bajo] Dry-run accuracy** → el conteo del dry-run refleja el momento del query. Si entre dry-run y purge real alguien trasha/restaura items, el conteo puede cambiar. Es esperado y aceptable.

## Migration Plan

### Deploy
1. `git pull` (toma los 4 archivos modificados).
2. `php artisan cache:clear` opcional.
3. Smoke test manual:
   - Login → trash → restore un archivo → recargar /files → debe aparecer inmediatamente.
   - `php artisan trash:purge --dry-run` → debe mostrar `would_delete=N`.
   - `curl -X DELETE /correcciones/abc` → debe devolver 404 (no 500).

### Rollback
- `git revert <commit>`. Comportamiento anterior.

### Post-deploy verification
1. Playwright: trash → restore → reload /files → archivo visible inmediatamente.
2. CLI: `php artisan trash:purge --dry-run` muestra conteo, `php artisan trash:purge` corre normal.
3. curl: DELETE con id no numérico devuelve 404.

## Open Questions

Ninguna.
