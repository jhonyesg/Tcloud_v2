# Tasks: El botón «Actualizar» reconcilia la BD con el disco

> Implementado y verificado en producción el 2026-08-20. El orden importa: declarar los montajes
> **antes** de cablear la purga forzada, o el botón se convierte en la vía para repetir el incidente
> del 2026-07-27 sobre los discos con 237.821 transcripciones.

## Fase 0 — Prerequisito de seguridad

- [x] Comprobar disco a disco cuáles son montajes reales comparando `st_dev` con el del padre. Resultado: `Disco_A/B/C/I` son montajes de bloque no declarados; `Disco_F` estaba **caído** y la guarda lo bloqueaba correctamente (82 `mount_detached` en el log).
- [x] Añadir `Disco_A`, `Disco_B`, `Disco_C` y `Disco_I` a `STORAGE_SYNC_EXPECTED_MOUNTS` en `app/.env`, con el porqué en el comentario.
- [x] Documentar la sección `storage_sync` completa en `app/.env.example` — no existía ninguna de sus siete variables.
- [x] `php artisan config:clear` y verificar con `MountGuard::isExpectedMountMissing()` los nueve montajes: ocho `ok`, `Disco_F` ausente.

## Fase 1 — El bug de semántica

- [x] Capturar `$totalDbRows` en `doSyncFolder()` **antes** del bucle de creación, y pasarlo como `dbCount` a `PruneGuard::decide()`. Hasta ahora se pasaba `$bdFiles->count()` después de los `unset()`, o sea los huérfanos.
- [x] Registrar el conteo exacto de huérfanos como `orphans` en `storage_sync.prune_refused`.
- [x] Renombrar `would_delete` → `estimated_deletes` en el contexto de `PruneGuard`: la guarda solo ve totales y su número era una estimación que se leía como exacta (decía 110 donde borraría 114).
- [x] Corregir el docblock de `dbCount`: decía «filas candidatas a borrarse» cuando los tests siempre asumieron el total.

## Fase 2 — El refresco manual como orden explícita

- [x] `refreshFiles()` en `files/index.blade.php` manda `&prune=1`. Parámetro distinto de `sync=1` para que `silentSync()` siga bajo las guardas.
- [x] `FileController::index()` calcula `$forcePrune = prune=1 && (isAdmin || hasStoragePermission($id, 'full'))` y lo pasa al servicio.
- [x] `syncFolderWithReport()` en `StorageSyncService`, con `syncFolder()` delegando en él para no tocar los cuatro llamadores existentes.
- [x] `report()` privado que etiqueta las siete salidas; las cinco tempranas devolvían el listado de BD sin decir que no habían escaneado.
- [x] Lock `block(3)` con `LockTimeoutException` **solo** cuando `$forcePrune`; el camino automático conserva el `get()` no bloqueante de julio.

## Fase 3 — Que la UI diga la verdad

- [x] `reportSync(stats, serverData)` en el Blade traduce `status` a un aviso; el toast verde fijo se sustituye por contadores reales.
- [x] Tipo `warning` (ámbar) en el toast global, y duración configurable —4 s por defecto, 7 s para los avisos que hay que leer.
- [x] `deleteRecursively()` devuelve el número de filas del subárbol: decir «1 eliminado» al borrar una carpeta-día con cientos de hijos era engañoso.
- [x] `fullSync()` acumula `created/updated/deleted` reales. Contaba las entradas devueltas sin `id`, que siempre era **cero** porque el listado sale de la BD y todas las filas tienen `id`.
- [x] Pasar `forcePrune` también al sync de la raíz dentro de `fullSync()` — antes `syncRootFolder()` lo perdía, y la raíz es justo donde estaban los 114 huérfanos.

## Fase 4 — Tests

- [x] `PruneGuardTest` — dos casos nuevos: la rotación diaria (118 vs 4, rechazada en automático y permitida al forzar) y el borrado del 40% que la versión rota dejaba pasar.
- [x] `StorageSyncPruneTest` (nuevo, `tests/Feature/`) — siete casos sobre BD de test y directorio temporal real: creación, el bug del 40%, purga forzada, rotación, recuento del subárbol, disco vacío, y compatibilidad de `syncFolder()`.
- [x] **Comprobar que el test detecta el bug**: revertir `dbCount: $totalDbRows` → `$bdFiles->count()` y confirmar que `testBorrarElCuarentaPorCientoYaNoPasaDesapercibido` falla. Falla con `null is not identical to 'mass_delete_ratio'`. Restaurado.
- [x] Baseline aislando solo los archivos de este cambio: **437 tests / 11 errores / 5 fallos antes**, **446 / 11 / 5 después**. Los fallos son preexistentes y ajenos (corrections, LLM, plantillas).

## Verificación

- [x] V1 cadena de guardas sana en Nación: `Disco_C` declarado y montado, `detachedAncestor` = `NULL`, `scan ok=true entradas=4`
- [x] V2 purga real, storage 43: `storage:sync 43 --force-prune` → **`+0 -114`**; raíz en BD **118 → 4** contra 4 entradas en disco
- [x] V3 sin dependencias colgando antes de borrar: 0 `shares`, 0 `transcriptions`, 0 `media_edit_jobs` en el árbol del storage 43
- [x] V4 los dos caminos, storage 44: automático → `deleted: 0, reason: mass_delete_ratio`; manual → `deleted: 14, pruned: true`
- [x] V5 el Blade compila y el bloque Alpine pasa `node --check`
- [x] V6 `opcache.validate_timestamps=On` con `revalidate_freq=3` — los cambios se sirven sin recargar FPM
- [ ] **Pendiente**: vigilar que `prune_refused` deje de crecer por `mass_delete_ratio` en los storages de prensa conforme se vayan usando
- [ ] **Pendiente (operador)**: `Disco_F` lleva días sin montar y bloquea 18 storages. Es correcto que la guarda lo frene, pero el montaje hay que arreglarlo
- [ ] **Pendiente (decisión)**: 9 storages siguen desfasados (`39, 40, 41, 42, 45, 59, 125, 126, 131`). El botón los reconcilia al usarse; `storage:sync --all --force-prune` los saldaría de una vez, pero toca los 175 storages locales
