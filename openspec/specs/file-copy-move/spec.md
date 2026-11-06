## Purpose

Definir el comportamiento de copiar y mover archivos y carpetas dentro de un storage local. Disponible únicamente para usuarios con permiso `full`.

## Requirements

### Requirement: Copiar archivo a carpeta destino
El sistema SHALL permitir copiar un archivo a una carpeta destino dentro del mismo storage, creando una copia física en disco y un nuevo registro en BD. La operación SHALL estar disponible únicamente para usuarios con permiso `full` en el storage. Si ya existe un archivo con el mismo nombre en la carpeta destino, el sistema SHALL retornar error 409 sin modificar nada.

#### Scenario: Copia exitosa de archivo
- **WHEN** un usuario con permiso `full` envía `POST /files/{id}/copy` con `destination_parent_id` válido
- **THEN** el archivo se copia físicamente en disco, se crea un nuevo registro en BD con `file_modified_at` desde `filemtime()`, y el servidor retorna 201 con el nuevo registro

#### Scenario: Conflicto de nombre en destino
- **WHEN** ya existe un archivo con el mismo nombre en la carpeta destino
- **THEN** el servidor retorna 409 sin crear ningún archivo ni registro

#### Scenario: Usuario sin permiso full intenta copiar
- **WHEN** un usuario con permiso `read`, `write` o `upload` intenta `POST /files/{id}/copy`
- **THEN** el servidor retorna 403

#### Scenario: Copiar a la raíz del storage
- **WHEN** `destination_parent_id` es `null`
- **THEN** el archivo se copia a la raíz del storage

### Requirement: Mover archivo a carpeta destino
El sistema SHALL permitir mover un archivo a una carpeta destino dentro del mismo storage, moviendo el archivo en disco y actualizando su `path` en BD. La operación SHALL estar disponible únicamente para usuarios con permiso `full`. Si ya existe un archivo con el mismo nombre en la carpeta destino, el sistema SHALL retornar error 409.

#### Scenario: Movimiento exitoso de archivo
- **WHEN** un usuario con permiso `full` envía `POST /files/{id}/move` con `destination_parent_id` válido
- **THEN** el archivo se mueve en disco, su registro en BD se actualiza con el nuevo `path` y `parent_id`, y el servidor retorna 200 con el registro actualizado

#### Scenario: Conflicto de nombre al mover
- **WHEN** ya existe un archivo con el mismo nombre en la carpeta destino
- **THEN** el servidor retorna 409 sin mover nada

#### Scenario: Mover archivo a su misma carpeta
- **WHEN** `destination_parent_id` es igual al `parent_id` actual del archivo
- **THEN** el servidor retorna 422 con mensaje de error

### Requirement: Copiar carpeta a destino (recursivo)
El sistema SHALL permitir copiar una carpeta y todo su contenido a una carpeta destino dentro del mismo storage. La operación es recursiva: copia todos los archivos y subcarpetas. Requiere permiso `full`.

#### Scenario: Copia recursiva exitosa de carpeta
- **WHEN** un usuario con permiso `full` envía `POST /files/{id}/copy` sobre una carpeta con `destination_parent_id` válido
- **THEN** la carpeta y todo su contenido se copian en disco, se crean nuevos registros en BD para la carpeta y todos sus descendientes, y el servidor retorna 201

#### Scenario: Conflicto de nombre de carpeta en destino
- **WHEN** ya existe una carpeta con el mismo nombre en la carpeta destino
- **THEN** el servidor retorna 409 sin crear nada

### Requirement: Mover carpeta a destino (recursivo)
El sistema SHALL permitir mover una carpeta y todo su contenido a una carpeta destino. El directorio se mueve atómicamente en disco con `rename()`. Todos los registros BD de la carpeta y sus descendientes se actualizan con los nuevos paths. Requiere permiso `full`.

#### Scenario: Movimiento exitoso de carpeta
- **WHEN** un usuario con permiso `full` envía `POST /files/{id}/move` sobre una carpeta con `destination_parent_id` válido
- **THEN** el directorio se mueve en disco, el registro de la carpeta y todos sus descendientes se actualizan en BD con los nuevos paths, y el servidor retorna 200

#### Scenario: Mover carpeta dentro de sí misma
- **WHEN** `destination_parent_id` apunta a un descendiente de la carpeta origen
- **THEN** el servidor retorna 422 con mensaje de error

### Requirement: Controles UI de copiar y mover visibles solo con permiso full
El sistema SHALL mostrar los botones "Copiar" y "Mover" en la vista de archivos únicamente cuando el usuario tiene permiso `full` en el storage activo, consistente con los botones "Renombrar" y "Eliminar".

#### Scenario: Botones visibles con permiso full
- **WHEN** el usuario tiene permiso `full` en el storage activo
- **THEN** los botones "Copiar" y "Mover" aparecen en la fila/tarjeta de cada archivo y carpeta, tanto en vista grilla como en vista tabla

#### Scenario: Botones ocultos sin permiso full
- **WHEN** el usuario tiene permiso `read`, `write` o `upload`
- **THEN** los botones "Copiar" y "Mover" no aparecen

### Requirement: Modal de selección de carpeta destino
El sistema SHALL mostrar un modal que permita al usuario navegar las carpetas del storage actual y seleccionar la carpeta destino para la operación de copiar o mover. El modal SHALL abrir en la carpeta padre del archivo o carpeta fuente (no en la raíz del storage) cuando el usuario está navegando dentro de una subcarpeta, y SHALL ofrecer un control "↑ Subir" para navegar al padre del destino seleccionado.

#### Scenario: Apertura del modal
- **WHEN** el usuario hace click en "Copiar" o "Mover" sobre un archivo o carpeta
- **THEN** se abre un modal con el destino seleccionado apuntando a la carpeta padre de la fuente (mismo nivel del archivo a mover); si la fuente está en la raíz del storage, el destino default es la raíz misma

#### Scenario: Apertura del modal desde la raíz del storage
- **WHEN** el usuario hace click en "Copiar" o "Mover" sobre un archivo o carpeta mientras navega en la raíz del storage (donde `parent_id` del archivo es `null`)
- **THEN** se abre un modal que muestra las carpetas del nivel raíz del storage como destinos disponibles, el destino default es "Raíz", y el control "↑ Subir" está deshabilitado porque ya está en la raíz

#### Scenario: Apertura del modal desde una subcarpeta
- **WHEN** el usuario hace click en "Copiar" o "Mover" sobre un archivo o carpeta mientras navega en una subcarpeta del storage (donde `parent_id` del archivo es el id de la subcarpeta)
- **THEN** se abre un modal que muestra el nombre de la subcarpeta actual como destino default, lista las carpetas hijas del folder actual, y ofrece un control "↑ Subir" habilitado para retroceder al padre

#### Scenario: Navegar a una subcarpeta desde el modal
- **WHEN** el usuario hace click en una carpeta listada en el modal
- **THEN** el destino seleccionado pasa a ser esa carpeta, el modal recarga sus hijas y actualiza el breadcrumb visual; el control "↑ Subir" se habilita

#### Scenario: Subir al padre desde el modal
- **WHEN** el usuario hace click en "↑ Subir" mientras el destino es una subcarpeta
- **THEN** el destino pasa a ser el `parent_id` de la subcarpeta actual; si ese padre es `null`, el destino pasa a ser la raíz del storage y el botón "↑ Subir" se deshabilita

#### Scenario: Modal deshabilita la carpeta fuente y sus descendientes como pista visual
- **WHEN** el modal lista carpetas hijas del destino seleccionado
- **THEN** la carpeta fuente (cuando es carpeta) y sus descendientes aparecen deshabilitadas en el listado, como pista visual de que no se puede mover una carpeta dentro de sí misma; el guardrail hard vive en el backend (`FileController::move` retorna 422 si el destino es descendiente del origen)

#### Scenario: Confirmación de destino
- **WHEN** el usuario selecciona una carpeta destino y confirma
- **THEN** se ejecuta la operación (copy o move) sobre la ruta indicada y el modal se cierra; la lista de archivos se refresca al completar

#### Scenario: Error durante la operación
- **WHEN** el servidor retorna error (409 conflicto, 422 destino es descendiente, 403 sin permiso, 500 error de disco)
- **THEN** el modal muestra el mensaje de error sin cerrarse

### Requirement: Frontend consume shape `{files, breadcrumbs, pagination}` del endpoint `/files`
El frontend SHALL consumir el shape `{files: array, breadcrumbs: array, pagination: object}` retornado por `GET /files` (establecido por el change `mis-archivos-fs-first`). El frontend SHALL aceptar ambas formas (objeto con `files` o array directo) por compatibilidad con consumidores históricos.

#### Scenario: Modal carga carpetas hijas desde `data.files`
- **WHEN** el handler de Alpine `loadCopyMoveFolders` recibe la respuesta de `GET /files?storage_id=X&parent_id=Y`
- **THEN** el array de carpetas disponibles se calcula como `Array.isArray(data) ? data : (data?.files ?? [])` filtrando por `is_folder === true`; cualquier respuesta válida (array u objeto con `files`) produce una lista correcta

#### Scenario: Compatibilidad con respuesta tipo array
- **WHEN** el backend retorna un array directo en lugar del shape `{files, ...}` (escenario legacy)
- **THEN** el frontend sigue funcionando porque `Array.isArray(data) === true` cae en la primera parte del ternario
