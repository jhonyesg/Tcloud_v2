## ADDED Requirements

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

### Requirement: El mutex del scheduler no basta
El lock del scheduler cubre **únicamente** el comando programado. El sistema SHALL serializar el sincronizado a nivel de servicio, porque estos cinco puntos de entrada lo esquivan y pueden ejecutarse concurrentemente entre sí y con el cron: `FileController::index()` con `?sync=1`, el auto-escaneo de carpeta vacía del mismo controlador (el de mayor volumen), `PublicShareController::autoSyncFolder()`, `ApiTranscriptorController::syncStorage()` y `DiskScannerService::resolveParentId()`.

- `syncFolder()` SHALL adquirir `Cache::lock("sync:folder:{storage}:{parent}")` de forma **no bloqueante**; sin él, SHALL devolver el listado actual de BD sin escanear.
- `fullSync()` SHALL adquirir `Cache::lock("sync:storage:{storage}")` y devolver `skipped_locked` si no lo obtiene.
- Los locks SHALL vivir **dentro del servicio**, no en los llamadores, para que los cinco puntos de entrada los hereden y ninguno pueda olvidarlos.
- La jerarquía SHALL ser estricta storage → carpeta: no hay deadlock posible.

#### Scenario: N cargas de página concurrentes sobre una carpeta vacía
- **WHEN** varias peticiones piden a la vez el listado de una carpeta sin filas en BD
- **THEN** SHALL ejecutarse **un solo** escaneo y las demás SHALL devolver el listado actual
- **AND** NO SHALL crearse filas duplicadas

> Escenario exacto del incidente del 2026-07-27: tras un borrado masivo toda carpeta queda vacía, así que cada carga de página de cada usuario disparaba un escaneo. Los 3 duplicados del storage 134 se crearon **en el mismo segundo**.

### Requirement: Nunca purgar sobre un escaneo no fiable
`FileScannerService::scanDirectory()` SHALL devolver un `ScanResult` que distinga «directorio vacío» de «no se pudo leer». Devolver `[]` para ambos casos permitió que un montaje NFS caído se interpretara como «borraron todo en disco» y se borrara el árbol completo.

`PruneGuard` SHALL decidir toda purga con cuatro reglas: (1) escaneo no fiable → rechazar siempre, incluso con `--force-prune`; (2) disco con 0 entradas y filas en BD → rechazar; (3) se borraría más del 34% de la carpeta → rechazar; (4) `--force-prune` salta 2 y 3, nunca 1. Todo rechazo SHALL registrarse con contexto.

#### Scenario: Montaje NFS caído
- **WHEN** el punto de montaje existe, es legible y está vacío porque el montaje se cayó
- **THEN** el sistema NO SHALL borrar ninguna fila
- **AND** SHALL marcar `storage_providers.is_accessible = false` para que se vea en la UI

> Un goteo de `prune_refused` sobre un storage concreto es la **alerta temprana** del problema de NFS que antes se manifestaba como un borrado silencioso.

### Requirement: Identidad única garantizada por la base de datos
La identidad canónica de una fila de `files` SHALL ser `(storage_provider_id, path)`, con un índice único sobre esa pareja como garantía de último recurso. Toda creación SHALL pasar por `FileRegistry::ensure()`, que ante una violación de unicidad relee al ganador en vez de duplicar. Una violación en una petición web SHALL responder **409**, no 500.

> El índice existía y se eliminó el 2026-05-21 con el comentario *"167MB unused index: 0 scans recorded, no app-level dependency"*. Ninguna consulta lo usaba para **leer**, pero era lo único que garantizaba la corrección. Sin él se insertaron 70.804 filas duplicadas.

### Requirement: Eager loading de storageProvider en eliminación recursiva
`FileController::deleteRecursive` y `deleteFile` SHALL cargar la relación `storageProvider` con eager loading al obtener los hijos de una carpeta, para eliminar el patrón N+1.

#### Scenario: Eliminar carpeta con múltiples archivos
- **WHEN** se elimina una carpeta que contiene N archivos
- **THEN** la relación `storageProvider` se carga en una sola query para todos los hijos, no una query por archivo
