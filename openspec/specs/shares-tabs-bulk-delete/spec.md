## Purpose

Definir las pestañas, la selección individual y la operación bulk del panel de compartidos en el detalle de archivo, con foco en la transición hacia una operación bulk server-side autorizada.

## Requirements

### Requirement: Pestañas de permiso en panel de compartidos
El sistema SHALL mostrar pestañas para filtrar los enlaces compartidos por tipo de permiso: Todos, Lectura, Escritura, Subida, Completo. Cada pestaña SHALL mostrar un contador con el número de enlaces de ese tipo. El filtrado SHALL ocurrir en el cliente sin peticiones adicionales al servidor.

#### Scenario: Visualización de pestañas con enlaces mixtos
- **WHEN** el modal de detalle tiene enlaces de distintos permisos
- **THEN** cada pestaña muestra su etiqueta y el conteo correspondiente; la pestaña "Todos" muestra el total

#### Scenario: Cambio de pestaña filtra la lista
- **WHEN** el usuario hace click en la pestaña "Lectura"
- **THEN** solo se muestran los enlaces con `permissions === 'read'`; los demás se ocultan

#### Scenario: Pestaña con cero enlaces
- **WHEN** no hay enlaces de un tipo de permiso
- **THEN** la pestaña muestra contador 0 y al seleccionarla aparece el mensaje "No hay enlaces de este tipo"

#### Scenario: Cambiar de pestaña limpia la selección
- **WHEN** el usuario cambia de pestaña activa
- **THEN** todos los checkboxes se desmarcan y la barra de acciones bulk desaparece

### Requirement: Selección individual de enlaces compartidos
El sistema SHALL mostrar un checkbox en cada enlace compartido visible que permita seleccionarlo para operaciones en bloque.

#### Scenario: Seleccionar un enlace
- **WHEN** el usuario marca el checkbox de un enlace compartido
- **THEN** el enlace queda marcado y aparece la barra de acciones bulk con el conteo de seleccionados

#### Scenario: Deseleccionar un enlace
- **WHEN** el usuario desmarca el checkbox de un enlace ya seleccionado
- **THEN** el enlace se deselecciona; si no queda ninguno seleccionado, la barra de acciones bulk desaparece

### Requirement: Seleccionar todos los enlaces visibles
El sistema SHALL mostrar un checkbox "Seleccionar todos" en la cabecera de la lista que seleccione o deseleccione todos los enlaces visibles en la pestaña activa.

#### Scenario: Marcar "Seleccionar todos"
- **WHEN** el usuario marca el checkbox de cabecera
- **THEN** todos los enlaces visibles en la pestaña activa quedan seleccionados

#### Scenario: Desmarcar "Seleccionar todos"
- **WHEN** el usuario desmarca el checkbox de cabecera estando todos seleccionados
- **THEN** todos los enlaces se deseleccionan

#### Scenario: Estado indeterminado
- **WHEN** hay algunos pero no todos los enlaces seleccionados
- **THEN** el checkbox de cabecera muestra estado visual indeterminado

### Requirement: Eliminación en bloque de enlaces seleccionados

El sistema SHALL permitir eliminar todos los enlaces seleccionados mediante una operación bulk server-side. La interfaz SHALL enviar una única solicitud de operación con los IDs seleccionados o el filtro que representa todos los resultados seleccionados; el servidor SHALL volver a validar propiedad/autorización y eliminar únicamente shares autorizados. No SHALL depender de enviar un `DELETE /shares/{id}` separado por cada elemento.

#### Scenario: Eliminación en bloque exitosa
- **WHEN** el usuario selecciona varios enlaces y confirma "Eliminar seleccionados"
- **THEN** se ejecuta una operación bulk autorizada, los enlaces eliminados desaparecen de la lista y aparece un resumen con el número eliminado

#### Scenario: Loading durante eliminación en bloque
- **WHEN** la operación bulk está en progreso
- **THEN** el botón muestra estado de carga, la selección queda bloqueada y no se puede iniciar otra operación concurrente desde la misma vista

#### Scenario: Fallo parcial en eliminación en bloque
- **WHEN** alguno de los candidatos no existe, no pertenece al usuario o no puede eliminarse
- **THEN** el servidor devuelve eliminados, omitidos y fallidos; la interfaz mantiene visibles los elementos no confirmados como eliminados y muestra el detalle resumido

#### Scenario: Selección de todos los resultados filtrados
- **WHEN** el usuario elige todos los resultados de un filtro paginado
- **THEN** el servidor evalúa el filtro bajo el alcance del usuario y no exige que el navegador envíe todos los IDs de todas las páginas
