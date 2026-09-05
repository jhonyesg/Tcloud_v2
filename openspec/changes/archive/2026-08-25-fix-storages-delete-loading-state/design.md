## Context

La vista `app/resources/views/admin/storages.blade.php` tiene dos operaciones destructivas sin feedback visual: `deleteStorage(id)` y `removeAssignmentFromModal(userId)`. La vista ya implementa el patrón correcto en `testStorage(storage)` con la propiedad `testingStorage` y `:disabled` — vamos a replicarlo.

Los 404 que ve el admin son **comportamiento correcto del backend** (`ModelNotFoundException` cuando un registro ya no existe). El problema es 100% UX: el admin no sabe si su primer click tuvo efecto, clickea de nuevo o recarga, y la segunda petición falla.

Verificado:
- `StorageProviderController@destroy(int $id)` responde 200 OK y elimina el storage (probado en CLI).
- `StorageProviderController@removeUserAssignment(int $id, int $userId)` responde 200 OK y elimina el row de `user_storages` (probado en CLI).
- Las rutas existen y matchean correctamente (verificado con `php artisan route:list`).

## Goals / Non-Goals

**Goals:**
- Replicar el patrón de `testStorage()` en las dos operaciones destructivas.
- Garantizar que el estado de carga se limpia con `try/finally` (no se puede quedar "stuck").
- Añadir toast de error en `removeAssignmentFromModal` (actualmente falla en silencio).

**Non-Goals:**
- No tocar backend, ni rutas, ni migraciones.
- No refactorizar `testStorage` ni otras operaciones que ya funcionan.
- No aplicar cambios a `admin/storage-users.blade.php` (vista alternativa, fuera de scope de este reporte del usuario).
- No añadir tests automatizados — el cambio es puramente visual y se valida manualmente.

## Decisions

### Decisión 1: Mismo patrón que `testStorage()`

**Por qué**: ya está implementado en el mismo archivo (líneas 179-193 + :disabled en línea 445), es consistente con el código existente, y los admins ya están familiarizados con el spinner de "Probar".

**Alternativa considerada**: usar `x-loading` o una abstracción global de loading. Descartado — sería desproporcionado para 2 botones, y el proyecto no usa esas abstracciones.

### Decisión 2: Dos estados Alpine separados

- `deletingStorageId: null` (storage que se está eliminando)
- `removingUserAssignmentKey: null` (clave `storageId-userId` para identificar qué chip está bloqueado)

**Por qué**: permite deshabilitar selectivamente solo el botón afectado, no toda la UI. Si el admin clickea dos chips distintos a la vez (improbable pero posible), ambos se procesan.

**Alternativa considerada**: un solo boolean `anyOperationInProgress` que deshabilita todo. Descartado — es más restrictivo de lo necesario y no diferencia entre operaciones.

### Decisión 3: `try/finally` en ambas funciones

**Por qué**: garantiza que el estado se limpia incluso si `apiFetch` lanza (error de red, abort, timeout). Sin `finally`, un error de red podría dejar el botón permanentemente deshabilitado.

**Alternativa considerada**: limpiar en cada rama del `if (res.ok)`. Descartado — duplica código y se salta el caso de excepción.

### Decisión 4: `await loadStorages()` en `deleteStorage`

**Por qué**: actualmente `loadStorages()` se llama sin `await`, así que el toast de éxito aparece antes de que la lista refresque. El admin ve el toast pero el storage todavía aparece en la tabla — confunde. Esperando la recarga, el orden visual es: lista refresca → modal cierra → toast aparece.

**Alternativa**: mostrar el toast inmediatamente y refrescar en background. Descartado — el admin podría clickear sobre un storage que "todavía está ahí" durante el breve intervalo.

### Decisión 5: Toast de error en `removeAssignmentFromModal`

**Por qué**: el spec actual `storage-users-management-modal` dice "el chip desaparece" pero no cubre el caso de error. Un 404 silencioso es peor UX que un toast explícito.

**Alternativa**: deshabilitar globalmente el botón × sin feedback. Descartado — ya era lo que pasaba y es exactamente lo que reportamos como bug.

## Risks / Trade-offs

- **[Doble click durante petición rápida]** → Mitigación: el `:disabled` evita el segundo click físico; `deletingStorageId` también previene re-entrada lógica si Alpine procesa el evento antes del disabled.
- **[Re-entrada por Alpine x-if re-render]** → Mitigación: usar `try/finally` garantiza limpieza incluso si Alpine re-monta el nodo durante la transición.
- **[El admin espera ver spinner en otros botones]** → Mitigación: NO aplicar el patrón a botones que no son destructivos (Editar, Probar ya tienen su propio patrón o no lo necesitan). Documentar en tasks.md.

## Migration Plan

No hay migración. Es un cambio de template Blade:
1. Editar `app/resources/views/admin/storages.blade.php` (frontend only).
2. Validar manualmente:
   - Abrir `/admin/storages`, crear un storage de prueba, eliminarlo → debe mostrar "Eliminando..." → toast verde → storage desaparece.
   - Re-crear storage, asignar usuario, abrir modal Usuarios, click × → spinner → toast verde → chip desaparece.
   - Probar caso de fallo: abrir dev tools, simular 404 (o esperar a doble click) → toast rojo → botón liberado.
3. Refrescar la página del tour interactivo `TcloudTour` si está referenciada en otro archivo (verificar primero).

## Open Questions

Ninguna. El alcance es claro y todos los patrones están definidos por la convención existente del archivo.
