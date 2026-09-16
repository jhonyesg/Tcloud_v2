## 0. Reporting (constraint transversal)

- [x] 0.1 Antes de ejecutar cualquier comando, Kilo envía un print al chat con el shape:
    ```
    [Tarea X.Y] <descripción>
      → antes:  <estado previo>
      → comando: <cmd>
      → después: <resultado + exit code>
    ```
- [x] 0.2 Después de cada print, **esperar confirmación del operador** antes de avanzar a la siguiente tarea (constraint `reportar_resultados_antes_de_comandos`).
- [x] 0.3 Al terminar cada grupo de tareas (1, 2, 3, 4, 5, 6), enviar un print de resumen del grupo y esperar OK del operador antes de empezar el siguiente.

## 1. Setup

- [x] 1.1 **[PRINT]** Confirmar el patrón de harness existente leyendo `tests/harness_mis_avisos_viewer.php` y `tests/harness_storage_sync_is_file_linked.php`. Print al chat con: helpers identificados, patrón de cleanup por tag, convenciones de naming.
- [x] 1.2 **[PRINT]** Confirmar la firma y comportamiento de `MediaClipController::clip(Request, int)` (`MediaClipController.php:106-139`) y de `User::hasReachedClipLimit()`/`mediaEditorClipsThisMonth()` (`User.php:115-130`). Print al chat con: snippet clave de cada método y línea exacta del guard del 403.

## 2. Crear el harness

- [x] 2.1 **[PRINT]** Crear el archivo `app/tests/harness_mis_avisos_clip_limit.php` con el header docblock. Print al chat con: ruta del archivo creado, primeras 10 líneas, comando `ls -la` confirmando.
- [x] 2.2 **[PRINT]** Generar el `tag` único (`hmcl_<8-hex>`) y definir las helpers `h_ok`/`h_fail`/`h_check`/`h_section`. Print con: tag generado, snippet de cada helper.
- [x] 2.3 **[PRINT]** Bloque de bootstrap: crear 1 usuario cliente + 1 admin. Print con: IDs de usuarios creados, query SQL `SELECT id, username, role, media_editor_clip_limit FROM users WHERE id IN (?, ?)` y su resultado.
- [x] 2.4 **[PRINT]** Crear 1 storage local temporal en `/tmp/<tag>` y asignarlo vía `user_storages`. Print con: storage_provider_id, base_path, filas en user_storages.
- [x] 2.5 **[PRINT]** Crear 1 archivo real en el storage. Print con: file_id, ruta física, `ls -la <base_path>/emision.mp4` confirmando.
- [x] 2.6 **[PRINT]** Insertar 1 transcripción + 3 segmentos. Print con: transcription_id, IDs de los 3 segmentos, `SELECT count(*) FROM transcription_segments WHERE transcription_id = ?`.

## 3. Aserciones — flujo principal

- [x] 3.1 **[PRINT]** Aserción (a): insertar 1 `MediaEditJob` y verificar `count=1`. Print con: job_id creado, valor de `mediaEditorClipsThisMonth()` antes/después.
- [x] 3.2 **[PRINT]** Aserción (b): preview no cuenta. Print con: HTTP status code de la respuesta, body excerpt, valor del count pre/post.
- [x] 3.3 **[PRINT]** Aserción (c): 2° clip → count=2=limit, `hasReachedClipLimit()=true`. Print con: job_id 2, valores booleanos.
- [x] 3.4 **[PRINT]** Aserción (d): 3er intento → HTTP 403. Print con: status code, body completo, aserción de substring "Límite".

## 4. Aserciones — casos borde

- [x] 4.1 **[PRINT]** Caso admin: 3 jobs + admin bypass. Print con: count real, valor de `hasReachedClipLimit()` para admin.
- [x] 4.2 **[PRINT]** Caso failed: status='failed' no cuenta. Print con: job_id failed, count pre/post.
- [x] 4.3 **[PRINT]** Caso limit=0: ilimitado. Print con: valor de `media_editor_clip_limit`, `hasReachedClipLimit()` resultante.
- [x] 4.4 (opcional) **[PRINT]** Caso sin editor: 403 antes del cupo. Print con: status code y body.

## 5. Cleanup y documentación

- [x] 5.1 **[PRINT]** Bloque `finally`: cleanup en orden inverso. Print con: query SQL pre-cleanup (`SELECT count(*) FROM ... WHERE ... LIKE 'hmcl_%'`) y post-cleanup (debe ser 0).
- [x] 5.2 **[PRINT]** Cleanup defensivo al inicio: borrar residuos `hmcl_*` de corridas anteriores. Print con: cantidad de filas residuales borradas.
- [x] 5.3 **[PRINT]** Exit code 0/1. Print con: valor del exit code al final.
- [x] 5.4 **[PRINT]** Documentar el harness en `AGENTS.md`. Print con: sección agregada, líneas exactas.

## 6. Validación

- [x] 6.1 **[PRINT]** Correr el harness: `cd app && php tests/harness_mis_avisos_clip_limit.php`. Print con: output completo, exit code, tiempo de ejecución.
- [x] 6.2 **[PRINT]** Forzar fallo intencional (cambiar `limit=2` → `limit=99`). Print con: output del fallo, exit code=1, mensaje específico que falló.
- [x] 6.3 **[PRINT]** Verificar limpieza post-run. Print con: `psql -c "SELECT count(*) FROM media_edit_jobs WHERE source_file_name LIKE 'hmcl_%'"` → debe retornar 0.
- [x] 6.4 **[PRINT]** `openspec validate verify-mis-avisos-clip-counts-toward-editor-limit --strict`. Print con: output del validador.
