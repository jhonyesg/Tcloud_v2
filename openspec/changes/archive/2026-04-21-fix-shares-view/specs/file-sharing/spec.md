## MODIFIED Requirements

### Requirement: Controlador de compartidos debe servir vista para navegación normal
El ShareController DEBERÁ retornar una vista Blade cuando el usuario accede directamente desde el navegador (no AJAX), y DEBERÁ retornar JSON cuando la request es AJAX.

#### Scenario: Acceso directo desde navegador
- **WHEN** usuario accede a /shares directamente desde el navegador
- **THEN** sistema SHALL mostrar la vista Blade 'shares.index' con los datos de shares del usuario

#### Scenario: Acceso via AJAX desde frontend
- **WHEN** frontend JavaScript hace request AJAX a /shares
- **THEN** sistema SHALL retornar JSON con la lista de shares

#### Scenario: Usuario no autenticado accede a /shares
- **WHEN** usuario no autenticado intenta acceder a /shares
- **THEN** sistema SHALL retornar error JSON 401 Unauthorized
