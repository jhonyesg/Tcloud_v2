## Why

El árbol de backup de TV ("Producción") ya existe en producción: es el export NFS `192.168.0.131:/media/difusor02/Backup_TV` montado en `Disco_D/backup`, con la misma estructura `<NN_Canal_BK>/<DDMMYYYY>/` que los discos de origen y con copia de mayor calidad (450 MB vs 223 MB por bloque de 15 min). Hoy ese material es inalcanzable para el cliente: no hay forma de pedirlo ni de entregarlo. Falta el enlace entre "la carpeta del día que el cliente está viendo" y "la carpeta del día que existe en el backup".

## What Changes

- **Mapeo declarativo origen → backup**: `storage_providers` gana `backup_storage_id` (FK, nullable) y `backup_folder_name` (nullable). El vínculo vive en el storage **origen** (`01 Caracol Tv` → storage 34 + `01_Caracol_BK`). No se crean storages nuevos ni se mueven filas de `files`.
- **Acción "Solicitar backup"** en ACCIONES de Mis Archivos, solo en carpetas cuyo nombre sea un día `DDMMYYYY` válido y cuyo storage tenga backup mapeado.
- **Modal de solicitud** con la ficha resuelta (canal, fecha, ruta del backup, disponibilidad, vencimiento) y motivo obligatorio.
- **Resolución automática**: al enviar, el sistema localiza la carpeta-día en el árbol de backup, crea un `Share` de solo lectura con vigencia de 2 días y registra la solicitud. Sin intervención de un administrador.
- **Historial de solicitudes**: nueva vista en Mis Archivos donde el cliente lista sus solicitudes y abre el enlace en pestaña nueva.
- **Tabla `backup_requests`** como fuente de verdad, independiente del share.
- **Migración de BD**: sí — dos columnas en `storage_providers` y la tabla `backup_requests`.

## Capabilities

### New Capabilities

- `storage-backup-mapping`: vínculo declarativo entre storage de origen y carpeta de backup, resolución de la carpeta-día y elegibilidad del botón.
- `backup-request-workflow`: modal, motivo, estados, creación automática del enlace, vigencia, historial y revocación.

### Modified Capabilities

- (ninguna)

## Non-goals

- **No se copia nada**: Tcloud no genera el backup, solo localiza y comparte el árbol existente.
- **No hay aprobación**: el enlace se crea al instante.
- **No se cambia la retención** del árbol de backup (13 carpetas-día rodantes).
- **No se registran las carpetas de backup como storages nuevos**: se descarta el patrón iniciado por `35 Red Mas BK` (storage 131) porque duplica filas de `files` y su navegación BD-first quedó vacía.
- **No se toca `ShareController::store()`**: el enlace se crea por un servicio interno con autorización propia.
- **No se otorgan permisos de lectura sobre el storage de backup**: el acceso al master es solo vía el enlace temporal.

## Impact

- **Migraciones**: `storage_providers` (+2 columnas), tabla `backup_requests`.
- **Modelos**: `StorageProvider`, `Share`, nuevo `BackupRequest`.
- **Servicios**: `App\Services\MisArchivos\BackupRequestService` + `BackupResolver`; `StorageSyncService`/`FileRegistry` solo como vía canónica de resolución de la carpeta-día.
- **Controlador/rutas**: `FileController` + `GET/POST/DELETE /files/backup-requests[/{id}]` y `/eligibility`, grupo `auth`.
- **Vista**: `resources/views/files/index.blade.php` (botón, modal, tercer `viewMode`).
- **Specs existentes tocados**: ninguno. **Harness nuevo**: `tests/harness_backup_requests.php`.
- **Riesgo alto**: escalada de privilegio (el cliente accede por enlace público al master de producción que no puede leer en la plataforma) → exige verificador independiente.
