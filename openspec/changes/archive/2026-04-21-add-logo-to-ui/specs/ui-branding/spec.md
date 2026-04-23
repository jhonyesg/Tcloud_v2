## ADDED Requirements

### Requirement: Favicon personalizado con logo.png
El sistema DEBERÁ usar logo.png como favicon del navegador.

#### Scenario: Favicon configurado
- **WHEN** usuario accede a cualquier página del sistema
- **THEN** el favicon mostrado en el navegador SHALL ser logo.png

### Requirement: Logo en página de login
El sistema DEBERÁ mostrar logo.png centrado encima del texto "Tcloud" en la página de login.

#### Scenario: Login con logo
- **WHEN** usuario accede a /login
- **THEN** SHALL mostrar logo.png con tamaño apropiado centrado, seguido de "Tcloud"

### Requirement: Logo en header del panel
El sistema DEBERÁ mostrar logo.png junto al texto "Tcloud" en la barra superior.

#### Scenario: Header con logo
- **WHEN** usuario autenticado ve el header
- **THEN** SHALL mostrar logo.png de 32x32 al lado de "Tcloud"
