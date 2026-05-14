## ADDED Requirements

### Requirement: Botón de corte en acciones de archivo
En el módulo de archivos, los archivos de tipo mp4/mp3/m4a SHALL mostrar un botón "✂ Cortar" en su menú de acciones cuando el usuario tiene acceso al editor.

#### Scenario: Botón visible para formatos de medios
- **WHEN** el usuario tiene acceso al editor y el archivo tiene extensión mp4, mp3 o m4a
- **THEN** aparece el botón "✂" (o "Cortar") en las acciones del archivo junto a los demás botones existentes

#### Scenario: Botón oculto para otros formatos
- **WHEN** el archivo es de cualquier otro tipo (pdf, docx, jpg, etc.)
- **THEN** el botón "✂ Cortar" no aparece

#### Scenario: Botón oculto para storages S3 en v1
- **WHEN** el archivo pertenece a un storage de tipo S3
- **THEN** el botón "✂ Cortar" no aparece (solo disponible para storage local)

### Requirement: Modal editor de corte
Al hacer clic en "✂ Cortar", SHALL abrirse un modal con el editor de segmentos.

#### Scenario: Modal se abre con el archivo cargado
- **WHEN** el usuario hace clic en "✂ Cortar" de un archivo
- **THEN** se abre un modal que muestra el nombre del archivo y carga el archivo en un reproductor HTML5 (`<video>` para mp4, `<audio>` para mp3/m4a)

#### Scenario: Modal incluye un segmento inicial
- **WHEN** el modal se abre por primera vez
- **THEN** se muestra un segmento inicial con campos de inicio (hh:mm:ss o segundos) y fin (hh:mm:ss o segundos) con valores por defecto 0 y duración total del archivo

#### Scenario: Agregar segmentos adicionales
- **WHEN** el usuario hace clic en "＋ Agregar segmento"
- **THEN** se añade una nueva fila de inicio/fin a la lista de segmentos
- **THEN** el botón "✂ Generar corte" concatenará todos los segmentos en orden en el output

#### Scenario: Eliminar un segmento
- **WHEN** el usuario hace clic en el botón de eliminar (×) de un segmento
- **THEN** ese segmento se elimina de la lista (siempre debe quedar al menos 1)

#### Scenario: Nombre del archivo de salida
- **WHEN** el modal está abierto
- **THEN** se muestra el nombre del archivo de salida que se generará: `{nombre_sin_extensión}_corte.{ext}`

### Requirement: Generación y descarga del corte
Al presionar "✂ Generar corte", el sistema SHALL procesar y descargar el archivo.

#### Scenario: Descarga automática al completar
- **WHEN** el usuario hace clic en "✂ Generar corte"
- **THEN** el botón muestra un indicador de carga ("Procesando...")
- **THEN** se envía `POST /files/{id}/clip` con los segmentos
- **THEN** al recibir la respuesta, el navegador descarga automáticamente el archivo generado
- **THEN** el modal se cierra (o muestra mensaje de éxito)

#### Scenario: Error en el procesamiento
- **WHEN** el servidor retorna un error (FFmpeg falla, archivo no encontrado, etc.)
- **THEN** el modal muestra un mensaje de error descriptivo
- **THEN** el botón vuelve a su estado normal para reintentar
