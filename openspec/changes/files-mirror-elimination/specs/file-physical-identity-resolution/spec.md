## Purpose

Define cómo el sistema resuelve la identidad física de archivos y carpetas repartidos entre storages padre/hijo **sin materializar filas espejo**: una identidad física corresponde a exactamente una fila canónica en `files`, y el listado, la descarga y los shares resuelven cross-storage en tiempo de lectura comparando paths físicos absolutos.

## ADDED Requirements

### Requirement: Una identidad física corresponde a una sola fila canónica

El sistema SHALL garantizar que para cada identidad física —definida como `lower(rtrim(base_path || '/' || path))`— existe **exactamente una** fila en `files` con `deleted_at IS NULL`. Ninguna fila SHALL existir únicamente para representar un duplicado navegable de otra fila ya presente en un storage distinto.

#### Scenario: Carpeta física en sub-storage no genera fila espejo en el padre

- **WHEN** existe la carpeta física `Disco_C/Prensa/Portafolio/20260918/imagenes` y el storage 44 (`206 Portafolio`, base `.../Prensa/Portafolio`) tiene su fila canónica
- **THEN** NO SHALL existir una fila adicional en storage 37 (`200_Diarios`, base `.../Prensa`) con `path = 'Portafolio/20260918/imagenes'`
- **AND** la navegación desde storage 37 SHALL resolver esa carpeta por comparación de path físico, no por `parent_id`

#### Scenario: Identidad física con filas duplicadas se consolida

- **WHEN** dos filas comparten `lower(rtrim(base_path || '/' || path))` y una de ellas es la representación canónica del archivo en disco
- **THEN** SHALL conservarse una sola fila, la del storage cuyo `base_path` es más específico (prefijo más largo)
- **AND** las FKs entrantes (`transcriptions.file_id`, `shares.file_id`) de la fila descartada SHALL repuntarse a la conservada
- **AND** ninguna transcripción ni share SHALL perderse

### Requirement: El listado cross-storage resuelve por path físico, no por filas espejo

Cuando el usuario navega a una carpeta `(storage_id, parent_id)`, el sistema SHALL listar los hijos directos de **todas** las filas de carpeta cuya identidad física sea equivalente a la carpeta navegada, incluyendo los hijos de sub-storages más específicos.

#### Scenario: El contenido del sub-storage aparece al navegar desde el padre

- **WHEN** el usuario navega `200_Diarios > Portafolio > 20260918 > imagenes` (storage 37)
- **AND** la carpeta física `.../Prensa/Portafolio/20260918/imagenes` tiene su fila canónica en storage 44
- **THEN** el listado SHALL devolver los 16 archivos PNG
- **AND** cada archivo SHALL pertenecer a la fila canónica de storage 44
- **AND** el listado NO SHALL devolver vacío

#### Scenario: La carpeta raíz de un sub-storage se resuelve cuando el path relativo es vacío

- **WHEN** el usuario navega una carpeta del storage padre cuyo path absoluto ES exactamente el `base_path` de un sub-storage
- **THEN** el sistema SHALL resolver el folder raíz de ese sub-storage (`parent_id IS NULL`, `storage_provider_id = <sub>`)
- **AND** SHALL incluir sus hijos en el listado
- **AND** NO SHALL abortar la resolución por `relativeToSub` vacío

#### Scenario: El navegador adopta el storage de la fila listada

- **WHEN** el listado devuelve una fila cuya `storage_provider_id` difiere del storage actualmente navegado
- **AND** el usuario hace click para entrar en esa carpeta
- **THEN** el cliente SHALL adoptar el `storage_provider_id` de la fila como storage activo
- **AND** el siguiente request SHALL consultar `storage_id` de esa fila
- **AND** NO SHALL consultar el storage anterior con el `parent_id` de la fila nueva

#### Scenario: Búsqueda recursiva dentro de una carpeta cross-storage

- **WHEN** el usuario busca un término dentro de una carpeta cuya identidad física abarca storages padre e hijo
- **THEN** la búsqueda recursiva SHALL recorrer el árbol de `parent_id` de todas las filas equivalentes
- **AND** NO SHALL omitir los resultados que viven únicamente en el sub-storage

### Requirement: La descarga canonicaliza la fila antes de resolver el path en disco

`FileController::download`, `FileController::downloadFolder` y `FileController::preview` SHALL resolver la fila canónica de la identidad física antes de construir la ruta absoluta `base_path . '/' . path`. El sistema NO SHALL construir una ruta absoluta a partir de una fila cuya identidad física esté representada por otra fila.

#### Scenario: Descargar un archivo cuyo id apunta a una fila no canónica

- **WHEN** el cliente solicita `GET /files/{id}/download` y la fila `{id}` no es la representación canónica de su identidad física
- **THEN** el sistema SHALL resolver la fila canónica y servir el archivo desde su path real
- **AND** SHALL responder HTTP 200 con el contenido binario
- **AND** NO SHALL responder HTTP 400 `Invalid file path`

#### Scenario: La ruta resuelta respeta el case exacto del disco

- **WHEN** el path almacenado en la fila difiere en mayúsculas/minúsculas del path real en disco
- **THEN** el sistema SHALL servir el archivo usando la ruta que existe físicamente
- **AND** NO SHALL responder HTTP 400 por un mismatch de case

#### Scenario: Archivo inexistente en disco responde 404, no 400

- **WHEN** el archivo no existe en disco tras resolver la fila canónica
- **THEN** el sistema SHALL responder HTTP 404 `File not found`
- **AND** NO SHALL confundirlo con un path inválido (HTTP 400)

### Requirement: El enlace materializado de mirrors se retira

El sistema SHALL dejar de escribir la columna `files.canonical_folder_id` y SHALL retirar el servicio de enlace de mirrors y sus comandos de backfill. `FilePhysicalIdentity::canonicalFor()` SHALL retornar la misma fila recibida, porque ya no existe un enlace materializado que resolver.

#### Scenario: El sync no crea ni enlaza filas espejo

- **WHEN** el sync indexa un archivo nuevo que cae dentro del `base_path` de un sub-storage más específico
- **THEN** SHALL crear el archivo una sola vez, en el sub-storage, colgando del folder equivalente
- **AND** NO SHALL crear una fila espejo en el storage padre
- **AND** NO SHALL escribir `canonical_folder_id`

#### Scenario: Los comandos de backfill de mirrors dejan de existir

- **WHEN** el operador ejecuta `php artisan list`
- **THEN** los comandos `files:repair-file-mirrors` y `files:repair-folder-mirrors` NO SHALL aparecer
- **AND** `FileMirrorLinker` NO SHALL existir en el árbol de código

#### Scenario: La columna canonical_folder_id queda deprecada sin drop

- **WHEN** se aplica este change
- **THEN** la columna `files.canonical_folder_id` SHALL permanecer en el esquema (nullable)
- **AND** SHALL tener 0 filas con valor no nulo
- **AND** su eliminación SHALL quedar para un change posterior

### Requirement: Reparent de hijos cuyo padre era una fila espejo retirada

Antes de retirar una fila espejo de carpeta, el sistema SHALL reasignar `parent_id` de sus hijos directos para que ningún archivo pierda su ubicación lógica.

#### Scenario: Hijo en el mismo storage que el espejo se delega al sub-storage

- **WHEN** un archivo canónico tiene `parent_id` apuntando a una fila espejo y vive en el **mismo** storage que esa fila
- **AND** su path absoluto cae dentro del `base_path` de un sub-storage más específico
- **THEN** el sistema SHALL delegarlo al sub-storage (mismo mecanismo que el sync de delegación)
- **AND** SHALL crear la cadena de carpetas necesaria en el sub-storage
- **AND** SHALL preservar su `file_id` y `path`

#### Scenario: Hijo en otro storage se reparenta al equivalente canónico

- **WHEN** un archivo canónico tiene `parent_id` apuntando a una fila espejo y vive en un storage **distinto**
- **THEN** el sistema SHALL reparentarlo al folder canónico equivalente (misma identidad física)
- **AND** si no existe folder canónico equivalente, SHALL dejar `parent_id = NULL` y loggear `files.mirror_retirement.orphan_parent`
- **AND** el archivo SHALL seguir siendo accesible por búsqueda y por path físico

#### Scenario: El padre espejo ya no existe (canónico borrado)

- **WHEN** una fila espejo apunta a un canónico que fue borrado, y tiene hijos
- **THEN** el sistema SHALL reasignar los hijos por identidad física antes de retirar el espejo
- **AND** SHALL NO borrar ningún archivo físico
- **AND** SHALL reportar el conteo en el resumen del comando

### Requirement: Migración de datos auditable, reversible y con dry-run

La retirada de filas espejo SHALL ejecutarse mediante un comando con `--dry-run` (default) y `--apply`, SHALL registrar cada fila retirada en `file_mirror_audit_log` con acción `retire_mirror`, y SHALL ser abortable con rollback si las verificaciones previas fallan.

#### Scenario: Dry-run no muta nada

- **WHEN** el operador ejecuta el comando sin `--apply`
- **THEN** SHALL reportar: filas espejo candidatas, FKs a repuntar, hijos a reparentar, hijos a delegar
- **AND** SHALL NO modificar ninguna fila
- **AND** SHALL responder exit code 0

#### Scenario: Verificación previa aborta si un espejo no tiene canónico vivo ni par físico

- **WHEN** una fila espejo candidata no tiene un canónico vivo y su identidad física no coincide con ninguna otra fila
- **THEN** el comando SHALL NO retirarla
- **AND** SHALL reportarla como `unresolvable_mirror`
- **AND** SHALL continuar con el resto sin abortar la corrida completa

#### Scenario: Aplicar registra auditoría por cada fila retirada

- **WHEN** el operador ejecuta con `--apply`
- **THEN** por cada fila retirada SHALL insertarse una fila en `file_mirror_audit_log` con `action = 'retire_mirror'`
- **AND** SHALL incluir `mirror_file_id`, `canonical_file_id` (o NULL), `metadata` con el motivo y los conteos de hijos reparentados
- **AND** el audit log SHALL seguir siendo append-only

#### Scenario: Idempotencia

- **WHEN** el operador re-ejecuta el comando con `--apply` tras una corrida exitosa
- **THEN** SHALL reportar 0 filas retiradas
- **AND** SHALL NO modificar ninguna fila
- **AND** SHALL responder exit code 0

### Requirement: Verificación operacional del invariante post-migración

Tras aplicar la migración, el sistema SHALL cumplir y SHALL poder verificar: `count(files WHERE canonical_folder_id IS NOT NULL) = 0`, y cada `(storage_provider_id, path)` SHALL corresponder a un archivo o carpeta existente en disco.

#### Scenario: Conteo de mirrors en cero

- **WHEN** el operador consulta `SELECT count(*) FROM files WHERE canonical_folder_id IS NOT NULL AND deleted_at IS NULL`
- **THEN** SHALL retornar 0

#### Scenario: Ninguna fila apunta a una ruta muerta

- **WHEN** el operador corre el verificador sobre una muestra de filas activas
- **THEN** cada `base_path . '/' . path` SHALL existir en disco
- **AND** las filas cuyo archivo fue eliminado del disco SHALL estar en `availability_state = 'missing'`, no en `available`

#### Scenario: El conteo de filas se reduce sin perder identidades

- **WHEN** el operador compara los conteos antes y después
- **THEN** el total de filas SHALL reducirse en la cantidad de espejos retirados
- **AND** el número de identidades físicas distintas (`lower(rtrim(base_path || '/' || path))`) SHALL permanecer igual o disminuir solo por consolidación de duplicados reales
- **AND** ninguna transcripción ni share SHALL haber cambiado de archivo físico
