# Spec Delta — Storage Sync Overlap Guard

> Delta sobre `openspec/specs/storage-sync-overlap-guard/spec.md`.
>
> Motivo: la regla 3 de `PruneGuard` se especificó como «se borraría más del 34% de la carpeta», pero
> el llamador le pasaba el conteo de **huérfanos** donde la regla necesita el **total**. Con ese
> denominador la proporción no medía lo que la spec afirma: dejaba pasar un borrado del 40% y
> rechazaba para siempre la rotación diaria legítima. 2.053 rechazos entre el 13 y el 20 de agosto.

## MODIFIED Requirements

### Requirement: Nunca purgar sobre un escaneo no fiable

`FileScannerService::scanDirectory()` SHALL devolver un `ScanResult` que distinga «directorio vacío» de «no se pudo leer». Devolver `[]` para ambos casos permitió que un montaje NFS caído se interpretara como «borraron todo en disco» y se borrara el árbol completo.

`PruneGuard` SHALL decidir toda purga con cuatro reglas:

1. Escaneo no fiable → rechazar **siempre**, incluso forzado
2. Disco con 0 entradas y filas en BD → rechazar
3. Se borraría más del 34% de la carpeta → rechazar
4. Una orden explícita —`--force-prune` o el refresco manual de la UI— salta 2 y 3, nunca 1

El parámetro `dbCount` de `PruneGuard::decide()` SHALL ser el **total de filas que la BD tiene para esa carpeta**, no las candidatas a borrarse. La regla 3 compara lo que hay contra lo que se vio en disco y necesita el denominador completo.

Todo rechazo SHALL registrarse con `{storage_id, parent_id, path, db_count, disk_count, orphans, reason}`, donde `orphans` es el conteo **exacto** de filas que se habrían borrado. El `estimated_deletes` que produce la guarda es una estimación: solo ve totales, no qué filas emparejaron.

#### Scenario: Montaje NFS caído

- **WHEN** el punto de montaje existe, es legible y está vacío porque el montaje se cayó
- **THEN** el sistema NO SHALL borrar ninguna fila
- **AND** SHALL marcar `storage_providers.is_accessible = false` para que se vea en la UI
- **AND** SHALL registrar `mount_detached` o `prune_refused`

#### Scenario: Se borra el 40% de una carpeta grande (corregido)

- **WHEN** una carpeta tiene 100 filas en BD y el disco pasa a tener 60 entradas
- **THEN** la purga automática SHALL rechazarse por `mass_delete_ratio` con `ratio = 0.4`

> Con el conteo de huérfanos como `dbCount` esto **se permitía**: `max(0, 40 − 60) / 40 = 0`. La
> regla que decía proteger contra el borrado masivo era ciega justo en su propio rango.

## ADDED Requirements

### Requirement: El refresco manual es una orden explícita

Desde dentro de una carpeta, «desapareció el 96% por rotación» y «desapareció el 96% porque el disco no responde» son indistinguibles. Ninguna heurística sobre conteos las separa; lo que las separa es **quién lo pidió**.

- El botón «Actualizar» de `files/index.blade.php` SHALL enviar `prune=1`, **parámetro distinto de `sync=1`**, y `FileController::index()` SHALL traducirlo a `forcePrune`.
- El `silentSync()` que se dispara en cada navegación SHALL seguir usando `sync=1` a secas y quedar bajo las guardas heurísticas. La purga forzada NO SHALL ocurrir nunca de fondo.
- El refresco forzado SHALL exigir rol admin o permiso `full` sobre el storage. Borrar filas es destructivo: `shares.file_id` y `transcriptions.file_id` son `ON DELETE CASCADE` y `files` no tiene soft deletes.
- Sin ese permiso el sync SHALL ejecutarse igual, sin forzar, y la UI SHALL decírselo al usuario.

#### Scenario: Storage con rotación diaria

- **WHEN** una carpeta tiene 118 filas en BD y el disco conserva 4 entradas por rotación
- **THEN** el sync automático y el cron SHALL rechazar por `mass_delete_ratio`
- **AND** el refresco manual SHALL borrar las 114 filas huérfanas

#### Scenario: Escaneo parcial durante un refresco manual

- **WHEN** una entrada de la carpeta devuelve EIO y el usuario pulsa «Actualizar»
- **THEN** el sistema SHALL crear y actualizar con las entradas legibles
- **AND** NO SHALL borrar ninguna fila, porque `scan_untrusted` no admite excepción por intención humana
- **AND** SHALL decírselo al usuario en vez de reportar éxito

### Requirement: El sincronizado informa de lo que hizo

`syncFolder()` devuelve el listado, no lo ocurrido. Cinco de las salidas de `doSyncFolder()` devuelven el listado de BD **sin haber escaneado** —lock tomado, montaje caído, escaneo no fiable, sync desactivado, ruta desaparecida— y eran indistinguibles de un sync limpio para el llamador.

- `StorageSyncService` SHALL exponer `syncFolderWithReport()` devolviendo `{files, stats}`, con `syncFolder()` delegando en él para no romper a los llamadores existentes.
- `stats.status` SHALL nombrar la salida tomada: `synced`, `locked`, `mount_detached`, `scan_untrusted`, `sync_disabled`, `path_missing`, `unknown_folder`.
- `stats` SHALL incluir `created`, `updated`, `deleted`, `disk_count`, `orphans` y `pruned`.
- `deleted` SHALL contar el **subárbol completo**: borrar una carpeta-día se lleva cientos de filas por cascada, y reportar la fila padre sola engaña.
- La UI SHALL distinguir visualmente «sincronizado» de «no se pudo escanear», y NO SHALL reportar éxito cuando no hubo escaneo.

#### Scenario: El lock está tomado durante un refresco manual

- **WHEN** el cron o un `silentSync` tienen `sync:folder:{storage}:{parent}` y el usuario pulsa «Actualizar»
- **THEN** el camino forzado SHALL esperar hasta 3 segundos por el lock
- **AND** si vence SHALL devolver `status: 'locked'` y decírselo al usuario, no fingir éxito

> El camino automático conserva el `get()` no bloqueante: N peticiones concurrentes deben producir
> **un** escaneo y N listados baratos. La espera es solo para el clic humano, que está mirando.

### Requirement: Todo montaje con storages debe estar declarado

`MountGuard::isExpectedMountMissing()` solo protege rutas declaradas en `storage_sync.mounts.expected`, por diseño: sin la declaración no se puede distinguir «montaje caído» de «directorio local normal».

`STORAGE_SYNC_EXPECTED_MOUNTS` SHALL declarar **todo** punto de montaje que contenga storages, sea de red o volumen de bloque local. Un ext4 que no monta tras un reinicio deja el punto de montaje como directorio vacío y legible, idéntico a un NFS caído.

#### Scenario: Volumen de bloque local sin montar

- **WHEN** un disco declarado como esperado no está montado y su punto de montaje queda vacío y legible
- **THEN** `detachedAncestor()` SHALL detectarlo y el sync NO SHALL escanear ni borrar bajo esa ruta
- **AND** SHALL marcar el storage como inaccesible

> En julio se declararon los cinco NFS. `Disco_A`, `B`, `C` e `I` son montajes de bloque
> independientes y concentran 237.821 transcripciones entre los cuatro. Cablear la purga forzada al
> botón sin declararlos habría creado la vía para repetir el incidente, a mano y sobre los discos con
> más que perder.

## REMOVED Requirements

Ninguno.

---

## Acceptance Criteria

1. Con 100 filas y 60 entradas en disco, la purga automática se rechaza con `ratio = 0.4`. *(Test: `PruneGuardTest::testBorradoDelCuarentaPorCientoSuperaElUmbral`.)*
2. Una carpeta con rotación (118 vs 4) se rechaza en automático y se reconcilia al forzar. *(Verificado en storage 43: `+0 -114`, raíz 118 → 4.)*
3. El mismo storage por los dos caminos: automático `deleted: 0, reason: mass_delete_ratio`; manual `deleted: 14, pruned: true`. *(Verificado en storage 44.)*
4. Borrar una carpeta con dos hijos reporta `deleted: 3`, no 1. *(Test: `StorageSyncPruneTest::testBorrarUnaCarpetaCuentaElSubarbolCompleto`.)*
5. Revertir el arreglo del denominador hace fallar el test de regresión. *(Verificado: `null is not identical to 'mass_delete_ratio'`.)*
6. `scan_untrusted` sigue rechazando con `forced: true`. *(Test preexistente: `PruneGuardTest::testEscaneoNoFiableRechazaInclusoConForce`.)*
