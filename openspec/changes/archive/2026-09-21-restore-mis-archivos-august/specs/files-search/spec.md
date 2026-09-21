## MODIFIED Requirements

### Requirement: Cuadro de búsqueda en el módulo de archivos
El módulo de archivos SHALL incluir un cuadro de búsqueda visible en la barra de herramientas, dentro del área de contenido, que permita buscar archivos y carpetas por nombre dentro del storage activo.

(Comportamiento idéntico al baseline — la búsqueda sigue siendo por nombre dentro del `storage_id` activo. La diferencia con septiembre: NO hay búsqueda cross-storage implícita vía mirror rows. Si un archivo vive en storage 7, no aparece al buscar desde storage 5.)

#### Scenario: Búsqueda por nombre devuelve resultados coincidentes
- **WHEN** el usuario escribe al menos 2 caracteres en el cuadro de búsqueda del módulo de archivos
- **THEN** el sistema realiza una consulta al backend después de 350ms de inactividad del teclado
- **THEN** se muestran todos los archivos y carpetas del storage activo cuyo nombre contiene el texto ingresado (insensible a mayúsculas)

#### Scenario: La búsqueda es global dentro del storage activo
- **WHEN** el usuario realiza una búsqueda con el storage activo seleccionado
- **THEN** los resultados incluyen archivos de cualquier carpeta del storage, no solo de la carpeta actual

#### Scenario: Indicador visual de modo búsqueda
- **WHEN** hay una búsqueda activa con resultados
- **THEN** la vista muestra un indicador o banner que indica que se está en modo búsqueda
- **THEN** los breadcrumbs normales de carpeta se reemplazan o complementan con el indicador de búsqueda

#### Scenario: Sin resultados para la búsqueda
- **WHEN** la búsqueda no devuelve ningún resultado
- **THEN** se muestra un mensaje "No se encontraron archivos" en lugar de la lista vacía

#### Scenario: Limpiar búsqueda restaura la vista de carpeta
- **WHEN** el usuario borra el texto del cuadro de búsqueda o hace clic en el botón de limpiar
- **THEN** se vuelve a mostrar el contenido de la carpeta que estaba activa antes de buscar
- **THEN** los breadcrumbs vuelven a su estado normal

#### Scenario: Búsqueda requiere storage activo
- **WHEN** el usuario intenta buscar sin haber seleccionado un storage
- **THEN** el cuadro de búsqueda no envía la consulta al backend (o se muestra un mensaje indicando que se debe seleccionar un storage primero)

### Requirement: Eliminación del buscador decorativo del navbar
El input de búsqueda decorativo que aparece en el header global SHALL ser eliminado de la interfaz.

#### Scenario: El navbar no muestra el input de búsqueda
- **WHEN** el usuario navega a cualquier página de la aplicación
- **THEN** el header global no muestra ningún cuadro de búsqueda

### Requirement: Backend acepta parámetro de búsqueda en el endpoint de archivos
El endpoint `GET /files` SHALL aceptar el parámetro opcional `q` para filtrar resultados por nombre dentro del storage indicado.

(Previamente: el backend podía devolver archivos de storages relacionados vía `resolveListingTargets`. Ahora: la búsqueda es estrictamente `WHERE storage_provider_id = $storageId AND name ILIKE %q%`, sin merges cross-storage.)

#### Scenario: Filtrado por nombre en la respuesta del API
- **WHEN** se realiza `GET /files?q=foto&storage_id=1`
- **THEN** el backend responde con los archivos y carpetas del storage 1 cuyo nombre contiene "foto" (búsqueda insensible a mayúsculas)
- **THEN** se ignora el parámetro `parent_id` cuando `q` está presente (búsqueda cross-folder DENTRO del storage)

#### Scenario: Las restricciones de permisos se respetan en la búsqueda
- **WHEN** un usuario no administrador realiza una búsqueda
- **THEN** solo se devuelven archivos de storages a los que el usuario tiene acceso asignado

#### Scenario: Búsqueda no cruza storages
- **WHEN** el usuario busca desde storage 5 con `q=nota`
- **THEN** el backend NO consulta storage 7 (sub-storage) aunque `parent_id` o el storage_id del folder estén allí
- **AND** solo retorna filas con `storage_provider_id = 5`
