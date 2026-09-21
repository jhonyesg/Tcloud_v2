## REMOVED Requirements

### Requirement: Self-healing sync de delegation leaks
**Reason**: `StorageSyncService::selfHealDelegationLeak()` corría nightly (cron) y migraba archivos mal ubicados del parent al sub-storage. La lógica de detección dependía de `parent_id` cross-storage y `original_parent_id` (columna agregada para preservar el padre original al re-forkear). Sin mirror rows, los archivos viven en un único storage desde el momento del sync; no hay "leak" que reparar.
**Migration**: El método se retira. La columna `files.original_parent_id` se DROP. El comando cron `files:repair-delegation-leak` se archiva.

### Requirement: `StorageSyncService::ensureSubstorageFolderChain`
**Reason**: Creaba filas en el storage padre para representar la cadena de folders de un sub-storage. Era el origen principal de las 1.455.875 mirror rows.
**Migration**: El método se retira. El sync solo escribe en el storage que está sincronizando.

### Requirement: Guard contra self-healing que afecte UI activa
**Reason**: El guard pausaba el self-healing cuando el usuario estaba navegando el folder afectado, para evitar que la UI viera "archivos desapareciendo" mientras él los miraba. Sin self-healing, no hay guard que mantener.
**Migration**: El guard se retira junto con la lógica que lo invocaba (`FolderListingService::isNavigationLocked()`, etc.).
