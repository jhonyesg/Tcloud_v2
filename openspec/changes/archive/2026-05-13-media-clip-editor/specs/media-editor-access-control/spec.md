## ADDED Requirements

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
El endpoint `POST /files/{file}/clip` SHALL rechazar peticiones de usuarios sin acceso al editor.

#### Scenario: Petición de usuario sin feature habilitado
- **WHEN** un usuario sin `media_editor_enabled` (ni admin) llama a `POST /files/{file}/clip`
- **THEN** el servidor responde con HTTP 403
