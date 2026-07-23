# Tasks

## 1. Schema migration

- [x] 1.1 Crear migración `2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php` con:
  - `+folder_layout VARCHAR(20) DEFAULT 'flat'`
  - `+allow_parent_overlap BOOLEAN DEFAULT false`
  - CHECK constraint sobre `folder_layout`
  - Seed: `UPDATE storage_providers SET folder_layout='grouped_by_subfolder' WHERE id IN (47, 49)`
  - DROP COLUMN `transcription_priority` (con guard `Schema::hasColumn`)
- [x] 1.2 Validar `down()` simétrico: recrea `transcription_priority=0`, dropea columnas nuevas.
- [x] 1.3 Ejecutar `php artisan migrate` en staging primero; verificar que la columna se elimina sin afectar otras referencias.

## 2. Modelo `StorageProvider`

- [x] 2.1 Agregar `'folder_layout'`, `'allow_parent_overlap'` al `$fillable`.
- [x] 2.2 Agregar casts: `'folder_layout' => 'string'`, `'allow_parent_overlap' => 'boolean'`.
- [x] 2.3 Quitar `'transcription_priority'` del `$fillable`.
- [x] 2.4 Confirmar que el scope `transcriptionEnabled()` no requiere cambios.

## 3. `DiskScannerService` — layout-aware

- [x] 3.1 Agregar constantes `LAYOUT_FLAT` y `LAYOUT_GROUPED`.
- [x] 3.2 Implementar `dayFoldersGrouped(string $basePath, int $daysBack, bool $all): array`.
- [x] 3.3 Implementar `immediateSubfolders(string $basePath): array` (helper).
- [x] 3.4 Refactorizar `scanStorage()`: elegir `dayFolders()` vs `dayFoldersGrouped()` según `$storage->folder_layout`.
- [x] 3.5 Verificar que `dayFolders()` y `dayFoldersGrouped()` no devuelven paths con segmentos excluidos (los pasos 4 y 5 se encargan).
- [x] 3.6 (Bug fix post-deploy 15:55 -06:00) `dayFoldersGrouped` ahora hace recursive scan (maxDepth=6) en vez de sólo 1 nivel. Storage 49 (`02 Emisoras 01 Reg`) tiene layout `base/<region>/<emisora>/dmY/` (2 niveles), no era descubierta.

## 4. `DiskScannerService` — scope-aware dedup (computeExcludedSubpaths)

- [x] 4.1 Implementar `computeExcludedSubpaths(StorageProvider $storage): array`.
- [x] 4.2 Implementar `isInExcludedPath(string $absoluteFolder, string $basePath, array $excludedFirstSegments): bool`.
- [x] 4.3 En `scanStorage()`, después de obtener `folderPaths`, filtrar las que caen en subpath excluido y loggear cada SKIP con `Log::info`.

## 5. `DiskScannerService` — dedup por absolute_path

- [x] 5.1 Implementar `findOwnerByAbsolutePath(string $absolutePath, int $excludeStorageId): ?StorageProvider`.
- [x] 5.2 En el loop de creación de `File`, antes de `File::create()`, llamar a `findOwnerByAbsolutePath()` y `continue` si devuelve un owner.
- [x] 5.3 Loggear cada SKIP con `Log::info` indicando el storage dueño.
- [x] 5.4 (Optimización post-deploy 13:43 -06:00) Refactorizar a `buildKnownOwnersMap()`: una sola query por scan para detectar owners, en vez de N queries por candidato. Reduce el tiempo total del scan de ~5 min a ~90 seg, evitando que `everyTwoMinutes()->withoutOverlapping()` se salte runs.
- [x] 5.5 (Bug fix post-deploy 15:55 -06:00) Filtrar dedup por `transcription_enabled=true` en `buildKnownOwnersMap()`. Sin este filtro, storages DESHABILITADOS con base_path amplio (ej. storage 5 "00 Discos" con base=/Tcloud) bloqueaban re-registro de archivos bajo storages hijos habilitados. Resultado: storage 47/49 creaban 0 files porque storage 5 era considerado dueño. Con el fix: storage 47 creó 90 files en el primer scan post-fix, 605 transcriptions encoladas.

## 6. `ConvertAndTranscribeJob` — eliminar priority

- [x] 6.1 Quitar método `calculatePriority()`.
- [x] 6.2 Renombrar `dispatchWithPriority()` → `dispatch()` con firma `(int $fileId, bool $generateAlerts = true)`.
- [x] 6.3 Cambiar `onQueue('transcription')` (single queue).
- [x] 6.4 Actualizar el constructor: quitar parámetro `$priority`.

## 7. `ScanAndSubmitCommand` — eliminar priority

- [x] 7.1 Quitar lectura de `$storage->transcription_priority`.
- [x] 7.2 Llamar `ConvertAndTranscribeJob::dispatch($tx->file_id, (bool) $tx->generate_alerts)` en vez de `dispatchWithPriority()`.
- [x] 7.3 Actualizar el docblock del comando si menciona priority.

## 8. `DiagnosePendingTranscriptionsCommand` — eliminar priority

- [x] 8.1 Quitar `->pluck('transcription_priority', 'id')` y referencias.

## 9. `ApiTranscriptorController` — eliminar priority

- [x] 9.1 Quitar `transcription_priority` del `select(['id', 'name', 'type', 'transcription_enabled', 'transcription_priority'])`.
- [x] 9.2 Quitar `->orderByDesc('transcription_priority')`.
- [x] 9.3 Quitar `$storagePriorityCache = StorageProvider::pluck('transcription_priority', 'id')` y uso posterior.
- [x] 9.4 Quitar validación `'transcription_priority' => 'nullable|integer|min:0'`.
- [x] 9.5 Quitar asignación `$data['transcription_priority']`.
- [x] 9.6 Quitar `transcription_priority` del return de `store()`.

## 10. UI — `index.blade.php`

- [x] 10.1 Quitar `<select x-model.number="s.transcription_priority" @change="savePriority(s)">` (línea 159).
- [x] 10.2 Quitar badge "P" (`<span x-text="'P' + s.priority">`) y su `:class` (líneas 1036-1037).
- [x] 10.3 Quitar método `savePriority(s)` del Alpine.js.
- [x] 10.4 Quitar `transcription_priority` del body JSON en `updateStorageField()` (líneas 2036 y 2046).
- [x] 10.5 Buscar referencias adicionales con `grep "transcription_priority\|priority"` y limpiar.

## 11. Supervisor

- [x] 11.1 Editar `/etc/supervisor/conf.d/tcloud-transcription-worker.conf`.
- [x] 11.2 Cambiar `--queue=transcription-high,transcription-medium,transcription-low` → `--queue=transcription`.
- [x] 11.3 `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart tcloud-transcription-worker`.

## 12. Tests manuales

- [x] 12.1 Habilitar storage 47, ejecutar `php artisan transcription:scan-and-submit` con `--run-id=test-47`. Confirmar logs con archivos descubiertos bajo `CARACOL/18072026`, `MELODIA/18072026`, etc. — Verificado: storage 47 escaneó 788 archivos con `grouped_by_subfolder`.
- [ ] 12.2 Habilitar storage 63, ejecutar el mismo comando. Confirmar logs con SKIP por "dueño: storage 47" para archivos LA_W. — Pospuesto (storage 63 no habilitado en este momento, validar cuando se habilite).
- [ ] 12.3 Verificar que `findOwnerByAbsolutePath` no genera falsos positivos (archivos que SÍ son de storage 63 y se procesan). — Pospuesto.
- [ ] 12.4 Verificar `psql`: el conteo de archivos `LA_W` bajo storage 47 sigue en ~4,580 (sin nuevos). — A verificar tras 24h.
- [x] 12.5 Verificar que ningún job queda encolado con queue `transcription-high` o `transcription-medium` (Redis: `LLEN redis:queue:transcription-high` debe ser 0). — Verificado: ambas colas vacías, todos los jobs van a `transcription`.

## 13. Specs OpenSpec

- [x] 13.1 Actualizar `openspec/specs/transcription-disk-scanner/spec.md` con:
  - `ADDED Requirements`:
    - "Layout-aware storage scan" (flat vs grouped_by_subfolder)
    - "Scope-aware deduplication" (computeExcludedSubpaths)
    - "Absolute-path owner detection" (findOwnerByAbsolutePath)
- [x] 13.2 Actualizar `openspec/specs/transcription-api-orchestrator/spec.md` con:
  - `REMOVED Requirements`: cualquier mención de priority o queue selection.

## 14. Validación final pre-deploy

- [x] 14.1 `php artisan migrate:status` confirma migración aplicada.
- [x] 14.2 `grep -r "transcription_priority" app/ resources/` retorna 0 resultados (excluyendo migrations históricas y vendor).
- [x] 14.3 `php artisan config:clear && php artisan view:clear && php artisan cache:clear`.
- [x] 14.4 Supervisor status: `supervisorctl status tcloud-transcription-worker` → RUNNING (10/10 workers).
- [ ] 14.5 Smoke test en UI: el badge "P" no aparece, el `<select>` de priority no aparece. — Pospuesto a validación manual del usuario.

## 15. Post-deploy monitoring (primeras 24h)

- [ ] 15.1 Monitorear `storage/logs/laravel.log` por entradas "DiskScanner: skip ... dueño:" → debe haber miles (dedup activo).
- [ ] 15.2 Monitorear conteo de transcripciones nuevas por storage: 47 debe crecer (antes estaba en 0), 63 debe crecer si tiene archivos no cubiertos por 47.
- [ ] 15.3 Verificar Redis: queue `transcription-high` y `transcription-medium` no se usan más (pueden purgarse con `DEL redis:queue:transcription-high redis:queue:transcription-medium`).