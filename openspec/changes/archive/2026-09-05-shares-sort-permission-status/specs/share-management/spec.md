## MODIFIED Requirements

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
