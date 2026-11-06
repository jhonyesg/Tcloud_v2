## MODIFIED Requirements

### Requirement: Listado server-side de shares

El sistema SHALL ofrecer un listado paginado de los shares del usuario autenticado. SHALL aceptar filtros combinables por texto de recurso, permiso, estado de expiración, estado de disponibilidad, storage, fecha de creación, fecha de expiración y fecha de último acceso. SHALL aceptar ordenamiento por nombre, fecha de creación, fecha de expiración, accesos, tamaño, **permiso y estado de expiración**, con dirección ascendente o descendente. **El orden por permiso SHALL agrupar por nivel (Lectura → Escritura/Subida → Completo), no por orden alfabético de la cadena almacenada. El orden por estado de expiración SHALL agrupar por Sin vencimiento, Activo y Expirado, no por valor crudo de `expires_at`.**

El servidor SHALL reconocer un vocabulario cerrado y explícito de parámetros de filtro, de modo que ningún filtro enviado por la interfaz quede sin efecto. El estado de expiración sin vencimiento SHALL ser seleccionable con el valor `never`; el estado de expiración SHALL ser seleccionable con `active` y `expired`; la disponibilidad SHALL ser seleccionable con `available`, `missing` y `unknown`; los rangos de fechas SHALL ser seleccionables con `created_from`, `created_to`, `expires_from` y `expires_to`. Un parámetro de filtro con valor no reconocido SHALL ser rechazado, no ignorado.

El listado SHALL entregar un contrato de respuesta estable consumible por la interfaz: cada share SHALL incluir `id`, `token`, `public_url`, `permissions`, `expires_at`, `created_at`, `is_expired`, `expiry_status` con valores `never`, `active` o `expired`, `has_password` y `access_logs_count`; el recurso asociado SHALL incluir `id`, `name`, `path`, `size`, `mime_type`, `is_folder`, `storage_provider_id` y `availability_state` con valores `available`, `missing` o `unknown`. El servidor SHALL calcular los contadores de administración con consultas agregadas y SHALL NOT hidratar la colección completa de shares para contarlos.

#### Scenario: Listado inicial acotado

- **WHEN** el usuario abre la vista de compartidos sin filtros
- **THEN** el servidor devuelve una primera página con metadatos de paginación y no serializa `password_hash`

#### Scenario: Filtros combinados

- **WHEN** el usuario combina estado expirado, permiso de lectura y un rango de fecha de creación
- **THEN** el servidor devuelve únicamente shares que cumplen todas las condiciones y los contadores reflejan el resultado filtrado

#### Scenario: Filtro de sin vencimiento

- **WHEN** el usuario selecciona el estado sin vencimiento
- **THEN** el servidor devuelve únicamente shares sin fecha de expiración
- **AND** SHALL NOT devolver shares con fecha de expiración presente

#### Scenario: Filtro de disponibilidad

- **WHEN** el usuario selecciona la disponibilidad `missing`
- **THEN** el servidor devuelve únicamente shares cuyo recurso está confirmado como ausente
- **AND** SHALL NOT incluir shares con recurso en estado `unknown`

#### Scenario: Rango de fechas de expiración

- **WHEN** el usuario define un rango de fechas de expiración
- **THEN** el servidor devuelve únicamente shares cuya fecha de expiración cae dentro del rango
- **AND** los enlaces sin vencimiento SHALL quedar fuera del resultado

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

#### Scenario: Cada share expone su estado de expiración

- **WHEN** el servidor devuelve la página de shares
- **THEN** cada share incluye `expiry_status` con valor `never`, `active` o `expired` coherente con su `expires_at`
- **AND** ningún share aparece como "no verificado" por falta de ese campo

#### Scenario: Contadores de administración consistentes

- **WHEN** el servidor devuelve la página de shares
- **THEN** los contadores `total`, `expired`, `permanent`, `missing` y `unknown` reflejan el conjunto del usuario y el estado de expiración y de disponibilidad de cada recurso
- **AND** el contador `missing` cuenta recursos confirmados ausentes, no shares sin recurso asociado

### Requirement: Previsualización de depuración bulk

El sistema SHALL permitir previsualizar una operación bulk usando IDs seleccionados o el conjunto definido por los filtros actuales. La previsualización SHALL devolver la cantidad afectada y un resumen por estado, permiso y disponibilidad sin modificar datos. **Cuando el alcance son todos los resultados filtrados, la operación SHALL respetar el orden visible (`sort` + `direction`) del usuario y SHALL poder paginarse usando un cursor estable por id sin perder ni duplicar filas.**

La previsualización SHALL estar disponible como endpoint propio y SHALL responder con éxito ante cualquier solicitud válida del usuario autenticado. La respuesta SHALL exponer la forma `count` y `summary`, y `summary` SHALL contener las claves `expired`, `permanent`, `missing`, `unknown` y `permissions`. Un parámetro de filtro o de alcance no reconocido SHALL ser rechazado con error de validación, no con error de servidor.

#### Scenario: Previsualización de expirados

- **WHEN** el usuario solicita previsualizar la eliminación de todos los shares expirados de su listado
- **THEN** recibe el total y el desglose de candidatos, y la base de datos permanece sin cambios

#### Scenario: Filtros sin coincidencias

- **WHEN** una previsualización no encuentra shares autorizados
- **THEN** devuelve cantidad cero y la interfaz deshabilita la confirmación de borrado

#### Scenario: El resumen distingue las dimensiones del estado

- **WHEN** el usuario previsualiza un conjunto que mezcla estados y permisos
- **THEN** la respuesta desglosa expirados, sin vencimiento, no disponibles, no verificados y la distribución por permiso

### Requirement: Depuración bulk autorizada

El sistema SHALL ejecutar la eliminación bulk mediante una operación server-side que vuelva a aplicar el alcance del usuario y los filtros recibidos. SHALL eliminar únicamente shares y sus logs asociados; nunca SHALL eliminar el `File`, su archivo físico ni transcripciones. La respuesta SHALL incluir cantidades eliminadas, omitidas y fallidas.

La operación SHALL estar disponible como endpoint propio y SHALL responder con éxito ante cualquier solicitud válida del usuario autenticado. La respuesta SHALL exponer las formas `deleted_count`, `omitted_count` y `failed_count`, además de los identificadores omitidos. Cuando el conjunto a eliminar haya cambiado entre la previsualización y la confirmación, el servidor SHALL rechazar la operación con conflicto y SHALL NOT eliminar nada.

#### Scenario: Eliminación de varios shares

- **WHEN** el usuario confirma una previsualización con varios enlaces autorizados
- **THEN** los enlaces quedan revocados, sus logs se eliminan por la relación existente y la respuesta informa el total eliminado

#### Scenario: Share fuera del alcance

- **WHEN** el payload incluye un ID que no pertenece al usuario
- **THEN** ese ID no se elimina y la respuesta lo reporta como omitido o no autorizado

#### Scenario: Falla parcial

- **WHEN** una parte de la operación bulk no puede completarse
- **THEN** el servidor devuelve un resumen explícito y la interfaz no elimina visualmente los elementos cuya eliminación no fue confirmada

#### Scenario: La revocación invalida el acceso público

- **WHEN** el usuario revoca un enlace, individual o en bloque
- **THEN** el token deja de resolver y una petición pública posterior recibe `404`
- **AND** el recurso del enlace nunca se elimina del disco

### Requirement: Verificación bulk completa de disponibilidad

El sistema SHALL permitir al usuario verificar la disponibilidad de los archivos asociados a sus shares sobre el conjunto definido por los filtros actuales. SHALL recorrer el conjunto completo en lotes server-side, **garantizando que cada share que cumple los filtros es verificado exactamente una vez**. SHALL devolver un cursor estable (`next_cursor`) y un indicador (`has_more`) que permita al cliente iterar sin heurísticas. **Cuando el usuario ha definido un orden visible (sort + direction), SHALL preservarlo al paginar.**

La verificación SHALL estar disponible como endpoint propio y SHALL responder con éxito ante cualquier solicitud válida del usuario autenticado. La respuesta SHALL exponer las formas `checked`, `available`, `missing`, `unknown`, `has_more` y `next_cursor`, de modo que la suma de `checked` al terminar sea igual al total del conjunto filtrado.

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

## ADDED Requirements

### Requirement: Contrato de respuesta de los endpoints de depuración

Los endpoints de previsualización, depuración y verificación de compartidos SHALL exponer una forma de respuesta estable que la interfaz pueda consumir sin transformaciones ad-hoc. La previsualización SHALL exponer `count` y `summary`; la depuración SHALL exponer `deleted_count`, `omitted_count` y `failed_count`; la verificación SHALL exponer `checked`, `available`, `missing`, `unknown`, `has_more` y `next_cursor`. Ninguno de estos endpoints SHALL responder con error de servidor ante una solicitud válida de un usuario autenticado.

#### Scenario: La interfaz consume la forma publicada

- **WHEN** la interfaz lee la respuesta de cualquiera de los tres endpoints
- **THEN** las claves que consume existen en la respuesta
- **AND** la ausencia de una clave no se manifiesta como un valor indefinido en pantalla

#### Scenario: Solicitud válida nunca degrada a error de servidor

- **WHEN** un usuario autenticado envía a cualquiera de los tres endpoints una solicitud que cumple el contrato
- **THEN** el servidor responde con un código de éxito o un error de validación o autorización
- **AND** SHALL NOT responder con error de servidor
