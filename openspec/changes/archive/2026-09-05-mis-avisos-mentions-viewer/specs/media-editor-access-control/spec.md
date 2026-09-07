## MODIFIED Requirements

### Requirement: El endpoint de procesamiento verifica acceso

El endpoint `POST /files/{file}/clip` SHALL rechazar peticiones de usuarios sin acceso al editor y SHALL verificar que el usuario autenticado tenga acceso al archivo fuente: ser admin, ser propietario del archivo o tener permiso de lectura en el storage del archivo. Un archivo sin relación con el usuario SHALL producir rechazo aunque el editor esté habilitado.

#### Scenario: Petición de usuario sin feature habilitado
- **WHEN** un usuario sin `media_editor_enabled` (ni admin) llama a `POST /files/{file}/clip`
- **THEN** el servidor responde con HTTP 403

#### Scenario: Petición sobre archivo ajeno sin permiso
- **WHEN** un usuario con `media_editor_enabled = true` llama a `POST /files/{file}/clip` sobre un archivo de un storage donde no tiene ningún permiso y no es propietario
- **THEN** el servidor responde con HTTP 403 sin procesar el corte

#### Scenario: Petición legítima de lectura sobre storage contratado
- **WHEN** un usuario con `media_editor_enabled = true` llama a `POST /files/{file}/clip` sobre un archivo de un storage donde tiene permiso de lectura
- **THEN** el servidor procesa el corte (comportamiento ya previsto por la spec del editor)

## ADDED Requirements

### Requirement: Los endpoints auxiliares del editor verifican acceso al archivo

Los endpoints auxiliares del editor de medios SHALL aplicar la misma verificación de acceso al archivo fuente que el endpoint de corte: miniaturas de línea de tiempo, re-generación de un trabajo propio y stream de previews temporales limitados al token del trabajo del propio usuario.

#### Scenario: Miniaturas de un archivo ajeno
- **WHEN** un usuario solicita miniaturas (`clip-thumbs` / `clip-thumb`) de un archivo de un storage donde no tiene permiso de lectura y no es propietario (ni es admin)
- **THEN** el servidor responde con HTTP 403 y no genera ni sirve imágenes

#### Scenario: Re-clip de un trabajo ajeno
- **WHEN** un usuario solicita re-generar (`reclip`) un trabajo de edición que no le pertenece
- **THEN** el servidor responde con HTTP 403 o 404 sin ejecutar el trabajo

#### Scenario: Historial propio
- **WHEN** un usuario consulta `/media-clip/history`
- **THEN** solo ve sus propios trabajos de edición
