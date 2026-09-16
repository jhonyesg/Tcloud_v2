## Purpose

Señaliza visualmente el medio (TV o Radio) de cada canal en las vistas del módulo Medios Puntuales, heredando el código cromático e iconografía del tipo del grabador asociado, para que cualquier usuario distinga de un vistazo si un canal graba de una emisora de radio o de un canal de TV, sin depender de columnas visibles solo para administradores.

## ADDED Requirements

### Requirement: Señal de tipo en la lista de canales
La vista de canales (`/grabaciones-puntuales/canales`) SHALL mostrar, para cada canal, un indicador visual de su medio derivado del tipo del grabador asociado (`tv` o `radio`), en la tarjeta móvil y en la fila de la tabla desktop.

#### Scenario: Canal de radio en lista
- **WHEN** la lista de canales muestra un canal cuyo grabador tiene tipo `radio`
- **THEN** el canal se presenta con el icono de radio y el color esmeralda asignado al medio radio, junto al nombre del slot

#### Scenario: Canal de TV en lista
- **WHEN** la lista de canales muestra un canal cuyo grabador tiene tipo `tv`
- **THEN** el canal se presenta con el icono de TV y el color púrpura asignado al medio TV, junto al nombre del slot

#### Scenario: Redundancia accesible en tabla desktop
- **WHEN** la tabla desktop muestra un canal con su indicador de medio
- **THEN** además del icono y color, se muestra una etiqueta textual "Radio" o "TV" visible sin interacción (sin hover ni tooltip)

#### Scenario: Canal sin grabador asociado
- **WHEN** un canal no tiene grabador asociado o el grabador no tiene tipo definido
- **THEN** el canal se presenta con la señal neutral genérica (sin color de medio ni etiqueta de tipo), sin errores

### Requirement: Visibilidad del medio para todos los roles
El indicador de medio SHALL ser visible tanto para administradores como para usuarios normales, incluyendo canales filtrados por `usuario_id`.

#### Scenario: Usuario normal ve el medio
- **WHEN** un usuario no administrador abre la lista de sus canales
- **THEN** cada canal muestra su indicador de medio aunque la tabla no presente las columnas de Usuario y Grabador

### Requirement: Consistencia con el módulo Grabadores
El código visual de medios en canales SHALL reutilizar exactamente los tokens del módulo Grabadores: TV = icono `fa-tv` con paleta púrpura; Radio = icono `fa-radio` con paleta esmeralda.

#### Scenario: Mismo medio, mismo lenguaje visual
- **WHEN** un usuario navega desde la lista de grabadores a la lista de canales
- **THEN** un mismo medio (TV o radio) se representa con el mismo icono y el mismo color en ambos módulos

### Requirement: Selección de grabador con señal de tipo
El formulario de creación de canal SHALL mostrar el tipo de cada grabador en las opciones del selector, de modo que el usuario sepa el medio del canal que va a crear antes de enviar el formulario.

#### Scenario: Selector de grabador indica el medio
- **WHEN** el usuario despliega el selector de grabadores en la creación de un canal
- **THEN** cada opción identifica su medio (radio o TV) mediante icono y/o texto

### Requirement: Medio visible en edición de canal
El formulario de edición de canal SHALL mostrar el tipo del grabador asignado al canal en el bloque de información del grabador (read-only).

#### Scenario: Edición muestra el medio del grabador asignado
- **WHEN** un usuario abre la edición de un canal con grabador asignado
- **THEN** el bloque del grabador muestra el icono y la etiqueta textual del medio (Radio o TV)

### Requirement: Guía interactiva explica el código de medios
El tour interactivo de la lista de canales SHALL explicar el código de color/icono de los medios de los canales en el paso correspondiente a la columna Slot.

#### Scenario: Tour menciona la distinción de medios
- **WHEN** el usuario recorre el paso del tour dedicado a la columna Slot
- **THEN** el contenido del paso explica que el icono y color indican el medio (radio esmeralda, TV púrpura) del grabador del canal

### Requirement: Estados neutros no invadidos por el código de medios
El encabezado de página, los estados vacíos y las acciones de la lista SHALL mantener la señal neutral existente; el código de medios se aplica únicamente como atributo de cada canal.

#### Scenario: Sin canales configurados
- **WHEN** la lista de canales no tiene elementos
- **THEN** el estado vacío se muestra con la señal neutral genérica, sin indicadores de medio