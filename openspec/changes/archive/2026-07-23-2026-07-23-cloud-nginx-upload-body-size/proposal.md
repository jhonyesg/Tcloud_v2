## Why

Al subir archivos por **link de share** (POST `/s/{token}/upload`) o directamente en el panel de archivos (POST `/files/upload`), el navegador abre la conexión, Nginx la cierra inmediatamente con **`413 Request Entity Too Large`** y devuelve una **página HTML**, no un JSON. El backend Laravel jamás ve el request.

Evidencia (2026-07-23):

```
/www/wwwlogs/cloud.mediaserver.com.co.error.log
2026/07/23 09:36:47 [error] *2546 client intended to send too large body: 107708998 bytes
   request: "POST /s/UUcayQuLLpXqk9NROk5koOvpJd6aA9sx/upload HTTP/1.1"
   referrer: https://cloud.mediaserver.com.co/s/UUcayQuLLpXqk9NROk5koOvpJd6aA9sx/folder/4995126

2026/07/23 09:43:08 [error] *2770 client intended to send too large body: 107708998 bytes
   request: "POST /s/OIslzohyCRM8fYuQyNg3JuZ6bEUlumwd/upload HTTP/1.1"
   referrer: https://cloud.mediaserver.com.co/s/OIslzohyCRM8fYuQyNg3JuZ6bEUlumwd
```

El archivo `2026-07-23_05-30-04.mp4` pesa ~107 MB, muy por encima del límite.

**Causa raíz:** el vhost `/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf` **no define** la directiva `client_max_body_size`. Nginx cae al default global `1m` (1 MB) y rechaza cualquier cuerpo mayor.

Comparación con otros vhosts del mismo servidor:

```
/www/server/panel/vhost/nginx/bkcloud.mediaserver.com.co.conf:    client_max_body_size 10G;   ← ya configurado
/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf:        (sin directiva — usa el default 1m)
```

El usuario ya intentó por share (`/s/...`) y por la UI de archivos (`/files/...`). En ambos casos ve el síntoma "no me deja subir" pero la causa es la misma: Nginx corta el request antes de que llegue a Laravel. El frontend no puede mostrar un error específico porque la respuesta es HTML, no JSON (la rama `JSON.parse()` en `shares/public.blade.php:393` falla y cae al fallback genérico `Error al subir {file.name}`).

**Por qué importa:** cualquier archivo multimedia (videos `.mp4`, audios `.mp3`, logs pesados) que un cliente o miembro del equipo intente compartir por link queda **silenciosamente bloqueado** con un mensaje vago. El módulo de compartidos, los módulos de transcripción que ingieren desde shares, y los flujos de subida directa están efectivamente muertos para archivos >1 MB.

## What Changes

- Añadir **`client_max_body_size 10G;`** al bloque `server { ... }` de `cloud.mediaserver.com.co.conf`, alineado con `bkcloud.mediaserver.com.co.conf` (mismo nginx, mismo aaPanel, mismo operador).

- **No** se cambia el código PHP/Laravel ni los blueprints del frontend. Nginx nunca estuvo entregando el request al backend, así que el bug es enteramente de infraestructura.

- **No** se cambia el límite de subida por usuario/storage. Los archivos siguen pasando por la quota personal del usuario, por el path de Filesystem del storage, etc. Solo removemos el tope global de Nginx que estaba escondido.

- Optimizar **opcional** del mensaje de error en el share: detectar `xhr.status === 413` con cuerpo no-JSON y mostrar `"El archivo excede el límite máximo permitido por el servidor"`. **Fuera de alcance** de este change; se captura en otro si el usuario lo pide.

## Capabilities

### New Capabilities
- `share-upload`: contrato de comportamiento para la subida por link público (permite carga, devuelve errores JSON claros, no rompe por tamaño de cuerpo HTTP). Hoy ese contrato no está escrito en `openspec/specs/`.

## Impact

- **Infraestructura (único archivo a modificar):**
  - `/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf`
    - `+client_max_body_size 10G;` dentro del bloque `server { ... }` (antes del `include enable-php-84.conf` para que Nginx lo parsee antes del handler PHP).

- **Sin reinicio de PHP-FPM**: `nginx -s reload` es suficiente. PHP-FPM no se toca.

- **Sin migración de BD**: ningún cambio de schema.

- **Sin cambios a Laravel/Blade**: no se tocan controllers, modelos, vistas ni JS.

- **Sin impacto en otros vhosts**: cada vhost nginx tiene su propio bloque `server`, así que añadir el límite al vhost cloud **no afecta** `bkcloud`, `domotica`, `medios`, etc.

## Non-goals

- **No** se cambia `php.ini` (`upload_max_filesize=5000M`, `post_max_size=5000M` ya son suficientes).
- **No** se cambia `client_max_body_size` en otros vhosts del servidor (bkcloud ya lo tiene; los demás quedan fuera de alcance).
- **No** se introduce una cuota de tamaño por usuario ni por share en este change (eso es lógica de aplicación, no de Nginx — change aparte si surge).
- **No** se mejora el mensaje de error del frontend para distinguir 413-de-Nginx vs 413-de-Laravel — queda como nota para un change de UX futuro.
- **No** se migran las quotas en bytes del campo `personal_quota_bytes` (5, 10, 1) — ese es un problema separado de UX del form de admin/users y se propuso abrir otro change para eso.
