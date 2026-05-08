## ADDED Requirements

### Requirement: Navegación secuencial entre archivos en el visor
El sistema SHALL permitir al usuario avanzar al siguiente o retroceder al archivo anterior dentro del visor a pantalla completa, sin cerrarlo.

#### Scenario: Navegar al siguiente archivo con botón
- **WHEN** el visor está abierto y existe un archivo siguiente en la lista navegable
- **THEN** al hacer click en el botón `>` se muestra el siguiente archivo en el visor, reseteando zoom, rotación y pan

#### Scenario: Navegar al archivo anterior con botón
- **WHEN** el visor está abierto y existe un archivo anterior en la lista navegable
- **THEN** al hacer click en el botón `<` se muestra el archivo anterior en el visor, reseteando zoom, rotación y pan

#### Scenario: Botón siguiente oculto en el último archivo
- **WHEN** el visor muestra el último archivo de la lista navegable
- **THEN** el botón `>` no es visible

#### Scenario: Botón anterior oculto en el primer archivo
- **WHEN** el visor muestra el primer archivo de la lista navegable
- **THEN** el botón `<` no es visible

### Requirement: Navegación por teclado en el visor
El sistema SHALL permitir usar las teclas de flecha izquierda y derecha para navegar entre archivos cuando el visor está abierto.

#### Scenario: Flecha derecha avanza al siguiente archivo
- **WHEN** el visor está abierto y el usuario presiona la tecla ArrowRight
- **THEN** el sistema navega al siguiente archivo (equivalente a hacer click en `>`)

#### Scenario: Flecha izquierda retrocede al archivo anterior
- **WHEN** el visor está abierto y el usuario presiona la tecla ArrowLeft
- **THEN** el sistema navega al archivo anterior (equivalente a hacer click en `<`)

#### Scenario: Teclas de flecha ignoradas cuando el visor está cerrado
- **WHEN** el visor está cerrado y el usuario presiona ArrowLeft o ArrowRight
- **THEN** no ocurre ninguna navegación de archivos

### Requirement: Indicador de posición en el visor
El sistema SHALL mostrar la posición del archivo actual dentro de la lista navegable en el header del visor.

#### Scenario: Indicador visible al abrir el visor
- **WHEN** el usuario abre un archivo en el visor
- **THEN** el header muestra un indicador con el formato "X / N" donde X es la posición (1-based) y N es el total de archivos navegables

#### Scenario: Indicador se actualiza al navegar
- **WHEN** el usuario navega al siguiente o anterior archivo
- **THEN** el indicador se actualiza reflejando la nueva posición

### Requirement: Las carpetas se excluyen de la navegación del visor
El sistema SHALL omitir las carpetas de la lista navegable del visor, incluyendo solo archivos.

#### Scenario: Carpetas no aparecen en la secuencia de navegación
- **WHEN** la carpeta actual contiene tanto archivos como subcarpetas y el usuario navega con las flechas
- **THEN** las subcarpetas son omitidas y solo se navega entre archivos
