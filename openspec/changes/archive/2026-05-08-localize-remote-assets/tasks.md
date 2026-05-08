## 1. Corregir reset-password.blade.php

- [x] 1.1 En `app/resources/views/auth/reset-password.blade.php`, reemplazar `<script src="https://cdn.tailwindcss.com"></script>` por `<script src="/js/tailwind.js"></script>`

## 2. Verificación

- [x] 2.1 Confirmar que no queda ninguna referencia CDN externa en ninguna vista del proyecto (`grep -rn "cdn\.\|jsdelivr\|cloudflare\|unpkg\.\|cdnjs\|fonts\.google" resources/views/`)
- [x] 2.2 Limpiar caché de vistas con `php artisan view:clear`
