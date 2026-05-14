## Context

El sistema actual usa sesiones de Laravel almacenadas en Redis. El `AuthController` guarda `user_id`, `user_role`, `user_email` y `user_username` en sesión con `Session::put()`. El middleware `Authenticate` verifica `Session::has('user_id')`. No existe ningún índice que relacione un `user_id` con sus `session_id` activos, por lo que es imposible consultar, listar o invalidar sesiones por usuario sin hacer un full scan de Redis.

El `SESSION_LIFETIME=120` es un valor global en `.env` que aplica a todos los usuarios por igual y no es modificable desde la aplicación.

## Goals / Non-Goals

**Goals:**
- Rastrear sesiones activas por usuario en PostgreSQL (fuente de verdad auditable).
- Limitar sesiones simultáneas: bloquear login cuando el usuario alcanza su límite.
- Permitir al admin configurar límites globales y por usuario.
- Permitir invalidación remota de sesiones (borra de Redis + DB).
- Dar al usuario visibilidad de sus propias sesiones desde el dashboard.
- Monitorear el estado operativo de Redis desde el panel admin.

**Non-Goals:**
- No se reemplaza el sistema de sesiones de Laravel ni el driver Redis.
- No se implementa JWT ni tokens de API.
- No se notifica al usuario cuando una sesión suya es cerrada remotamente.
- No se implementa geo-blocking ni detección de fraude.

## Decisions

### D1: PostgreSQL como fuente de verdad para sesiones activas (no solo Redis)

**Decisión:** Crear tabla `user_sessions` en PostgreSQL que registra cada sesión activa.

**Alternativa descartada:** Mantener solo en Redis con sets `user_sessions:{user_id}`. Problema: los datos de Redis no son persistentes por defecto y un restart limpia el índice, dejando el sistema ciego sobre qué sesiones existen.

**Rationale:** PostgreSQL ya es la fuente de verdad del dominio. Permite queries simples para contar sesiones, listar por usuario y auditar actividad. La sincronización Redis↔DB se mantiene mediante login/logout/kill; no hay estado intermedio.

### D2: Session ID de Laravel como clave de enlace entre Redis y DB

**Decisión:** Almacenar el `Session::getId()` (que es el valor del cookie `tcloud_session`) en `user_sessions.session_id`. Para invalidar remotamente: `Redis::del(config('cache.prefix') . ':' . $sessionId)` + delete de DB.

**Rationale:** Es el único identificador compartido entre el store de Redis y la aplicación. No requiere cambios al driver de sesión de Laravel.

### D3: Bloqueo en login, sin auto-kill de sesión más antigua

**Decisión:** Cuando `count(active_sessions) >= max_sessions`, el login falla con mensaje: *"Límite de sesiones simultáneas superado. Cierra una sesión desde otro dispositivo e intenta de nuevo."*

**Alternativa descartada:** Matar automáticamente la sesión más antigua. Esto puede expulsar a un usuario que está trabajando activamente sin previo aviso.

**Rationale:** El usuario debe decidir conscientemente qué sesión cerrar. Si quedó sin acceso a otros dispositivos, contacta al admin.

### D4: Middleware SessionTracker con throttle de 1 update por minuto

**Decisión:** El middleware actualiza `last_activity_at` solo si han pasado > 60 segundos desde el último update (comparando con el valor actual en DB). Se registra el `session_id` actual en la sesión de Laravel para poder localizar el registro en DB.

**Alternativa descartada:** Actualizar en cada request. En apps con muchas llamadas AJAX (como el file manager), esto generaría una escritura a DB por cada operación.

**Rationale:** Balance entre frescura del dato (máximo 1 min de desfase) y carga en BD.

### D5: system_settings como tabla key-value para configuración global

**Decisión:** Tabla `system_settings(key VARCHAR, value TEXT)` con registros seeder para `global_max_sessions` y `global_session_lifetime`.

**Alternativa descartada:** Variables en `.env`. Requeriría reinicio del servidor para cambiar valores desde la UI.

**Rationale:** Permite al admin cambiar configuración global desde el panel sin tocar el servidor.

### D6: Clave Redis para invalidación

El prefijo de Laravel se construye como: `config('database.redis.options.prefix')` (por defecto `laravel_database_`). La key completa de sesión en Redis es `{prefix}{session_id}`. Se usa `Illuminate\Support\Facades\Redis::del()` con el prefijo correcto.

## Data Model

```
users (tabla existente, columnas nuevas)
├── max_sessions          INT NOT NULL DEFAULT 6
└── session_lifetime_minutes  INT NULL  (null = hereda global)

user_sessions (tabla nueva)
├── id                    BIGSERIAL PK
├── user_id               BIGINT FK → users.id ON DELETE CASCADE
├── session_id            VARCHAR(255) UNIQUE  ← Session::getId()
├── ip_address            VARCHAR(45)
├── user_agent            TEXT
├── created_at            TIMESTAMP
├── last_activity_at      TIMESTAMP
└── expires_at            TIMESTAMP NULL  (null = sin expiración)

system_settings (tabla nueva)
├── id                    BIGSERIAL PK
├── key                   VARCHAR(100) UNIQUE
├── value                 TEXT
└── updated_at            TIMESTAMP
```

## Flujo de Login

```
POST /login
  │
  ├─ Validar credenciales
  ├─ Obtener max_sessions del usuario
  │     = user.max_sessions (si > 0) o system_settings.global_max_sessions
  │
  ├─ Si max_sessions > 0:
  │   COUNT user_sessions WHERE user_id = ? AND (expires_at IS NULL OR expires_at > now())
  │   Si count >= max → return back()->with('error', '...')
  │
  ├─ Session::regenerate()  ← nuevo session_id limpio
  ├─ Session::put('user_id', ...) etc.
  ├─ Calcular expires_at:
  │     lifetime = user.session_lifetime_minutes ?? system_settings.global_session_lifetime
  │     expires_at = lifetime > 0 ? now() + lifetime min : null
  │
  └─ INSERT user_sessions(user_id, session_id, ip, ua, created_at, last_activity_at, expires_at)
```

## Flujo de Logout

```
GET /logout
  ├─ $sessionId = Session::getId()
  ├─ Session::flush()              ← invalida la sesión en Redis
  └─ DELETE user_sessions WHERE session_id = ?
```

## Invalidación Remota (admin o usuario)

```
DELETE /admin/sessions/{id}  (admin) | DELETE /user/sessions/{id}  (usuario)
  ├─ Obtener registro UserSession
  ├─ Redis::del(prefix . ':' . $record->session_id)
  └─ $record->delete()
```

## Arquitectura de Componentes

```
app/Http/Controllers/
  ├── AuthController.php           (modificado: login, logout)
  ├── SessionController.php        (nuevo: admin sessions CRUD)
  ├── UserSessionController.php    (nuevo: user self-service sessions)
  └── RedisMonitorController.php   (nuevo: Redis status API)

app/Http/Middleware/
  └── SessionTracker.php           (nuevo)

app/Models/
  ├── UserSession.php              (nuevo)
  └── SystemSetting.php           (nuevo)

app/Services/
  └── SessionService.php          (nuevo: lógica de conteo, kill, cleanup)

resources/views/
  ├── admin/sessions.blade.php     (nuevo)
  ├── admin/redis.blade.php        (nuevo)
  └── dashboard/user.blade.php     (modificado: sección Mis Sesiones)

database/migrations/
  ├── ..._create_user_sessions_table.php
  ├── ..._create_system_settings_table.php
  └── ..._add_session_fields_to_users_table.php
```

## Monitor Redis

El `RedisMonitorController` usa `Redis::connection()->client()->info()` para obtener:
- `redis_version`, `uptime_in_seconds`, `used_memory_human`, `maxmemory_human`
- `connected_clients`, `total_commands_processed`

Para contar sesiones activas en Redis: `Redis::keys(config('database.redis.options.prefix') . ':*')` — se compara con `user_sessions` count para detectar desync.

**Nota de seguridad:** El endpoint `/admin/redis` está protegido por middleware `['auth', 'admin']`.

## Risks / Trade-offs

| Riesgo | Mitigación |
|--------|-----------|
| Desync Redis↔DB si Redis se limpia | Botón admin "Limpiar sesiones huérfanas" hace DELETE user_sessions WHERE session_id NOT IN (keys de Redis). También: limpiar sesiones expiradas periódicamente. |
| Carga en DB por updates de last_activity | Throttle de 60s en SessionTracker. |
| session_id en DB expone identificador de sesión | La columna solo es accesible por el admin vía queries directas; las vistas muestran solo los últimos 8 chars para referencia visual. |
| Redis::keys() lento en producción con muchas keys | Solo se llama desde panel admin bajo demanda, no en requests de usuario. |
| Prefix de Redis incorrecto al invalidar | SessionService centraliza la construcción del prefijo y tiene test unitario. |

## Migration Plan

1. Ejecutar las 3 migraciones (no destructivas, solo adds).
2. Seeder de `system_settings` con valores actuales de `.env`.
3. Registrar middleware `SessionTracker` en el stack de rutas `auth`.
4. Deploy — sesiones existentes no se invalidan; `user_sessions` empieza vacía y se puebla en los próximos logins.
5. Rollback: revertir migraciones, quitar middleware del stack.

## Open Questions

- ¿El housekeeping de sesiones expiradas debe tener un scheduled command (cron) además del botón manual en el panel admin?
- ¿Mostrar la sesión actual del usuario en la vista "Mis sesiones" como no-cerrable o simplemente omitirla de la lista?
