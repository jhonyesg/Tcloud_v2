## Why

El proyecto depende de recursos externos (JS, CSS, fuentes) servidos desde CDNs de terceros. Si esos CDNs caen, se actualizan o eliminan el recurso, el sitio queda sin estilos, sin iconos o sin interactividad. Para garantizar disponibilidad total sin depender de infraestructura externa, todos los recursos deben servirse localmente.

## What Changes

- `auth/reset-password.blade.php`: reemplazar `https://cdn.tailwindcss.com` por el archivo local `/js/tailwind.js`
- `layouts/app.blade.php`: ya migrado en cambio anterior — verificar que no quede ninguna referencia remota residual

## Capabilities

### New Capabilities

- `local-asset-serving`: Todos los recursos estáticos (JS, CSS, fuentes) se sirven desde el servidor propio del proyecto

### Modified Capabilities

## Impact

- `app/resources/views/auth/reset-password.blade.php` — único archivo pendiente con CDN externo
- `app/public/js/tailwind.js` ya existe (descargado previamente)
