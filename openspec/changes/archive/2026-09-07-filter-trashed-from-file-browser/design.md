## Context

Ver `proposal.md` para motivación. El leak es `FileController@index` AJAX query (líneas 168-174 originales).

```
Estado actual (con leak):
  $query = File::query();
  if ($parentId !== null) {
      $query->where('parent_id', $parentId);
  } else {
      $query->whereNull('parent_id');   ← trashed files match this branch
  }

Estado deseado (defense in depth):
  $query = File::query()->where('is_trashed', false);
  if ($parentId !== null) {
      $query->where('parent_id', $parentId);
  } else {
      $query->whereNull('parent_id');
  }
```

`StorageSyncService::currentListing()` ya filtra `is_trashed=false` (línea 356) — esta misma protección debe existir en el controller del browser.

## Goals / Non-Goals

**Goals:**
- Cerrar el leak de items trashed en el root listing del file browser.
- Cambio de 1 línea, sin queries adicionales.
- Sin cambios en cache invalidation (que ya cerraba la ventana TTL).

**Non-goals:**
- Auditar otros controllers que puedan tener el mismo leak.
- Cambiar el scope de las queries (joins, eager-load).
- Reescribir `PapeleraService`.

## Decisions

### D1. Filtro `is_trashed=false` siempre, no condicional

```php
$query = File::query()->where('is_trashed', false);
```

**Por qué:**
- Trashed files jamás deben aparecer en el browser, ni en root ni en subfolder (porque después del trash `parent_id=NULL`).
- El check es barato: `is_trashed` es un boolean con índice parcial `files_trash_sweep_idx` para trashed rows. El planner de Postgres evalúa `is_trashed=false` con un seq scan + filter, pero combinado con `storage_provider_id` + `parent_id` (ambos indexados) el costo es despreciable.
- Más simple que un `whereNotTrashed()` condicional: una sola línea, sin rama especial.

**Alternativa descartada:**
- `$query->where('is_trashed', false)->orWhereNull('is_trashed')` (por si hay filas legacy con `is_trashed` NULL): innecesaria, la columna tiene `default false` y la migración es no-destructiva.
- Scope `File::notTrashed()`: ya existe el scope pero usar `where('is_trashed', false)` directamente es más explícito sobre QUÉ columna se filtra.

### D2. Solo modificar la rama AJAX, no la vista HTML

El `index()` tiene dos modos:
- AJAX: devuelve JSON con la query actual.
- HTML: devuelve `view('files.index')` sin query.

Solo la rama AJAX toca la query. El cambio es quirúrgico.

## Risks / Trade-offs

- **[Riesgo] Performance** → agregar `is_trashed=false` a una query que ya tiene `storage_provider_id` + `parent_id`. **Mitigación:** el planner de Postgres usa el índice más selectivo; para el root listing, `parent_id IS NULL` es lo más selectivo, e `is_trashed=false` filtra el ~99% de filas. El costo extra es despreciable.
- **[Trade-off] Doble defensa** → ahora el cache invalidation Y la query misma filtran trashed. **Aceptado:** defense in depth. Si el cache falla, la query protege. Si la query falla, el cache protege.
- **[Riesgo bajo] Filtros custom downstream** → si algún endpoint downstream espera que la query del browser incluya trashed, este cambio lo rompe. **Mitigación:** el único consumer downstream es Alpine, que pinta la lista; no hay consumidores que esperen trashed en el listing del browser.

## Migration Plan

### Deploy
1. `git pull` (toma la línea modificada en `FileController.php`).
2. `php artisan cache:clear` opcional (para que cualquier cache pre-existente se purgue).
3. Smoke test manual: login → `/files` root listing → no debe haber items trashed visibles.

### Rollback
- `git revert <commit>`. Sin estado persistente que limpiar.

### Post-deploy verification
1. Playwright: navegar a `/files?storage_id=X` con un storage que tenga items trashed → verificar que el JSON no incluye items con `is_trashed=true`.
2. Regresión: las suites existentes (`playwright_papelera_*`) deben seguir pasando.

## Open Questions

Ninguna. Decisión diferible a follow-up:
- ¿Auditar `ShareController`, `MediaEditJobController`, otros endpoints que puedan tener el mismo leak? — Sí si se observan más leaks, pero no en este pase.
