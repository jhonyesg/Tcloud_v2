## 1. Esquema y configuración

- [ ] 1.1 Crear migración `add_backup_mapping_to_storage_providers`: `backup_storage_id` (bigint nullable, FK `storage_providers.id` con `ON DELETE SET NULL`, índice parcial) y `backup_folder_name` (varchar(120) nullable) en `storage_providers`
- [ ] 1.2 Crear migración `create_backup_requests_table`: `user_id`, `source_storage_id`, `source_folder_id`, `day` (char(8)), `motivo` (text), `status` (string, con CHECK sobre `ready|unavailable|expired|revoked`), `backup_storage_id`, `backup_path`, `share_id` (FK null `ON DELETE SET NULL`), `link_expires_at`, timestamps; índices por `(user_id, created_at DESC)` y `(user_id, status)`
- [ ] 1.3 Añadir `backup_storage_id` y `backup_folder_name` al `$fillable` de `StorageProvider` y a la lista de atributos editables del admin de storages (mismo tratamiento que `duplicate_of_storage_id`: escribible solo por el admin, nunca mass-assignable desde Mis Archivos)
- [ ] 1.4 Crear `app/config/backups.php` con `link_validity_days` (default 2) y `min_motivo_length` (default 10), leídos de env con default seguro
- [ ] 1.5 Crear el modelo `App\Models\BackupRequest` con casts (`day` string, `link_expires_at` datetime), relaciones `user`, `sourceStorage`, `sourceFolder`, `backupStorage`, `share`, y un scope `vivas` (`status = ready` y no expirada)
- [ ] 1.6 Migración `down()` de ambas: revertir columnas y eliminar la tabla, sin tocar `shares` ni `files`

## 2. Resolución del vínculo y de la carpeta-día

- [ ] 2.1 Implementar en `StorageProvider` los helpers `hasBackupMapping(): bool` y `backupMapping(): ?array` (devuelve `backup_storage_id`, `backup_folder_name` y el modelo del storage de backup, o `null` si el FK apunta a un storage inexistente o no local)
- [ ] 2.2 Crear `App\Services\MisArchivos\BackupResolver` con `isDayFolderName(string $name): bool` (8 dígitos, día 01-31, mes 01-12, año de 4 dígitos) y `parseDayFolder(string $name): ?string` (devuelve la fecha normalizada)
- [ ] 2.3 Implementar en `BackupResolver` `resolveDayFolder(StorageProvider $source, string $dayName): ?array` que resuelva la fila del día **anclada en `backup_storage_id`** (nunca por nombre cruzando storages), con la ruta relativa `<backup_folder_name>/<DDMMYYYY>`
- [ ] 2.4 Implementar la guarda de contención por cadena (sin `realpath()`) de la ruta resuelta dentro del `base_path` del storage de backup, con el mismo criterio de `FilesystemListingService::resolveAbsolutePath`
- [ ] 2.5 Implementar la comprobación de existencia en disco de la carpeta-día del backup pasando **siempre** por `MountGuard::detachedAncestor()` antes de tocar el path, y clasificar el resultado en `backup_not_ready` / `backup_expired_window`
- [ ] 2.6 Cuando la fila del día no exista en el catálogo, resolver la carpeta-día padre y delegar en `StorageSyncService::syncFolder($backupStorage, $parentFolderId)` para materializarla; nunca insertar filas de `files` por otra vía
- [ ] 2.7 Unit tests de `BackupResolver`: día válido/inválido, día imposible, `20042023`, resolución con carpeta ausente, contención fallida, storage de backup inexistente o no local

## 3. Solicitud y creación del enlace

- [ ] 3.1 Crear `App\Services\MisArchivos\BackupRequestService` con `create(User $user, File $sourceFolder, string $motivo): BackupRequest` que valide en este orden: `read` sobre el storage de origen → carpeta-día → mapeo existente → carpeta-día disponible en el backup → motivo con longitud mínima
- [ ] 3.2 Implementar la creación del `Share` dentro del servicio (nunca vía `ShareController::store()`): `permissions = 'read'`, `expires_at = now + config('backups.link_validity_days')`, `created_by = $user->id`, `token` con el mismo alfabeto que usa `ShareController::store()`
- [ ] 3.3 Registrar la solicitud con estado `ready` y los datos resueltos (`backup_storage_id`, `backup_path`, `share_id`, `link_expires_at`); registrar estado `unavailable` con la causa cuando la carpeta-día no esté disponible, sin crear enlace
- [ ] 3.4 Implementar la idempotencia: si el usuario ya tiene una solicitud viva para el mismo (storage origen, día), devolver esa solicitud y su enlace en lugar de crear un segundo enlace
- [ ] 3.5 Emitir `Log::info('backup_request.created', ...)` con actor, storage de origen, carpeta-día, estado y expiración, sin incluir secretos del enlace
- [ ] 3.6 Implementar `revoke(User $user, BackupRequest $request): void` que rechace solicitudes ajenas, borre el share asociado e invalidate la cache pública `share:meta:{token}`, dejando la solicitud en estado `revoked`
- [ ] 3.7 Implementar `listFor(User $user)` que devuelva las solicitudes del usuario con el enlace resuelto solo cuando el share sigue vivo y no expiró, y la causa cuando no

## 4. API

- [ ] 4.1 Añadir a `FileController` (o a un controller dedicado bajo `App\Http\Controllers`) los endpoints `backupRequestEligibility`, `storeBackupRequest`, `indexBackupRequests` y `destroyBackupRequest`, todos bajo el middleware `auth` y resolviendo el usuario con `session('user_id')`
- [ ] 4.2 Registrar las rutas `GET /files/backup-requests/eligibility`, `GET /files/backup-requests`, `POST /files/backup-requests` y `DELETE /files/backup-requests/{id}` en `app/routes/web.php` dentro del grupo `auth`, sin colisionar con `Route::resource('files')`
- [ ] 4.3 Definir el contrato de respuesta: `eligibility` devuelve `{day, source_storage, backup_path, available, reason, expires_at}`; `store` devuelve `{request, share: {public_url, expires_at}}`; `index` devuelve `{data: [...]}` con el enlace presente solo si la solicitud está viva
- [ ] 4.4 Traducir los errores a JSON con código y mensaje en español (motivo ausente o corto, sin permiso, sin mapeo, día no disponible, no encontrado, ya revocado)
- [ ] 4.5 Verificar que ninguna ruta nueva quede huérfana: `php tests/harness_route_contract.php` en verde sin añadir excepciones a `KNOWN_ORPHAN_ROUTES`

## 5. Interfaz

- [ ] 5.1 Añadir a `fileManager()` el estado de la solicitud: `showBackupRequestModal`, `backupRequestFolder`, `backupMotivo`, `backupEligibility`, `backupSubmitting`, `backupError`, `backupRequests` (historial)
- [ ] 5.2 Añadir el botón "Solicitar backup" en la celda ACCIONES (vista lista y vista grid), gateado por el criterio de elegibilidad resuelto en el servidor, y con `@click.stop`
- [ ] 5.3 Implementar `openBackupRequest(file)`: pide la elegibilidad al endpoint, muestra la ficha resuelta (canal, fecha, ruta del backup, disponibilidad, vencimiento) y deja el motivo editable; si no está disponible, muestra la causa y deshabilita el envío
- [ ] 5.4 Implementar `submitBackupRequest()`: valida el motivo en cliente, hace el POST, y en éxito cierra el modal, muestra toast y recarga el historial
- [ ] 5.5 Añadir el tercer `viewMode` `'backups'` con su `x-if` en la cabecera, la lista de solicitudes (storage, día, motivo, estado, causa) y el enlace con apertura en pestaña nueva (`target="_blank"`, `rel="noopener"`)
- [ ] 5.6 Persistir y restaurar el `viewMode` junto al estado de navegación existente en `localStorage['tcloud_files_nav']`, sin romper la restauración actual
- [ ] 5.7 Añadir el historial al botón que ya existe en la barra de herramientas, con contador de solicitudes vivas

## 6. Datos de mapeo

- [ ] 6.1 Crear el comando artisan `backups:map-storages` en dry-run por defecto: lista los pares origen ↔ carpeta de backup candidatos (origen por nombre de storage y destino por las 28 carpetas raíz de `Disco_D/backup`), verifica que la carpeta exista en disco y que su `base_path` esté dentro del storage de backup
- [ ] 6.2 Con `--apply`, escribir el mapeo solo para los pares verificados; los dudosos se reportan sin escribir y quedan sin mapear
- [ ] 6.3 Ejecutar el comando en dry-run, revisar la lista completa con el operador y recién después aplicar
- [ ] 6.4 Reportar los storages de origen con carpeta-día visible que NO tienen mapeo, para que el operador decida si se dejan fuera

## 7. Verificación

- [ ] 7.1 Crear `tests/harness_backup_requests.php` con el patrón del repo (tag único, `h_ok`/`h_fail`, cleanup en `finally`): resolución de día válido e inválido, elegibilidad con y sin mapeo, creación de solicitud con motivo válido, rechazo por motivo vacío/corto, rechazo sin permiso de lectura, expiración a los 2 días, idempotencia, revocación, y que el enlace creado es de solo lectura y expira
- [ ] 7.2 En el harness, verificar que la fila del día resuelta pertenece al `backup_storage_id` declarado y **no** a las filas duplicadas del storage 5
- [ ] 7.3 Correr `php tests/harness_route_contract.php`, `php tests/harness_shares_module.php`, `php tests/harness_merged_into_rename_compat.php` y `php tests/harness_modules_rename_audit.php` en verde
- [ ] 7.4 Verificación manual en la UI: abrir una carpeta-día de `01 Caracol Tv`, comprobar que aparece el botón, enviar la solicitud con motivo, comprobar que el enlace abre la vista de carpeta del backup y que el archivo es descargable
- [ ] 7.5 Verificación manual del caso no disponible: solicitar un día fuera de la ventana de retención y comprobar que el modal lo explica antes de enviar
- [ ] 7.6 Verificar en BD que la solicitud sobrevive a la rotación: simular la purga de la fila `files` del día y comprobar que el historial la reporta como no disponible y no como enlace roto

## 8. Cierre

- [ ] 8.1 Documentar en `AGENTS.md` el contrato: dónde vive el mapeo, cómo se resuelve la carpeta-día, por qué el share se crea por servicio interno y no por `ShareController::store()`, y por qué el cliente no recibe permisos sobre el storage de backup
- [ ] 8.2 Revisar de forma independiente el flujo de escalada de privilegio (ítems de alto riesgo 2 y 3 del protocolo ODD): alcance del enlace, expiración, idempotencia, revocación y auditoría
- [ ] 8.3 Documentar el rollback: `git revert` + `migrate:rollback --step=2`, y el comportamiento de las solicitudes ya creadas si se borra la tabla
