## Purpose

Define el house style visual de los modales de la familia admin de storages (crear/editar storage, confirmación de borrado, gestión de usuarios), su feedback de errores y los botones de acción de la tabla, alineando la familia con el estilo que ya aplican los demás módulos admin de la plataforma.

## Requirements

### Requirement: House style del contenedor de modales
Los modales de la familia storage admin (crear, editar, confirmación de borrado y gestión de usuarios, tanto en `storages` como en las vistas hermanas `storage-users` y `user-storages`) SHALL usar un contenedor `rounded-2xl` con sombra prominente (`shadow-2xl`), sobre un overlay oscuro semitransparente con desenfoque de fondo (`bg-black/50 backdrop-blur-sm`) que incluya padding para que el modal no toque los bordes en pantallas pequeñas.

#### Scenario: Contenedor del modal de crear storage
- **WHEN** el admin abre el modal "Crear Storage"
- **THEN** el panel visible usa esquinas `rounded-2xl`, sombra `shadow-2xl`, y el overlay tiene fondo semitransparente con blur y padding lateral para pantallas móviles

#### Scenario: Contenedor consistente en toda la familia
- **WHEN** el admin compara los modales de crear, editar, eliminar y usuarios entre `storages`, `storage-users` y `user-storages`
- **THEN** todos comparten el mismo contenedor (esquinas, sombra, overlay con blur), el mismo estilo de labels (mayúsculas, pequeñas, gris) y el mismo estilo de inputs (borde, redondeo y anillo de foco en marca)

### Requirement: Footer de acciones con primaria destacada y destructivo en rojo
Los modales SHALL cerrar con una fila de botones donde la acción primaria ocupa el ancho flexible (`flex-1`) con fondo de marca (`brand-600`, hover `brand-700`), el botón Cancelar usa fondo neutro claro (`slate-100`), y toda acción destructiva de confirmación usa rojo (`red-500`, hover `red-600`).

#### Scenario: Botones del modal de crear
- **WHEN** el admin ve el footer del modal "Crear Storage"
- **THEN** el botón primario "Crear" es ancho flexible con fondo de marca y "Cancelar" es neutro claro a su lado

#### Scenario: Botones del modal de eliminar
- **WHEN** el admin abre el modal de confirmación de borrado
- **THEN** el botón "Eliminar" es rojo (preservando su estado de carga con spinner ya especificado) y "Cancelar" es neutro claro

### Requirement: Feedback de error del formulario de creación vía toast
El modal de creación de storage SHALL reportar errores de la petición POST `/admin/storages` mediante el toast existente de la vista (verde éxito / rojo error), sin usar `alert()` nativo.

#### Scenario: Error al crear storage
- **WHEN** la petición de creación falla (validación del servidor o error de red)
- **THEN** aparece el toast rojo con el mensaje de error del servidor y el modal permanece abierto para corregir

#### Scenario: Éxito al crear storage
- **WHEN** la petición de creación responde 200 OK
- **THEN** el modal se cierra, la lista se recarga y aparece el toast verde de confirmación

### Requirement: Conmutación de tipo Local/S3 reactiva en el modal de crear
El formulario de creación SHALL conmutar la visibilidad de los campos S3 (región, key, secret, bucket) mediante el estado de Alpine.js de la vista, sin manipulación directa del DOM vía `onchange`.

#### Scenario: Seleccionar tipo S3
- **WHEN** el admin elige "S3" en el select de tipo del modal de crear
- **THEN** el bloque de campos S3 aparece y el de Base Path se oculta, sin recargar ni manipular el DOM fuera del estado de Alpine

#### Scenario: Volver a Local
- **WHEN** el admin vuelve a elegir "Local"
- **THEN** los campos S3 se ocultan y Base Path reaparece

### Requirement: Botones de acción de la tabla con estilo unificado
Las acciones por fila (Usuarios, Probar, Editar, Eliminar) en las tres vistas de la familia SHALL usar un estilo visual consistente entre sí (mismo tamaño, redondeo y jerarquía de color: verde para Usuarios, neutro para Probar, índigo/marca para Editar, rojo para Eliminar), preservando su texto visible.

#### Scenario: Acciones en la tabla de storages
- **WHEN** el admin ve la columna Acciones de la tabla (o los botones de la tarjeta en móvil)
- **THEN** los cuatro botones comparten forma y tamaño, y el texto visible de cada uno es preservado para que la guía interactiva pueda localizarlos

### Requirement: El restyle no altera comportamiento funcional
El restyle visual SHALL preservar todos los requisitos funcionales existentes: flujo de asignación de usuarios, chips, typeahead, checkbox "Todas las personas", estados de carga de acciones destructivas, ordenamiento/filtros de tabla y tour guiado.

#### Scenario: Regresión del tour guiado
- **WHEN** el admin ejecuta la guía interactiva del módulo después del restyle
- **THEN** todos los pasos localizan sus elementos (encabezados, fila, botones por texto) y el tour completa sin pasos huérfanos

### Requirement: El modal de borrado obtiene el conteo de archivos bajo demanda

El modal de confirmación de borrado de storage SHALL mostrar el número de archivos asociados al storage seleccionado, obtenido mediante `GET /admin/storages/{id}` en el momento de abrir el modal — nunca a partir del listado. Mientras el conteo no esté disponible, SHALL mostrar un estado "Calculando archivos asociados…"; si el request falla, SHALL mostrar el texto genérico "los archivos asociados a este storage" sin inventar un número. El botón "Eliminar" SHALL permanecer habilitado en todos los casos.

#### Scenario: Conteo disponible al abrir el modal
- **WHEN** el admin hace clic en "Eliminar" sobre una fila de storage
- **THEN** el modal se abre mostrando el nombre del storage y un estado "Calculando archivos asociados…"
- **AND** al responder `GET /admin/storages/{id}`, el aviso muestra "…se eliminarán permanentemente: N archivo(s) asociado(s)" con el número exacto

#### Scenario: Conteo no disponible — fallback textual
- **WHEN** el request `GET /admin/storages/{id}` falla o no responde a tiempo
- **THEN** el aviso muestra "los archivos asociados a este storage" sin cifra
- **AND** el botón "Eliminar" sigue habilitado y el borrado procede normalmente

#### Scenario: El conteo no se pide al listar
- **WHEN** el admin carga `/admin/storages` (listado)
- **THEN** NO se dispara ningún `GET /admin/storages/{id}` por fila
- **AND** el conteo solo se solicita al abrir el modal de borrado de un storage concreto
