## Purpose

Definir el flujo de solicitud puntual de TV y Radio: cómo un cliente pide una franja horaria o el día completo de un medio que no tiene asignado, cómo se valida que el material cubra el rango pedido, cómo se generan los enlaces de entrega con vigencia y cómo queda registrado lo entregado para el control contable.

## ADDED Requirements

### Requirement: El módulo lista únicamente los medios no asignados

El módulo de solicitud puntual SHALL listar solo los medios que el solicitante **no** tiene asignados. Un medio se considera asignado cuando el solicitante tiene permiso de lectura sobre el storage que lo contiene o sobre cualquier storage ancestro cuya ruta física contenga la ruta interna del medio.

Los medios que el solicitante ya tiene asignados SHALL NOT aparecer en el listado del módulo, con independencia de que el material exista o no en disco. El listado SHALL ofrecer búsqueda por nombre y filtro por tipo de medio.

#### Scenario: El medio asignado no aparece

- **WHEN** el solicitante tiene permiso de lectura sobre el storage `01 Caracol Tv`
- **THEN** ese medio no aparece en el listado del módulo

#### Scenario: El medio no asignado aparece

- **WHEN** el solicitante no tiene permiso de lectura sobre ningún storage que contenga el medio `18 Canal Trece`
- **THEN** ese medio aparece en el listado del módulo

#### Scenario: Un medio dentro de un storage asignado no aparece

- **WHEN** el solicitante tiene permiso de lectura sobre el storage contenedor `02 Emisoras 01 Reg`
- **THEN** los sub-canales de ese contenedor no aparecen en el listado del módulo

### Requirement: La solicitud se define por fecha y rango horario

La solicitud SHALL registrar la fecha del material, la hora de inicio y la hora de fin del rango pedido, y la duración en minutos derivada de ese rango. El sistema SHALL rechazar un rango cuya hora de fin no sea posterior a la hora de inicio, y un rango cuya duración exceda el máximo configurado.

#### Scenario: Rango válido

- **WHEN** el cliente pide el 14/11/2026 de 12:00 a 13:00
- **THEN** la solicitud registra la fecha, el inicio, el fin y una duración de 60 minutos

#### Scenario: Rango invertido

- **WHEN** el cliente pide de 13:00 a 12:00
- **THEN** el sistema rechaza la solicitud y explica que la hora de fin debe ser posterior a la de inicio

### Requirement: La cobertura se valida contra la grilla de emisión del medio

La disponibilidad del material SHALL validarse contra la grilla de slots del medio para la fecha pedida. El paso de la grilla SHALL derivarse de los datos del propio medio (la cadencia observada entre inicios de captura consecutivos) y SHALL NOT ser una constante global, porque los medios tienen cadencias distintas.

El sistema SHALL determinar qué archivos cubren el rango pedido, SHALL reportar la cobertura resultante y SHALL distinguir entre cobertura completa y cobertura parcial. La disponibilidad SHALL verificarse contra el sistema de archivos, nunca solo contra el catálogo, porque el catálogo puede contener registros de material que ya no existe.

#### Scenario: Cobertura completa

- **WHEN** el cliente pide de 12:00 a 13:00 un medio de TV cuya grilla es de 15 minutos
- **THEN** el sistema resuelve los cuatro slots que caen en el rango
- **AND** informa cobertura completa con el número de archivos y el total de bytes

#### Scenario: Cobertura parcial

- **WHEN** el rango pedido cae sobre una franja sin material capturado
- **THEN** el sistema informa que la cobertura es parcial y cuantifica los slots faltantes
- **AND** no genera enlaces de entrega por los slots inexistentes

#### Scenario: Material fuera de la ventana de retención

- **WHEN** el cliente pide una fecha más antigua que la ventana de retención del árbol de origen
- **THEN** el sistema informa que el material ya no está disponible y no genera enlaces

#### Scenario: La disponibilidad se comprueba en disco

- **WHEN** el catálogo contiene el registro de un archivo pero el archivo ya no existe en disco
- **THEN** el sistema lo trata como no disponible

### Requirement: La entrega por franja genera un enlace por archivo exacto

Cuando el cliente elige la entrega por franja horaria, el sistema SHALL generar un enlace público de solo lectura por cada archivo exacto que cubre el rango, y SHALL NOT generar un enlace a la carpeta del día ni un enlace que exponga material fuera del rango pedido.

El sistema SHALL registrar, por cada archivo entregado, su nombre, su ruta, su tamaño en bytes, el inicio y fin de su slot y los instantes reales de captura.

#### Scenario: Franja de una hora en TV

- **WHEN** el cliente pide de 12:00 a 13:00 un medio de TV con grilla de 15 minutos
- **THEN** el sistema genera cuatro enlaces, uno por cada slot cubierto
- **AND** cada enlace apunta a su archivo exacto
- **AND** el historial muestra los cuatro archivos con su tamaño en bytes

#### Scenario: La carpeta del día no se comparte en modo franja

- **WHEN** el cliente pide una franja de una hora
- **THEN** ningún enlace generado apunta a la carpeta del día completo

### Requirement: La entrega por día completo genera un único enlace de carpeta

Cuando el cliente elige la entrega del día completo, el sistema SHALL generar un único enlace público de solo lectura sobre la carpeta del día del medio, y SHALL registrar igualmente el detalle de los archivos que esa carpeta contiene a efectos de control contable.

#### Scenario: Día completo

- **WHEN** el cliente pide el 14/11/2026 completo
- **THEN** el sistema genera un único enlace sobre la carpeta del día
- **AND** el registro de la solicitud incluye el detalle de archivos y bytes de esa carpeta

### Requirement: El sistema recomienda el día cuando la franja es extensa

El sistema SHALL comparar el costo operativo de la entrega elegida con el de la entrega por día y SHALL recomendar la entrega por día cuando la franja pedida supere el umbral configurable. El umbral SHALL ser un valor de configuración, con un valor inicial de 120 minutos, y la interfaz SHALL mostrar la comparación con el número de enlaces y el número de archivos de cada alternativa.

La recomendación SHALL ser orientativa: el cliente SHALL poder elegir la franja de todos modos, y la elección del cliente SHALL prevalecer sobre la recomendación.

#### Scenario: Franja por encima del umbral

- **WHEN** el cliente pide 2 horas y 30 minutos y el umbral vigente es de 120 minutos
- **THEN** la interfaz muestra la comparación entre la franja y el día completo, con el número de enlaces de cada alternativa
- **AND** señala el día completo como la opción recomendada

#### Scenario: Franja dentro del umbral

- **WHEN** el cliente pide 45 minutos
- **THEN** la interfaz no muestra la recomendación de cambiar a día completo

#### Scenario: El cliente elige la franja

- **WHEN** el cliente pide más de dos horas y elige la franja de todos modos
- **THEN** el sistema genera los enlaces de la franja y registra la elección

### Requirement: El registro contable conserva lo entregado

Cada solicitud SHALL persistir el solicitante, el medio, la fecha, el rango pedido, el modo de entrega, el estado, el número de archivos, el total de bytes, el número de enlaces y la fecha de vencimiento de los enlaces.

El detalle por archivo SHALL persistir nombre, ruta y tamaño en el momento de la entrega. El sistema SHALL NOT depender de que la fila del archivo siga existiendo en el catálogo para que el registro conserve esos valores.

#### Scenario: El registro sobrevive a la caída del material

- **WHEN** la fila del archivo en el catálogo se elimina después de la entrega
- **THEN** el registro de la solicitud conserva el nombre, la ruta y el tamaño del archivo entregado

#### Scenario: Totales consultables por cliente

- **WHEN** el administrador consulta el control de solicitudes de un cliente en un período
- **THEN** obtiene el total de solicitudes, minutos, archivos, bytes y enlaces entregados

### Requirement: Los enlaces de entrega tienen vigencia propia y son revocables

Los enlaces generados por el flujo de solicitud SHALL tener una vigencia configurable, con un valor inicial de 3 días contados desde el momento de la solicitud. El sistema SHALL NOT permitir que un enlace de entrega quede sin vencimiento ni que su vencimiento sea extendido.

El solicitante SHALL poder revocar una solicitud, lo que deja de servir sus enlaces y marca la solicitud como revocada. La revocación SHALL ser idempotente.

#### Scenario: Vigencia por defecto

- **WHEN** el sistema genera los enlaces de una solicitud
- **THEN** los enlaces vencen 3 días después del momento de la solicitud

#### Scenario: Revocación

- **WHEN** el solicitante revoca una solicitud viva
- **THEN** sus enlaces dejan de servir el material
- **AND** la solicitud queda en estado revocado

#### Scenario: Revocación repetida

- **WHEN** el solicitante revoca una solicitud ya revocada
- **THEN** el sistema responde con éxito y no altera el estado

### Requirement: El historial es paginado, buscable y ordenable

El historial de solicitudes SHALL presentar las solicitudes del usuario ordenadas de la más reciente a la más antigua, con paginación, un cuadro de búsqueda y encabezados de columna que ordenan de forma ascendente o descendente al alternar el clic.

El historial SHALL exponer el estado de cada solicitud con un vocabulario cerrado: la solicitud vigente, la expirada, la no disponible y la revocada.

#### Scenario: Orden por defecto

- **WHEN** el solicitante abre su historial
- **THEN** las solicitudes aparecen de la más reciente a la más antigua

#### Scenario: Orden por columna

- **WHEN** el solicitante hace clic en el encabezado de fecha
- **THEN** el orden se invierte y la interfaz lo refleja en el encabezado

#### Scenario: Búsqueda

- **WHEN** el solicitante escribe texto en el cuadro de búsqueda
- **THEN** el historial se filtra por medio, fecha o estado según el texto

### Requirement: La solicitud es idempotente

El sistema SHALL impedir que dos solicitudes vivas del mismo solicitante cubran el mismo medio, la misma fecha y el mismo rango. Ante una repetición, el sistema SHALL devolver la solicitud viva existente en lugar de crear una segunda ni generar enlaces adicionales.

#### Scenario: Repetición de la misma solicitud

- **WHEN** el solicitante repite una solicitud viva idéntica
- **THEN** el sistema devuelve la solicitud existente
- **AND** no se generan enlaces adicionales

#### Scenario: Revocación previa y repetición

- **WHEN** el solicitante revocó la solicitud y vuelve a pedir el mismo rango
- **THEN** el sistema crea una solicitud nueva con enlaces nuevos
