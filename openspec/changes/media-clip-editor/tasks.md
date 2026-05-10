## 1. Infraestructura — FFmpeg en el contenedor PHP

- [x] 1.1 Añadir `ffmpeg` al Dockerfile del PHP: en el bloque `apt-get install -y` añadir `ffmpeg`
- [x] 1.2 Reconstruir el contenedor PHP: `docker compose build php && docker compose up -d php`
- [x] 1.3 Verificar que FFmpeg está disponible: `docker exec tcloud_php ffmpeg -version`

## 2. Base de datos — feature flag y tabla de logs

- [x] 2.1 Crear migración `add_media_editor_to_users` que añade `media_editor_enabled BOOLEAN DEFAULT FALSE` a la tabla `users`
- [x] 2.2 Crear migración `create_media_edit_jobs_table` con columnas: `id`, `user_id` (FK users), `source_file_id` (bigint nullable), `source_file_name` (varchar), `segments_json` (json), `output_filename` (varchar), `status` (varchar: processing/done/failed), `error_message` (text nullable), `created_at`, `updated_at`
- [x] 2.3 Ejecutar ambas migraciones: `docker exec tcloud_php php artisan migrate --path=database/migrations/...`
- [x] 2.4 Añadir método `canUseMediaEditor()` al modelo `User`: retorna `true` si `role === 'admin'` O `media_editor_enabled === true`

## 3. Backend — controlador de procesamiento

- [x] 3.1 Crear `app/app/Http/Controllers/MediaClipController.php` con método `clip(Request $request, int $id)`
- [x] 3.2 Validar acceso: verificar `$user->canUseMediaEditor()`, rechazar con 403 si no
- [x] 3.3 Validar input: `segments` debe ser array no vacío; cada segmento con `start >= 0`, `end > start`; rechazar con 422 si inválido
- [x] 3.4 Verificar que el storage del archivo es `type = 'local'`; rechazar con 422 si es S3
- [x] 3.5 Construir ruta física: `$storage->base_path . '/' . $file->path`; verificar que el archivo existe en filesystem
- [x] 3.6 Crear registro `MediaEditJob` en DB con `status = 'processing'` antes de ejecutar FFmpeg
- [x] 3.7 Implementar lógica de comando FFmpeg para **un segmento**: `ffmpeg -i {input} -ss {start} -to {end} -c copy {tmp_output}`
- [x] 3.8 Implementar lógica de comando FFmpeg para **múltiples segmentos** usando `filter_complex` con concat; para video mp4 usar `-c:v libx264 -preset fast -c:a aac`; para audio mp3 usar `-c:a libmp3lame`; para m4a usar `-c:a aac`
- [x] 3.9 Ejecutar el comando FFmpeg con `Process` de Symfony (disponible en Laravel); capturar stdout/stderr; timeout 120s
- [x] 3.10 Si FFmpeg falla: actualizar job a `status = 'failed'`, borrar temp file, retornar JSON 500 con mensaje de error
- [x] 3.11 Si FFmpeg éxito: actualizar job a `status = 'done'`; retornar `StreamedResponse` con el archivo temp; en el callback de stream, después de leer el archivo, borrarlo con `unlink()`
- [x] 3.12 Nombre del output: `{nombre_sin_ext}_corte.{ext}` — calcular desde `$file->name`
- [x] 3.13 Crear modelo `MediaEditJob` con `$fillable` y relación `belongsTo(User::class)`

## 4. Rutas y middleware

- [x] 4.1 Añadir ruta en `routes/web.php`: `Route::post('/files/{file}/clip', [MediaClipController::class, 'clip'])` dentro del grupo auth
- [x] 4.2 Añadir ruta para toggle del feature flag desde admin: `Route::post('/admin/users/{user}/toggle-media-editor', [UserController::class, 'toggleMediaEditor'])` dentro del grupo admin

## 5. Admin UI — toggle del feature por usuario

- [x] 5.1 Identificar la vista de admin donde se gestionan usuarios (`resources/views/admin/users.blade.php`)
- [x] 5.2 Añadir columna/toggle "Editor de Medios" en la tabla de usuarios admin con llamada AJAX al endpoint del paso 4.2
- [x] 5.3 Implementar el método `toggleMediaEditor` en `UserController`

## 6. Frontend — botón ✂ en acciones de archivo

- [x] 6.1 En `files/index.blade.php`, en el bloque de acciones de cada archivo (vista grid y vista list), añadir el botón ✂ con `x-show` que evalúa: archivo es mp4/mp3/m4a AND storage es local AND `canUseMediaEditor`
- [x] 6.2 Añadir `canUseMediaEditor` al estado Alpine cargado desde `/auth/me` en el init
- [x] 6.3 Al hacer clic en ✂, llamar a `openClipEditor(file)` que guarda el archivo actual y abre el modal

## 7. Frontend — modal editor de corte

- [x] 7.1 Añadir al estado Alpine.js las propiedades: `showClipModal`, `clipFile`, `clipSegments`, `clipProcessing`, `clipError`
- [x] 7.2 Implementar `openClipEditor(file)` que asigna `clipFile`, inicializa `clipSegments` con un segmento, y abre el modal
- [x] 7.3 Crear el HTML del modal con: título, reproductor video/audio, lista de segmentos con campos inicio/fin, botones agregar/eliminar segmento, nombre de salida, botón generar
- [x] 7.4 Implementar `addClipSegment()` que añade `{start: 0, end: 0}` a `clipSegments`
- [x] 7.5 Implementar `removeClipSegment(index)` que elimina el segmento (mínimo 1)
- [x] 7.6 Implementar `generateClip()` que hace POST a `/files/{clipFile.id}/clip`, recibe el blob, dispara descarga, cierra el modal
- [x] 7.7 En `generateClip()`, manejar errores: leer el JSON de error y asignar a `clipError`
- [x] 7.8 Inputs de tiempo como `number` (segundos con decimales, step=0.1)

## 8. Verificación

- [ ] 8.1 Verificar que FFmpeg está instalado en el contenedor y acepta comandos
- [ ] 8.2 Verificar que el botón ✂ aparece solo para mp4/mp3/m4a en storage local
- [ ] 8.3 Verificar que el botón ✂ no aparece para usuarios sin el feature habilitado
- [ ] 8.4 Verificar corte de un segmento: abrir modal, definir inicio/fin, generar, comprobar que descarga el archivo con nombre correcto
- [ ] 8.5 Verificar corte de múltiples segmentos: añadir 2 segmentos, generar, comprobar que el output contiene los dos segmentos concatenados
- [ ] 8.6 Verificar que el registro queda en `media_edit_jobs` con `status = 'done'`
- [ ] 8.7 Verificar que el archivo temp es eliminado del servidor tras la descarga
- [ ] 8.8 Verificar el toggle desde admin activa/desactiva el feature para un usuario específico
