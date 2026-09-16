## 1. Config y modelo

- [x] 1.1 Añadir `config/storage.php` con `personal_base_path` (valor `/home/www/Usuarios_tcloud/`) y registrar el config en `config/app.php` si aplica
- [x] 1.2 `StorageProvider`: añadir `is_personal` a `$fillable` y `$casts` (boolean)
- [x] 1.3 `File`: quitar `is_personal` de `$fillable` y `$casts`
- [x] 1.4 `UserStorage`: verificar que no referencia `transcription_enabled` (no debe)

## 2. Migración de normalización

- [x] 2.1 Crear migración `2026_09_04_XXXXXX_normalize_storage_schema.php`:
  - [x] 2.1.1 `ADD COLUMN storage_providers.is_personal boolean NOT NULL DEFAULT false`
  - [x] 2.1.2 Backfill: `UPDATE storage_providers SET is_personal = true WHERE base_path LIKE config('storage.personal_base_path') || '%'`
  - [x] 2.1.3 `DROP INDEX idx_user_storages_tx_enabled` + `DROP COLUMN user_storages.transcription_enabled`
  - [x] 2.1.4 `DROP INDEX idx_files_personal` + `DROP COLUMN files.is_personal`
  - [x] 2.1.5 Reemplazar `external_sites_color_check` por CHECK con los 16 colores
  - [x] 2.1.6 Recrear `fn_storage_provider_delete_quota` leyendo `OLD.is_personal` (CREATE OR REPLACE)
- [x] 2.2 Implementar `down()` completo: restaurar columnas, índices, CHECK de 8 colores y trigger por prefijo

## 3. App: reemplazar prefijo por bandera

- [x] 3.1 `FileController::storages()` (línea ~901): `is_personal` del storage en vez de `str_starts_with(base_path)`
- [x] 3.2 `FileController::store()` (upload, líneas ~392 y ~437): usar `$storage->is_personal` para cuota e incremento
- [x] 3.3 `FileController` (líneas ~945 y ~433): reemplazar chequeos de prefijo y escritura de `is_personal`
- [x] 3.4 `DashboardController::personalStorageId()`: `where('storage_providers.is_personal', true)` en vez de `first(fn ...)`
- [x] 3.5 `UserController::createPersonalStorage()`: crear con `is_personal => true` y `base_path` desde config
- [x] 3.6 `PasswordTokenService::createPersonalStorage()`: idem, con `is_personal => true` y config
- [x] 3.7 `RecalcPersonalQuota`: SQL con `sp.is_personal = true` en vez de `base_path LIKE`
- [x] 3.8 `TranscriptionTuneCommand` (línea ~70): actualizar la pista que menciona `user_storages.transcription_enabled`

## 4. Productores de files: quitar is_personal

- [x] 4.1 `FileController` (líneas 298, 1044, 1151, 1181): eliminar `'is_personal' => false`
- [x] 4.2 `PublicShareController` (líneas 459, 537): eliminar `'is_personal' => false`
- [x] 4.3 `StorageSyncService` (línea 398): eliminar `'is_personal' => false`
- [x] 4.4 `DiskScannerService` (líneas 155, 517): eliminar `'is_personal' => false`

## 5. Testing funcional

- [x] 5.1 `php artisan migrate` en entorno de prueba y verificar esquema: `is_personal` en storage_providers, columnas dropeadas, CHECK de 16 colores
- [x] 5.2 Verificar backfill: `SELECT id, name, base_path, is_personal FROM storage_providers ORDER BY is_personal DESC` — los personales en true
- [x] 5.3 `php artisan files:recalc-personal-quota` — debe reportar 0 cambios (o corregir solo lo que el backfill dejó)
- [x] 5.4 Crear y editar un site externo con color `indigo` (y un color inválido → 422) — sin 500
- [x] 5.5 Subir un archivo a storage personal y verificar que la cuota se incrementa; borrar el storage personal y verificar que la cuota se descuenta (trigger)
- [x] 5.6 Subir un archivo vía share upload y verificar que persiste sin `is_personal`
- [x] 5.7 Correr la suite de tests existente (`php artisan test`) y confirmar que no hay regresiones
- [x] 5.8 Verificación en producción: migrar, recalc, prueba manual de site con color nuevo y de cuota personal
