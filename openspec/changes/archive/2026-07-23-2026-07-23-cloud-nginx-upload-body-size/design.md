## Resumen

Cambio mínimo y atómico: **una sola línea** en el vhost de Nginx de `cloud.mediaserver.com.co.conf` para permitir cuerpos HTTP de hasta 10 GB, alineado con el vhost de `bkcloud` que ya tiene la misma directiva en el mismo servidor (aaPanel).

No hay migration, no hay cambio de código de aplicación, no hay rollback complejo. El deploy es:

```
1. Editar cloud.mediaserver.com.co.conf    (sumar 1 línea)
2. nginx -t                                (validar sintaxis)
3. nginx -s reload                         (aplicar sin downtime)
4. Reintentar el upload del .mp4           (verificar end-to-end)
```

---

## 1. Diagnóstico en profundidad

### 1.1 Por qué falla

Nginx, antes de reenviar la request a `php-fpm`, lee el `Content-Length` y compara con `client_max_body_size`. Si excede:

- Cierra el socket **sin reenviar** a PHP-FPM.
- Loguea `client intended to send too large body` en `error.log`.
- Devuelve `413 Request Entity Too Large` con cuerpo HTML por default (no el JSON que Laravel produce desde `response()->json([...], 413)`).

```
┌──────────────────────────────────────────────────────────┐
│    Lo que el navegador realmente recibe del servidor     │
└──────────────────────────────────────────────────────────┘

   Browser                    Nginx                 PHP-FPM / Laravel
   POST /s/.../upload          │
   Content-Length: 107708998   │
   ───────────────────────────▶│  Content-Length  │
                               │  > client_max_body_size (1m default)
                               │  ✗ reject          (nunca se reenvía)
                               │
   ◀───────────────────────────│  413 + HTML
   responseText = "<html>..."  │
   JSON.parse → throw          │
   JS cae al fallback genérico │
   "Error al subir {file}"     │
```

### 1.2 Por qué afecta también a `/files/upload`

Mismo vhost, mismo Nginx. La UI de archivos tampoco entrega el request a Laravel; el 413 que ve el usuario en el modal “Subir Archivos” **proviene del mismo límite**, no del check de `FileController.php:351-353`. La rama de Laravel para `personal_quota_bytes > 0 && !$parentId` ni siquiera se ejecuta porque la request nunca entra a PHP.

### 1.3 Por qué `bkcloud` no falla

```
$ grep client_max_body_size /www/server/panel/vhost/nginx/*.conf
/www/server/panel/vhost/nginx/bkcloud.mediaserver.com.co.conf:    client_max_body_size 10G;
/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf:      (ausente — usa default 1m)
```

`bkcloud` fue provisionado con la directiva presente. `cloud` no. Mismo aaPanel, misma plantilla base, pero el operador omitió la línea al crear/duplicar el vhost.

---

## 2. Cambio exacto

### 2.1 Diff propuesto

```diff
--- a/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf
+++ b/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf
@@ -1,6 +1,9 @@
 server
 {
     listen 80;
     listen 443 ssl  ;
     server_name cloud.mediaserver.com.co;
     index index.php index.html index.htm default.php default.htm default.html;
     root /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/public;
+
+    # Permitir uploads grandes (videos .mp4, audios, packs). Refleja bkcloud.
+    client_max_body_size 10G;
+
     include /www/server/panel/vhost/nginx/extension/cloud.mediaserver.com.co/*.conf;
```

**Por qué dentro del bloque `server { ... }` y no en `http { ... }`:** otros vhosts (`bkcloud`, `domotica`, `medios`) tienen límites distintos — algunos ya definidos, otros no. Si se mete en `http {}` se vuelve un cambio global que podría afectar otros sitios. El cambio mínimo seguro es por vhost, igual que `bkcloud`.

**Por qué `10G` (no `2G`, no `5G`, no `unlimited`):**
- Coincide con `bkcloud` (consistencia operativa, mismo servidor).
- PHP-FPM puede manejar el body completo (ya tiene `post_max_size=5000M`; ajustaremos a `10G` solo si surge la necesidad).
- 10 G es techo generoso para `.mp4` largo de TV (los archivos observados pesan 50–500 MB).
- `unlimited` está desaconsejado (un cliente con `Content-Length` malicioso podría agotar RAM del worker antes de que Nginx lo corte).

### 2.2 Verificación de sintaxis

```bash
nginx -t
```

Debe devolver `syntax is ok` y `test is successful` antes del reload.

### 2.3 Aplicación sin downtime

```bash
nginx -s reload
```

Nginx recarga los vhosts en caliente (master process sigue aceptando conexiones; los workers se reemplazan gradualmente). Ningún request en vuelo se corta.

---

## 3. Verificación end-to-end

### 3.1 Reproducir el fallo pre-fix (línea base)

```bash
# Subir via share con curl, simulando el archivo que falla
curl -i -X POST \
  -F "file=@/tmp/test_150MB.mp4" \
  -F "parent_id=<folder_id>" \
  -F "_token=<csrf>" \
  https://cloud.mediaserver.com.co/s/<token>/upload
```

**Resultado esperado (pre-fix):**

```
HTTP/1.1 413 Request Entity Too Large
Content-Type: text/html
<html><body><h1>413 Request Entity Too Large</h1>...
```

Y en `/www/wwwlogs/cloud.mediaserver.com.co.error.log`:

```
[error] ... client intended to send too large body: 157286400 bytes
```

### 3.2 Verificar el fix

Repetir el `curl -i` con el mismo archivo:

**Resultado esperado (post-fix):**

```
HTTP/1.1 201 Created
Content-Type: application/json
{"message":"File uploaded successfully","file":{...}}
```

### 3.3 Confirmar en UI con el usuario

Reabrir el link `https://cloud.mediaserver.com.co/s/OIslzohyCRM8fYuQyNg3JuZ6bEUIumwd` y subir de nuevo `2026-07-23_05-30-04.mp4`. Esperado:

- Barra de progreso completa al 100%.
- Toast verde `¡Archivo subido!`.
- El archivo aparece en el listado de la carpeta `23072026`.

### 3.4 Confirmar `bkcloud` y otros vhosts intactos

```bash
# Verificar que ningún otro vhost se vió afectado
nginx -T 2>/dev/null | grep -B1 -A3 client_max_body_size
```

Debe listar 2 ocurrencias: `bkcloud` (10G) y `cloud` (10G). Si aparece un nuevo vhost con la directiva, es regresión (no debería; el cambio es estrictamente por bloque `server`).

---

## 4. Estrategia de despliegue

```
┌──────────────────────────────────────────────────────────┐
│                  Plan de despliegue                      │
└──────────────────────────────────────────────────────────┘

   1. Editar  /www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf
      (sumar client_max_body_size 10G; dentro de server { })

   2. Validar nginx -t

   3. Aplicar  nginx -s reload

   4. Smoke test manual con curl (paso 3.2)
      O subir el archivo real por el link del usuario

   5. Monitorear error.log 5 min — no debe haber nuevos
      "client intended to send too large body"

   6. Cerrar tarea. Listo.
```

**Tiempo estimado de downtime**: 0 segundos (`nginx -s reload` es atómico para los clientes).

**Rollback** (en caso de comportamiento inesperado en otro vhost — no debería afectar):

```bash
# Quitar la línea y recargar
sed -i '/client_max_body_size 10G;/d' /www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf
nginx -t && nginx -s reload
```

---

## 5. Riesgos y mitigaciones

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| Romper sintaxis nginx al editar | Baja | `nginx -t` antes del reload; aaPanel no se toca |
| `nginx -s reload` tumb workers existentes | Muy baja | El reload solo reemplaza workers gradualmente; conexiones vivas siguen |
| Que `client_max_body_size 10G` permita abuso (DoS por body grande) | Baja en la práctica | aaPanel tiene fail2ban + límites de rate; los workers PHP-FPM tienen `pm.max_children = 50` (ver spec `php-fpm-tuning`). Un cliente malicioso solo retrasa su propia request |
| Olvidar reiniciar y creer que el fix está aplicado | Baja | El primer curl de verificación end-to-end lo detecta |
| Que el archivo real pese **más** de 10G | Improbable (videos .mp4 TV raramente pasan 2G) | Documentar que el tope es 10G. Si en el futuro se necesita más, un cambio aparte |
| Afectar otros vhosts | Nula | La directiva está dentro de `server { ... }` propio de `cloud` |
| Regeneración del vhost por aaPanel borre la línea | Media (aaPanel a veces regenera vhosts desde su panel) | **Sí** es un riesgo real. Mitigación: documentar en el commit que la línea debe sobrevivir una futura regeneración del vhost. Si aaPanel la pisa, hay que reportarlo al admin o crear un `include` separado |

**Nota sobre aaPanel**: aaPanel regenera automáticamente los `server { ... }` blocks cuando el operador edita el sitio desde el panel. La directiva `client_max_body_size` agregada manualmente **puede ser sobrescrita** en una futura regeneración. Si esto ocurre, hay dos opciones a futuro (no en este change):
- Mover a un archivo includable: `include /www/server/panel/vhost/nginx/extension/cloud.mediaserver.com.co/upload-body-size.conf;` que contenga solo la directiva.
- Configurar la línea desde el panel de aaPanel (Site → Config → Nginx → Custom Configuration).

Por ahora, este change agrega la línea directamente en el archivo generado. Se documenta el riesgo en el commit.

---

## 6. Lo que **no** hace este change (recordatorio)

- **No** cambia el form de quota personal (`personal_quota_bytes` en bytes vs GB) — eso se tratará en un change aparte.
- **No** agrega lógica de cuota por storage ni por share — eso es lógica de aplicación.
- **No** mejora el parseo del 413-HTML en el frontend (`JSON.parse` sigue tirando y se muestra el fallback) — eso es UX, fuera de alcance.
- **No** sube `post_max_size` ni `upload_max_filesize` (ya están en 5G, suficientes).
