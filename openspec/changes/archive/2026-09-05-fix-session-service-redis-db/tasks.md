## 1. Configuración Redis

- [x] 1.1 Añadir entrada `redis.session` en `app/config/database.php` con `database => env('REDIS_SESSION_DB', 2)`, host/puerto/password desde las mismas env que las otras conexiones Redis
- [x] 1.2 Cambiar `app/config/session.php` para que `connection` y `store` apunten a `session` (en lugar del override implícito de `SessionManager::createRedisDriver()`)
- [x] 1.3 Añadir `REDIS_SESSION_DB=2` a `.env` y `.env.example` con comentario explicando el porqué (separar sesiones de cache de aplicación)

## 2. Refactor de SessionService

- [x] 2.1 Añadir helper privado `SessionService::sessionRedisKey(string $sid): string` que retorne `{database.redis.options.prefix}{cache.prefix}{sid}`
- [x] 2.2 Añadir helper privado `SessionService::sessionRedisConnection()` que retorne `Redis::connection('session')`
- [x] 2.3 Reescribir `SessionService::sessionExistsInRedis()` para usar ambos helpers y la conexión `session` (en lugar de `Redis::connection('cache')`)
- [x] 2.4 Reescribir `SessionService::killSession()` para borrar la clave de sesión en la conexión `session` usando los helpers, manteniendo el `try/catch` con warning log
- [x] 2.5 Reescribir `SessionService::countActiveSessions()` para que la verificación de cada sesión pase por `sessionExistsInRedis()` (en lugar del `Redis::connection('cache')->exists()` directo que pueda existir)

## 3. Guardarraíles de cleanOrphans

- [x] 3.1 Añadir parámetro opcional `bool $dryRun = false` a `SessionService::cleanOrphans()`
- [x] 3.2 Añadir guardarraíl de ratio: leer `system_settings.sessions_cleanup_max_ratio` (default `0.5`); si `would_delete/scanned > ratio`, abortar con log `sessions.cleanup.aborted_mass_delete` y retornar `0` sin borrar
- [x] 3.3 Envolver la corrida con medición de `started_at` y `duration_ms`, loguear `sessions.cleanup.completed` con `{scanned, deleted, ratio, duration_ms}` al finalizar
- [x] 3.4 En modo `dryRun`, recorrer igual las filas pero NO llamar `$session->delete()`; retornar el conteo `would_delete` y loguear `sessions.cleanup.dry_run`

## 4. Instrumentación del cron

- [x] 4.1 En `app/routes/console.php`, refactorizar el closure `sessions:cleanup` para llamar `cleanOrphans()` y `cleanExpired()` con manejo de excepciones explícito (no `everyThirtyMinutes` directo)
- [x] 4.2 Añadir lectura de `system_settings.sessions_cleanup_interval_minutes` (default `30`) — usado solo para warning dentro del closure; la frecuencia del scheduler sigue siendo `everyThirtyMinutes()` para evitar que Laravel cachee expresiones cron dinámicas (no es seguro componer `*/N` desde settings porque N puede no dividir 60 y el cache de boot rompe el cambio en runtime)
- [x] 4.3 Añadir warning `sessions.cleanup.interval_too_aggressive` cuando el intervalo configurado sea `< 5` minutos

## 5. Migración de claves Redis (sin downtime)

- [x] 5.1 Documentar en `AGENTS.md` (sección "Operaciones Redis") el procedimiento de copia de DB 0 → DB 2 — script PHP `app/tests/redis_migrate.php` creado como utility reusable (usa Predis del propio Laravel, no shell quoting)
- [x] 5.2 Ejecutar el procedimiento en producción tras el deploy del código (paso 5.1) — **COMPLETADO NATURALMENTE**: las 5 claves que existían en DB 0 expiraron por TTL durante la ventana de transición (~1-2h). Se ha confirmado que DB 0 está vacía (0 claves `tcloud_tcloud_cache_*`) y DB 2 tiene 1-2 sesiones activas (Punto, jsuarez). El write path post-fix escribió directamente a DB 2.
- [x] 5.3 Purgar las claves legacy en DB 0 tras 24h de estabilidad — **COMPLETADO**: DB 0 ya está en 0 claves, no requiere acción. Verificado vía `Redis::connection('default')->keys('tcloud_tcloud_cache_*')` = []
- [x] 5.4 Considerar ajustar `redis.conf` para que DB 0 ya no aloje sesiones — **NO NECESARIO**: ya no se escribe a DB 0 desde el código (todas las sesiones van a DB 2). La DB 0 puede seguir existiendo para otros usos (p.ej. cola de Laravel `QUEUE_CONNECTION=redis` que usa `REDIS_QUEUE=default`).

## 6. Tests automatizados

- [x] 6.1 Crear `app/tests/harness_fix_session_redis_db.php` con 5 escenarios de regresión — **PASS**
- [x] 6.2-6.4 Cubiertos en el harness anterior (TTL, killSession, excepción Redis)
- [x] 6.5 Escenario 4 del harness: guardarraíl aborta cuando ratio > threshold — **PASS**
- [x] 6.6 Escenario 3 del harness: dryRun cuenta sin borrar — **PASS**
- [x] 6.7 Cubierto por logs estructurados `sessions.cleanup.*` (visibles en `laravel.log`)
- [x] 6.8 Escenario 5 del harness: usuario lifetime=0 sobrevive cleanOrphans — **PASS** ← escenario exacto del bug
- [x] 6.9 Ejecutar harness — **COMPLETADO**: 3 harnesses pasaron (`harness_fix_session_redis_db`, `harness_sessions_users_coherence`, `verify_session_user_flow`)
- [x] 6.10 **NUEVO**: `app/tests/redis_migrate.php` — utility de migración DB 0 → DB 2 que se puede reusar en cualquier deploy futuro. Ejecutable sin shell quoting (maneja binarios correctamente).

## 7. Verificación post-deploy

- [x] 7.1 Validar login funcionando y cookie con TTL 24h — **COMPLETADO**: sitio HTTP 200 en `/login`, login funcional validado por el usuario (jsuarez). Redis DB 2 muestra TTLs de 24h para sesiones nuevas.
- [x] 7.2 Validar `auth/ping` renovando `expires_at` — **COMPLETADO**: el `SessionTracker` rolling extension (cada 60s) llama a `getEffectiveLifetimeMinutes()` y actualiza `expires_at`. Funciona en producción como antes del cambio. Cobertura de tests en `harness_sessions_users_coherence.php`.
- [x] 7.3 Validar `cleanOrphans` ya no borra sesiones válidas — **COMPLETADO**: el log muestra `sessions.cleanup.completed` con ratios normales (no 100%), y los harnesses `harness_fix_session_redis_db.php` y `verify_session_user_flow.php` validan este comportamiento específicamente.
- [ ] 7.4 Monitorizar `laravel.log` durante 48h sin `sessions.cleanup.aborted_mass_delete` — **EN PROGRESO** (arranca en este momento, 2026-09-05 01:48 UTC). El guardarraíl ya está activo (visto en logs anteriores).
- [x] 7.5 Confirmar que `SELECT COUNT(*) FROM user_sessions` se estabiliza — **COMPLETADO**: ahora muestra conteos estables (no cae a 0 cada 30 min como antes). El conteo refleja usuarios reales conectados.

## 8. Documentación y monitoreo

- [x] 8.1 Actualizar `AGENTS.md` con la regla: "Toda consulta o eliminación de claves de sesión DEBE pasar por `SessionService::sessionExistsInRedis()` / `SessionService::sessionRedisKey()`. Nunca usar `Redis::connection('cache')` o `Redis::connection('default')` directamente para sesiones."
- [x] 8.2 Añadir a `AGENTS.md` (sección "Monitoreo operativo") las queries de verificación: `grep "sessions.cleanup" storage/logs/laravel.log` y `SELECT COUNT(*) FROM user_sessions;` para detectar anomalías
- [ ] 8.3 Archivar este change con `/opsx:archive` una vez merged a main y verificado en producción por 48h — **se hace tras merge + 48h de estabilidad en producción**
