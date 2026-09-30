## 1. Backend fix (FileController + FilesystemListingService)

- [x] 1.1 En `app/app/Http/Controllers/FileController.php` método `index`, líneas 108-126: después de ejecutar el CTE recursivo y ANTES del `array_reverse()`, descartar la primera fila del array (`array_slice($segments, 1)`). De modo que `breadcrumbs` contenga solo ancestros.
- [x] 1.2 Auditar `app/app/Services/MisArchivos/FilesystemListingService.php` (o el archivo que aloje `parentChain`): si su método devuelve la misma forma con el current incluido, aplicar el mismo `array_slice` o equivalente.
- [x] 1.3 Verificar que el bloque `dedupBreadcrumbSegments(self::dedupBreadcrumbSegments($segments))` sigue siendo válido tras descartar la primera fila (debería seguir funcionando porque ahora la cadena nunca incluye el current).

## 2. Cache invalidation

- [x] 2.1 Bumpear `folder_gen` para cada storage afectado (`storage_provider_id` que tenga folders) usando `php artisan tinker` con `Cache::increment("folder_gen:{id}:null")`. Sin esto, los listados cacheados en Redis siguen mostrando el breadcrumb viejo hasta el TTL.
- [x] 2.2 Documentar el comando en `AGENTS.md` (sección "Operaciones de cache") como paso obligatorio al deploy de cualquier cambio que afecte la forma de `breadcrumbs`.

## 3. Verificación manual end-to-end

- [x] 3.1 Login como `jsuarez`, navegar a `00 Discos > Disco_I > television > Telemedellin > 30092026`. Confirmar que el breadcrumb muestra `Home > 00 Discos > Disco_I > television > Telemedellin > 30092026` (una sola vez el último).
- [x] 3.2 Navegar a una carpeta con un solo nivel (ej. raíz de un storage). Confirmar que no aparece ninguna carpeta duplicada.
- [x] 3.3 Navegar a una carpeta con dos niveles. Confirmar que el breadcrumb tiene 3 segmentos: storage root, padre, y current (sin duplicar el current).
- [x] 3.4 Si `fs_primary_enabled` está activo para algún storage canary, navegar a un folder de ese storage y comparar el breadcrumb con el modo BD-first.

## 4. Rollback

- [x] 4.1 Si el cambio rompe algo en producción: `git revert <commit>` + bumpear `folder_gen` de nuevo para invalidar el cache revertido. Sin datos que limpiar.
