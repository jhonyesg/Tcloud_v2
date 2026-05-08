# file-upload-ux Specification

## Purpose
TBD - created by archiving change upload-and-image-viewer. Update Purpose after archive.
## Requirements
### Requirement: Zona de drop en la vista principal de archivos
El sistema SHALL mostrar un overlay visual cuando el usuario arrastra archivos sobre el área principal del módulo "Mis Archivos", y SHALL subir los archivos al soltarlos sin necesidad de abrir el modal.

#### Scenario: Arrastrar archivo sobre la vista principal
- **WHEN** el usuario arrastra uno o más archivos sobre el panel de archivos mientras está dentro de un storage
- **THEN** aparece un overlay con texto "Suelta para subir" que cubre el área de archivos

#### Scenario: Soltar archivos en la vista principal
- **WHEN** el usuario suelta archivos sobre el overlay
- **THEN** el overlay se cierra, el modal de subida se abre mostrando el progreso de cada archivo, y los archivos se suben al directorio actual

#### Scenario: Arrastrar sin estar en un storage
- **WHEN** el usuario arrastra archivos mientras está en la vista de selección de storages (no dentro de uno)
- **THEN** el overlay no aparece (o aparece con mensaje "Selecciona un storage primero") y no se inicia ninguna subida

### Requirement: Feedback de progreso por archivo
El sistema SHALL mostrar una barra de progreso individual para cada archivo que se está subiendo, y SHALL mostrar un mensaje de error específico si la subida falla.

#### Scenario: Progreso durante la subida
- **WHEN** un archivo está siendo subido
- **THEN** se muestra una barra de progreso que refleja el porcentaje real subido (0–100%)

#### Scenario: Archivo duplicado
- **WHEN** se sube un archivo cuyo nombre ya existe en la carpeta actual
- **THEN** se muestra el error "Ya existe un archivo con ese nombre"

#### Scenario: Cuota excedida
- **WHEN** el archivo supera la cuota disponible del usuario
- **THEN** se muestra el error "Cuota de almacenamiento excedida"

#### Scenario: Sin permisos de escritura
- **WHEN** el usuario no tiene permisos de escritura en el storage
- **THEN** se muestra el error "No tienes permisos para subir archivos aquí"

#### Scenario: Subida exitosa
- **WHEN** un archivo termina de subirse correctamente
- **THEN** la barra de progreso llega al 100%, muestra un indicador de éxito, y la lista de archivos se recarga automáticamente

### Requirement: Subida de múltiples archivos
El sistema SHALL aceptar múltiples archivos en una sola operación drag-and-drop y SHALL procesarlos secuencialmente.

#### Scenario: Drop de múltiples archivos
- **WHEN** el usuario arrastra y suelta N archivos a la vez
- **THEN** el modal muestra N entradas con sus respectivas barras de progreso y los sube uno a uno

