## REMOVED Requirements

### Requirement: Cache del endpoint `/ia/api-transcriptor/empty-folders` por storage

**Reason**: La spec documenta el cache `transcriptor:empty_folders:{storage_id}:max{maxDirs}` con TTL 600s que sirve el endpoint `GET /ia/api-transcriptor/empty-folders`, alimentando el banner "carpetas sin archivos" dentro de la pestaña Storages. El banner y el endpoint se eliminan en el change `simplify-api-transcriptor-to-storage-and-config`.

**Migration**: La detección de carpetas vacías sigue siendo útil pero ahora se hace desde el sistema de storage-sync existente, que ya loguea `storage_sync.prune_refused` y `storage_sync.orphan_linked`. Para auditar manualmente:
```sql
SELECT sp.name, COUNT(*) AS empty_dirs
FROM storage_providers sp
JOIN files f ON f.storage_provider_id = sp.id
WHERE f.is_folder = true
  AND NOT EXISTS (SELECT 1 FROM files c WHERE c.parent_id = f.id AND c.is_folder = false)
GROUP BY sp.id;
```