# spa-navigation Specification

## Purpose
TBD - created by archiving change spa-turbo-navigation. Update Purpose after archive.
## Requirements
### Requirement: Navegación interna sin recarga de página
El sistema SHALL navegar entre módulos internos (dashboard, archivos, admin, etc.) sin recargar la página completa, manteniendo header y sidebar visualmente estáticos.

#### Scenario: Click en enlace del sidebar
- **WHEN** el usuario hace click en un enlace del sidebar (ej. "Mis Archivos")
- **THEN** solo el contenido central cambia, el header y sidebar no parpadean ni se recargan

#### Scenario: URL actualizada en barra de direcciones
- **WHEN** el usuario navega a un módulo mediante Turbo
- **THEN** la URL en la barra de direcciones refleja el nuevo módulo correctamente

#### Scenario: Botón atrás/adelante del navegador
- **WHEN** el usuario usa los botones atrás/adelante del navegador
- **THEN** el contenido vuelve al módulo correspondiente sin recarga completa

### Requirement: Link activo del sidebar se actualiza
El sistema SHALL resaltar el link activo del sidebar según el módulo que el usuario está visitando, incluso cuando la navegación es via Turbo.

#### Scenario: Cambio de módulo activo
- **WHEN** el usuario navega de Dashboard a Mis Archivos
- **THEN** el link "Mis Archivos" queda resaltado y "Dashboard" pierde el resaltado

### Requirement: Alpine.js funciona en contenido nuevo
El sistema SHALL inicializar Alpine.js en el nuevo contenido tras cada navegación Turbo, de modo que los componentes reactivos del módulo destino funcionen correctamente.

#### Scenario: Componente Alpine en módulo destino
- **WHEN** el usuario navega a un módulo que usa Alpine.js (ej. Mis Archivos)
- **THEN** todos los componentes Alpine del módulo se inicializan y son interactivos

