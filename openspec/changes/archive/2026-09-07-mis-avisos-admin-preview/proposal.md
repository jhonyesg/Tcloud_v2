## Why

Hoy el admin puede ver una vista administrativa de cada cliente en `/ia/avisos-inteligentes/{userId}`, pero esa vista está desfasada respecto del módulo real que vive el cliente en `/mis-avisos`. El cliente ve keywords, categorías, scope editor por storage, pestañas de En Vivo / Histórico / Preferencias; el admin no ve nada de eso. Para evaluar cómo le está funcionando el módulo a un cliente sin pedirle credenciales y entrar como él, el admin tiene que abrir múltiples pestañas y cruzar datos mentalmente.

El cambio es **mínimo y no destructivo**: agregar un mecanismo de "preview" no-sticky (vía `?as_user={id}`) que le permita al admin abrir `/mis-avisos` cargando los datos de cualquier cliente con el módulo activado — incluyendo a sí mismo — y actuar como él (leer Y editar). La sesión real del admin nunca se reemplaza: la impersonación vive solo durante el request.

## What Changes

- Nuevo middleware `App\Http\Middleware\AdminPreviewSwap` (~25 líneas): cuando el actor autenticado tiene `role === 'admin'` y el request trae `?as_user={id}`, swappea `session('user_id')` por el valor del parámetro al inicio y lo restaura al final del request (try/finally). Pasa `as_user` también a `session('admin_previewing_id')` para que la Blade pueda decidir si dibujar el banner.
- En `routes/web.php` se agrega `AdminPreviewSwap::class` al final del chain del grupo `['auth', 'misavisos']` (sin tocar ninguna ruta).
- En `resources/views/mis-avisos/index.blade.php` se agrega un bloque condicional (al inicio del `<div x-data="misAvisosPage()">`) que muestra:
  - Un banner fijo en la parte superior con el usuario impersonado, un dropdown para cambiar de cliente y un link "Volver a mi cuenta".
  - El dropdown está poblado por los usuarios con `user_alerts_inteligentes.enabled = true`, ordenado alfabéticamente, y al elegir uno navega a `/mis-avisos?as_user={id}`.
  - "Volver a mi cuenta" navega a `/mis-avisos` sin parámetro.
- Configurar un canal de log dedicado (`config/logging.php`: `admin_preview`) que escriba a `storage/logs/admin-preview.log`. El middleware registra un `Log::info()` con `admin_id`, `impersonated_id`, `route`, `method`, `ip` cada vez que se aplica.
- (Opcional, recomendado) Un nuevo endpoint `GET /admin/preview/impersonatable-users` que devuelve la lista de usuarios impersonables, utilizado por el dropdown de la Blade para mantener el listado ligero. Vive en el grupo de rutas `['auth', 'admin']` ya existente.

## Capabilities

### New Capabilities
- `mis-avisos-admin-preview`: el admin puede abrir `/mis-avisos?as_user={id}` para ver y editar los datos del cliente `id` exactamente igual que si fuera el cliente, con un banner explícito de impersonación, sin alterar su propia sesión ni la sesión real del cliente.

### Modified Capabilities
_Ninguna._ El módulo Mis Avisos como tal no cambia — sigue funcionando exactamente igual para los clientes no-admin. Solo se agregan dos elementos: el middleware (transparente para no-admin) y el banner condicional (no se muestra si no hay impersonación activa).

## Impact

- **Middleware nuevo:** `App\Http\Middleware\AdminPreviewSwap.php`. Único archivo nuevo.
- **Rutas:** una sola línea modificada en `routes/web.php` (el middleware `AdminPreviewSwap::class` se agrega a la cadena existente). Ningún grupo de rutas nuevo, ninguna ruta nueva — excepto el endpoint opcional `GET /admin/preview/impersonatable-users` que es read-only y devuelve un `<select>` HTML renderizado por Blade, no JSON (lo usamos con `htmx` o lo hidratamos en la Blade al cargar).
- **Blade:** un bloque condicional de ~60 líneas agregado al `mis-avisos/index.blade.php`. Cero líneas modificadas del resto.
- **Config:** un canal de log `admin_preview` añadido a `config/logging.php`.
- **Sesión:** se introducen dos claves de sesión (`admin_previewing_id` e `admin_original_user_id`), ambas **temporales**: viven en la request, se eliminan en el `finally` del middleware.
- **Sin cambios en:** `MisAvisosController`, las ~10 rutas `/mis-avisos/*` ya existentes, las tablas, los endpoints del cliente, las migraciones, el matching pipeline, los crons.
- **Seguridad:** el middleware verifica `session('user')->role === 'admin'` antes de aceptar el swap. Un usuario no-admin que envíe `?as_user=99` en la URL es ignorado: el middleware deja pasar al siguiente middleware sin tocar la sesión. El banner y el dropdown solo se renderizan para usuarios con rol admin.

## Non-goals

- No se crea una sesión paralela persistente ni cookie de "impersonación". El swap vive durante el request únicamente.
- No se modifica el módulo de autenticación (`AuthController`, `SessionService`, `UserSession`). El login sigue siendo single-user.
- No se replica la sesión del admin a una ventana separada. Sigue siendo la misma ventana, la misma sesión.
- No se registran todas las acciones de edición del admin contra los datos del cliente — solo el "entrar a preview" (un `Log::info` por request). Si más adelante se requiere audit granular por keyword editada, eso es un cambio aparte.
- No se permite preview de usuarios que NO tengan `user_alerts_inteligentes.enabled = true`. El middleware aplica esa validación y rechaza con 404.
- No se cambia el layout global (`layouts/app.blade.php`). El banner es local a Mis Avisos.
- No se otorgan permisos de admin al cliente impersonado. La sesión real del cliente no es tocada — solo la sesión actual del admin durante la request.
