# Spec: storage-sync-overlap-guard

## Purpose

Garantiza que el sincronizado de archivos entre disco y la tabla `files` no pueda duplicar filas ni borrar el árbol completo. Serializa toda entrada al sincronizado con locks por storage y por carpeta, distingue «directorio vacío» de «no legible» para que un montaje NFS caído no se interprete como un borrado masivo, y protege la identidad canónica de cada fila con un índice único en la base de datos.

---

## Requirements

### Requirement: storage:sync no puede ejecutarse en paralelo
El schedule del comando `storage:sync --all` SHALL usar `->withoutOverlapping(30)` — con **TTL explícito de 30 minutos** — para garantizar que una nueva ejecución no comience si la anterior todavía está corriendo.

#### Scenario: Sync termina antes del siguiente ciclo
- **WHEN** `storage:sync --all` tarda menos de 15 minutos
- **THEN** la siguiente ejecución programada comienza normalmente

#### Scenario: Sync tarda más de 15 minutos
- **WHEN** `storage:sync --all` está en ejecución al momento del siguiente disparo del schedule
- **THEN** el nuevo intento se omite silenciosamente gracias al lock de caché y no se lanza un proceso paralelo

#### Scenario: El proceso muere sin liberar el lock
- **WHEN** el proceso muere sin liberar el lock (`SIGKILL`, OOM, reinicio) — típicamente durante un cuelgue de NFS, donde queda bloqueado en IO ininterrumpible
- **THEN** el lock persiste como máximo **30 minutos** y después el schedule vuelve a ejecutar con normalidad

> **Corrección (2026-07-27)**: este escenario afirmaba que tras un reinicio «el lock Redis habrá expirado o desaparecido». Era falso por partida doble: con Redis como store el lock **sobrevive** al reinicio de la aplicación, y el TTL por defecto de `withoutOverlapping()` es de **1.440 minutos (24 horas)**. Un `SIGKILL` dejaba el lock huérfano y paraba todos los syncs un día entero, en silencio. De ahí el TTL explícito.

---

### Requirement: El mutex del scheduler no basta
El lock del scheduler cubre **únicamente** el comando programado. El sistema SHALL serializar el sincronizado a nivel de servicio, porque estos cinco puntos de entrada lo esquivan y pueden ejecutarse concurrentemente entre sí y con el cron: `FileController::index()` con `?sync=1`, el auto-escaneo de carpeta vacía del mismo controlador (el de mayor volumen), `PublicShareController::autoSyncFolder()`, `ApiTranscriptorController::syncStorage()` y `DiskScannerService::resolveParentId()`.

#### Scenario: N cargas de página concurrentes sobre una carpeta vacía
- **WHEN** varias peticiones piden a la vez el listado de una carpeta sin filas en BD
- **THEN** SHALL ejecutarse **un solo** escaneo y las demás SHALL devolver el listado actual
- **AND** NO SHALL crearse filas duplicadas

> Escenario exacto del incidente del 2026-07-27: tras un borrado masivo toda carpeta queda vacía, así que cada carga de página de cada usuario disparaba un escaneo. Los 3 duplicados del storage 134 se crearon **en el mismo segundo**.

---

### Requirement: Locks por carpeta y por storage, dentro del servicio
- `syncFolder()` SHALL adquirir `Cache::lock("sync:folder:{storage}:{parent}")` de forma **no bloqueante**; sin él, SHALL devolver el listado actual de BD sin escanear.
- `fullSync()` SHALL adquirir `Cache:lock("sync:storage:{storage}")` y devolver `skipped_locked` si no lo obtiene.
- Los locks SHALL vivir **dentro del servicio**, no en los llamadores, para que los cinco puntos de entrada los hereden y ninguno pueda olvidarlos.
- La jerarquía SHALL ser estricta storage → carpeta: no hay deadlock posible.
- No bloqueante y no `block()`: N peticiones concurrentes deben producir **un** escaneo y N listados baratos, no N escaneos en cola.

#### Scenario: Dos fullSync concurrentes sobre el mismo storage
- **WHEN** dos invocaciones de `fullSync()` llegan al mismo tiempo sobre el storage 134
- **THEN** una adquiere `sync:storage:134` y ejecuta el escaneo completo
- **AND** la otra no obtiene el lock y devuelve `skipped_locked` sin escanear

#### Scenario: Escaneo de carpeta con lock tomado
- **WHEN** `syncFolder()` no obtiene `sync:folder:{storage}:{parent}`
- **THEN** devuelve el listado actual de BD sin escanear
- **AND** no bloquea ni encola la petición

---

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

### Requirement: Identidad única garantizada por la base de datos
La identidad canónica de una fila de `files` SHALL ser `(storage_provider_id, path)`, con un índice único sobre esa pareja como garantía de último recurso. Toda creación SHALL pasar por `FileRegistry::ensure()`, que ante una violación de unicidad relee al ganador en vez de duplicar. Una violación en una petición web SHALL responder **409**, no 500.

> El índice existía y se eliminó el 2026-05-21 con el comentario *"167MB unused index: 0 scans recorded, no app-level dependency"*. Ninguna consulta lo usaba para **leer**, pero era lo único que garantizaba la corrección. Sin él se insertaron 70.804 filas duplicadas.

#### Scenario: Inserción duplicada dentro de una transacción
- **WHEN** un `INSERT` con `(storage_provider_id, path)` ya existente se ejecuta dentro de un `BEGIN`/`ROLLBACK`
- **THEN** la base de datos rechaza con error de unicidad 23505
- **AND** `FileRegistry::ensure()` relee la fila ganadora en vez de duplicar

#### Scenario: Violación de unicidad en una petición web
- **WHEN** dos peticiones web intentan crear la misma fila `(storage, path)` concurrentemente
- **THEN** la perdedora recibe respuesta **409 Conflict**, no 500

---

### Requirement: Eager loading de storageProvider en eliminación recursiva
`FileController::deleteRecursive` y `deleteFile` SHALL cargar la relación `storageProvider` con eager loading al obtener los hijos de una carpeta, para eliminar el patrón N+1.

#### Scenario: Eliminar carpeta con múltiples archivos
- **WHEN** se elimina una carpeta que contiene N archivos
- **THEN** la relación `storageProvider` se carga en una sola query para todos los hijos, no una query por archivo

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
