## Context

Ver `proposal.md` para motivación. El bug es de cache TTL window.

Estado actual en `app/app/Http/Controllers/FileController.php:392-417`:

```php
public function destroy(int $id)
{
    // ... checks ...
    $service->softTrash($file, (int) Session::get('user_id'));

    // Invalidar la cache de listado del padre (la fila ya no aparece ahi).
    $service->invalidateSidebarCache((int) $file->owner_id);
    app(\App\Services\StorageSyncService::class)
        ->invalidateFolderCache(
            (int) $file->storage_provider_id,
            $file->getOriginal('parent_id')   // ← solo el padre ANTES del trash
        );

    return response()->json(['message' => 'Moved to trash', 'trashed_id' => $file->id]);
}
```

`$file->getOriginal('parent_id')` devuelve el padre **antes** del update (`parent_id=NULL` que `PapeleraService::softTrash` acaba de aplicar). El cache del padre original se invalida, pero el cache del root listing del mismo storage no — y ahí es donde termina el archivo trashado (`parent_id=NULL`).

## Goals / Non-Goals

**Goals:**
- Cerrar la ventana de hasta 60s donde el root listing cacheado puede devolver la fila trashed.
- Cambio quirúrgico: 1 llamada adicional a `invalidateFolderCache()` en `FileController@destroy`.
- Mantener el comportamiento existente para el caso "archivo ya estaba en root" (sin doble invalidación redundante).

**Non-goals:**
- Añadir `where('is_trashed', false)` a `FileController@index` (defensa adicional, pero más invasiva).
- Invalidar caches de otros storages.
- Refactorizar la lógica de invalidación al service.

## Decisions

### D1. Segunda invalidación solo cuando `$file->parent_id === null` y original !== null

```php
$syncService = app(\App\Services\StorageSyncService::class);
$originalParentId = $file->getOriginal('parent_id');
$syncService->invalidateFolderCache(
    (int) $file->storage_provider_id,
    $originalParentId
);

// Si el archivo trashado terminó en parent_id=NULL (movido de subcarpeta
// a root), también invalidar el root listing del mismo storage. La query
// whereNull('parent_id') lo devolvería mientras dure el TTL (60s).
if ($file->parent_id === null && $originalParentId !== null) {
    $syncService->invalidateFolderCache(
        (int) $file->storage_provider_id,
        null
    );
}
```

**Por qué:**
- Si `originalParentId` ya era NULL (archivo siempre estuvo en root), la primera invalidación con `null` ya cubrió el root — no duplicamos.
- Si `originalParentId` era no-null y `$file->parent_id` es NULL (caso del bug), la segunda llamada cierra la ventana TTL.

**Alternativa descartada:**
- Llamar siempre dos veces (con y sin `null`): innecesario en el caso "siempre estuvo en root" (donde el padre original ya era null).
- Llamar siempre con `null`: dejaría el cache del padre original sin invalidar cuando el archivo sale de una subcarpeta — bug opuesto.

### D2. Mantener la invalidación en el controller, no en el service

El controller conoce el contexto de UI (qué caches necesitan invalidarse tras un cambio). El service (`PapeleraService::softTrash`) es agnóstico al cache de listados y solo invalida el sidebar badge (cache por usuario, no por carpeta). Mover esta lógica al service introduciría dependencia de `StorageSyncService` desde el module — más acoplamiento.

## Risks / Trade-offs

- **[Riesgo] Cache stampede** al invalidar root → un listado muy cacheado del root puede reconstruirse de golpe. **Mitigación:** el root listing ya es cacheable hasta 60s y se reconstruye bajo `Cache::lock` (atómico); el comportamiento es el mismo que el ya existente cuando se invalida cualquier otra carpeta.
- **[Trade-off] No invalida caches de descendientes** → si la papelera se llena de items trashados en subcarpetas, los listados internos de esas subcarpetas ya no muestran esos items (porque `$bdFiles` filtra `is_trashed=false`); pero el cache puede tenerlos stale hasta 60s. **Aceptado:** los descendants trashados NO cambian de parent_id (siguen colgando del folder trashado padre), así que la query que los sirve sigue siendo la misma — no hay riesgo de que el cache devuelva items trashados como vivos.
- **[Riesgo bajo] Folder con muchos hijos trashados** → `PapeleraService::softTrash` es recursivo en hijos. La invalidación del padre original ya cubre el listado donde los hijos vivían. El root listing solo entra si el folder padre era root (no era el caso del bug).

## Migration Plan

### Deploy
1. `git pull` (toma el patch de `FileController.php`).
2. `php artisan cache:clear` opcional (solo si se quiere purgar caches stale existentes).
3. Smoke test manual: cargar `/files` root cacheado, borrar archivo en subcarpeta, recargar `/files` root, confirmar que el archivo trashado no aparece.

### Rollback
- `git revert <commit>`. La ventana TTL de 60s vuelve, pero no hay daño permanente.

### Post-deploy verification
1. Playwright: cargar `/files` con root listing → tomar hash del payload → DELETE de un archivo en subcarpeta → recargar `/files` → assert que el payload cambió (no contiene el archivo trashado).
2. Verificar que el comportamiento para "archivo en root trashado" sigue funcionando (test ya existente en flujo papelera).

## Open Questions

Ninguna.
