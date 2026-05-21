## ADDED Requirements

### Requirement: Botón para subir carpeta en el modal de upload
El sistema SHALL mostrar un botón "Subir carpeta" en el modal de upload (además del botón "Seleccionar" de archivos individuales) que abre un selector de carpeta usando `<input webkitdirectory>`.

#### Scenario: Click en "Subir carpeta"
- **WHEN** el usuario hace click en el botón "Subir carpeta" dentro del modal de upload
- **THEN** el sistema abre el diálogo del sistema operativo para seleccionar una carpeta

#### Scenario: Carpeta seleccionada con archivos
- **WHEN** el usuario selecciona una carpeta con el selector
- **THEN** el modal muestra el progreso de creación de la estructura de carpetas y subida de cada archivo, preservando la jerarquía original

### Requirement: Creación de estructura de carpetas antes de la subida
El sistema SHALL crear todas las carpetas necesarias en la DB (vía `POST /files` con `is_folder=true`) en orden de profundidad (padres antes que hijos) antes de subir cualquier archivo.

#### Scenario: Carpeta con sub-carpetas anidadas
- **WHEN** la carpeta seleccionada contiene sub-carpetas a cualquier nivel de profundidad
- **THEN** el sistema crea primero las carpetas de nivel superior y luego las anidadas, asignando a cada archivo su `parent_id` correcto

#### Scenario: Carpeta o nombre ya existente (conflicto 409)
- **WHEN** al crear una carpeta el servidor responde con 409 (ya existe)
- **THEN** el sistema usa el ID de la carpeta existente y continúa la subida sin error

### Requirement: Drag & drop de carpetas sobre el área principal
El sistema SHALL detectar cuando el usuario arrastra una carpeta (no solo archivos) sobre el área de drop y procesarla con la FileSystemEntry API para leer su contenido recursivamente.

#### Scenario: Arrastrar una carpeta sobre el panel de archivos
- **WHEN** el usuario arrastra una carpeta al área de drop dentro de un storage con permisos de escritura
- **THEN** el sistema lee todos los archivos de la carpeta (y sub-carpetas) recursivamente y los sube preservando la estructura jerárquica

#### Scenario: Arrastrar mezcla de archivos y carpetas
- **WHEN** el usuario arrastra una selección que contiene archivos sueltos y carpetas
- **THEN** los archivos sueltos se suben directamente al directorio actual y las carpetas se procesan con `uploadFolder()`

### Requirement: Descarga ZIP siempre completa
El sistema SHALL generar el ZIP de una carpeta leyendo directamente el filesystem (sin depender del estado de sincronización de la DB), garantizando que el contenido sea completo independientemente de si las sub-carpetas fueron previamente navegadas.

#### Scenario: Descarga de carpeta con sub-carpetas no navegadas
- **WHEN** el usuario descarga una carpeta cuyas sub-carpetas no han sido navegadas en la sesión
- **THEN** el ZIP descargado contiene todos los archivos y sub-carpetas del disco, no solo los registrados en la DB

#### Scenario: Descarga de carpeta vacía
- **WHEN** el usuario descarga una carpeta que no tiene archivos en disco
- **THEN** el ZIP se descarga con la carpeta raíz vacía (no da error)

#### Scenario: Límite de 2 GB respetado
- **WHEN** la carpeta supera 2 GB en disco
- **THEN** el sistema rechaza la descarga con el mensaje de error de tamaño excedido antes de generar el ZIP
