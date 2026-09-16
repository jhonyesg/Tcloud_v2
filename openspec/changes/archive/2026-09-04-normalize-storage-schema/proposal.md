## Why

El esquema de storage acumula tres manchas de normalización: una columna muerta (`user_storages.transcription_enabled`), una columna derivada que nadie lee (`files.is_personal`, espejo de un prefijo de path), y un CHECK constraint de `external_sites.color` desincronizado con la validación de la app (8 colores en BD vs 16 en el controller) que provoca 500 al guardar un site con color nuevo. Es el primer change de una secuencia de tres (BD → backend → visual), cada uno con su fase de testing funcional.

## What Changes

- **Fix bug**: actualizar `external_sites_color_check` para aceptar los 16 colores que ya valida `ExternalSiteController` (blue, sky, cyan, teal, green, lime, yellow, amber, orange, red, rose, pink, fuchsia, purple, indigo, slate).
- **Droppear columna muerta**: eliminar `user_storages.transcription_enabled` y su índice parcial `idx_user_storages_tx_enabled` (documentada como MUERTA en la migración `2026_08_18_210000`; nadie la lee ni escribe).
- **Normalizar storage personal**: añadir `storage_providers.is_personal` (boolean, backfill desde el prefijo `/home/www/Usuarios_tcloud/`), reemplazar los 6+ chequeos de prefijo de path por la bandera, y eliminar `files.is_personal` + `idx_files_personal` (columna escrita en 9 lugares, leída en ninguno).
- **Alinear la BD con la app**: el trigger `fn_storage_provider_delete_quota` y `RecalcPersonalQuota` pasan de `base_path LIKE` a `is_personal = true`.

## Capabilities

### New Capabilities
- `personal-storage`: el storage personal se identifica por una bandera en `storage_providers`, no por prefijo de path ni por columna en `files`; la cuota personal se calcula sobre storages con `is_personal = true`.

### Modified Capabilities
- `external-sites-visual-catalog`: la BD SHALL imponer el mismo conjunto de colores que valida la aplicación (cierra el gap que hoy produce 500).
- `share-upload`: los `File` creados vía share upload ya no persisten `is_personal` (la columna se elimina).

## Impact

- **Migraciones**: 1 nueva en `app/database/migrations/` (puede dividirse en varias para orden de ejecución seguro).
- **Modelos**: `StorageProvider` (fillable + casts `is_personal`), `File` (quitar `is_personal` de fillable/casts), `UserStorage` (sin cambios, ya ignora la columna muerta).
- **Controladores**: `FileController` (upload quota, delete, `storages()`), `DashboardController` (`personalStorageId`), `UserController` (`createPersonalStorage`), `PasswordTokenService` (`createPersonalStorage`).
- **Comandos**: `RecalcPersonalQuota` (SQL), `TranscriptionTuneCommand` (string de pista obsoleto).
- **BD**: trigger `fn_storage_provider_delete_quota` reescrito para usar `is_personal`.
- **Specs**: `share-upload` y `external-sites-visual-catalog` requieren delta; `personal-storage` es nueva.

## Non-goals

- **No** se extraen las credenciales S3 del jsonb `storage_providers.config` (candidato para el change de backend/refactor).
- **No** se toca `user_storages.transcription_access` (viva y correcta).
- **No** se cambia el comportamiento de la cuota personal, solo su fuente de verdad.
- **No** se toca la UI ni el frontend (change visual posterior).
