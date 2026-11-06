## Purpose

Define el ciclo de vida de la solicitud de backup de una carpeta-día dentro de Mis Archivos: cómo el cliente la inicia, qué motivo exige, cómo el sistema genera automáticamente el enlace temporal hacia el material de producción, y cómo el cliente consulta y revoca sus solicitudes.

## ADDED Requirements

### Requirement: La solicitud exige un motivo

La interfaz SHALL exigir que el solicitante describa el motivo de la solicitud antes de confirmarla. El motivo SHALL tener una longitud mínima y el sistema SHALL NOT crear la solicitud si el motivo está vacío o es más corto que ese mínimo.

#### Scenario: Motivo válido

- **WHEN** el cliente escribe un motivo de al menos la longitud mínima y confirma
- **THEN** el sistema SHALL crear la solicitud
- **AND** SHALL persistir el motivo tal como fue escrito

#### Scenario: Motivo ausente

- **WHEN** el cliente confirma sin escribir motivo
- **THEN** el sistema SHALL NOT crear ninguna solicitud ni ningún enlace
- **AND** la interfaz SHALL informar que el motivo es obligatorio

#### Scenario: Motivo demasiado corto

- **WHEN** el cliente confirma con un motivo más corto que la longitud mínima
- **THEN** el sistema SHALL NOT crear ninguna solicitud ni ningún enlace
- **AND** la interfaz SHALL informar el mínimo requerido

### Requirement: El enlace se genera automáticamente al confirmar

Al confirmar una solicitud válida, el sistema SHALL resolver la carpeta-día en el árbol de backup, crear un enlace público de solo lectura sobre esa carpeta y registrar la solicitud, todo en la misma operación y sin intervención de un administrador. El sistema SHALL NOT copiar ni mover ningún archivo.

#### Scenario: Solicitud atendida

- **WHEN** el cliente solicita el backup de una carpeta-día disponible y el motivo es válido
- **THEN** el sistema SHALL crear un enlace público de solo lectura sobre la carpeta-día del backup
- **AND** SHALL registrar la solicitud con el estado `ready`
- **AND** SHALL devolver la URL pública del enlace

#### Scenario: Backup no disponible para ese día

- **WHEN** el cliente solicita el backup de una carpeta-día que no existe en el árbol de backup
- **THEN** el sistema SHALL registrar la solicitud con el estado `unavailable`
- **AND** SHALL NOT crear ningún enlace
- **AND** SHALL indicar por qué no hay backup de ese día

#### Scenario: No se toca el material físico

- **WHEN** el sistema atiende una solicitud
- **THEN** NO SHALL crear, mover, copiar ni eliminar ningún archivo en el árbol de backup
- **AND** SHALL NOT eliminar ni reescribir filas del catálogo de archivos

### Requirement: El enlace es de solo lectura y de vigencia acotada

El enlace generado SHALL tener permiso de solo lectura y SHALL expirar en la vigencia configurada, que por defecto SHALL ser de dos días a partir de su creación. El cliente SHALL NOT poder ampliar la vigencia ni convertir el enlace en permanente desde la interfaz de solicitud.

#### Scenario: Vigencia por defecto

- **WHEN** se crea un enlace de backup
- **THEN** su fecha de expiración SHALL ser dos días después de su creación
- **AND** su permiso SHALL ser solo lectura

#### Scenario: El enlace expira

- **WHEN** se cumple la fecha de expiración del enlace
- **THEN** el acceso público al enlace SHALL responder que está expirado
- **AND** la solicitud SHALL pasar al estado `expired`

#### Scenario: El cliente no puede pedir enlace permanente

- **WHEN** el cliente envía la solicitud
- **THEN** el sistema SHALL ignorar cualquier bandera de expiración permanente o de expiración personalizada en la entrada
- **AND** SHALL aplicar siempre la vigencia configurada

### Requirement: El enlace de backup lo administra Mis Archivos, no el módulo de compartidos

La vigencia y el alcance de un enlace de backup son parte del contrato de la solicitud, no una opción de quien lo posee. El módulo de compartidos SHALL NOT editar ni eliminar un enlace que pertenezca a una solicitud de backup, y la depuración masiva SHALL excluirlo y reportarlo aparte. La única vía para desactivarlo SHALL ser revocar la solicitud.

#### Scenario: El propietario intenta hacer permanente el enlace

- **WHEN** el solicitante de un enlace de backup intenta cambiar su fecha de vencimiento para que no expire
- **THEN** el módulo de compartidos SHALL rechazarlo con un error de autorización
- **AND** la fecha de vencimiento del enlace SHALL permanecer sin cambios

#### Scenario: El propietario intenta borrar el enlace

- **WHEN** el solicitante de un enlace de backup intenta eliminarlo desde el módulo de compartidos
- **THEN** el módulo de compartidos SHALL rechazarlo con un error de autorización
- **AND** el enlace SHALL seguir existiendo

#### Scenario: La purga masiva alcanza un enlace de backup

- **WHEN** una depuración masiva incluye un enlace que pertenece a una solicitud de backup
- **THEN** el enlace SHALL NOT ser eliminado
- **AND** la respuesta SHALL reportar el enlace como excluido

### Requirement: Solo quien puede leer el origen puede solicitar

El sistema SHALL autorizar la creación de una solicitud a los usuarios que tengan permiso de lectura sobre el storage de origen de la carpeta-día. La creación del enlace SHALL ser en nombre del solicitante, de modo que la solicitud y el enlace queden asociados a su usuario. El sistema SHALL NOT usar el enlace para conceder al cliente acceso de lectura al árbol de backup dentro de Mis Archivos.

#### Scenario: Usuario con permiso de lectura

- **WHEN** un usuario con permiso de lectura sobre el storage de origen solicita un backup
- **THEN** el sistema SHALL crear la solicitud y el enlace a su nombre
- **AND** el enlace SHALL quedar registrado en su historial de solicitudes

#### Scenario: Usuario sin permiso

- **WHEN** un usuario sin permiso de lectura sobre el storage de origen intenta crear una solicitud
- **THEN** el sistema SHALL rechazarla
- **AND** SHALL NOT crear ningún enlace

#### Scenario: El cliente sigue sin acceso al árbol de backup

- **WHEN** un cliente tiene una solicitud de backup atendida
- **THEN** el sistema SHALL NOT agregarle permisos sobre el storage de backup
- **AND** el árbol de backup SHALL NOT aparecer en su listado de storages de Mis Archivos

#### Scenario: El storage de origen es el propio árbol de backup

- **WHEN** el storage de origen de la carpeta declara como destino de backup a sí mismo
- **THEN** el sistema SHALL rechazar la solicitud
- **AND** SHALL NOT crear ningún enlace

### Requirement: Un único enlace vivo por usuario, canal y día

El sistema SHALL mantener como máximo una solicitud viva —con enlace activo— por usuario, storage de origen y día. Dos solicitudes simultáneas del mismo día SHALL NOT producir dos enlaces.

#### Scenario: Dos envíos simultáneos del mismo día

- **WHEN** dos solicitudes del mismo día para el mismo usuario y canal se procesan a la vez
- **THEN** solo una SHALL quedar con enlace
- **AND** la otra SHALL devolver la solicitud que quedó viva

### Requirement: El historial de solicitudes es del solicitante

Mis Archivos SHALL ofrecer una vista de solicitudes de backup en la que el usuario ve únicamente las solicitudes propias, con el storage de origen, la carpeta-día, el motivo, el estado y el enlace cuando exista. Cada enlace disponible SHALL poder abrirse en una pestaña nueva.

#### Scenario: Listado del cliente

- **WHEN** el cliente abre el historial de solicitudes de backup
- **THEN** el sistema SHALL listar únicamente las solicitudes creadas por ese usuario
- **AND** SHALL NOT listar solicitudes de otros usuarios

#### Scenario: Enlace disponible

- **WHEN** el cliente abre el historial y una solicitud está en estado `ready`
- **THEN** la vista SHALL mostrar el enlace y SHALL permitir abrirlo en una pestaña nueva

#### Scenario: Enlace no disponible

- **WHEN** una solicitud está en estado `unavailable`, `expired` o `revoked`
- **THEN** la vista SHALL explicar el motivo
- **AND** SHALL NOT ofrecer un enlace activo

### Requirement: El cliente puede revocar su solicitud

El cliente SHALL poder revocar una solicitud propia que aún no haya expirado. La revocación SHALL invalidar el enlace de forma inmediata.

#### Scenario: Revocación efectiva

- **WHEN** el cliente revoca una solicitud en estado `ready`
- **THEN** el enlace SHALL dejar de servir el material
- **AND** la solicitud SHALL pasar al estado `revoked`

#### Scenario: Revocación de una solicitud ajena

- **WHEN** el cliente intenta revocar una solicitud que no es suya
- **THEN** el sistema SHALL rechazarla
- **AND** la solicitud ajena SHALL permanecer intacta

### Requirement: La solicitud sobrevive a la desaparición del material

La solicitud SHALL persistir aunque la carpeta-día referida desaparezca del árbol de backup. En ese caso el sistema SHALL reportar la solicitud como no disponible en lugar de dejar un enlace que responde con error.

#### Scenario: El material rotó y la carpeta-día ya no está

- **WHEN** la carpeta-día del backup es purgada del árbol de backup porque su ventana de retención venció
- **AND** el cliente consulta su historial
- **THEN** la solicitud SHALL seguir listada
- **AND** SHALL estar en un estado no disponible con la explicación correspondiente
- **AND** el historial SHALL NOT mostrar un enlace roto

#### Scenario: El material del día todavía no ha llegado

- **WHEN** el cliente solicita el backup de la carpeta-día en curso y el árbol de backup todavía no la contiene
- **THEN** la solicitud SHALL estar en estado no disponible
- **AND** la interfaz SHALL informar que el backup de ese día aún no está disponible

### Requirement: Toda solicitud queda auditada

El sistema SHALL registrar en el log de aplicación cada solicitud creada, con el actor, el storage de origen, la carpeta-día, el estado resultante y la fecha de expiración del enlace. El registro SHALL NOT incluir secretos de autenticación del enlace.

#### Scenario: Solicitud atendida auditada

- **WHEN** se crea una solicitud atendida
- **THEN** el log SHALL contener un evento con el actor, la carpeta-día y el estado `ready`

#### Scenario: Solicitud no atendida auditada

- **WHEN** se registra una solicitud no disponible
- **THEN** el log SHALL contener un evento con el actor, la carpeta-día y el estado `unavailable`
