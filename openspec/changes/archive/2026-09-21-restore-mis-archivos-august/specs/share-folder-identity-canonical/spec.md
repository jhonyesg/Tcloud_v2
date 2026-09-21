## REMOVED Requirements

### Requirement: Canonicalización de `file_id` al crear shares
**Reason**: `ShareController::store` canónica el `file_id` recibido vía `FilePhysicalIdentity::canonicalFor()` antes de crear el share, para evitar que un share apunte a un mirror vacío. Con la eliminación de los mirrors, el `file_id` recibido es siempre el canónico real.
**Migration**: La línea de canonicalización se retira; `ShareController::store` crea el share sobre el `file_id` recibido tal cual.

### Requirement: Reparentación de folder shares al canónico
**Reason**: El comando `shares:repair-mirror-targets --apply` repuntaba folder shares históricos al canónico cuando sus `file_id` apuntaban a mirrors. Con 0 mirrors vivos, no hay a qué reparar.
**Migration**: El comando se archiva; si en el futuro se reintroduce canonicalización, se reactiva con un nombre distinto.

### Requirement: Notificación a creadores de shares write/full repuntados
**Reason**: El comando `shares:notify-repointed --apply` notificaba por correo a los creadores de shares write/full que fueron repuntados al canónico. Sin repoints masivos, no hay destinatarios.
**Migration**: El comando y la tabla `share_notification_log` se retiran.

### Requirement: Auditoría de link/unlink/repoint en `file_mirror_audit_log`
**Reason**: La tabla append-only registraba cada `link_mirror`, `unlink_mirror`, `repoint_share`. Sin mirrors ni repoints, no hay eventos que auditar.
**Migration**: La tabla se DROP. El log de auditoría que se necesite en el futuro se modela de nuevo (idealmente integrado con el log de auditoría general del proyecto).
