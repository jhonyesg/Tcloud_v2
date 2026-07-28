# Spec Delta — Storage Sync Overlap Guard

> Delta sobre `openspec/specs/storage-sync-overlap-guard/spec.md`.
>
> Motivo: la spec vigente afirma que un lock perdido «habrá expirado o desaparecido». Es falso, y esa
> suposición es parte de lo que hizo posible el incidente del 2026-07-27.

## MODIFIED Requirements

### Requirement: `storage:sync` no puede ejecutarse en paralelo (modificado)

El schedule de `storage:sync --all` SHALL usar `->withoutOverlapping(30)` con **TTL explícito**.

- *Razón del cambio*: el TTL por defecto de `withoutOverlapping()` es de **1.440 minutos (24 horas)**.
  Un `SIGKILL` durante un cuelgue de NFS —donde el proceso queda bloqueado en IO ininterrumpible, que
  es exactamente lo que ocurrió— dejaba el lock huérfano y **paraba todos los syncs un día entero, en
  silencio**. 30 minutos deja margen holgado sobre los ~4 minutos de ejecución real.

#### Scenario: Servidor reinicia mientras sync está corriendo (corregido)

- **WHEN** el proceso muere sin liberar el lock (`SIGKILL`, OOM, reinicio)
- **THEN** el lock persiste como máximo **30 minutos**, no 24 horas
- **AND** el siguiente ciclo tras esa ventana ejecuta con normalidad

> El escenario anterior afirmaba que el lock «habrá expirado o desaparecido» tras un reinicio. Con
> Redis como store el lock **sobrevive** al reinicio de la aplicación, y con el TTL por defecto habría
> sobrevivido 24 horas.

## ADDED Requirements

### Requirement: El mutex del scheduler no basta

El lock del scheduler cubre **únicamente** el comando programado. El sistema SHALL serializar el
sincronizado a nivel de servicio, porque estos cinco puntos de entrada lo esquivan por completo y
pueden ejecutarse concurrentemente entre sí y con el cron:

1. `FileController::index()` con `?sync=1` — síncrono dentro de la petición web
2. `FileController::index()` auto-escaneo de carpeta vacía — **el de mayor volumen**
3. `PublicShareController::autoSyncFolder()` — protegido solo por una clave de caché de 60 s, que no es un lock
4. `ApiTranscriptorController::syncStorage()` — `fullSync()` completo dentro de una petición web
5. `DiskScannerService::resolveParentId()` — cada 2 minutos desde `transcription:tick`

#### Scenario: N cargas de página concurrentes sobre una carpeta vacía

- **WHEN** varias peticiones piden a la vez el listado de una carpeta sin filas en BD
- **THEN** SHALL ejecutarse **un solo** escaneo y las demás SHALL devolver el listado actual de BD
- **AND** NO SHALL crearse filas duplicadas

> Este es el escenario exacto del incidente: tras un borrado masivo toda carpeta queda vacía, así que
> cada carga de página de cada usuario disparaba un escaneo. Los 3 duplicados del storage 134 se
> crearon **en el mismo segundo**.

### Requirement: Locks por carpeta y por storage, dentro del servicio

- `syncFolder()` SHALL adquirir `Cache::lock("sync:folder:{storage}:{parent}")` de forma **no
  bloqueante**; si no lo obtiene, SHALL devolver el listado actual sin escanear.
- `fullSync()` SHALL adquirir `Cache::lock("sync:storage:{storage}")` y devolver `skipped_locked` si
  no lo obtiene.
- Los locks SHALL vivir **dentro del servicio**, no en los llamadores, para que los cinco puntos de
  entrada los hereden y ninguno pueda olvidarlos.
- La jerarquía SHALL ser estricta storage → carpeta, de modo que no exista deadlock posible.
- No bloqueante y no `block()`: N peticiones concurrentes deben producir **un** escaneo y N listados
  baratos, no N escaneos en cola.

### Requirement: Nunca purgar sobre un escaneo no fiable

`FileScannerService::scanDirectory()` SHALL devolver un `ScanResult` que distinga «directorio vacío»
de «no se pudo leer». Devolver `[]` para ambos casos es lo que permitió que un montaje NFS caído se
interpretara como «borraron todo en disco».

`PruneGuard` SHALL decidir toda purga con estas reglas:

1. Escaneo no fiable → rechazar **siempre**, incluso con `--force-prune`
2. Disco con 0 entradas y filas en BD → rechazar *(esta regla sola habría evitado el incidente)*
3. Se borraría más del 34% de la carpeta → rechazar
4. `--force-prune` salta 2 y 3, nunca 1

Todo rechazo SHALL registrarse con `{storage_id, parent_id, path, db_count, disk_count, reason}`.

#### Scenario: Montaje NFS caído

- **WHEN** el punto de montaje existe, es legible y está vacío porque el montaje se cayó
- **THEN** el sistema NO SHALL borrar ninguna fila
- **AND** SHALL marcar `storage_providers.is_accessible = false` para que se vea en la UI
- **AND** SHALL registrar `mount_detached` o `prune_refused`

> Un goteo de `prune_refused` sobre un storage concreto es la **alerta temprana** del problema de NFS
> que antes se manifestaba como un borrado silencioso del árbol completo.

### Requirement: Identidad única garantizada por la base de datos

- La identidad canónica de una fila de `files` SHALL ser `(storage_provider_id, path)`.
- SHALL existir un índice único sobre esa pareja como garantía de último recurso.
- Toda creación SHALL pasar por `FileRegistry::ensure()`, que ante una violación de unicidad relee al
  ganador en vez de duplicar.
- Una violación de unicidad en una petición web SHALL responder **409**, no 500.

> El índice existía y fue eliminado el 2026-05-21 con el comentario *"167MB unused index: 0 scans
> recorded, no app-level dependency"*. Ninguna consulta lo usaba para **leer**, pero era lo único que
> garantizaba la corrección. Sin él se insertaron 70.804 filas duplicadas.

## REMOVED Requirements

Ninguno.

---

## Acceptance Criteria

1. Tres `storage:sync` concurrentes sobre el mismo storage → uno hace el trabajo, los otros salen de
   inmediato, **delta de filas 0**. *(Verificado: 0,55 s vs 0,01 s.)*
2. `sum(c-1)` agrupando por `(storage_provider_id, path)` → **0**. *(Verificado: 70.804 → 0.)*
3. Un `INSERT` duplicado dentro de `BEGIN`/`ROLLBACK` falla con 23505. *(Verificado.)*
4. Recuento contra disco: storage 134 nivel raíz = 17 filas = 17 carpetas. *(Verificado: era 51.)*
5. Con el montaje caído, `storage:sync` no borra nada y deja traza.
6. Una hora con sync y `transcription:tick` activos → cero duplicados nuevos. *(Verificado.)*
