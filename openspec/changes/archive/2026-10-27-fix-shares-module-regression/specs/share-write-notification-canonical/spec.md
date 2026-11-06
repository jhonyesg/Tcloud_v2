## REMOVED Requirements

### Requirement: Notification command identifies recently repointed write/full shares

**Reason**: El comando `shares:notify-repointed` nunca llegó a implementarse en el repositorio, y su fuente de verdad —la tabla `file_mirror_audit_log` con la acción `repoint_share`— fue eliminada por el restore de Mis Archivos de agosto 2026 (`13645ca`), junto con la columna `files.canonical_folder_id` que daba sentido a la operación de repoint. Sin la noción de repoint, el aviso "tu share ahora apunta al folder canónico" describe un evento que el sistema ya no puede producir.

**Migration**: Ninguna. La notificación se retira con su comando. Si en el futuro se reintroduce la identidad mirror→canónico, el aviso deberá reintroducirse con ella.

### Requirement: Notification email content is informative

**Reason**: Es la notificación asociada al comando retirado en el requisito anterior y depende de la misma operación de repoint, que ya no existe.

**Migration**: Ninguna.

### Requirement: Notification skips creators without email

**Reason**: Es un requisito operativo del comando retirado en el primer requisito.

**Migration**: Ninguna.

### Requirement: Notification is idempotent within a window

**Reason**: Es un requisito operativo del comando retirado en el primer requisito. La tabla `share_notification_log` que utilizaba permanece en la base de datos, pero queda sin consumidor: SHALL eliminarse en el mismo cambio por no tener propósito.

**Migration**: La tabla `share_notification_log` SHALL eliminarse de la base de datos, tras confirmar que no contiene filas que se conserven como histórico. Es una tabla sin filas de producción esperadas y sin consumidor, por lo que su eliminación no requiere migración de datos.