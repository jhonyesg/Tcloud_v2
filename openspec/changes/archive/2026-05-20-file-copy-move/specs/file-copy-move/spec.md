## ADDED Requirements

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
El sistema SHALL mostrar un modal que permita al usuario navegar las carpetas del storage actual y seleccionar la carpeta destino para la operación de copiar o mover.

#### Scenario: Apertura del modal
- **WHEN** el usuario hace click en "Copiar" o "Mover" sobre un archivo o carpeta
- **THEN** se abre un modal que muestra la raíz del storage y las carpetas del nivel actual, con opción de navegar hacia subcarpetas y seleccionar "Aquí" como destino

#### Scenario: Confirmación de destino
- **WHEN** el usuario selecciona una carpeta destino y confirma
- **THEN** se ejecuta la operación (copy o move) y el modal se cierra; la lista de archivos se refresca al completar

#### Scenario: Error durante la operación
- **WHEN** el servidor retorna error (409 conflicto, 403 sin permiso, 500 error de disco)
- **THEN** el modal muestra el mensaje de error sin cerrarse
