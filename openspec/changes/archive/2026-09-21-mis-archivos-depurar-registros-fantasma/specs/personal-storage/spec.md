## ADDED Requirements

### Requirement: Cada usuario ve solo su propio personal en Mis Archivos

El sistema SHALL filtrar el endpoint `GET /user/storages` (que alimenta el grid de Mis Archivos) para que un storage con `is_personal=true` SOLO aparezca al usuario canónico del storage (username = último segmento de `base_path` tras `/home/www/Usuarios_tcloud/`). Usuarios administradores (`users.role = 'admin'`) SHALL seguir viendo todos los personales. Usuarios no-admin con `permissions='full'` sobre un personal ajeno SHALL NO verlo en el listado de Mis Archivos (esos accesos quedan como deuda administrativa pero no exponen el storage a través del módulo personal).

El filtro SHALL aplicarse también al endpoint equivalente de la API usada por el frontend. Ningún otro endpoint (descarga, share público, API transcriptor) SHALL cambiar su comportamiento de permisos.

#### Scenario: Usuario no-admin entra a Mis Archivos y ve solo su personal
- **WHEN** el usuario `jsuarez` (no admin) autentica y carga `/files`
- **THEN** la lista de storages muestra su propio `Personal - jsuarez`
- **AND** NO muestra `Personal - StakeholdersPrensa` aunque `user_storages` lo tenga asignado con `permissions='full'`

#### Scenario: Admin ve todos los personales
- **WHEN** un usuario con `role='admin'` autentica y carga `/files`
- **THEN** la lista de storages muestra TODOS los `storage_providers` con `is_personal=true` del sistema, además de los no personales asignados

#### Scenario: Acceso full权 a personal ajeno no expone el storage
- **WHEN** `user_storages` contiene una fila `permissions='full'` para un usuario no-admin sobre un storage personal ajeno
- **THEN** el endpoint `GET /user/storages` NO devuelve ese storage al usuario no-admin
- **AND** el registro en `user_storages` queda vigente pero opaco a la UI (la limpieza administrativa se hace con `user-storages:fix-personal-visibility`)
