## ADDED Requirements

### Requirement: Verificación de permiso de escritura al crear carpeta o subir archivo

`FileController::store` SHALL invocar `User::hasStoragePermission($storageId, 'write')` antes de ejecutar cualquier `mkdir()` o `Storage::put()`, y SHALL abortar con HTTP 403 + `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}` (o mensaje análogo para upload) si la verificación falla. La verificación aplica también a `PublicShareController::createFolder` y a `PublicShareController::upload` para mantener el contrato consistente entre los dos puntos de entrada de Mis Archivos.

#### Scenario: Usuario sin permiso de escritura intenta crear carpeta
- **WHEN** el usuario no tiene `user_storages.permissions` `'write'` ni `'full'` sobre el `storage_provider_id` destino
- **AND** llama a `POST /files` con `is_folder=true`
- **THEN** `FileController::store` responde HTTP 403 con `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}`
- **AND** no se ejecuta `mkdir()` ni se crea fila en `files`
- **AND** no se registra error en logs del servidor (es rechazo esperado, no error)

#### Scenario: Admin bypassa el chequeo de escritura
- **WHEN** el usuario tiene `role = 'admin'`
- **AND** llama a `POST /files` con `is_folder=true`
- **THEN** el chequeo de permiso retorna `true` sin consultar `user_storages`
- **AND** el flujo continúa normalmente hacia `mkdir()` + `File::create`

#### Scenario: Usuario con permiso upload no puede crear carpetas
- **WHEN** el usuario tiene `user_storages.permissions = 'upload'` (solo subir, no crear carpetas)
- **AND** llama a `POST /files` con `is_folder=true`
- **THEN** `FileController::store` responde HTTP 403 con `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}`
- **AND** puede seguir subiendo archivos (el chequeo es independiente por tipo de operación)
