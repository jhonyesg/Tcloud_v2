## MODIFIED Requirements

### Requirement: Previsualización de depuración bulk

El sistema SHALL permitir previsualizar una operación bulk usando IDs seleccionados o el conjunto definido por los filtros actuales. La previsualización SHALL devolver la cantidad afectada y un resumen por estado, permiso y disponibilidad sin modificar datos. **Cuando el alcance son todos los resultados filtrados, la operación SHALL respetar el orden visible (`sort` + `direction`) del usuario y SHALL poder paginarse usando un cursor estable por id sin perder ni duplicar filas.**

#### Scenario: Previsualización de expirados
- **WHEN** el usuario solicita previsualizar la eliminación de todos los shares expirados de su listado
- **THEN** recibe el total y el desglose de candidatos, y la base de datos permanece sin cambios

#### Scenario: Filtros sin coincidencias
- **WHEN** una previsualización no encuentra shares autorizados
- **THEN** devuelve cantidad cero y la interfaz deshabilita la confirmación de borrado

### Requirement: Depuración bulk autorizada

El sistema SHALL ejecutar la eliminación bulk mediante una operación server-side que vuelva a aplicar el alcance del usuario y los filtros recibidos. SHALL eliminar únicamente shares y sus logs asociados; nunca SHALL eliminar el `File`, su archivo físico ni transcripciones. La respuesta SHALL incluir cantidades eliminadas, omitidas y fallidas.

#### Scenario: Eliminación de varios shares
- **WHEN** el usuario confirma una previsualización con varios enlaces autorizados
- **THEN** los enlaces quedan revocados, sus logs se eliminan por la relación existente y la respuesta informa el total eliminado

#### Scenario: Share fuera del alcance
- **WHEN** el payload incluye un ID que no pertenece al usuario
- **THEN** ese ID no se elimina y la respuesta lo reporta como omitido o no autorizado

#### Scenario: Falla parcial
- **WHEN** una parte de la operación bulk no puede completarse
- **THEN** el servidor devuelve un resumen explícito y la interfaz no elimina visualmente los elementos cuya eliminación no fue confirmada

## ADDED Requirements

### Requirement: Verificación bulk completa de disponibilidad

El sistema SHALL permitir al usuario verificar la disponibilidad de los archivos asociados a sus shares sobre el conjunto definido por los filtros actuales. SHALL recorrer el conjunto completo en lotes server-side, **garantizando que cada share que cumple los filtros es verificado exactamente una vez**. SHALL devolver un cursor estable (`next_cursor`) y un indicador (`has_more`) que permita al cliente iterar sin heurísticas. **Cuando el usuario ha definido un orden visible (sort + direction), SHALL preservarlo al paginar.**

#### Scenario: Verificación completa sobre filtro con más resultados que un lote
- **WHEN** el usuario pulsa "Verificar disponibilidad" sobre un conjunto filtrado mayor que el batch size
- **THEN** el cliente itera hasta que `has_more === false` y la suma de `checked` es igual al total filtrado

#### Scenario: Último lote menor que batch size por efecto del filtro
- **WHEN** el último lote devuelve menos filas que el batch size porque el filtro acota el resultado (no porque se acabó el dataset)
- **THEN** el cliente continúa usando `next_cursor` hasta recibir `has_more === false`

#### Scenario: Orden visible preservado durante la verificación
- **WHEN** el usuario tiene un orden visible definido (por ejemplo `permission asc`) y dispara la verificación bulk
- **THEN** la query server-side respeta ese orden y los IDs procesados avanzan monotónicamente por id como tiebreaker

#### Scenario: Cancelación implícita por cambio de filtro
- **WHEN** el usuario aplica un nuevo filtro o limpia los filtros durante un barrido en curso
- **THEN** el siguiente batch que retorne el cliente pertenece al nuevo conjunto filtrado y la cuenta total reinicia desde cero
