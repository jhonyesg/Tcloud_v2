## Context

El change archivado `2026-08-25-fix-storages-delete-loading-state` validó un patrón UX para operaciones destructivas en `admin/storages.blade.php`: estado Alpine por operación (`deletingStorageId`, `removingUserAssignmentKey`), `:disabled` + spinner SVG inline en el botón afectado, rama `else` con toast de error (en `removeAssignmentFromModal` que antes fallaba en silencio), y `try/finally` para limpieza garantizada.

Ese mismo patrón falta en 5 vistas más del admin. La vista `admin/correo.blade.php` ya implementa una variante correcta usando `confirmDelete.loading` — la usaremos como segunda referencia.

## Goals / Non-Goals

**Goals:**
- Replicar el patrón validado en cada vista con operación destructiva pendiente.
- Añadir rama `else` con toast de error en TODAS las operaciones que actualmente fallan en silencio (`killSession`, `killUserSessions`, `removeAssignment` en `user-storages` y `storage-users`).
- Sin tocar backend, rutas, ni migraciones.

**Non-Goals:**
- No refactorizar la estructura de cada vista — solo agregar el patrón.
- No unificar el patrón en un helper Alpine compartido — desproporcionado.
- No añadir tests automatizados.
- No traducir mensajes al inglés.
- No tocar `admin/storages.blade.php` — ya está arreglado en el change archivado.

## Decisions

### Decisión 1: Una capability spec nueva, no modificar la existente

**Por qué**: el spec `admin-storages-loading-state` archivado describe el patrón aplicado específicamente a storages. Crear uno nuevo `admin-destructive-actions-loading-state` para las demás vistas mantiene cada spec enfocado en su dominio. Evita tener un solo spec enorme con 6 secciones.

**Alternativa**: ampliar `admin-storages-loading-state` para cubrir todas las vistas. Descartado — pierde foco.

### Decisión 2: Estados Alpine nombrados por dominio

Cada vista usa su propio nombre de estado Alpine siguiendo la convención de la vista (no forzar nombres idénticos):

| Vista | Estado | Botón afectado |
|---|---|---|
| `users.blade.php` | `deletingUserId` | "Eliminar" del modal |
| `external-sites.blade.php` | `deletingSiteId`, `removingSiteUserKey` | "Eliminar site", "×" de usuario |
| `sessions.blade.php` | `killingSessionId` | botón de kill en cada fila |
| `user-storages.blade.php` | `removingStorageKey` | "Remover" de cada fila |
| `storage-users.blade.php` | `removingStorageUserKey` | "Remover" de cada fila |

**Por qué**: cada vista tiene su propio naming y respetarlo reduce el diff y mantiene consistencia local.

### Decisión 3: Reusar el mismo SVG spinner

Mismo SVG `animate-spin` definido en el change archivado (círculo + path con `opacity-25`/`opacity-75`). Copiar-pegar entre archivos es aceptable; abstraer un componente Blade compartido sería desproporcionado.

### Decisión 4: Añadir rama `else` con toast incluso donde no existía

Varias vistas (`sessions`, `user-storages`, `storage-users`) NO tienen rama `else` — fallan en silencio. Este change las corrige como parte de la aplicación del patrón. Es el sub-bug más grave (el admin ni siquiera sabe que la operación falló).

### Decisión 5: No tocar el tour interactivo

`admin/users.blade.php` tiene tour (`startUsersTour`). El feedback de loading es comportamiento interno del botón, no un paso del tour — el tour documenta columnas y acciones, no estados de loading. No agregar step.

**Alternativa**: añadir un step al tour que mencione el feedback. Descartado — no aporta valor al admin (que ya vio el spinner en la primera eliminación).

## Risks / Trade-offs

- **[5 vistas modificadas]** → Mitigación: cada cambio es local y sigue el mismo patrón. Validación visual por vista.
- **[Toast genérico si el servidor no devuelve `error` JSON]** → Mismo manejo que en storages (`err.error || 'Error al...'`) — fallback aceptable.
- **[Re-entrada en `killUserSessions` y `assignSelectedUsers` (loop)]** → Mitigación: el `:disabled` solo bloquea el botón clickeado. El loop secuencial de `assignSelectedUsers` en `storage-users.blade.php` puede tardar — pero ese NO es destructivo, queda fuera de scope.
- **`sessions.blade.php` redirige a `/login` si matas tu propia sesión** → El `try/finally` debe limpiar el estado ANTES del redirect (que ya ocurre en un `setTimeout` de 1200ms, así que es seguro).

## Migration Plan

Solo frontend, 5 archivos:

1. `app/resources/views/admin/users.blade.php` — agregar estado `deletingUserId`, `:disabled` + spinner en botón Eliminar, `try/finally` + `await` en `deleteUser`, rama `else` con toast.
2. `app/resources/views/admin/external-sites.blade.php` — idem para `deleteSite` y `removeUser`.
3. `app/resources/views/admin/sessions.blade.php` — idem para `killSession` y `killUserSessions` (sin toast de error → añadirlo).
4. `app/resources/views/admin/user-storages.blade.php` — idem para `removeAssignment` (sin toast de error → añadirlo).
5. `app/resources/views/admin/storage-users.blade.php` — idem para `removeAssignment` (sin toast de error → añadirlo).

Rollback: revert de los 5 archivos.

## Open Questions

Ninguna.
