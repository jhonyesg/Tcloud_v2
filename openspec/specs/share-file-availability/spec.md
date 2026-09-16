# share-file-availability Specification

## Purpose
Representar de forma segura si el recurso catalogado por un share está disponible, ausente o no verificado, sin confundir una fila válida de `files` con una garantía de existencia física en un storage local o remoto.

## Requirements

### Requirement: Estado de disponibilidad del recurso

Cada `File` relacionado con un share SHALL tener un estado observable `unknown`, `available` o `missing`, además de la fecha de última verificación. Las filas nuevas SHALL comenzar en `unknown` salvo que una operación de escritura haya confirmado el archivo físico.

#### Scenario: Archivo confirmado disponible
- **WHEN** un escaneo confiable o una escritura exitosa confirma la ruta física
- **THEN** el recurso queda en `available`, actualiza su fecha de verificación y limpia `missing_since_at`

#### Scenario: Archivo confirmado ausente
- **WHEN** una verificación acotada confirma que un storage local accesible no contiene la ruta
- **THEN** el recurso queda en `missing` y conserva la fecha desde la que se detectó la ausencia

#### Scenario: Estado no confiable
- **WHEN** el storage está inaccesible, el montaje falta, el escaneo es parcial o la purga es rechazada por seguridad
- **THEN** el recurso queda o permanece en `unknown` y el sistema no afirma que fue borrado

### Requirement: Disponibilidad visible en administración

El listado de shares SHALL mostrar el estado de disponibilidad del recurso y permitir filtrar por `available`, `missing` y `unknown`. El estado `unknown` SHALL diferenciarse visualmente de un archivo confirmado ausente.

#### Scenario: Filtro de recursos ausentes
- **WHEN** el usuario selecciona "Archivo no disponible"
- **THEN** solo aparecen shares cuyo `File` está confirmado como `missing`

#### Scenario: Share con estado desconocido
- **WHEN** el catálogo no tiene una verificación confiable reciente
- **THEN** la interfaz muestra "No verificado" y no lo incluye en una acción automática de eliminación por ausencia

### Requirement: Verificación física acotada

El sistema SHALL permitir verificar disponibilidad para shares seleccionados o para el conjunto de resultados filtrados, con un límite operativo por operación. La verificación SHALL actualizar el estado del catálogo, pero nunca SHALL borrar automáticamente filas `files`, archivos físicos o transcripciones.

#### Scenario: Verificar selección
- **WHEN** el usuario solicita verificar un conjunto de shares de un storage local accesible
- **THEN** cada recurso queda en `available` o `missing` según el resultado y la interfaz muestra el resumen

#### Scenario: Verificar storage remoto
- **WHEN** un share apunta a un storage no local que no tiene comprobador disponible
- **THEN** el resultado queda en `unknown` con una razón legible y no se intenta usar `file_exists()` sobre una ruta local

### Requirement: Share público con recurso confirmado ausente

Un token vigente cuyo recurso está confirmado como `missing` SHALL responder como recurso no disponible y no SHALL renderizar una vista que sugiera que el enlace funciona. Un recurso `unknown` SHALL conservar el comportamiento público actual hasta que exista una verificación concluyente.

#### Scenario: Vista de archivo ausente
- **WHEN** se abre un share vigente cuyo `File` está en `missing`
- **THEN** la respuesta pública es `404` o la vista de no encontrado y no se registra una descarga exitosa

#### Scenario: Descarga de archivo ausente
- **WHEN** se solicita la descarga de un share cuyo recurso está en `missing`
- **THEN** se devuelve `404` sin intentar servir un path inexistente
