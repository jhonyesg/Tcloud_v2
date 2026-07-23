# Spec: share-upload

## Purpose

Definir el contrato de la subida por link de share (`POST /s/{token}/upload` y endpoints hermanos) para que cualquier cliente del frontend, del script de transcripción que ingiere desde links, o de integraciones externas pueda confiar en la semántica de respuesta.

---

## Requirements

### Requirement: Subida por share acepta un archivo con permisos válidos
Cuando un cliente hace `POST /s/{token}/upload` con un token existente, sin password o con la password correcta en `X-Share-Password` o sesión `share_auth_{token}`, el share con `permissions ∈ {write, upload, full}`, y adjunta un archivo válido en `multipart/form-data`, el backend SHALL persistir el archivo en el storage provider local asociado a la carpeta compartida, crear el registro `File` con `owner_id` igual al dueño de la carpeta, `parent_id` igual al folder destino (root del share o `parent_id` enviado si es descendiente válido), y devolver **`201 Created`** con `{ "message": "File uploaded successfully", "file": { ... } }`.

#### Scenario: Upload exitoso a la raíz del share
- **WHEN** el share apunta a folder `F` con `permissions=upload` y el cliente sube `archivo.mp4` sin `parent_id`
- **THEN** el archivo queda en `<storage.base_path>/<F.path>/archivo.mp4` y la respuesta es 201 con el `File` recién creado

#### Scenario: Upload exitoso a una subcarpeta del share
- **WHEN** el cliente envía `parent_id` que es descendiente de la carpeta raíz del share
- **THEN** el archivo se guarda en la subcarpeta y se respeta `IsDescendantOf` (no se acepta un `parent_id` fuera del árbol del share)

#### Scenario: Reemplazo explícito con `replace=1`
- **WHEN** ya existe un archivo con el mismo nombre en la carpeta destino y el cliente envía `replace=1`
- **THEN** el archivo previo se elimina (registro `File` y archivo físico) y el nuevo queda en su lugar

### Requirement: Errores de subida por share devuelven JSON con forma estable
Toda respuesta de error del endpoint `POST /s/{token}/upload` SHALL ser JSON con la forma `{ "error": "..." }` y un status HTTP 4xx/5xx legible. Esta propiedad es necesaria para que el frontend pueda mostrar mensajes específicos; si el backend no puede producir JSON, SHALL al menos devolver un status 4xx cuyo `Content-Type` no sea JSON y dejar claro en logs que el cuerpo HTTP fue cortado por una capa externa (Nginx, proxy).

#### Scenario: Permisos insuficientes
- **WHEN** el share tiene `permissions ∈ {read}` y se intenta subir
- **THEN** la respuesta es 403 JSON `{ "error": "Upload not allowed" }`

#### Scenario: Storage no local
- **WHEN** el `StorageProvider` de la carpeta destino tiene `type ≠ 'local'`
- **THEN** el endpoint SHALL rechazar el upload con 400 JSON explicando que solo se soportan storages locales

#### Scenario: Cuerpo HTTP rechazado por proxy (Nginx)
- **WHEN** Nginx (o cualquier proxy frente a PHP-FPM) rechaza el cuerpo por exceder `client_max_body_size`
- **THEN** la respuesta NO es JSON sino HTML 413 — el contrato aquí NO garantiza JSON para este caso. Documentado como dependencia operativa: mantener `client_max_body_size` alineado con el tamaño máximo de archivo que la app acepta.

### Requirement: Archivos subidos conservan metadatos básicos
Cada `File` creado vía share upload SHALL persistir al menos: `name` (nombre original), `path` (relativo al `storage.base_path`), `size` (bytes en disco), `mime_type`, `storage_provider_id`, `owner_id`, `parent_id`, `is_folder = false`, `is_personal = false`.

#### Scenario: Metadata consistente
- **WHEN** se crea el `File` desde el endpoint de share
- **THEN** los campos anteriores están poblados y `mime_type` se obtiene del UploadedFile de Laravel (no se infiere del nombre)

### Requirement: Auditoría registra cada subida
Cada subida por share que complete (201) SHALL generar un `ShareAccessLog` con `accessed_at = now()` y `ip_address` del cliente. Los errores antes de crear el archivo NO están obligados a generar log de auditoría (puede existir log de error separado).

#### Scenario: Auditoría tras 201
- **WHEN** la subida termina con éxito
- **THEN** existe exactamente 1 `ShareAccessLog` reciente asociado al `share_id`

---

## Notes operativas (fuera del contrato formal)

- `client_max_body_size` de Nginx en el vhost `cloud.mediaserver.com.co` SHALL estar alineado con el tamaño máximo permitido por la app. Hoy `10G` (ver change `2026-07-23-cloud-nginx-upload-body-size` archivado).
- No existe cuota por share: cualquier persona con el link (y password si la tiene) puede subir hasta donde el proxy y el disco lo permitan.
- El endpoint NO valida cuotas personales del `User` porque el share es anónimo / no autenticado en sesión.