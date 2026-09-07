# share-management Specification

## Purpose
Definir una administración de enlaces compartidos que permita consultar, ordenar, filtrar y depurar grandes conjuntos de shares sin cargar toda la colección en el navegador ni ejecutar borrados no autorizados.

## Requirements

### Requirement: Listado server-side de shares

El sistema SHALL ofrecer un listado paginado de los shares del usuario autenticado. SHALL aceptar filtros combinables por texto de recurso, permiso, estado de expiración, estado de disponibilidad, storage, fecha de creación, fecha de expiración y fecha de último acceso. SHALL aceptar ordenamiento por nombre, fecha de creación, fecha de expiración, accesos, tamaño, **permiso y estado de expiración**, con dirección ascendente o descendente. **El orden por permiso SHALL agrupar por nivel (Lectura → Escritura/Subida → Completo), no por orden alfabético de la cadena almacenada. El orden por estado de expiración SHALL agrupar por Sin vencimiento, Activo y Expirado, no por valor crudo de `expires_at`.**

#### Scenario: Listado inicial acotado
- **WHEN** el usuario abre la vista de compartidos sin filtros
- **THEN** el servidor devuelve una primera página con metadatos de paginación y no serializa `password_hash`

#### Scenario: Filtros combinados
- **WHEN** el usuario combina estado expirado, permiso de lectura y un rango de fecha de creación
- **THEN** el servidor devuelve únicamente shares que cumplen todas las condiciones y los contadores reflejan el resultado filtrado

#### Scenario: Ordenamiento inválido
- **WHEN** la petición solicita un campo de ordenamiento o dirección no permitidos
- **THEN** el servidor rechaza la petición con `422` y no ejecuta una columna arbitraria como SQL

#### Scenario: Alcance por propietario
- **WHEN** un usuario solicita el listado de compartidos
- **THEN** nunca recibe shares creados por otro usuario, salvo una operación explícitamente autorizada para administrador

#### Scenario: Orden por nivel de permiso
- **WHEN** el usuario solicita ordenamiento por `permission` ascendente
- **THEN** el servidor devuelve primero los shares de Lectura, luego Escritura y Subida (mismo nivel) y al final los de Completo

#### Scenario: Orden por estado de expiración
- **WHEN** el usuario solicita ordenamiento por `status` ascendente
- **THEN** el servidor agrupa primero los shares Sin vencimiento, luego los Activos y al final los Expirados

### Requirement: Controles visuales de búsqueda y ordenamiento

La interfaz de compartidos SHALL mostrar búsqueda, filtros rápidos, rango de fechas, control de limpiar filtros y encabezados clickeables para invertir el orden, **incluyendo los encabezados de Permiso y Estado de expiración**. SHALL conservar la selección, el estado de carga y los errores de forma coherente cuando cambia de página o filtro.

#### Scenario: Orden ascendente y descendente
- **WHEN** el usuario hace click en el mismo encabezado dos veces
- **THEN** el primer click ordena ascendentemente y el segundo invierte a descendente con un indicador visual

#### Scenario: Orden por Permiso
- **WHEN** el usuario hace click en el encabezado "Permiso"
- **THEN** el servidor ordena por nivel de permiso y la columna muestra el indicador visual de orden activo

#### Scenario: Orden por Estado
- **WHEN** el usuario hace click en el encabezado "Estado"
- **THEN** el servidor agrupa por Sin vencimiento, Activo y Expirado y la columna muestra el indicador visual de orden activo

#### Scenario: Limpiar filtros
- **WHEN** el usuario pulsa "Limpiar filtros"
- **THEN** se eliminan búsqueda, fechas, estados y permisos, se restablece el orden por defecto y se solicita la primera página

#### Scenario: Error de consulta
- **WHEN** el listado server-side falla
- **THEN** la interfaz muestra un estado de error accionable y no presenta los datos anteriores como si fueran actuales

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
