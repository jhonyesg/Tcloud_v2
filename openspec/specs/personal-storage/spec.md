## Purpose

Define cómo se identifica el storage personal de cada usuario: una bandera `is_personal` en `storage_providers` reemplaza la derivación por prefijo de path y la columna espejo `files.is_personal`, de modo que la cuota personal y las operaciones de archivo usan una única fuente de verdad.

## Requirements

### Requirement: El storage personal se identifica por bandera en storage_providers

El sistema SHALL marcar con `storage_providers.is_personal = true` los storages cuyo `base_path` cae bajo el directorio de usuarios personales (`/home/www/Usuarios_tcloud/`). La bandera SHALL ser la única fuente de verdad para decidir si un storage es personal; el sistema SHALL NOT derivar esa decisión comparando prefijos de path en cada consulta.

#### Scenario: Backfill marca storages personales existentes
- **WHEN** se ejecuta la migración de normalización
- **THEN** todos los `storage_providers` con `base_path` bajo `/home/www/Usuarios_tcloud/` quedan con `is_personal = true`
- **AND** el resto queda con `is_personal = false`

#### Scenario: Creación de storage personal lo marca
- **WHEN** el sistema crea un storage personal para un usuario (bienvenida o perfil)
- **THEN** el storage se crea con `is_personal = true` y su `base_path` bajo el directorio de usuarios personales

#### Scenario: La cuota personal usa la bandera
- **WHEN** se recalcula o consulta `users.personal_used_bytes`
- **THEN** la suma de tamaños se calcula sobre archivos de storages con `is_personal = true`, sin comparar prefijos de path

### Requirement: files.is_personal deja de existir

El sistema SHALL eliminar la columna `files.is_personal` y su índice parcial `idx_files_personal`. Ningún productor de filas en `files` SHALL escribir esa columna, y ninguna consulta SHALL leerla.

#### Scenario: Migración elimina la columna
- **WHEN** se ejecuta la migración de normalización
- **THEN** `files.is_personal` y `idx_files_personal` dejan de existir en el esquema
- **AND** las filas existentes no pierden ningún dato (la columna era espejo de la bandera del storage)

#### Scenario: Productores de archivos no la escriben
- **WHEN** se crea un `File` por cualquier vía (upload, share upload, scanner, sync)
- **THEN** la fila se crea sin la columna `is_personal` y la operación no falla

### Requirement: El borrado de un storage personal ajusta la cuota

El trigger de borrado de `storage_providers` SHALL descontar `personal_used_bytes` de los usuarios afectados cuando el storage borrado tiene `is_personal = true`, en lugar de comparar el prefijo del path.

#### Scenario: Borrar storage personal descuenta cuota
- **WHEN** se elimina un storage con `is_personal = true`
- **THEN** `personal_used_bytes` de cada `owner_id` con archivos en ese storage se reduce en la suma de tamaños de sus archivos no-carpeta
- **AND** el contador nunca baja de cero

#### Scenario: Borrar storage no personal no toca cuota
- **WHEN** se elimina un storage con `is_personal = false`
- **THEN** ningún `personal_used_bytes` se modifica
