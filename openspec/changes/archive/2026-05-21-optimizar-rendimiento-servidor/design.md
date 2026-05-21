## Context

El servidor corre PHP 8.4 vía PHP-FPM con el pool `[www]` configurado con `pm.max_children = 300` y `memory_limit = 1024M`. La RAM disponible es 78 GB. En condiciones de carga moderada (uploads concurrentes + sync de storage + streaming de video) los procesos PHP-FPM se acumulan porque: (a) los workers no se reciclan nunca (`pm.max_requests` no está configurado), (b) el comando `storage:sync --all` puede solaparse consigo mismo, y (c) el middleware `SessionTracker` hace una query DB en cada request HTTP, incluyendo polling frecuente del frontend.

## Goals / Non-Goals

**Goals:**
- Limitar el consumo de memoria de PHP-FPM a ~12–15 GB máximo bajo carga total (50 workers × 256 MB).
- Eliminar la acumulación de procesos sync solapados.
- Reducir queries DB innecesarias en el hot path de cada request.
- Corregir N+1 en eliminación recursiva de archivos.
- Todos los cambios son reversibles sin migración de BD.

**Non-Goals:**
- X-Accel-Redirect para streaming (requiere cambio de infraestructura nginx, se pospone).
- Cambio de driver de sesiones o arquitectura de auth.
- Índices adicionales de BD.
- Queue workers o jobs asíncronos para sync.

## Decisions

### D1: Valores de PHP-FPM

**Decisión**: `pm.max_children = 50`, `pm.min_spare_servers = 5`, `pm.max_spare_servers = 15`, `pm.max_requests = 500`, `memory_limit = 256M`.

**Razonamiento**:
- 50 × 256 MB = 12.8 GB máximo bajo carga total — bien dentro de los 78 GB.
- `pm.max_requests = 500` recicla workers periódicamente, evitando drift de memoria por leaks acumulados.
- 256 MB es suficiente para todas las operaciones de la app excepto ZIPs grandes. Los ZIPs se arman en `/tmp` con `ZipArchive` que opera sobre el filesystem, no en memoria PHP.
- **Alternativa descartada**: `pm = ondemand` — reduciría workers idle pero introduce latencia de arranque en picos de carga.

### D2: Cache de SessionTracker en Redis

**Decisión**: cachear el resultado de `UserSession::where('session_id', ...)->first()` en Redis con clave `session_valid:{session_id}` y TTL de 30 segundos.

**Razonamiento**:
- El throttle de `last_activity_at` ya es de 60 s. Una caché de 30 s garantiza que la validación es fresca antes de la próxima actualización.
- Si la sesión se invalida (admin la mata), el usuario seguirá teniendo acceso hasta 30 s más — aceptable dado que la sesión solo existe en Redis/BD de todos modos y el caso es raro.
- La clave se invalida explícitamente cuando `SessionService::killSession()` elimina la sesión.
- **Alternativa descartada**: caché en memoria PHP por request — no funciona porque cada request es un proceso FPM diferente.

### D3: withoutOverlapping en storage:sync

**Decisión**: agregar `->withoutOverlapping()` al schedule del comando.

**Razonamiento**: Laravel implementa esto con un lock de caché (Redis). Si el lock existe, el nuevo intento de ejecución se omite silenciosamente. Sin efecto secundario.

### D4: Eager loading y memoización de hasStoragePermission

**Decisión**: 
- En `FileController::deleteRecursive` y `deleteFile`, pre-cargar `storageProvider` con `->with('storageProvider')`.
- En `User::hasStoragePermission`, memoizar `userStorages` en una propiedad del modelo para el ciclo de vida del request.

**Razonamiento**: La eliminación recursiva de una carpeta con 100 archivos genera 100 queries a `storage_providers` sin eager loading. La memoización de `userStorages` evita repetir la misma query cuando `hasStoragePermission` se llama varias veces por request (ocurre en FileController::index para cada archivo).

## Risks / Trade-offs

- **[Riesgo] Cache de sesión introduce lag de 30 s para revocaciones de sesión** → Mitigación: aceptable por diseño; el caso de uso crítico (sesión robada) se mitiga con el logout explícito que invalida el caché inmediatamente.
- **[Riesgo] Bajar memory_limit puede romper ZIPs muy grandes** → Mitigación: los ZIPs usan `ZipArchive` con archivos en disco; el consumo PHP real es bajo. Si hay problemas, subir a 512M solo para ese pool no afecta al cálculo de workers.
- **[Riesgo] pm.max_requests = 500 puede introducir micro-downtime al reciclar workers** → Mitigación: FPM recicla un worker a la vez después de que termina su request; no hay downtime observable.

## Migration Plan

1. Editar `/www/server/php/84/etc/php-fpm.conf` con los nuevos valores.
2. Recargar PHP-FPM: `systemctl reload php84-fpm` o `/www/server/php/84/sbin/php-fpm reload`.
3. Desplegar cambios de código (console.php, SessionTracker, FileController, User).
4. Verificar con `ps aux | grep php | wc -l` que el número de procesos es estable.

**Rollback**: revertir php-fpm.conf a valores anteriores + reload FPM. El código es retrocompatible.

## Open Questions

- ¿Se quiere agregar `pm.max_requests` también al pool de php-cgi si existe? (No aplica a esta app, solo FPM).
- ¿El TTL de caché de SessionTracker debe ser configurable vía `.env`? Por ahora se deja hardcodeado en 30 s.
