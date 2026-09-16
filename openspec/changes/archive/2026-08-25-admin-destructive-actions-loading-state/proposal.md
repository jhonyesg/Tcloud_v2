## Why

El bug original en `/admin/storages` (botones Eliminar/Desvincular sin feedback → 404 confusos al reintentar) ya está corregido y archivado en `2026-08-25-fix-storages-delete-loading-state`. Pero el **mismo patrón UX roto existe en otras 5 vistas del admin** que también llaman a endpoints DELETE sin estado de carga ni toast de error en la rama de fallo:

- `admin/users.blade.php` — `deleteUser(id)` (línea 70)
- `admin/external-sites.blade.php` — `deleteSite(site)` (345) y `removeUser(u)` (394)
- `admin/sessions.blade.php` — `killSession(id)` (48) y `killUserSessions(userId)` (66) — sin toast de error
- `admin/user-storages.blade.php` — `removeAssignment(storageId)` (74) — sin toast de error
- `admin/storage-users.blade.php` — `removeAssignment(userId)` (117) — sin toast de error

La excepción es `admin/correo.blade.php` que YA implementa el patrón correcto con `confirmDelete.loading` y `:disabled` — ese sirve como referencia.

Sin este change, cualquier admin que use estas vistas va a reportar el mismo bug que acabamos de arreglar para storages.

## What Changes

Aplicar el patrón validado en el change archivado `fix-storages-delete-loading-state` a las 5 vistas mencionadas:

- **Estado Alpine por operación**: `deletingId`, `removingKey`, `killingId`, etc. según corresponda.
- **Botón `:disabled` + spinner SVG inline** mientras la petición está en vuelo.
- **Toast de error en la rama `else`** (varios archivos hoy fallan en silencio sin avisar al admin).
- **`try/finally` para garantizar limpieza del estado** aunque la petición lance excepción.
- **`await loadXxx()` antes del toast de éxito** para que la lista refresque antes de que el admin pueda interactuar de nuevo.

Sin cambios en backend, rutas, ni migraciones.

## Capabilities

### New Capabilities
- `admin-destructive-actions-loading-state`: Patrón de feedback visual aplicado a TODAS las operaciones destructivas del admin que no sean storages (las storages ya tienen su propio spec `admin-storages-loading-state` archivado). Reusa el patrón establecido.

### Modified Capabilities
- (ninguno — el spec `admin-storages-loading-state` describe las operaciones de storages; este change describe el patrón paralelo para las otras vistas)

## Impact

- **Solo frontend**: 5 archivos Blade en `app/resources/views/admin/`.
- Backend intacto: los controllers de Users, ExternalSites, Sessions, UserStorage siguen devolviendo 200/404 igual que ahora.
- Sin migraciones, sin cambios de API.
- Algunos de estos views tienen tour interactivo (`users.blade.php`). Si agregamos feedback visible, no requiere step nuevo del tour (el tour documenta columnas y acciones existentes, no estados internos de loading).
- Riesgo: bajo — replicar patrón ya validado en storages.

## Non-goals

- No refactorizar la estructura de cada vista — solo agregar el patrón de loading.
- No añadir tests automatizados.
- No unificar el patrón en una abstracción compartida (helper Alpine global) — desproporcionado para 5 vistas y el proyecto no usa ese patrón.
- No traducir los mensajes al inglés — solo replicar el comportamiento de los mensajes en español ya existentes.
- No tocar el backend aunque algunas ramas de fallo no muestren toast — este change es solo UX frontend.
