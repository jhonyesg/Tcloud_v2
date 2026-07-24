## Context

TCloud es una plataforma Laravel 13 + Alpine.js con auth por sesión (Redis, 120 min de lifetime). El middleware `Authenticate` (`app/app/Http/Middleware/Authenticate.php:14`) detecta sesión expirada y devuelve `401 JSON` cuando la request espera JSON, o `redirect /login` cuando es navegación normal. El problema: cuando la pestaña queda abierta horas y la sesión muere, los `fetch()` en background reciben 401 pero la UI no sabe qué hacer — solo una de las 17 vistas (`admin/sessions.blade.php:25`) chequea explícitamente `status === 401`. Las demás rompen silenciosamente.

El proyecto no usa build step: el JS se sirve como assets estáticos desde `app/public/js/`. Tailwind y Alpine vienen por CDN. Esto obliga a que cualquier solución frontend sea un único archivo `.js` autocontenido que se cargue desde el layout.

## Goals / Non-Goals

**Goals:**
- Detectar 401 (sesión expirada) desde cualquier `fetch()` de la app y redirigir al login con feedback visual.
- Mantener la sesión "viva" mientras la pestaña esté visible, con un ping cada 30 min.
- Pausar el keep-alive cuando la pestaña está oculta; reanudar inmediatamente al volver.
- Distinguir 401 por sesión expirada de 401 legítimo (rutas públicas sin auth).
- Tratar 419 (CSRF expirado) igual que 401 para no dejar al usuario con un fallo silencioso.
- No introducir build step ni dependencias nuevas.

**Non-Goals:**
- Cambiar la duración de la sesión o el driver.
- Implementar refresh tokens / JWT.
- Renovar sesión con la pestaña oculta.
- Refactorizar a una arquitectura SPA completa.
- Notificar al backend al cerrar la pestaña.

## Decisions

### 1. Wrapper `apiFetch()` en lugar de monkey-patch de `window.fetch`

**Decisión**: exportar una función `apiFetch(url, options)` desde `session-manager.js` que envuelve `fetch` y devuelve la `Response` original.

**Por qué**: 
- Es explícito: las vistas declaran su intención (`apiFetch` = "esto puede fallar por sesión").
- Permite saltarse el wrapper en llamadas internas del propio manager (`/auth/ping`) usando `fetch` directo.
- Más fácil de testear y razonar.
- Las migraciones son mecánicas (sustitución de texto `fetch(` → `apiFetch(`), revisión en code review.

**Alternativa considerada**: monkey-patch de `window.fetch`. Descartada porque oculta el comportamiento, complica debugging y mezcla concerns (sesión vs lógica de UI).

### 2. Header `X-Session-Expired: 1` en respuestas 401 del middleware

**Decisión**: `Authenticate` middleware añade este header en TODA respuesta 401. El front decide si redirige basado en:
- status 401 + `X-Session-Expired: 1` → redirigir a `/login`
- status 401 sin header → ignorar (caso API pública, share link, etc.)
- status 419 (CSRF) → redirigir también

**Por qué**: hay rutas públicas en `/shares/{token}/...` que pueden legítimamente responder 401 si el share expiró. Sin un marcador, el front no puede distinguir "sesión tuya expiró" de "este recurso no es accesible".

**Alternativa**: usar `Accept: application/json` vs `text/html` para distinguir. Descartada porque muchas llamadas internas ya piden JSON aunque sean auth-related.

### 3. Keep-alive basado en `setInterval` + `visibilitychange`

**Decisión**: 
- `setInterval` de 30 min (`KEEP_ALIVE_INTERVAL_MS = 30 * 60 * 1000`).
- Si `document.hidden === true` → limpiar interval y suspender.
- Si evento `visibilitychange` con `document.hidden === false` → hacer ping inmediato (por si la sesión ya venció en background) y reanudar interval.

**Por qué**: 30 min es la mitad del lifetime (120 min), da 1 ping de gracia si uno se pierde. Pausar cuando oculta evita tráfico inútil y no afecta la lógica servidor (Laravel igual mide desde el último request).

**Alternativa**: Web Worker con timer real. Descartada por complejidad innecesaria; los browsers modernos throttlean timers en tabs background pero los reactivan al volver, que es justo lo que queremos.

### 4. Guard global contra redirecciones duplicadas

**Decisión**: variable módulo-level `let redirecting = false`. Cuando el interceptor ve 401:
- Si `redirecting === true` → `return res` (no-op silencioso).
- Si `redirecting === false` → set `true`, mostrar toast, `setTimeout(() => window.location = '/login', 1500)`.

**Por qué**: tres fetches en paralelo fallando disparan tres timeouts. Sin guard, el usuario ve flashes de redirects, posibles dobles toasts, y se rompe cualquier cleanup.

### 5. Ruta `POST /auth/ping` separada, no usar `me()`

**Decisión**: nueva ruta dedicada `POST /auth/ping` que solo ejecuta `Session::put('_last_ping', now()->timestamp)` y devuelve `200 OK` con `{ ok: true }`.

> **Nota de implementación**: `Session::touch()` no existe en Laravel 13 (`Illuminate\Session\Store`). Cualquier escritura via `Session::put()` dispara `Session::save()` y refresca el TTL del driver Redis, que es lo que necesitamos.

**Por qué**:
- `me()` devuelve datos del usuario (overhead de query).
- `/auth/ping` es contrato explícito de "keep-alive, nada más".
- `POST` para que CSRF middleware lo cubra automáticamente sin exceptions.
- Permite cambiar el comportamiento del ping en el futuro (p.ej. heartbeat con telemetría) sin tocar `me()`.

### 6. Toast global reutilizable, no un componente por vista

**Decisión**: `session-manager.js` crea un div `#session-toast` en el `<body>` la primera vez que se necesita. Lo muestra con animación CSS simple (slide-down + fade). Lo limpia tras el redirect.

**Por qué**: 
- No requiere que cada vista defina markup de toast.
- Funciona incluso si la vista está en estado roto.
- Se monta en el `body` con `position: fixed`, no afecta layout.
- Estilo coherente con la paleta `brand-*` ya definida en el layout.

### 7. Carga del manager desde `layouts/app.blade.php`

**Decisión**: incluir `<script src="/js/session-manager.js" defer></script>` solo en `layouts/app.blade.php` (no en `auth/login.blade.php` ni en vistas públicas de shares).

**Por qué**: 
- El layout principal es el que ya usan las 17 vistas autenticadas.
- Las vistas de login y públicas no necesitan keep-alive ni interceptor (no hay sesión que expirar en medio de un login).
- Las vistas públicas de shares extienden un layout diferente (`layouts/public` o ninguno), no se ven afectadas.

## Risks / Trade-offs

[Risk] **Ping se pierde por red inestable** → La sesión expira igualmente → Mitigación: el próximo fetch del usuario (≤30 min después) disparará el flujo de toast + redirect. Si la pestaña vuelve de background con sesión expirada, ping inmediato + manejo de 401 lo cubren.

[Risk] **Reload forzado durante una operación crítica del usuario (subida de archivo, descarga ZIP)** → Mitigación: el toast dura 1.5s antes del redirect, dando margen visual. El usuario ve "se venció tu sesión" en vez de un error críptico.

[Risk] **CSRF expirado redirige al login, pero el usuario estaba a mitad de una operación** → Mitigación: aceptamos este trade-off (es estrictamente mejor que el silencio actual). El login page puede preservar el `intended URL` si se desea en follow-up, fuera de alcance aquí.

[Risk] **17 vistas modificadas aumentan superficie de bugs** → Mitigación: el cambio es mecánico (`fetch(` → `apiFetch(` con verificación de imports). Hacerlo en un PR atómico con revisión cuidadosa. Test manual rápido en 3-4 vistas críticas (files/, admin/sessions, admin/users).

[Risk] **`apiFetch` y `fetch` coexisten en el código; developers nuevos podrían usar el incorrecto** → Mitigación: documentar en un comment del propio `session-manager.js`. ESLint rule es deseable pero el proyecto no tiene lint configurado (fuera de alcance).

[Risk] **Timer de keep-alive sobrevive a navegación SPA parcial** → Mitigación: el manager se carga una vez por page load; si la app usa SPA navigation entre vistas, el interval sigue vivo. Alpine `init()` en el layout garantiza una sola instancia.

[Trade-off] **No se distingue entre "sesión tuya expiró" y "fuiste kickeado por admin"** → Ambos disparan el mismo flow. Es aceptable: ambos casos terminan en `/login` con el mismo feedback. Si el admin quiere un mensaje distinto ("fuiste desconectado por el administrador"), se puede extender en follow-up añadiendo un segundo header opcional.

## Migration Plan

1. **Desarrollo local**: implementar manager + endpoint + middleware header.
2. **Migración de vistas**: PR con los 17 archivos cambiados (búsqueda/reemplazo verificada).
3. **Test manual**: dejar pestaña abierta 2h, verificar toast + redirect.
4. **Test de keep-alive**: loguearse, no interactuar 1h, verificar que sesión sigue viva (admin/sessions muestra sesión activa).
5. **Test de pestaña oculta**: abrir app, ocultar pestaña 2h, volver, verificar flujo correcto.
6. **Test de CSRF**: simular token expirado (cambiar `SESSION_LIFETIME` a 1 min, esperar, intentar subir archivo).
7. **Test de share público**: abrir link de share, verificar que NO redirige al login si el share está expirado.
8. **Deploy**: no requiere migraciones ni config changes. Rollback = revertir PR.

## Open Questions

- ¿Vale la pena añadir `intended_url` para que el login recuerde dónde quería ir el usuario? (Sugerido: follow-up, no en este PR.)
- ¿El admin de sesiones debe mostrar un toast diferenciado cuando cierra la sesión de otro usuario? (Sugerido: fuera de alcance, ya manejado por el toast genérico en `admin/sessions.blade.php:57`.)
- ¿Migrar también a `apiFetch` las llamadas internas de Alpine (`x-init` con fetch)? Sí, es parte de la migración de las 17 vistas.