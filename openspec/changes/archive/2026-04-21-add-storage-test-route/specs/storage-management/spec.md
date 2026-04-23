## ADDED Requirements

### Requirement: Ruta de prueba de storage debe estar definida
El sistema DEBERÁ tener una ruta `GET /admin/storages/{storage}/test` que permita verificar si la ruta configurada de un storage es accesible.

#### Scenario: Ruta de prueba configurada
- **WHEN** se configura la ruta `/admin/storages/{storage}/test` en web.php
- **THEN** el método `test()` del `StorageProviderController` es accesible via HTTP GET

#### Scenario: Botón "Probar" funcional
- **WHEN** usuario hace clic en botón "Probar" en la UI de admin/storages
- **THEN** la aplicación muestra el resultado de verificar si la ruta configurada es accesible (local) o las credenciales S3 funcionan (S3)

#### Scenario: Verificación de storage local
- **WHEN** se llama a `/admin/storages/{id}/test` con un storage de tipo "local"
- **THEN** el sistema DEBERÁ verificar que `base_path` existe, es un directorio, y es legible
