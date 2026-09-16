## Purpose

Extender el patrón de feedback visual (botón `:disabled` + spinner SVG inline + toast de error en rama de fallo + `try/finally` para limpieza garantizada) a TODAS las operaciones destructivas del admin que aún no lo tienen: eliminar usuario, eliminar/quitar usuarios de external sites, cerrar sesiones y remover assignments de storage tanto desde la vista de usuario como desde la vista de storage.

## ADDED Requirements

### Requirement: Feedback visual al eliminar un usuario

Cuando el admin hace clic en "Eliminar" del modal de confirmación de borrado de usuario en `/admin/users`, el sistema SHALL deshabilitar ese botón y mostrar un indicador de progreso hasta que la petición `DELETE /admin/users/{id}` termine, sin importar éxito o error.

#### Scenario: Botón muestra estado de carga durante petición
- **WHEN** el admin hace clic en "Eliminar" en el modal de confirmación de borrado de usuario
- **THEN** ese botón se deshabilita con opacidad reducida y texto alternativo mientras la petición DELETE está en vuelo

#### Scenario: Error en eliminación de usuario muestra toast rojo
- **WHEN** la petición DELETE de usuario responde con error no-2xx
- **THEN** aparece un toast rojo con el mensaje de error del servidor (o un mensaje genérico si no hay detalle) y el modal permanece abierto

### Requirement: Feedback visual al eliminar un external site o quitar un usuario de él

Cuando el admin elimina un external site o quita un usuario asignado a ese site desde `/admin/external-sites`, el sistema SHALL deshabilitar el botón correspondiente durante la petición y notificar del resultado.

#### Scenario: Eliminar site con estado de carga
- **WHEN** el admin confirma la eliminación de un external site
- **THEN** la confirmación visual queda bloqueada hasta que la petición DELETE responda

#### Scenario: Quitar usuario de un site con feedback
- **WHEN** el admin quita un usuario de un external site
- **THEN** la operación tiene feedback visual de carga y notifica éxito o error

### Requirement: Feedback visual al cerrar sesiones de admin

Cuando el admin cierra una sesión individual (`killSession`) o todas las sesiones de un usuario (`killUserSessions`) en `/admin/sessions`, el sistema SHALL deshabilitar el botón correspondiente durante la petición y mostrar un toast explícito en caso de fallo — actualmente estas operaciones fallan en silencio.

#### Scenario: killSession tiene estado de carga
- **WHEN** el admin confirma cerrar una sesión
- **THEN** el botón de confirmación se deshabilita mientras la petición DELETE está en vuelo

#### Scenario: Error al cerrar sesión muestra toast rojo
- **WHEN** la petición DELETE de killSession o killUserSessions responde con error
- **THEN** aparece un toast rojo con el mensaje del servidor (la operación NO debe fallar en silencio)

### Requirement: Feedback visual al remover assignments de storage

Cuando el admin remueve un storage de un usuario desde `/admin/users/{id}/storages` o remueve un usuario de un storage desde `/admin/storages/{id}/users`, el sistema SHALL deshabilitar el botón de remover durante la petición y mostrar un toast explícito en caso de fallo — actualmente estas dos operaciones fallan en silencio.

#### Scenario: removeAssignment desde user-storages tiene estado de carga
- **WHEN** el admin hace clic en "Remover" desde la vista de storages de un usuario
- **THEN** ese botón queda deshabilitado mientras la petición DELETE está en vuelo

#### Scenario: removeAssignment desde storage-users tiene estado de carga
- **WHEN** el admin hace clic en "Remover" desde la vista de usuarios de un storage
- **THEN** ese botón queda deshabilitado mientras la petición DELETE está en vuelo

#### Scenario: Error al remover assignment muestra toast rojo
- **WHEN** la petición DELETE de removeAssignment responde con error
- **THEN** aparece un toast rojo con el mensaje del servidor (la operación NO debe fallar en silencio)

### Requirement: Estado de carga se limpia con try/finally

Para todas las operaciones cubiertas por este spec, el sistema SHALL usar `try/finally` en la función Alpine para garantizar que el estado de carga se limpia aunque la petición lance excepción de red o timeout — replicando el patrón del spec archivado `admin-storages-loading-state`.

#### Scenario: Excepción de red limpia el estado
- **WHEN** la petición fetch falla por error de red o timeout
- **THEN** el botón vuelve a estar habilitado y la UI queda interactiva (no debe quedar bloqueada)
