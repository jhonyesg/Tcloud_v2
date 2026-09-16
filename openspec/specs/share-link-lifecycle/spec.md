# share-link-lifecycle Specification

## Purpose
Definir el ciclo de vida visible y operativo de un enlace compartido, incluyendo expiración explícita, enlaces permanentes, acceso bloqueado después del vencimiento y revocación administrativa permanente.

## Requirements

### Requirement: Expiración explícita para enlaces nuevos

El sistema SHALL aplicar a los enlaces nuevos una política configurable de vencimiento, inicialmente de 30 días mediante `SHARE_DEFAULT_EXPIRY_DAYS`. La interfaz SHALL ofrecer presets y una opción explícita "Nunca" que persiste `expires_at = NULL`. Los enlaces existentes con `expires_at = NULL` SHALL permanecer sin vencimiento hasta una acción explícita.

#### Scenario: Creación con vencimiento por defecto
- **WHEN** el usuario crea un enlace sin cambiar el preset de vencimiento
- **THEN** el enlace se crea con `expires_at` futuro según la configuración vigente

#### Scenario: Creación permanente
- **WHEN** el usuario selecciona explícitamente "Nunca"
- **THEN** el enlace se crea con `expires_at = NULL` y la interfaz lo identifica como permanente

#### Scenario: Enlace permanente existente
- **WHEN** se aplica la nueva política a enlaces creados antes del cambio
- **THEN** los enlaces con `expires_at = NULL` no se modifican automáticamente

### Requirement: Estado de expiración consistente

El sistema SHALL derivar el estado de expiración desde `expires_at` usando la zona horaria de la aplicación. Un enlace con fecha pasada SHALL aparecer como expirado en la administración y no SHALL considerarse activo aunque su fila continúe en la base de datos.

#### Scenario: Enlace expirado en administración
- **WHEN** `expires_at` es anterior al momento actual
- **THEN** el listado muestra estado "Expirado" y permite incluirlo en una depuración filtrada

#### Scenario: Enlace sin vencimiento
- **WHEN** `expires_at` es `NULL`
- **THEN** el listado muestra "Sin vencimiento" y no lo clasifica como expirado

### Requirement: Acceso público bloqueado después de expirar

Todo endpoint público asociado a un share expirado SHALL rechazar el acceso con estado `410` o la vista de enlace expirado, según el tipo de cliente. El rechazo SHALL aplicar a visualización, navegación de carpetas, descarga, preview, upload, renombrado, borrado y creación de carpetas.

#### Scenario: Descarga de share expirado
- **WHEN** un cliente intenta descargar usando un token expirado
- **THEN** recibe `410` y no se lee el archivo físico

#### Scenario: Mutación de share expirado
- **WHEN** un cliente intenta subir, renombrar o borrar mediante un token expirado
- **THEN** recibe `410` y no se modifica el storage ni la tabla `files`

### Requirement: Revocación permanente explícita

Una operación de revocación confirmada SHALL eliminar el share, invalidar su token y conservar el comportamiento de cascada de sus logs de acceso. La limpieza automática de logs no SHALL revocar shares ni la carga de una vista SHALL borrar shares silenciosamente.

#### Scenario: Token revocado
- **WHEN** el usuario confirma la revocación de un enlace
- **THEN** el token deja de resolver y una petición pública posterior recibe `404`

#### Scenario: Expirados visibles hasta depuración
- **WHEN** existen shares expirados que no han sido seleccionados para limpiar
- **THEN** permanecen consultables en administración, pero no son accesibles públicamente
