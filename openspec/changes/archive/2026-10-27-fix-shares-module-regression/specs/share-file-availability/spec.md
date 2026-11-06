## MODIFIED Requirements

### Requirement: Estado de disponibilidad del recurso

Cada `File` relacionado con un share SHALL tener un estado observable `unknown`, `available` o `missing`, además de la fecha de última verificación. Las filas nuevas SHALL comenzar en `unknown` salvo que una operación de escritura haya confirmado el archivo físico.

El estado SHALL persistirse en la fila del recurso de modo que la administración pueda filtrar por él con una consulta de base de datos, sin necesidad de sondear el disco por cada share. El estado persistido SHALL ser un dato derivado de una sonda de filesystem y SHALL NOT ocupar el papel de catálogo: la fila del recurso SHALL seguir siendo propiedad exclusiva del módulo de archivos. Una verificación SHALL escribir únicamente las columnas de disponibilidad y verificación, y SHALL NOT modificar otros atributos de la fila ni su marca de actualización.

#### Scenario: Archivo confirmado disponible

- **WHEN** un escaneo confiable o una escritura exitosa confirma la ruta física
- **THEN** el recurso queda en `available`, actualiza su fecha de verificación y limpia `missing_since_at`

#### Scenario: Archivo confirmado ausente

- **WHEN** una verificación acotada confirma que un storage local accesible no contiene la ruta
- **THEN** el recurso queda en `missing` y conserva la fecha desde la que se detectó la ausencia

#### Scenario: Estado no confiable

- **WHEN** el storage está inaccesible, el montaje falta, el escaneo es parcial o la purga es rechazada por seguridad
- **THEN** el recurso queda o permanece en `unknown` y el sistema no afirma que fue borrado

#### Scenario: La verificación no altera el catálogo

- **WHEN** una verificación marca un recurso como `available` o `missing`
- **THEN** la fila modificada conserva su nombre, ruta, tamaño y propietario
- **AND** la operación no crea ni elimina filas de recursos

### Requirement: Disponibilidad visible en administración

El listado de shares SHALL mostrar el estado de disponibilidad del recurso y permitir filtrar por `available`, `missing` y `unknown`. El estado `unknown` SHALL diferenciarse visualmente de un archivo confirmado ausente.

El filtrado por disponibilidad SHALL aplicarse en el servidor. Un recurso en estado `unknown` SHALL NOT aparecer dentro del conjunto `missing` y SHALL NOT ser incluido por una acción automática de eliminación por ausencia. El contador de recursos no disponibles SHALL contar únicamente recursos confirmados ausentes.

#### Scenario: Filtro de recursos ausentes

- **WHEN** el usuario selecciona "Archivo no disponible"
- **THEN** solo aparecen shares cuyo `File` está confirmado como `missing`

#### Scenario: Share con estado desconocido

- **WHEN** el catálogo no tiene una verificación confiable reciente
- **THEN** la interfaz muestra "No verificado" y no lo incluye en una acción automática de eliminación por ausencia

#### Scenario: Contador de no disponibles refleja ausencias confirmadas

- **WHEN** el usuario consulta el panel de compartidos
- **THEN** el contador de no disponibles coincide con el número de shares cuyo recurso está confirmado como ausente
- **AND** SHALL NOT contar como ausente un recurso en estado `unknown` ni un share sin recurso

### Requirement: Verificación física acotada

El sistema SHALL permitir verificar disponibilidad para shares seleccionados o para el conjunto de resultados filtrados, con un límite operativo por operación. La verificación SHALL actualizar el estado del catálogo, pero nunca SHALL borrar automáticamente filas `files`, archivos físicos ni transcripciones.

La verificación SHALL estar disponible como endpoint propio y SHALL responder con éxito ante cualquier solicitud válida del usuario autenticado. La operación SHALL ser acotada por lote y SHALL devolver un cursor estable que permita recorrer el conjunto completo sin repetir ni omitir shares. Cuando el recurso no pueda evaluarse de forma concluyente por tratarse de un storage no local o inaccesible, el resultado SHALL ser `unknown` con una razón legible, y el sistema SHALL NOT afirmar sobre la ruta local.

#### Scenario: Verificar selección

- **WHEN** el usuario solicita verificar un conjunto de shares de un storage local accesible
- **THEN** cada recurso queda en `available` o `missing` según el resultado y la interfaz muestra el resumen

#### Scenario: Verificar storage remoto

- **WHEN** un share apunta a un storage no local que no tiene comprobador disponible
- **THEN** el resultado queda en `unknown` con una razón legible y no se intenta usar `file_exists()` sobre una ruta local

#### Scenario: Cada share verificado exactamente una vez

- **WHEN** la verificación recorre un conjunto filtrado mayor que el tamaño de lote
- **THEN** la suma de los elementos verificados es igual al total del conjunto, sin repeticiones ni omisiones

### Requirement: Share público con recurso confirmado ausente

Un token vigente cuyo recurso está confirmado como `missing` SHALL responder como recurso no disponible y no SHALL renderizar una vista que sugiera que el enlace funciona. Un recurso `unknown` SHALL conservar el comportamiento público actual hasta que exista una verificación concluyente.

El estado persistido SHALL ser una caché de la comprobación y SHALL NOT ser la única fuente de verdad: el sistema SHALL confirmar la existencia física del recurso al servir la vista o la descarga, de modo que un recurso marcado como disponible que ya no está en disco SHALL producir la misma respuesta de recurso ausente. La comprobación en tiempo de petición SHALL tener precedencia sobre el estado persistido.

#### Scenario: Vista de archivo ausente

- **WHEN** se abre un share vigente cuyo `File` está en `missing`
- **THEN** la respuesta pública es `404` o la vista de no encontrado y no se registra una descarga exitosa

#### Scenario: Descarga de archivo ausente

- **WHEN** se solicita la descarga de un share cuyo recurso está en `missing`
- **THEN** se devuelve `404` sin intentar servir un path inexistente

#### Scenario: Recurso presente en disco pero estado persistido desconocido

- **WHEN** el recurso existe en disco y su estado persistido es `unknown`
- **THEN** el enlace público funciona con normalidad
- **AND** el estado persistido no se usa para negar el acceso
