## Context

El proyecto ya migró los recursos del layout principal (`app.blade.php`) a archivos locales en `public/js/`, `public/css/` y `public/webfonts/`. Quedó pendiente la vista `auth/reset-password.blade.php` que no extiende el layout compartido y tiene su propio `<head>` con Tailwind CDN.

Recursos locales ya disponibles:
- `/js/tailwind.js` — Tailwind CDN script descargado
- `/js/alpine.min.js` — Alpine.js 3.14.9
- `/css/fontawesome.min.css` + `/webfonts/` — Font Awesome 6.5.1

## Goals / Non-Goals

**Goals:**
- Eliminar la última referencia externa (`cdn.tailwindcss.com`) de `reset-password.blade.php`
- Verificar que ninguna otra vista tenga referencias CDN externas

**Non-Goals:**
- Cambiar la estructura HTML de la vista
- Migrar a un sistema de build (Vite, webpack) — se mantiene el enfoque de archivos estáticos

## Decisions

**Reutilizar `/js/tailwind.js` ya descargado**: La vista reset-password solo usa Tailwind para estilos. El archivo local ya existe y es idéntico al CDN. No se necesita descarga adicional.

**No convertir reset-password al layout principal**: La página de recuperación de contraseña funciona de forma independiente (sin sesión). Mantenerla como vista standalone es correcto; solo hay que apuntar su Tailwind al archivo local.

## Risks / Trade-offs

- **Riesgo**: Si Tailwind lanza una versión con cambios de utilidades, el archivo local quedará desactualizado. Mitigación: actualizar `tailwind.js` manualmente al actualizar el proyecto.
