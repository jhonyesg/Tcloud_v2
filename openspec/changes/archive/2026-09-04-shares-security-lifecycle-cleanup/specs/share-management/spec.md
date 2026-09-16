## Purpose

Definir una administración de enlaces compartidos que permita consultar, ordenar, filtrar y depurar grandes conjuntos de shares sin cargar toda la colección en el navegador ni ejecutar borrados no autorizados.

## ADDED Requirements

### Requirement: Listado server-side de shares

El sistema SHALL ofrecer un listado paginado de los shares del usuario autenticado. SHALL aceptar filtros combinables por texto de recurso, permiso, estado de expiración, estado de disponibilidad, storage, fecha de creación, fecha de expiración y fecha de último acceso. SHALL aceptar ordenamiento por nombre, fecha de creación, fecha de expiración, accesos o tamaño, con dirección ascendente o descendente.

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

### Requirement: Controles visuales de búsqueda y ordenamiento

La interfaz de compartidos SHALL mostrar búsqueda, filtros rápidos, rango de fechas, control de limpiar filtros y encabezados clickeables para invertir el orden. SHALL conservar la selección, el estado de carga y los errores de forma coherente cuando cambia de página o filtro.

#### Scenario: Orden ascendente y descendente
- **WHEN** el usuario hace click en el mismo encabezado dos veces
- **THEN** el primer click ordena ascendentemente y el segundo invierte a descendente con un indicador visual

#### Scenario: Limpiar filtros
- **WHEN** el usuario pulsa "Limpiar filtros"
- **THEN** se eliminan búsqueda, fechas, estados y permisos, se restablece el orden por defecto y se solicita la primera página

#### Scenario: Error de consulta
- **WHEN** el listado server-side falla
- **THEN** la interfaz muestra un estado de error accionable y no presenta los datos anteriores como si fueran actuales

### Requirement: Previsualización de depuración bulk

El sistema SHALL permitir previsualizar una operación bulk usando IDs seleccionados o el conjunto definido por los filtros actuales. La previsualización SHALL devolver la cantidad afectada y un resumen por estado, permiso y disponibilidad sin modificar datos.

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
