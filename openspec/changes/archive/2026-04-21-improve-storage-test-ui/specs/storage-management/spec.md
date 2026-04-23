## MODIFIED Requirements

### Requirement: Mensajes de prueba en español
Todos los mensajes de respuesta del método `test()` DEBERÁN estar en español.

#### Scenario: Storage local accesible
- **WHEN** se prueba un storage local con ruta válida
- **THEN** respuesta JSON.message SHALL ser "La ruta local es accesible"

#### Scenario: Storage local no accesible
- **WHEN** se prueba un storage local con ruta inválida
- **THEN** respuesta JSON.message SHALL ser "La ruta local no es accesible"

#### Scenario: Credenciales S3 faltantes
- **WHEN** se prueba un storage S3 con credenciales faltantes
- **THEN** respuesta JSON.message SHALL ser "Credenciales S3 inválidas: falta key o secret"

#### Scenario: Error de conexión S3
- **WHEN** se prueba un storage S3 y la conexión falla
- **THEN** respuesta JSON.message SHALL contener "Error de conexión S3:"

### Requirement: Toast notifications en vez de modal
La UI de resultado de prueba DEBERÁ usar toast notifications en Alpine.js en lugar de modal.

#### Scenario: Toast de éxito
- **WHEN** la prueba de storage retorna success=true
- **THEN** mostrar toast verde con mensaje de éxito, auto-dismiss 3s

#### Scenario: Toast de error
- **WHEN** la prueba de storage retorna success=false
- **THEN** mostrar toast rojo con mensaje de error, auto-dismiss 4s
