## ADDED Requirements

### Requirement: Barra de acciones flotante al seleccionar
El sistema SHALL mostrar una barra de acciones en la parte superior del área de archivos cuando haya al menos un elemento en `selectedFiles`, con contador y botón de descarga masiva.

#### Scenario: Aparición de la barra
- **WHEN** el usuario selecciona el primer archivo
- **THEN** aparece la barra mostrando "1 elemento seleccionado" y el botón "Descargar ZIP"

#### Scenario: Contador actualizado
- **WHEN** el usuario añade o quita archivos de la selección
- **THEN** el contador en la barra se actualiza en tiempo real ("3 elementos seleccionados")

#### Scenario: Desaparición de la barra
- **WHEN** `selectedFiles` queda vacío (por click en fondo, deselección manual, o botón "Limpiar")
- **THEN** la barra desaparece

#### Scenario: Botón "Limpiar selección"
- **WHEN** el usuario hace click en el botón "✕ Limpiar" de la barra
- **THEN** `selectedFiles` queda vacío y la barra desaparece

### Requirement: Descarga masiva como un único ZIP
El sistema SHALL generar un único archivo ZIP que contenga todos los elementos seleccionados cuando el usuario hace click en "Descargar ZIP" de la barra de acciones.

#### Scenario: Descarga de archivos sueltos seleccionados
- **WHEN** el usuario tiene N archivos (no carpetas) seleccionados y hace click en "Descargar ZIP"
- **THEN** se descarga un ZIP llamado `descarga.zip` con todos los archivos en la raíz del ZIP

#### Scenario: Descarga con carpetas seleccionadas
- **WHEN** la selección incluye una o más carpetas
- **THEN** cada carpeta aparece como subdirectorio en el ZIP con toda su jerarquía interna preservada

#### Scenario: Descarga mixta (archivos y carpetas)
- **WHEN** la selección mezcla archivos sueltos y carpetas
- **THEN** los archivos sueltos van a la raíz del ZIP y cada carpeta como su propio subdirectorio

#### Scenario: Conflicto de nombres en raíz del ZIP
- **WHEN** dos archivos seleccionados tienen el mismo nombre
- **THEN** el segundo se renombra añadiendo sufijo numérico (ej. `video_2.mp4`) sin error

#### Scenario: Tamaño total excede 2 GB
- **WHEN** el tamaño acumulado de los elementos seleccionados supera 2 GB
- **THEN** el sistema muestra un toast de error con el tamaño total y no inicia la descarga

#### Scenario: Sin permisos de lectura en algún elemento
- **WHEN** el usuario no tiene permiso `read` sobre algún elemento seleccionado
- **THEN** el endpoint devuelve 403 y el frontend muestra un toast de error

### Requirement: Endpoint `POST /files/download-multi`
El sistema SHALL exponer un endpoint que acepte un array de IDs de archivos/carpetas y devuelva un ZIP.

#### Scenario: Request válido
- **WHEN** se hace `POST /files/download-multi` con `{ ids: [1, 2, 3] }` y el usuario tiene sesión activa con permisos de lectura
- **THEN** el servidor responde con un ZIP descargable con `Content-Type: application/zip`

#### Scenario: Array vacío
- **WHEN** se envía `{ ids: [] }`
- **THEN** el servidor responde con 422 (validación)

#### Scenario: ID inexistente
- **WHEN** algún ID del array no existe en la DB
- **THEN** el servidor responde con 404
