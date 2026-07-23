## 1. Backend — endpoint `bulkDispatch`

- [x] 1.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`, añadir el método público `bulkDispatch(Request $request)` después de `dispatchNow()`. Recibir body validado `{ ids?: int[] }` (max 2000 elementos), donde `null`/ausente significa auto-selección.
- [x] 1.2 Dentro del método, si `ids` es `null`/vacío, auto-seleccionar los `Transcription` con `whereIn('state', ['pending','queued','processing'])->whereNull('job_id')->orderBy('created_at')->limit(2000)->pluck('id')`. Si `ids` está presente, continuar con ellos.
- [x] 1.3 Hacer un único query `Transcription::whereIn('id', $ids)->with('file:id,storage_provider_id')->get()`, calculando `$storageCache = StorageProvider::pluck('transcription_priority', 'id')` antes para evitar N+ queries.
- [x] 1.4 Por cada fila: calcular `$priority = ConvertAndTranscribeJob::calculatePriority((int)($storageCache[$tx->file->storage_provider_id] ?? 0), true, false)` y llamar `ConvertAndTranscribeJob::dispatchWithPriority($tx->file_id, (bool)$tx->generate_alerts, $priority)` dentro de try/catch. Incrementar `$enqueued` o `$errors` según corresponda.
- [x] 1.5 Si la fila NO cumple (`state IN {done,error,dead}` O `job_id IS NOT NULL`), incrementar `$skipped_queued` y NO llamar dispatch.
- [x] 1.6 Devolver `response()->json(['enqueued' => $enqueued, 'skipped_queued' => $skipped_queued, 'errors' => $errors], 200)`. Wrap todo en try/catch que capture `PredisConnectionException` específicamente → 503 con `partial: true`. (Nota: usé solo `PredisConnectionException` porque `\RedisException` requiere extensión nativa php-redis y el LSP la marca como indefinida; en este server predis es el cliente, no nativo.)

## 2. Backend — ruta

- [x] 2.1 En `app/routes/web.php` línea 167 (junto a `dispatch-now`), añadir: `Route::post('/api-transcriptor/jobs/bulk-dispatch', [App\Http\Controllers\Ia\ApiTranscriptorController::class, 'bulkDispatch']);`.
- [x] 2.2 Verificar que `route:list | grep bulk-dispatch` lo muestra tras `route:clear`. (**Confirmado**: aparece `POST ia/api-transcriptor/jobs/bulk-dispatch`.)

## 3. Frontend — refactor `bulkDispatchPending()`

- [x] 3.1 En `app/resources/views/ia/api-transcriptor/index.blade.php` línea 1659, reemplazar todo el método `bulkDispatchPending()` por una versión que: (a) calcula `targets` (mismo filtro que la versión actual), (b) hace UN `fetch('/ia/api-transcriptor/jobs/bulk-dispatch', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ ids: targets.map(t => t.id) }) })`, (c) parsea `data` con shape nuevo `{ enqueued, skipped_queued, errors }`, (d) popula `bulkDispatchResult = data` y mantiene `bulkDispatching` durante el request.
- [x] 3.2 Mantener el guardado `bulkDispatching = true` antes del fetch y `bulkDispatching = false` en el `finally`. El botón sigue deshabilitado mientras tanto.
- [x] 3.3 Si `res.status === 503`, mostrar mensaje específico "Redis no disponible — reintenta en unos segundos" en lugar del alert genérico.
- [x] 3.4 Si `res.status === 422`, mostrar mensaje del validation error (`data.message`).
- [x] 3.5 En el `finally`, llamar `await this.load()` y `await this.loadStats()` para refrescar contadores (igual que en la versión actual).

## 4. Frontend — actualizar texto del `bulkDispatchResult`

- [x] 4.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, sección `bulkDispatchResult` (cerca de la línea 605-640), adaptar el render para usar los campos nuevos: mostrar "✓ `{enqueued}` trabajos encolados", "  `{skipped_queued}` omitidos (ya enviados o terminales)", "  ⚠ `{errors}` con error de cola".
- [x] 4.2 Añadir texto contextual: "Los workers los procesarán en background. El progreso se refleja arriba en el panel 'Estado por BD'."

## 5. Validación

- [x] 5.1 Verificar `php -l app/app/Http/Controllers/Ia/ApiTranscriptorController.php` y `php -l app/routes/web.php` sin errores. (**Confirmado**: ambos `No syntax errors detected`.)
- [x] 5.2 Ejecutar `php artisan route:clear && php artisan view:clear` (Laravel puede cachear). (**Confirmado** route:clear; view:clear pendiente acción manual en deploy.)
- [ ] 5.3 Login admin → `/ia/api-transcriptor` → clic en "Procesar N pendientes ahora" sin selección → confirmar respuesta y progreso. **(Pendiente acción humana con sesión admin.)**
- [ ] 5.4 Con selección explícita: marcar 10 checkboxes, clic → confirmar `{ enqueued: 10 }`. **(Pendiente acción humana.)**
- [ ] 5.5 Con id inválido desde Network tab → esperar 422. **(Pendiente acción humana o test automatizado.)**
- [ ] 5.6 Workers supervisord activos: `supervisorctl status | grep transcription` → confirmar 10 workers RUNNING. **(Pendiente verificación humana; el supervisor config existe en `/etc/supervisor/conf.d/tcloud-transcription-worker.conf`.)**
- [ ] 5.7 Verificar que `dispatch-now` individual sigue funcionando (clic en una fila → modal de progreso). Sin regresión. **(Pendiente acción humana.)**
- [ ] 5.8 Verificar que `cancel`, `refresh-status`, `reprocess`, `retry` siguen funcionando. **(Pendiente acción humana; cambios son aditivos sin tocar esas rutas.)**
