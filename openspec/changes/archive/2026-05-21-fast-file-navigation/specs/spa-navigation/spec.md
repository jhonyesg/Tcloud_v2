## MODIFIED Requirements

### Requirement: Alpine.js funciona en contenido nuevo
El sistema SHALL inicializar Alpine.js en el nuevo contenido tras cada navegacion, de modo que los componentes reactivos del modulo destino funcionen correctamente. El componente `fileManager` SHALL exponer los estados `isNavigating`, `navigatingToId`, `currentPage`, y `hasMore` para controlar feedback visual y paginacion durante navegacion.

#### Scenario: Componente Alpine en modulo destino
- **WHEN** el usuario navega a Mis Archivos
- **THEN** todos los componentes Alpine del modulo se inicializan y son interactivos

#### Scenario: Estado de navegacion y paginacion disponible
- **WHEN** el componente `fileManager` se inicializa
- **THEN** `isNavigating` es `false`, `navigatingToId` es `null`, `currentPage` es 1, `hasMore` es `false`

### Requirement: Reset de paginacion al cambiar carpeta
El sistema SHALL resetear el estado de paginacion completo al navegar a una carpeta diferente o al storage raiz.

#### Scenario: Navegacion a sub-carpeta resetea pagina
- **WHEN** el usuario hace clic en una carpeta
- **THEN** `currentPage` se resetea a 1, `hasMore` a `false`, y `files` se limpia antes del primer fetch

#### Scenario: Navegacion a raiz resetea pagina
- **WHEN** el usuario hace clic en "Raiz" del breadcrumb o en el storage
- **THEN** el estado de paginacion se resetea identico al anterior
