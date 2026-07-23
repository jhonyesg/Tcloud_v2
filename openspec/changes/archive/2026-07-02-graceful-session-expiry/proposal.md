## Why

Cuando la sesión expira mientras el usuario tiene una pestaña abierta (caso muy común: sesión de 120 min, pestaña dejada en background), las llamadas `fetch()` empiezan a recibir `401` desde `Authenticate` middleware. La UI rompe silenciosamente: toasts a medias, contadores que no actualizan, vistas congeladas, hasta que el usuario hace F5 manual. La experiencia no es aceptable para un producto de almacenamiento que se anuncia como auto-hospedado y "always-on".

Adicionalmente, hoy la sesión vence por inactividad absoluta del lado servidor (Laravel mide el tiempo desde el último request), así que aunque la pestaña esté abierta y visible, si el usuario no interactúa por 120 min la sesión muere.

## What Changes

- **Nuevo módulo JS `session-manager.js`** instalado globalmente: keep-alive cada 30 min (pausado cuando `document.hidden`) + interceptor de `fetch()` que detecta respuestas `401` (y `419` CSRF expirado), muestra un toast suave `"Tu sesión expiró, te llevamos al login..."` y redirige a `/login` tras 1.5s.
- **Nueva ruta `POST /auth/ping`**: toca la sesión (`Session::touch()`) sin alterar nada más. Protegida por middleware `auth`.
- **Nueva capability `session-ux`** que define el comportamiento esperado del frontend y backend ante expiración.
- **Wrapper `apiFetch()`** exportado desde el manager; las 17 vistas que usan `fetch()` se migran al wrapper.
- **Middleware `Authenticate`**: añade header `X-Session-Expired: 1` en respuestas 401 para distinguir "sesión expirada" de "no autenticado de entrada" (público, comparte por link, etc.). En rutas no-HTML sigue devolviendo JSON 401; el front decide.
- **Guard de redirección única**: si múltiples fetches fallan con 401 casi simultáneo, solo se dispara una vez el toast + redirect (los demás son no-op).

### No rompe

- Páginas públicas (`/shares/{token}/...`): el interceptor respeta el flag `X-Session-Expired` y rutas conocidas como públicas.
- Login normal (sin sesión previa): mismo comportamiento que hoy.
- CSRF mismatch (419): se trata igual que 401 → toast + login.

## Capabilities

### New Capabilities

- `session-ux`: Comportamiento de la aplicación ante expiración de sesión, incluyendo keep-alive, detección de 401 y redirección elegante al login con feedback al usuario.

### Modified Capabilities

- `login-by-username`: ningún cambio de requirement, pero se referencia `session-ux` como contrato complementario.
- `spa-navigation`: si aplica, el wrapper `apiFetch` debe respetar la lógica SPA existente.

## Impact

- **Rutas**: nueva `POST /auth/ping` en `app/routes/web.php`.
- **Backend**: `app/app/Http/Controllers/AuthController.php` añade método `ping()`; `app/app/Http/Middleware/Authenticate.php` añade header `X-Session-Expired`.
- **Frontend**: nuevo archivo `app/resources/js/session-manager.js` (CDN-served, sin build step). Layout `app/resources/views/layouts/app.blade.php` lo carga e inicializa.
- **Vistas**: 17 archivos bajo `app/resources/views/` migran `fetch(` → `apiFetch(`.
- **Configuración**: nada nuevo en `.env`; el intervalo de 30 min puede vivir como constante JS para simplicidad (no requiere reconfiguración por entorno).
- **Sesiones**: ningún cambio en driver (sigue Redis), ni en lifetime (120 min), ni en `SESSION_*` config.
- **Migraciones**: ninguna.

## Non-goals

- No se cambia el lifetime de la sesión ni el storage backend.
- No se implementa refresh token ni JWT (sigue auth por sesión Laravel).
- No se renueva sesión con la pestaña oculta (solo cuando vuelve a ser visible, se hace ping inmediato).
- No se introduce build step (sigue siendo Alpine + JS por CDN, sin Vite/webpack).
- No se cambia el diseño visual del login.
- No se notifica al backend sobre cierre de pestaña (`navigator.sendBeacon`) — fuera de alcance.