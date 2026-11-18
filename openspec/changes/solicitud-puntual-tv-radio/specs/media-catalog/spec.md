## Purpose

Definir el catálogo explícito de medios direccionables del sistema: cómo se identifica un canal de TV o Radio cuando vive dentro de un storage agrupado por subcarpeta, cómo se detectan los sub-canales de los contenedores Emisoras 01/02/03/04 y cómo el administrador los da de alta para que pasen a estar disponibles para el cliente.

## ADDED Requirements

### Requirement: El medio se identifica por storage y ruta interna

Un medio direccionable SHALL identificarse por el par `(storage_id, subpath)`, donde `subpath` es la ruta interna del canal dentro del storage, vacía cuando el medio es el storage completo. El catálogo SHALL persistir, además, un nombre legible, el tipo del medio (`tv` o `radio`) y su región.

El catálogo SHALL NOT crear storages nuevos, SHALL NOT mover filas de `files` y SHALL NOT duplicar contenido ya catalogado. Un sub-canal que ya existe como storage propio SHALL NOT volver a declararse como medio sobre el mismo storage.

#### Scenario: Un medio es el storage completo

- **WHEN** se declara el canal `01 Caracol Tv`, cuyo storage no contiene otro canal
- **THEN** el medio queda registrado con `subpath` vacío y apunta al storage de origen

#### Scenario: Un medio vive dentro de un storage agrupado

- **WHEN** se declara el canal `Antioquia/BLU`, que vive dentro del storage `02 Emisoras 01 Reg`
- **THEN** el medio queda registrado con `subpath = Antioquia/BLU` sobre ese storage
- **AND** no se crea ningún storage nuevo ni se altera ninguna fila de `files`

#### Scenario: Un canal que ya es storage no se duplica

- **WHEN** el canal `Antioquia/CamaraFm` ya tiene un storage propio declarado
- **THEN** el catálogo no lo vuelve a declarar como medio del storage contenedor

### Requirement: La detección de sub-canales reconoce el layout físico

El sistema SHALL detectar un sub-canal dentro de un storage contenedor cuando la carpeta candidata contenga directamente subcarpetas con nombre de día válido `DDMMYYYY`. La detección SHALL recorrer los layouts físicos existentes: plano (el canal es hijo directo del storage) y agrupado por subcarpeta (el canal cuelga de una carpeta regional).

La detección SHALL EXCLUIR las carpetas cuyo nombre está en la lista de exclusión de metadatos del sistema (`System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, `.Trash*`, `lost+found` y todo lo que empiece por punto) y toda carpeta que no contenga subcarpetas de día.

#### Scenario: Carpeta con días válidos es un canal

- **WHEN** la carpeta `Antioquia/BLU` contiene subcarpetas `31082026`, `31072026` y `30082026`
- **THEN** el sistema la clasifica como sub-canal y propone declararla

#### Scenario: Carpeta regional no es un canal

- **WHEN** la carpeta `Antioquia` contiene `BLU`, `Caracol` y `Colmundo` pero ninguno de esos nombres es un día
- **THEN** el sistema no la clasifica como sub-canal

#### Scenario: Carpeta excluida del sistema

- **WHEN** la carpeta candidata se llama `$RECYCLE.BIN`
- **THEN** el sistema la excluye y no la propone

### Requirement: El remapeo administrativo previsualiza antes de escribir

La pantalla de administración de medios SHALL ofrecer una acción de remapeo por storage contenedor. La acción SHALL presentar en pantalla, antes de escribir, el detalle de cada sub-canal detectado: su ruta interna, el nombre y la región propuestos, y si queda fuera por ya estar declarado.

La acción SHALL NOT escribir ninguna modificación hasta que el operador confirme de forma explícita. La confirmación SHALL ser la misma para la interfaz y para el comando de consola, y ambas SHALL usar el mismo conjunto de validaciones.

#### Scenario: Previsualización sin escritura

- **WHEN** el administrador abre la acción de remapeo sobre un contenedor
- **THEN** el sistema lista los sub-canales detectados y los ya declarados
- **AND** no se crea ni se modifica ningún medio

#### Scenario: Confirmación del remapeo

- **WHEN** el administrador confirma la previsualización
- **THEN** el sistema declara los sub-canales propuestos con su nombre, tipo y región
- **AND** los sub-canales ya declarados quedan intactos

#### Scenario: Consola y interfaz comparten reglas

- **WHEN** el comando de consola se ejecuta con el modo de solo informe
- **THEN** produce el mismo listado de sub-canales propuestos que la previsualización de la interfaz
- **AND** no escribe ninguna modificación

### Requirement: El nombre del medio desambigua por región

El nombre legible de un medio SHALL incorporar la región cuando el canal cuelgue de una carpeta regional, de modo que dos canales homónimos de regiones distintas sean distinguibles. El sistema SHALL ofrecer al administrador la edición del nombre y de la región antes de declarar el medio.

#### Scenario: Dos canales homónimos de regiones distintas

- **WHEN** se detectan `Antioquia/Caracol` y `Radios/Bogota/CARACOL`
- **THEN** los nombres propuestos incluyen la región y son distinguibles entre sí

#### Scenario: El administrador ajusta el nombre

- **WHEN** el administrador edita el nombre o la región propuestos antes de confirmar
- **THEN** el medio se declara con los valores editados
