## 1. PHP-FPM tuning

- [x] 1.1 Editar `/www/server/php/84/etc/php-fpm.conf`: ajustar `pm.max_children`, `pm.start_servers`, `pm.min_spare_servers`, `pm.max_spare_servers`, agregar `pm.max_requests = 500` y `php_admin_value[memory_limit] = 256M`
- [x] 1.2 Recargar PHP-FPM y verificar que los nuevos valores están activos con `ps aux | grep php | wc -l`

## 2. Storage sync overlap guard

- [x] 2.1 Agregar `->withoutOverlapping()` al schedule de `storage:sync --all` en `app/routes/console.php`

## 3. SessionTracker cache

- [x] 3.1 En `SessionTracker::handle()`, antes de la query a `UserSession`, consultar la clave `session_valid:{session_id}` en Redis; si existe y es `"1"`, saltar la query y continuar al siguiente middleware
- [x] 3.2 Después de confirmar que el registro existe y no está expirado, escribir `Cache::put("session_valid:{$sessionId}", "1", 30)` (TTL 30 s)
- [x] 3.3 En `SessionService::killSession()`, agregar `Cache::forget("session_valid:{$session->session_id}")` inmediatamente antes o después de eliminar el registro

## 4. Memoización de storages del usuario

- [x] 4.1 En `User.php`, agregar propiedad `private ?Collection $cachedStorages = null` y modificar `hasStoragePermission()` para usar `$this->cachedStorages ??= $this->userStorages()->get()` en lugar de `$this->userStorages()->where(...)->first()` con una búsqueda en colección
- [x] 4.2 Verificar que `canCreateSharesInStorage()` también usa la colección memoizada para evitar su propia query separada

## 5. Eager loading en deleteRecursive

- [x] 5.1 En `FileController::deleteRecursive()`, cambiar `File::where('parent_id', $folder->id)->get()` por `File::where('parent_id', $folder->id)->with('storageProvider')->get()`
- [x] 5.2 En `FileController::deleteFile()`, eliminar el acceso a `$file->storageProvider` como lazy-load si ya viene precargado desde el paso anterior (verificar que no rompe llamadas directas a `deleteFile`)

## 6. Verificación final

- [x] 6.1 Hacer un upload y eliminación de carpeta con varios archivos y confirmar en query log que no hay N+1
- [x] 6.2 Confirmar que `php artisan schedule:list` muestra `withoutOverlapping` en storage:sync
- [x] 6.3 Confirmar en Redis (`redis-cli keys "session_valid:*"`) que las claves se crean al navegar y se eliminan al hacer logout
- [ ] 6.4 Monitorear procesos PHP con `watch -n 5 "ps aux | grep php | wc -l"` durante 30 min bajo uso normal
