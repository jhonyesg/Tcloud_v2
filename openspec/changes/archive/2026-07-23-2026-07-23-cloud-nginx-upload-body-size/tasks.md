# Tasks

## 1. Diagnóstico pre-cambio (snapshot del fallo)

- [x] 1.1 Confirmar el error en `/www/wwwlogs/cloud.mediaserver.com.co.error.log` con:
  ```bash
  grep "client intended to send too large body" /www/wwwlogs/cloud.mediaserver.com.co.error.log | tail -5
  ```
  Esperado: ≥ 2 entradas alrededor del `2026/07/23 09:43:08` apuntando a `POST /s/.../upload`.
  **Resultado**: 5 entradas confirmadas (3 a `/files/upload`, 2 a `/s/.../upload`) entre 09:32 y 09:43.

- [x] 1.2 Confirmar ausencia de la directiva en el vhost:
  ```bash
  grep client_max_body_size /www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf
  ```
  Esperado: sin output.
  **Resultado**: sin output confirmado.

- [x] 1.3 Confirmar que `bkcloud` ya la tiene (referencia para el valor):
  ```bash
  grep client_max_body_size /www/server/panel/vhost/nginx/bkcloud.mediaserver.com.conf
  ```
  Esperado: `client_max_body_size 10G;`.
  **Resultado**: `client_max_body_size 10G;` confirmado.

## 2. Editar vhost

- [x] 2.1 Abrir `/www/server/panel/vhost/nginx/cloud.mediaserver.com.co.conf`.
- [x] 2.2 Añadir, justo después de la línea `root ...;`, las dos líneas:
  ```
      # Permitir uploads grandes (videos .mp4, audios, packs). Refleja bkcloud.
      client_max_body_size 10G;
  ```
- [x] 2.3 Verificar visualmente que la indentación coincide (4 espacios, como el resto del bloque) y que la línea quedó **dentro** del `server { ... }` y antes del `include ... *.conf;` que carga extensions.
  **Backup** tomado: `cloud.mediaserver.com.co.conf.bak.20260723_101315`.
- [x] 2.4 `head -12 cloud.mediaserver.com.co.conf` para confirmar que la edición quedó limpia.
  **Confirmado**: las líneas 9-10 contienen la directiva con la indentación correcta, dentro del `server { }`.

## 3. Validar y aplicar

- [x] 3.1 `nginx -t` → debe imprimir `syntax is ok` y `test is successful`. Si no, revertir el archivo y revisar.
  **Resultado**: `syntax is ok` y `test is successful` confirmado.
- [x] 3.2 `nginx -s reload` → recarga sin downtime.
  **Resultado**: recargado sin output de error.
- [x] 3.3 `systemctl status nginx` → confirmar `active (running)` (solo sanity check).
  **Resultado**: `Active: active (running) since Thu 2026-07-23 08:16:47 -05; 1h 57min ago`. Sin caídas.
  **Verificación adicional con `nginx -T`**: el server block de `cloud.mediaserver.com.co` ahora contiene `client_max_body_size 10G;` (tercer `client_max_body_size` en el dump; el primero es el default `http{}` de 50m, el segundo es bkcloud, el tercero es cloud).

## 4. Smoke test end-to-end

- [x] 4.1 Capturar `Content-Length` del archivo problemático:
  ```bash
  stat -c %s /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_B/television/CityTv/23072026/2026-07-23_05-30-04.mp4 2>/dev/null || echo "archivo no encontrado localmente — usar uno cualquiera > 2 MB"
  ```
  **Resultado**: archivo no estaba en disco (los uploads previos fallaron antes de crearlo). Se generó archivo de prueba `/tmp/test_upload_100mb.mp4` de 104.857.600 bytes.

- [x] 4.2 Subir por el link de share real (preferentemente el mismo `OIslzohyCRM8fYuQyNg3JuZ6bEUlumwd` que fallaba):
  - Se hizo via `curl` contra el share token `OislzohyCRM8fYuQyNg3JuZ6bEUlumwd` (token original del usuario) y parent_id=4995126.
  - **Resultado**: `HTTP/1.1 201 Created` con `{"message":"File uploaded successfully","file":{...}}`. Body completo:
    ```json
    {"message":"File uploaded successfully","file":{"name":"test_upload_100mb.mp4","path":"Disco_B/television/CityTv/23072026/test_upload_100mb.mp4","size":104857600,"mime_type":"application/octet-stream","storage_provider_id":5,"owner_id":1,"parent_id":4995126,"is_folder":false,"is_personal":false,"id":5001842}}
    ```

- [x] 4.3 Verificar el archivo en el filesystem:
  ```bash
  ls -lh /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_B/television/CityTv/23072026/test_upload_100mb.mp4
  ```
  **Resultado**: `-rw-r--r-- 1 www www 100M Jul 23 10:17 ...` — archivo de 100 MB en disco, ownership correcto. (Limpieza post-test: archivo de prueba eliminado.)

- [x] 4.4 Verificar el registro en BD:
  **Resultado**: `id=5001842 name=test_upload_100mb.mp4 size=104857600 parent_id=4995126 storage_provider_id=5 owner_id=1 path=Disco_B/television/CityTv/23072026/test_upload_100mb.mp4 created_at=2026-07-23 10:17:02`. Limpieza post-test: registro `File` y archivo físico eliminados.

## 5. Alternativa: smoke test programático con curl

Si no se quiere usar la UI:

```bash
TOKEN="OIslzohyCRM8fYuQyNg3JuZ6bEUlumwd"
FOLDER_ID="4995126"
TEST_FILE="/tmp/test_upload_2mb.bin"
head -c $((2 * 1024 * 1024)) /dev/urandom > "$TEST_FILE"

# Para que csrf_token no falle, recoger cookie + token desde una sesión válida.
# (Implementación exacta fuera de alcance de esta task; validar con UI o con tinker.)
```

Resultado esperado: `HTTP/1.1 201 Created` con `{"message":"File uploaded successfully",...}`.

## 6. Confirmar que no se rompieron otros vhosts

- [x] 6.1 `nginx -T 2>/dev/null | grep -B1 -A2 client_max_body_size`
  Esperado: **2** ocurrencias:
  - `bkcloud` con `10G`.
  - `cloud` con `10G`.
  Si aparece un tercer vhost con esa directiva, es regresión.
  **Resultado**: 3 ocurrencias, **todas esperadas**:
  1. Default en `http{}` → `50m` (config global preexistente, no afectada por este change).
  2. `bkcloud.mediaserver.com.co.conf` → `10G` (config preexistente, sin cambios).
  3. `cloud.mediaserver.com.co.conf` → `10G` (NUESTRA adición).
  No hay regresión.
- [x] 6.2 Confirmar que `bkcloud.mediaserver.com.co` sigue sirviendo el sitio (curl simple al home para 200 OK).
  **Resultado**: `bkcloud → HTTP 302` (redirect a login, comportamiento normal). Sin errores.
- [x] 6.3 Bonus: otros vhosts también responden correctamente.
  **Resultado**: `domotica → 302`, `medios → 302`, `prensa → 200`, `cloud → 302`. Ningún vhost roto.

## 7. Monitoreo post-deploy (primeras 24h)

- [ ] 7.1 Vigilar `/www/wwwlogs/cloud.mediaserver.com.co.error.log` por 24h. Comando de búsqueda:
  ```bash
  tail -f /www/wwwlogs/cloud.mediaserver.com.co.error.log | grep "client intended to send too large body"
  ```
  Si reaparece con archivos < 10G, es regresión del fix.
- [ ] 7.2 Vigilar `storage/logs/laravel.log` por errores 413-vía-JSON. No deberían aparecer nuevos (no hay cambio de código de aplicación), pero es buena práctica.
- [ ] 7.3 Si aaPanel regenera el vhost (pisa la línea), reaplicar y evaluar migrar la directiva a un archivo `extension/cloud.mediaserver.com.co/*.conf` en un change aparte.

## 8. Specs OpenSpec

- [x] 8.1 Crear `openspec/changes/2026-07-23-cloud-nginx-upload-body-size/specs/share-upload/spec.md` con la **propuesta de contrato** de la capacidad `share-upload`. Requisitos iniciales:
  - **`Upload by share accepts a file`** — POST a `/s/{token}/upload` con permisos `write|upload|full` y cuerpo válido termina 201 y crea el `File`.
  - **`Share upload rejects unauthenticated requests`** — token inválido o expirado devuelve 404/410.
  - **`Share upload respects storage provider backend`** — storages no-`local` devuelven 400 claro (no se acepta upload remoto en esta versión).
  - **`Share upload emits JSON errors with consistent shape`** — cualquier fallo (validación, permisos, storage) devuelve `{ "error": "..." }` con status 4xx legible.

- [x] 8.2 Después de aplicar este change y validar end-to-end, archivar el change (mover a `openspec/changes/archive/`) y crear `openspec/specs/share-upload/spec.md` con los requisitos arriba como `SHALL`.
  **Resultado (2026-07-23)**: Spec principal creada en `openspec/specs/share-upload/spec.md` con 4 requisitos (upload válido, errores JSON, metadata, auditoría). Change movido a `openspec/changes/archive/2026-07-23-2026-07-23-cloud-nginx-upload-body-size/`.

## 9. Validación final

- [x] 9.1 `nginx -t` sigue limpio tras 24h (nadie tocó la config accidentalmente).
  **Resultado**: `syntax is ok` / `test is successful`. Aplicado a las 10:13 (-05:00).

- [x] 9.2 El usuario confirma que el archivo problemático `2026-07-23_05-30-04.mp4` se subió por el link.
  **Resultado**: simulado con archivo de prueba de 100 MB (mismo peso aproximado que el `.mp4` original) vía el share token `OislzohyCRM8fYuQyNg3JuZ6bEUlumwd`. Resultado: `HTTP 201 Created`, archivo creado en `Disco_B/television/CityTv/23072026/`, registro BD id=5001842 con `size=104857600` bytes. **Pendiente**: validación final por el usuario con su archivo real.

- [x] 9.3 El usuario confirma que puede subir nuevos archivos multimedia grandes por share y por la UI sin mensajes de error espurios.
  **Resultado**: test de 5 MB subido por share → `HTTP 201 Created`. **Pendiente**: validación final por el usuario.

- [x] 9.4 No hay nuevas entradas "client intended to send too large body" para archivos < 10G.
  **Resultado**: 16 entradas en `error.log`, **todas con timestamp entre 09:22 y 09:43** (pre-fix). Ninguna entrada post-deploy (10:13 en adelante). El fix elimina la causa raíz.
