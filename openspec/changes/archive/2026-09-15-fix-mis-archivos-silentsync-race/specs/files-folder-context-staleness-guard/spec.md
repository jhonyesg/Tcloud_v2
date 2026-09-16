## Purpose

Garantiza que el listado de archivos que el usuario ve en Mis Archivos corresponda siempre a la carpeta y el almacenamiento que está visualizando, descartando silenciosamente las responses de los sincronizados en background (`silentSync`, `loadFiles`, `loadMore`) que lleguen después de que el usuario haya navegado a otra carpeta. Cierra la regresión que producía "carpeta vacía" o contenido de la carpeta equivocada al alternar rápido entre fechas dentro del mismo storage.

## ADDED Requirements

### Requirement: silentSync descarta responses obsoletas por cambio de carpeta

El `silentSync()` de Mis Archivos SHALL descartar la response (sin escribirla en `this.files`) cuando, entre el momento en que se disparó la request y el momento en que llegó la response, el usuario haya navegado a otra carpeta o a otro almacenamiento.

#### Scenario: Navegación rápida a otra carpeta antes de que vuelva silentSync

- **WHEN** el usuario está en la carpeta X y dispara `silentSync(X)`, luego navega a la carpeta Y dentro del mismo storage
- **AND** la response de `silentSync(X)` llega DESPUÉS de que la nueva carpeta Y ya se haya cargado
- **THEN** los datos de X NO SHALL escribirse en `this.files`
- **AND** la UI SHALL seguir mostrando el contenido de Y sin parpadeo ni sobreescritura

#### Scenario: Vuelta a una carpeta anterior mientras hay silentSync pendiente

- **WHEN** el usuario navega X→Y→X y `silentSync(Y)` queda en vuelo
- **AND** la response de `silentSync(Y)` llega cuando el usuario ya está de vuelta en X
- **THEN** los datos de Y NO SHALL escribirse sobre `this.files` de X

#### Scenario: Cambio de almacenamiento invalida silentSync pendiente

- **WHEN** el usuario está en un storage A y dispara `silentSync(storageA, folderA1)`
- **AND** antes de que vuelva, el usuario entra a un storage B distinto (que resetea `currentFolder` a null)
- **THEN** la response de `silentSync` del storage A NO SHALL escribirse en `this.files` del storage B

### Requirement: loadFiles y loadMore también descartan responses obsoletas

`loadFiles()` y `loadMore()` SHALL aplicar la misma guarda que `silentSync()` para defensa en profundidad. Si entre el disparo de la request y la llegada de la response el usuario navegó a otra carpeta o cambió de almacenamiento, la response NO SHALL escribirse en `this.files`.

#### Scenario: loadFiles queda obsoleta por navegación rápida

- **WHEN** `loadFiles(folderX)` está en vuelo
- **AND** el usuario hace clic en `folderY` antes de que vuelva la response de X
- **THEN** la response de X NO SHALL asignarse a `this.files`
- **AND** SHALL valer la response abortada y la nueva request de Y será la que pinte

#### Scenario: loadMore obsoleto por navegación atrás

- **WHEN** el usuario está paginando la carpeta X (page=2 con `loadMore` en vuelo)
- **AND** hace clic en el breadcrumb raíz de su storage antes de que vuelva la response
- **THEN** los archivos extra de X (page=2) NO SHALL concatenarse a `this.files` cuando el usuario ya está en el root

### Requirement: El descarte es silencioso para el usuario

Cuando una response es descartada por obsolescencia, el sistema NO SHALL mostrar un toast, error, ni warning. El reemplazo natural por la siguiente navegación correcta es el comportamiento esperado y visible para el usuario.

#### Scenario: Response obsoleta descartada sin UI de error

- **WHEN** una response de `silentSync` o `loadFiles` queda obsoleta y se descarta
- **THEN** NO SHALL aparecer ningún toast, mensaje de error, ni warning modal
- **AND** la UI SHALL seguir mostrando el contenido de la carpeta actual sin intervención

### Requirement: Estado de generación es interno de la pestaña Alpine

El contador de generación que detecta obsolescencia SHALL ser estado interno del componente Alpine `fileManager`. NO SHALL persistirse en `localStorage`, NO SHALL comunicarse con el backend, y NO SHALL ser visible para otras pestañas. Cada pestaña mantiene su propio contador.

#### Scenario: Cada pestaña tiene su propio contador

- **WHEN** el usuario abre dos pestañas de Mis Archivos en paralelo
- **AND** navega rápidamente en una de ellas
- **THEN** la otra pestaña NO SHALL verse afectada por los descartes ni por el contador de la primera
