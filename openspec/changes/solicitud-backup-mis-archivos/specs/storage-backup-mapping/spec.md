## Purpose

Define cómo un storage de origen de Mis Archivos declara cuál es su carpeta equivalente en el árbol de backup de producción, cómo se resuelve la carpeta-día a partir de esa declaración y bajo qué condiciones la acción de solicitar backup queda habilitada.

## ADDED Requirements

### Requirement: El vínculo con el backup se declara en el storage de origen

Un storage de origen SHALL poder declarar su carpeta de backup mediante un identificador de storage de backup y un nombre de carpeta, ambos opcionales. El vínculo SHALLNOT deducirse de nombres: el sistema NO SHALL intentar derivar la carpeta de backup a partir del nombre del storage de origen ni del nombre de la carpeta en disco.

#### Scenario: Storage con backup mapeado

- **WHEN** un storage de origen tiene declarado un storage de backup y un nombre de carpeta de backup
- **THEN** el sistema SHALL considerar que ese storage tiene un respaldo disponible
- **AND** el mapeo SHALL ser consultable de forma programática por los demás componentes

#### Scenario: Storage sin mapeo

- **WHEN** un storage de origen no tiene storage de backup ni nombre de carpeta de backup declarados
- **THEN** el sistema SHALL tratarlo como un storage sin respaldo
- **AND** NO SHALL ofrecer la acción de solicitar backup sobre sus carpetas

#### Scenario: Mapeo declarado contra un storage inexistente

- **WHEN** un storage de origen declara un storage de backup que no existe
- **THEN** el sistema SHALL tratarlo como un storage sin respaldo
- **AND** NO SHALL lanzar una excepción al resolver el mapeo

#### Scenario: Mapeo declarado contra un storage que no es local

- **WHEN** un storage de origen declara un storage de backup cuyo tipo no es local
- **THEN** el sistema SHALL tratarlo como un storage sin respaldo
- **AND** SHALL NOT intentar leer el sistema de archivos remoto

### Requirement: El nombre de carpeta-día se valida como fecha

El sistema SHALL considerar una carpeta como carpeta-día únicamente si su nombre tiene exactamente ocho dígitos, los dos primeros corresponden a un día entre 01 y 31, los dos siguientes a un mes entre 01 y 12 y los dos últimos a un año de cuatro dígitos. Una carpeta cuyo nombre NO cumpla esa forma SHALL NOT ser elegible para solicitar backup.

#### Scenario: Carpeta con nombre de día válido

- **WHEN** una carpeta se llama `05112026`
- **THEN** el sistema SHALL considerarla una carpeta-día
- **AND** SHALL interpretarla como el 5 de noviembre de 2026

#### Scenario: Carpeta con nombre que no es fecha

- **WHEN** una carpeta se llama `caracol_05112026_000201` o `Impresos`
- **THEN** el sistema SHALL NOT considerarla una carpeta-día

#### Scenario: Carpeta con fecha imposible

- **WHEN** una carpeta se llama `32132026`
- **THEN** el sistema SHALL NOT considerarla una carpeta-día

#### Scenario: Carpeta con año lejano

- **WHEN** una carpeta se llama `20042023`
- **THEN** el sistema SHALL considerarla una carpeta-día por forma
- **AND** la disponibilidad del backup para ese día SHALL resolverse aparte, por existencia en el árbol de backup

### Requirement: La carpeta-día del backup se resuelve por declaración, no por búsqueda

Dado un storage de origen con backup mapeado y un nombre de carpeta-día, el sistema SHALL resolver la carpeta-día correspondiente dentro del árbol de backup usando el nombre de carpeta declarado y el nombre de la carpeta-día. La resolución NO SHALL explorar ni adivinar carpetas del árbol de backup.

#### Scenario: Resolución con carpeta-día presente

- **WHEN** el storage `01 Caracol Tv` declara el storage de backup `30 Television Bk` con la carpeta `01_Caracol_BK`
- **AND** el usuario navega la carpeta-día `05112026`
- **THEN** el sistema SHALL resolver la carpeta `01_Caracol_BK/05112026` dentro del storage de backup
- **AND** SHALL NOT buscar coincidencias por similitud de nombre en el árbol de backup

#### Scenario: Resolución con carpeta-día ausente del backup

- **WHEN** el storage de origen declara la carpeta `01_Caracol_BK`
- **AND** el backup no contiene la carpeta-día `20092026`
- **THEN** el sistema SHALL reportar el backup de ese día como no disponible
- **AND** SHALL NOT crear ninguna fila de catálogo ni ningún enlace

#### Scenario: La ruta resuelta se mantiene dentro del storage de backup

- **WHEN** el sistema resuelve la carpeta-día del backup
- **THEN** la ruta absoluta resultante SHALL estar contenida dentro del directorio base del storage de backup
- **AND** si la contención no se cumple, el sistema SHALL reportar el backup como no disponible

### Requirement: La acción de solicitar backup solo se habilita con mapeo y carpeta-día

La interfaz de Mis Archivos SHALL habilitar la acción de solicitar backup únicamente sobre carpetas-día cuyo storage de origen tenga un backup mapeado. La interfaz SHALL NOT habilitar la acción sobre carpetas-día sin mapeo, sobre carpetas que no sean carpeta-día, ni sobre archivos.

#### Scenario: Acción habilitada

- **WHEN** el usuario navega el storage `01 Caracol Tv` con backup mapeado
- **AND** ve la carpeta `05112026`
- **THEN** la acción de solicitar backup SHALL estar habilitada sobre esa fila

#### Scenario: Acción deshabilitada por falta de mapeo

- **WHEN** el usuario navega un storage sin backup mapeado
- **AND** ve una carpeta con nombre de día válido
- **THEN** la acción de solicitar backup SHALL NOT estar habilitada

#### Scenario: Acción deshabilitada sobre archivos

- **WHEN** el usuario ve un archivo
- **THEN** la acción de solicitar backup SHALL NOT estar habilitada

#### Scenario: La habilitación no depende de los permisos del cliente sobre el storage de backup

- **WHEN** el cliente no tiene ninguna asignación sobre el storage de backup
- **AND** navega una carpeta-día de un storage de origen con backup mapeado
- **THEN** la acción de solicitar backup SHALL estar habilitada
- **AND** el cliente SHALL NOT tener acceso de lectura al árbol de backup dentro de Mis Archivos
