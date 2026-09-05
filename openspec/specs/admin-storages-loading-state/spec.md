# admin-storages-loading-state Specification

## Purpose

Define visual feedback (disabled state + spinner + toast de error) durante operaciones destructivas en el admin de storages — eliminar un storage y desvincular un usuario — para que el admin sepa si su acción está en curso, terminó bien, o falló.

## Requirements

### Requirement: Feedback visual al eliminar un storage

Cuando el admin hace clic en "Eliminar" dentro del modal de confirmación de borrado de storage, el sistema SHALL deshabilitar ese botón y mostrar un indicador de progreso hasta que la petición `DELETE /admin/storages/{id}` termine, sin importar si termina con éxito o error.

#### Scenario: Click en Eliminar dispara estado de carga
- **WHEN** el admin hace clic en el botón "Eliminar" del modal de confirmación
- **THEN** el botón se deshabilita, su opacidad se reduce y su texto cambia a "Eliminando..." durante toda la duración de la petición

#### Scenario: Eliminación exitosa cierra modal y refresca lista
- **WHEN** la petición DELETE responde 200 OK
- **THEN** el modal se cierra, la lista de storages se recarga, aparece un toast verde con el mensaje "Storage eliminado correctamente", y el estado de carga del botón se limpia

#### Scenario: Error en eliminación muestra toast y libera el botón
- **WHEN** la petición DELETE responde con error (cualquier código no-2xx)
- **THEN** aparece un toast rojo con el mensaje de error del servidor (o un mensaje genérico si no hay detalle), el estado de carga del botón se limpia, y el modal de confirmación permanece abierto para que el admin pueda reintentar

### Requirement: Feedback visual al desvincular un usuario de un storage

Cuando el admin hace clic en el icono × de un chip de usuario en el modal de gestión de usuarios, el sistema SHALL deshabilitar ese botón ×, mostrar un spinner inline, y notificar al admin del resultado — éxito o error — antes de permitir otro intento sobre ese mismo chip.

#### Scenario: Click en × deshabilita el botón del chip
- **WHEN** el admin hace clic en el botón × de un chip de usuario
- **THEN** ese botón × queda deshabilitado y muestra un spinner hasta que la petición `DELETE /admin/storages/{storageId}/users/{userId}` termine

#### Scenario: Desvinculación exitosa actualiza el modal sin cerrarlo
- **WHEN** la petición DELETE responde 200 OK
- **THEN** el chip desaparece de la lista, la lista de usuarios del modal se recarga, y aparece un toast verde "Usuario removido"

#### Scenario: Error en desvinculación muestra toast y libera el botón
- **WHEN** la petición DELETE responde con error (por ejemplo 404 si la asignación ya no existe)
- **THEN** aparece un toast rojo con el detalle del error, el botón × se rehabilita, y el chip permanece visible — el sistema MUST NOT fallar en silencio

### Requirement: Estado de carga siempre se limpia

El sistema SHALL garantizar que cualquier estado de carga establecido por estas dos operaciones (`deletingStorageId`, `removingUserAssignmentKey`) se limpie cuando la petición termina, sin importar si termina con éxito, error, excepción de red o timeout — para que la UI nunca quede bloqueada.

#### Scenario: Error de red limpia el estado de carga
- **WHEN** la petición fetch falla por error de red o timeout
- **THEN** el botón vuelve a estar habilitado y la UI queda en estado interactivo normal
