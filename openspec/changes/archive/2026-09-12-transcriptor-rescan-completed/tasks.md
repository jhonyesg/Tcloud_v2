# Tasks — transcriptor-rescan-completed

## 1. Service: nuevo método `collectDoneCandidates`

- [x] 1.1 Abrir `app/Services/Ia/DiskScannerService.php` y leer `collectFailedCandidates()` (líneas 306-371) hasta entender el patrón completo.
- [x] 1.2 Crear `collectDoneCandidates(StorageProvider $storage, ?string $fromIso = null, ?string $toIso = null): array` justo después del método de fallidos.
- [x] 1.3 Implementar el SELECT:
  - `where('state', Transcription::STATE_DONE)` (en vez de STATE_ERROR)
  - `whereHas('file', fn($q) => $q->where('storage_provider_id', $storage->id))`
  - filtro `finished_at BETWEEN from AND to` cuando hay scope (en vez de `created_at`)
  - `with('file.storageProvider')` para evitar N+1
- [x] 1.4 Implementar el loop de reset (espejo del de fallidos):
  - Si archivo/storage no existe → promover a `state='dead'` con mensaje
  - Si archivo no accesible en disco (`!is_file($srcPath) || !is_readable($srcPath)`) → promover a `state='dead'` con mensaje
  - Si accesible → `$tx->update([...])` con: `state='pending'`, `error_message=null`, `job_id=null`, `node_url=null`, `node_id=null`, `retries=$tx->retries+1`, `finished_at=null`
  - **NO** tocar `srt_content` (conservar como fallback si el job nuevo falla)
- [x] 1.5 Implementar limpieza del lock `ShouldBeUnique` antes del dispatch (ver riesgo R1 en design.md):
  - Dentro del mismo loop, después del update, ejecutar:
    ```php
    \Illuminate\Support\Facades\Cache::forget(
        'laravel-queue:unique:' . sha1(\App\Jobs\ConvertAndTranscribeJob::class) . ':' . $tx->file_id
    );
    ```
  - **Verificar el nombre exacto del cache key** leyendo `vendor/laravel/framework/src/Illuminate/Queue/UniqueLock.php` antes de implementar. Si el patrón es distinto, ajustar y dejar comentario.
- [x] 1.6 Devolver array de stats:
  ```php
  [
      'candidates' => int,
      'reset_to_pending' => int,
      'promoted_to_dead' => int,
      'skipped_no_file' => int,  // archivo/storage no existe
  ]
  ```

## 2. Command: nuevo flag `--include-done`

- [x] 2.1 Abrir `app/Console/Commands/ScanAndSubmitCommand.php`.
- [x] 2.2 Agregar a `$signature` (después de `--include-failed`, línea 26):
  ```
  {--include-done : Incluir transcripciones en estado done con archivo accesible (reprocesar finalizados)}
  ```
- [x] 2.3 Agregar lectura del flag en `runHandle()` después de `$includeFailed` (línea 77):
  ```php
  $includeDone = (bool) $this->option('include-done');
  ```
- [x] 2.4 Agregar stats array análogo a `$failedStats` (líneas 127-132):
  ```php
  $doneStats = [
      'candidates' => 0,
      'reset_to_pending' => 0,
      'promoted_to_dead' => 0,
      'skipped_no_file' => 0,
  ];
  ```
- [x] 2.5 Agregar Fase 1.6 después de Fase 1.5 (después de línea 244), análoga a la lógica de `--include-failed`:
  ```php
  if ($includeDone) {
      foreach ($storages as $storage) {
          try {
              $stats = $scanner->collectDoneCandidates($storage, $retryFromIso, $retryToIso);
              foreach ($stats as $k => $v) {
                  $doneStats[$k] = ($doneStats[$k] ?? 0) + $v;
              }
              if (($stats['candidates'] ?? 0) > 0) {
                  $this->info("Storage {$storage->name} (rescan-done): candidates={$stats['candidates']} reset={$stats['reset_to_pending']} dead={$stats['promoted_to_dead']}");
              }
          } catch (\Throwable $e) {
              // mismo manejo de errores que collect-failed
          }
      }
      $this->info("Rescan-done resumen: candidates={$doneStats['candidates']} reset_to_pending={$doneStats['reset_to_pending']} promoted_to_dead={$doneStats['promoted_to_dead']}");
  }
  ```
- [x] 2.6 En el cache final (líneas 375-392), agregar al payload:
  - `done_rescan: $doneStats` (mismo shape que `failed_recovered`)
  - Sumar `doneStats['reset_to_pending']` al `total_candidates` total

## 3. Controller: aceptar `include_done` en `processBatch`

- [x] 3.1 Abrir `app/Http/Controllers/Ia/ApiTranscriptorController.php`, método `processBatch` (línea 1281).
- [x] 3.2 Después de `$includeFailed = ...` (línea 1295), agregar:
  ```php
  $includeDone = (bool) $request->input('include_done', false);
  ```
- [x] 3.3 Después del bloque `if ($includeFailed)` (línea 1334), agregar:
  ```php
  if ($includeDone) {
      $cmd .= ' --include-done';
  }
  ```

## 4. Controller: estimar candidatos `done_rescan` en `estimateScan`

- [x] 4.1 En el mismo archivo, método `estimateScan` (línea 1180).
- [x] 4.2 Después del cálculo de `$errorCount` y `$deadCount` (líneas 1258-1259), agregar el conteo de completados con filtro de fecha:
  ```php
  $doneCountQuery = DB::table('transcriptions')
      ->where('state', 'done')
      ->whereNull('deleted_at');  // sanity check
  if ($mode === 'today') {
      $doneCountQuery->where('finished_at', '>=', now()->startOfDay());
  } elseif ($mode === 'range' && !empty($folderNames)) {
      // folderNames viene en formato DDMMYYYY (e.g. "11092026")
      // convertir a YYYY-MM-DD
      $fromIso = substr($folderNames[0], 4, 4) . '-' . substr($folderNames[0], 2, 2) . '-' . substr($folderNames[0], 0, 2);
      $toIso   = substr(end($folderNames), 4, 4) . '-' . substr(end($folderNames), 2, 2) . '-' . substr(end($folderNames), 0, 2);
      $doneCountQuery->where('finished_at', '>=', $fromIso)
                      ->where('finished_at', '<', date('Y-m-d', strtotime($toIso . ' +1 day')));
  }
  $doneCount = (int) $doneCountQuery->count();
  ```
- [x] 4.3 Agregar al JSON de respuesta:
  ```php
  'done_rescan' => $doneCount,
  ```
- [x] 4.4 Verificar que se aplica el guardarraíl `MAX_FILES = 50000` si el conteo es enorme (sumar al `$totalMissing` y cortar como el resto).

## 5. UI: checkbox nuevo en el modal

- [x] 5.1 Abrir `resources/views/ia/api-transcriptor/index.blade.php`.
- [x] 5.2 En la sección Alpine state (~línea 2303, junto a `batchIncludeFailed`), agregar:
  ```js
  batchIncludeDone: false,
  ```
- [x] 5.3 En el modal, justo después del bloque "Reintentar fallidos" (líneas 1844-1853), agregar un bloque gemelo con:
  ```html
  <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
      <label class="flex items-center gap-2 cursor-pointer">
          <input type="checkbox" x-model="batchIncludeDone" class="w-4 h-4 accent-amber-600 rounded">
          <span class="text-sm font-medium text-slate-700">Incluir completados</span>
          <i class="fas fa-info-circle text-slate-400 text-xs cursor-help"
             title="Reenvía transcripciones ya finalizadas (state='done') a la API externa para regenerarlas. Conserva el archivo en disco y la fila; solo se sobreescribe srt_content al confirmar el nuevo resultado. Si el reenvío falla, la fila queda en 'error' con el srt_content viejo como fallback. Genera nuevas alertas según el flag 'Generar alertas'."></i>
      </label>
      <span class="text-xs text-amber-700" x-show="batchIncludeDone"><i class="fas fa-redo mr-1"></i>Se reencolarán transcripciones finalizadas (archivo accesible, retries++). El srt_content viejo se mantiene hasta que el nuevo se confirme.</span>
  </div>
  ```
- [x] 5.4 En `refreshBatchEstimate()` (línea 3457), dentro del body del fetch, agregar:
  ```js
  include_done: this.batchIncludeDone,
  ```
- [x] 5.5 En la sección de "Estimación previa" (línea 1801), agregar una línea nueva:
  ```html
  <p x-show="batchEstimate.done_rescan != null && batchEstimate.done_rescan > 0">
      <span x-text="batchEstimate.done_rescan.toLocaleString()"></span> transcripciones en <strong>done</strong> reprocesables marcando "Incluir completados" abajo
  </p>
  ```
- [x] 5.6 En `runBatch()` (línea 3536), dentro del body del fetch, agregar:
  ```js
  include_done: this.batchIncludeDone,
  ```

## 6. Verificación manual end-to-end

> Tareas operacionales — el código está listo, el operador debe ejecutar estos casos contra un ambiente de staging con datos reales.

- [ ] 6.1 Preparar fixture:
  - 5 archivos en storage de prueba con transcripciones en `state='done'` (pueden ser reales o creadas con un seeder de prueba).
  - 2 archivos con `state='error'` (para verificar que `--include-failed` sigue funcionando en paralelo).
  - 3 archivos sin transcripción (para verificar que la Fase 1 normal sigue creando filas nuevas).
- [ ] 6.2 Caso A: checkbox "Incluir completados" desmarcado
  - Lanzar el batch.
  - Verificar que SOLO se reprocesan los 3 archivos nuevos (Fase 1).
  - Verificar que los 5 `done` siguen en `done` (no tocados).
- [ ] 6.3 Caso B: checkbox marcado, scope "Hoy"
  - Ajustar el fixture para que los 5 `done` tengan `finished_at` de hoy.
  - Lanzar el batch con `batchIncludeDone=true`, `batchScope='today'`.
  - Verificar en BD que los 5 pasaron por `pending` (con `job_id` eventualmente nuevo) y luego a `done` de nuevo.
  - Verificar que `srt_content` cambió (o al menos `finished_at` y `retries` se incrementaron).
- [ ] 6.4 Caso C: checkbox marcado + "Reintentar fallidos"
  - Verificar que tanto los 5 `done` como los 2 `error` se reprocesan.
  - Verificar que los `error` se manejaron con la lógica vieja y los `done` con la nueva.
- [ ] 6.5 Caso D: archivo borrado
  - Borrar 1 de los 5 archivos `done` del disco antes del reproceso.
  - Lanzar el batch.
  - Verificar que esa fila terminó en `state='dead'` con `error_message` mencionando "Archivo no accesible".
  - Verificar que `srt_content` sigue ahí (no se borró al promover a dead).
- [ ] 6.6 Caso E: `dispatch_paused=true`
  - Poner `dispatch_paused=true` en `system_settings`.
  - Lanzar el batch con `batchIncludeDone=true`.
  - Verificar que las filas pasaron a `state='pending'` pero NO se encoló ningún job.
  - Resetear `dispatch_paused` después.

## 7. Verificación del lock `ShouldBeUnique` (R1)

> Ya verificado durante implementación leyendo `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php`.

- [x] 7.1 Leer `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php` para confirmar el patrón exacto del cache key.
- [x] 7.2 Ajustar la línea en `collectDoneCandidates` si el patrón es distinto al asumido.
  - **Patrón confirmado**: `laravel_unique_job:{get_class($job)}:{uniqueId()}`
  - `ConvertAndTranscribeJob::uniqueId()` retorna `(string) $this->fileId` (no implementa `displayName()`).
  - Implementación usa `Cache::lock('laravel_unique_job:' . ConvertAndTranscribeJob::class . ':' . $tx->file_id)->forceRelease()`.
- [ ] 7.3 Probar manualmente: dispatchar un job para `file_id=42`, esperar 5min, intentar dispatchar otro. Si el lock está vigente, el segundo debería ser deduplicado. Llamar `Cache::forget(...)` con el patrón correcto y verificar que ahora SÍ se encola.
- [ ] 7.4 Si el patrón no funciona (Laravel cambió el formato), implementar fallback: detectar via `php artisan queue:work --once` y validar manualmente.

## 8. Documentación

- [x] 8.1 Agregar nueva sección en `AGENTS.md` después de "Rollback del change `optimize-transcriptor-dispatch-throughput`":
  ```
  ## Reprosescar completados (`--include-done` / checkbox "Incluir completados")

  El botón "Escanear storages" tiene un tercer checkbox, "Incluir completados",
  que reprocesa transcripciones en state='done' (mismo trato que el path de
  fallidos, distinto state).

  Comportamiento:
  - Conserva el archivo en disco y la fila; solo sobreescribe srt_content al
    confirmar el nuevo resultado.
  - Bumpea retries++ (sirve como "veces reprocesado").
  - Si el archivo se borró del disco, promueve la fila a state='dead'.
  - Si el reenvío falla upstream, la fila queda en state='error' con el
    srt_content viejo como fallback.

  Verificación operacional:
  - Log por corrida: grep "rescan-done" storage/logs/transcription-batch-*.log
  - Conteo en estimación: campo `done_rescan` en /ia/api-transcriptor/scan/estimate.

  Rollback (sin deploy de emergencia): ignorar el flag en cualquiera de los
  tres puntos (UI checkbox, controller propaga al comando, comando invoca el
  service). Cero migración que revertir.
  ```
- [x] 8.2 Si el patrón del lock `ShouldBeUnique` resulta ser distinto al asumido, documentar el nombre exacto en `AGENTS.md` bajo la misma sección.
  - Documentado: `laravel_unique_job:{get_class($job)}:{uniqueId()}`.

## 9. Cierre

- [ ] 9.1 Correr `php artisan optimize` para refrescar opcache/config cache.
- [ ] 9.2 Recargar PHP-FPM (`systemctl reload php84-php-fpm` o equivalente) para que el nuevo método del service esté disponible.
- [ ] 9.3 Los workers supervisord NO necesitan reinicio (el cambio NO toca `ConvertAndTranscribeJob` ni `TranscriptorTickCommand`).
- [ ] 9.4 Verificar con un lanzamiento real desde la UI que el modal muestra la estimación correcta y el botón ejecuta el batch end-to-end.
