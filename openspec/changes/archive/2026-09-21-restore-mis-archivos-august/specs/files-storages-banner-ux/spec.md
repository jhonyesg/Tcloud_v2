## REMOVED Requirements

### Requirement: Banner "X archivos sin storage asignado"
**Reason**: El banner advertía sobre archivos cuyo `storage_provider_id` apuntaba a un storage borrado o cuyo `owner_id` no tenía `permissions='full'` en el storage. Con el modelo simple de agosto, el sync nunca crea filas huérfanas porque solo escribe en storages que está sincronizando; y los permisos per-storage se respetan en el listing.
**Migration**: El banner se retira de la UI. Si en el futuro se reintroduce auditoría de filas huérfanas, se modela como reporte admin separado.
