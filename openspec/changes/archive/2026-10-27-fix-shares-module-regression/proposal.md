## Why

El módulo de compartidos quedó **inconsistente con sus propias specs** tras la ola de restauración de Mis Archivos (2026-09-21): dos commits eliminaron métodos de controller y servicios sin retirar las rutas ni la UI que los invocan. En producción eso es **HTTP 500** en toda depuración masiva y en **todo enlace público con contraseña**, más un juego de filtros, ordenamientos y contadores que hoy devuelven resultados falsos **en silencio**.

## What Changes

**Errores 500 vivos**
- Restaurar `ShareController::bulkPreview`, `bulkDelete` y `verifyAvailability` (`POST /shares/bulk-preview`, `/shares/bulk-delete`, `/shares/availability/verify`).
- Restaurar `PublicShareController::authenticate` (`POST /s/{token}/authenticate`): hoy todo enlace con contraseña es insusable.

**Resultados falsos en `/shares`** (la UI los envía; el controller los ignora)
- Filtros `availability`, `created_from/to`, `expires_from/to` y `status=never`.
- Ordenamiento por `name`, `permission` (por **nivel**), `status` (por **grupo**) y `accesses`; `422` en sort/dirección inválidos, no fallback silencioso.
- Payload con `expiry_status` y `file.availability_state`; `counters.missing`/`unknown` con la semántica de la spec (recurso confirmado ausente, no `file === null`).
- Contar en SQL en vez de hidratar todos los shares en RAM.

**Pestaña "Enlaces Compartidos" de Mis Archivos** (`resources/views/files/index.blade.php`)
- `loadFileShares` lee `data.shares`; la API devuelve `data.data` → la pestaña siempre muestra vacío.
- `bulkDeleteShares` hace N `DELETE /shares/{id}`; debe usar la operación bulk server-side.
- `POST /shares` no aplica la expiración por defecto (`SHARE_DEFAULT_EXPIRY_DAYS`).

**Seguridad**
- `password_hash` se serializa hoy en `POST /shares`, `GET /shares/{id}` y `PUT /shares/{id}`.
- `GET /shares/{id}` no autoriza por propietario: un autenticado con el ID obtiene token y archivo ajenos.
- La vista pública cachea `share:meta:{token}` 3600 s con la relación `file`: un archivo renombrado se muestra con el nombre viejo.

**Guardarraíles rotos (hallazgo colateral)**
- `harness_merged_into_rename_compat.php` y `harness_merged_into_create_edit_delete.php` crashean (no existen `files.canonical_folder_id` ni `file_mirror_audit_log`) y **abORTAN en vez de reportar FAIL**.
- `openspec/specs/share-folder-identity-canonical/spec.md` exige infraestructura (mirror/canonical, `physical_path_normalized`, `base_path_snapshot`) desmontada **deliberadamente** por el restore de agosto: hoy describe un mundo inexistente.
- `openspec/specs/share-write-notification-canonical/spec.md` exige el comando `shares:notify-repointed` y la tabla `file_mirror_audit_log`: ninguno existe. `RepairShareMirrorTargetsCommand` sigue en el repo y fallaría con error de SQL si se invoca.
- AGENTS.md documenta `File::isMirror()`/`File::canonical()` y esas columnas como existentes; no lo son.

**Migration**: sí — `files.availability_state`, `files.last_verified_at`, `files.missing_since_at` (aditivas, nullables, reversibles) + índices.

## Capabilities

### New Capabilities
- `route-contract-integrity`: mecanismo ejecutable que falla cuando una ruta apunta a un método de controller inexistente.

### Modified Capabilities
- `share-management`: filtros de disponibilidad y rango de fechas, `status=never`, ordenamiento por nivel/estado/accesos, `422` en sort inválido, contadores según spec, payload con `expiry_status`/`availability_state`, conteo en SQL.
- `share-security`: `password_hash` nunca serializado, autorización por propietario en el detalle, endpoint de contraseña HTML, metadatos públicos frescos.
- `share-file-availability`: estado persistido en `files`, verificación acotada y filtrable.
- `share-link-lifecycle`: expiración por defecto en enlaces nuevos, endpoint de autenticación.
- `shares-tabs-bulk-delete`: operación bulk server-side en la pestaña de Mis Archivos y forma de respuesta consumida.
- `share-folder-identity-canonical`: re-escopada al modelo vigente (`parent_storage_id` + path normalizado) y retirada de los requisitos que dependen de la infraestructura desmontada.
- `share-write-notification-canonical`: retirada — su comando y la tabla de auditoría que consume ya no existen.

## Impact

`ShareController`, `PublicShareController`, modelos `Share` y `File`, `resources/views/shares/index.blade.php`, `resources/views/files/index.blade.php` (pestaña de enlaces), `routes/web.php`, `config/shares.php`, migraciones PostgreSQL y los specs `share-*`/`shares-tabs-bulk-delete`. Afecta 317 shares en producción (245 permanentes, 72 expirados).

## Non-goals

- No restaurar la infraestructura mirror/canonical de `files` (`canonical_folder_id`, `base_path_snapshot`, `physical_path_normalized`, `file_mirror_audit_log`): fue desmontada deliberadamente por el restore de agosto.
- No borrar filas `files`, archivos físicos ni transcripciones al depurar enlaces.
- No tocar las rutas muertas de otros módulos (`admin/users/create`, `admin/storages/create`, `files/{file}/transcription`, `canales/{canal}`, `grabadores/create|edit`): son de otra naturaleza y mezclarían el rollback.
- No alterar la expiración de los enlaces existentes con `expires_at IS NULL`.
