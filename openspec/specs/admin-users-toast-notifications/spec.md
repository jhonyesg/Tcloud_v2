# admin-users-toast-notifications Specification

## Purpose
Define las notificaciones no-bloqueantes (toast) que la pantalla
`/admin/users` muestra al operador tras crear, editar o eliminar un usuario,
incluyendo su duración, posición y comportamiento de auto-dismiss.

## Requirements

### Requirement: Toast Alpine no-bloqueante para el resultado de operaciones sobre usuarios

El sistema SHALL mostrar una notificación toast Alpine en la pantalla
`/admin/users` cada vez que una operación (crear, editar, eliminar usuario)
termine con éxito o con error. La notificación SHALL ser no-bloqueante
(nunca usar `alert()` del navegador), SHALL aparecer en la esquina superior
derecha de la pantalla, SHALL usar fondo verde para éxito y rojo para
error, SHALL mostrar el mensaje textual correspondiente, y SHALL
auto-dismissarse a los **16000 ms** sin intervención del operador.

#### Scenario: Eliminar usuario con éxito muestra toast verde

- **WHEN** el operador confirma la eliminación de un usuario y la respuesta
  HTTP es `2xx`
- **THEN** el sistema muestra un toast verde con el texto
  "Usuario eliminado correctamente" durante 16000 ms

#### Scenario: Eliminar usuario con error muestra toast rojo

- **WHEN** el operador confirma la eliminación de un usuario y la respuesta
  HTTP NO es `2xx`
- **THEN** el sistema muestra un toast rojo con el texto del error
  reportado por el servidor (o "Error desconocido" si no hay detalle)
  durante 16000 ms

#### Scenario: Crear usuario con error de validación muestra toast rojo

- **WHEN** el operador envía el formulario de creación y el servidor
  devuelve error de validación
- **THEN** el sistema muestra un toast rojo con el mensaje de error
  durante 16000 ms

#### Scenario: Editar usuario con error muestra toast rojo

- **WHEN** el operador envía el formulario de edición y la respuesta
  HTTP NO es `2xx`
- **THEN** el sistema muestra un toast rojo con el detalle del error
  durante 16000 ms

### Requirement: El toast no bloquea el hilo de JavaScript

El sistema SHALL usar Alpine + `setTimeout` para el auto-dismiss del
toast. La notificación SHALL desaparecer automáticamente al cumplir los
16000 ms, y SHALL NO requerir ninguna acción del operador para cerrarla
(no usar `alert()` ni `confirm()` del navegador).

#### Scenario: El operador puede seguir interactuando mientras el toast está visible

- **WHEN** se muestra un toast tras una operación exitosa
- **THEN** el operador puede seguir haciendo scroll, abrir modales y
  operar la pantalla sin necesidad de cerrar el toast manualmente
