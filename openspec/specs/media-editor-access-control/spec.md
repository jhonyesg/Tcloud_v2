# media-editor-access-control Specification

## Purpose
TBD - created by archiving change media-clip-editor. Update Purpose after archive.

## Requirements

### Requirement: Feature flag de editor de medios por usuario
La tabla `users` SHALL incluir la columna `media_editor_enabled` (boolean, default false) para controlar el acceso al editor de corte por usuario.

#### Scenario: Admin siempre tiene acceso
- **WHEN** un usuario con `role = 'admin'` accede al módulo de archivos
- **THEN** el botón "✂ Cortar" aparece en las acciones de archivos mp4/mp3/m4a independientemente del valor de `media_editor_enabled`

#### Scenario: Usuario normal sin feature habilitado
- **WHEN** un usuario con `role = 'user'` y `media_editor_enabled = false` accede al módulo de archivos
- **THEN** el botón "✂ Cortar" no aparece en ningún archivo

#### Scenario: Usuario normal con feature habilitado
- **WHEN** un usuario con `role = 'user'` y `media_editor_enabled = true` accede al módulo de archivos
- **THEN** el botón "✂ Cortar" aparece en las acciones de archivos mp4/mp3/m4a del storage activo, independientemente de si tiene permisos de escritura o solo lectura

### Requirement: El admin puede activar y desactivar el feature por usuario
La vista de administración SHALL permitir al admin cambiar el valor de `media_editor_enabled` para cualquier usuario.

#### Scenario: Admin activa el editor para un usuario
- **WHEN** el admin activa el toggle de "Editor de Medios" para un usuario en el panel de administración
- **THEN** el sistema actualiza `media_editor_enabled = true` para ese usuario
- **THEN** el usuario puede usar el editor en su próxima visita al módulo de archivos

#### Scenario: Admin desactiva el editor
- **WHEN** el admin desactiva el toggle de "Editor de Medios" para un usuario
- **THEN** el sistema actualiza `media_editor_enabled = false`
- **THEN** el botón ✂ deja de aparecer para ese usuario

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
