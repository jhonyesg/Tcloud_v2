## Context

Ver `proposal.md` para motivación. El estado actual es que `FileController::index` ejecuta un CTE recursivo que parte del `parent_id` y devuelve la cadena completa (incluida la fila del propio `parent_id`). Luego `array_reverse()` la deja en orden root → current, y `dedupBreadcrumbSegments()` deduplica consecutivos. La carpeta actual queda como **último elemento** del array.

El template Blade (`index.blade.php:2359-2373`) itera TODO el array `breadcrumbs` con `x-for` y luego imprime OTRA vez `currentFolderName` en un `x-if` separado → render duplicado del último segmento.

El change abierto paralelo `fix-mis-archivos-breadcrumb-duplicates` ataca **auto-referencias reales en BD** (un folder `X` con padre `X`), lo cual NO es nuestro caso. La BD está limpia: la cadena padre para `30092026` (id 8499102) es `Disco_I (4806263) > television (4810747) > Telemedellin (8451988) > 30092026 (8499102) > NULL` sin ciclos ni duplicados.

## Goals / Non-Goals

**Goals:**
- `breadcrumbs` representa ancestros únicamente, en cualquier modo (BD o FS).
- Cero cambio observable en el contrato actual de los demás campos (`files`, `pagination`, `meta`).
- Compatibilidad con el cache de listado (Redis `folder_listing:{storageId}:{pid}:{gen}:{page}`): bumpear `folder_gen` invalida las claves cacheadas en una sola pasada.

**Non-Goals:**
- No se elimina el bloque `x-if="currentFolderName"` del template. Sigue siendo la fuente de renderizado de la carpeta actual.
- No se cambia el endpoint `/files/upload` ni el modal de subida.
- No se repara `parent_id` en BD (cambio paralelo ya lo cubre).
- No se introduce deduplicación de nombres consecutivos (cambio paralelo ya lo cubre).

## Decisions

### D1 — Descartar la fila del `parent_id` antes de invertir

El CTE `WITH RECURSIVE parent_chain AS (SELECT id, name, parent_id, storage_provider_id FROM files WHERE id = ?)` parte con la fila del folder solicitado. Después de mapear a segmentos con `id/name/storage_provider_id`, se aplica `array_slice($segments, 1)` para descartar esa primera fila, y luego `array_reverse()`. Resultado: array con ancestros en orden root → parent.

**Alternativa descartada**: cortar la fila del current en el JS cliente. Se descartó porque la regla "ancestros = breadcrumbs" debe quedar en backend para que el contrato sea único y no haya deriva entre el FS-first mode y BD-first mode.

### D2 — Aplicar la misma regla en `FilesystemListingService::parentChain` si difiere

Auditar `FilesystemListingService::parentChain()`: si devuelve la misma forma con el current incluido, aplicar el mismo `array_slice` o equivalente. Verificar en `tests/harness_mis_archivos_fs_first.php` que el canary storage 134 sigue mostrando breadcrumbs coherentes.

### D3 — Bumpear `folder_gen` para invalidar cache de listado

Las entradas cacheadas en Redis bajo `folder_listing:{storageId}:{pid}:{gen}:{page}` contienen el `breadcrumbs` viejo. Al bumpear `Cache::increment("folder_gen:{$storageId}:{$pid}")` para cada `pid` afectado (los ancestros y la propia carpeta), las claves viejas quedan inalcanzables. Sin esto, los usuarios verían el duplicado hasta que el TTL (60s/300s/86400s según antigüedad) expire.

Operación de migración: ejecutar en consola al hacer deploy:

```bash
cd app && php artisan tinker
> foreach (DB::select('SELECT DISTINCT storage_provider_id FROM files WHERE is_folder=true') as $r) {
      Cache::increment("folder_gen:{$r->storage_provider_id}:null");
  }
```

(Solo incrementa `null` para el root de cada storage; los descendientes heredan el cambio al re-listar.)

### D4 — Sin cambio en template Blade

El template ya tiene `x-if="currentFolderName"` que renderiza la carpeta actual. Al quitar el current del array `breadcrumbs`, el primer render del loop produce los ancestros y el `x-if` produce la carpeta actual. Resultado: una sola aparición de cada nombre.

## Risks / Trade-offs

- **[R1] Cache de listado obsoleto en producción** → Bumpear `folder_gen:storageId:null` en cada storage afectado al deploy (ver D3). Sin esto, el bug persiste hasta que el TTL venza (max 86400s = 24h para folders antiguos).
- **[R2] Otros consumidores del array `breadcrumbs`** → Audit grep en `app/`: confirmado que solo `index.blade.php` consume el array, y ya tiene `currentFolderName` separado. Sin riesgo de breakage.
- **[R3] FS-first mode desincronizado** → El path FS-first puede haber sido implementado con la misma convención errónea. Mitigation: ejecutar el harness `tests/harness_mis_archivos_fs_first.php` post-fix y comparar la longitud del array con BD mode para el mismo folder.

## Migration Plan

1. Deploy de código (cambio en `FileController.php` y posiblemente `FilesystemListingService.php`).
2. `php artisan tinker` → `foreach (...) Cache::increment("folder_gen:...")` para invalidar el cache de breadcrumb.
3. Verificación visual desde navegador: navegar a `00 Discos > Disco_I > television > Telemedellin > 30092026`. El breadcrumb debe mostrar 4 segmentos: `Home > 00 Discos > Disco_I > television > Telemedellin > 30092026` (el último es el bloque no clickable, sin duplicación).
4. Si hay reporte de usuarios viendo duplicado después del deploy: refrescar manualmente (`Ctrl+F5`) para limpiar HTTP cache de assets (no afecta `breadcrumbs` pero el cliente JS puede estar cacheado en service worker si se hubiera introducido, no es el caso aquí).

**Rollback**: `git revert <commit>` restaura el comportamiento. Bumpear `folder_gen` para propagar la reversión al cache. Sin datos que limpiar.
