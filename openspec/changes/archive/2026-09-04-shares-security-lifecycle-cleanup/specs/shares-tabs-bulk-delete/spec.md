## MODIFIED Requirements

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
