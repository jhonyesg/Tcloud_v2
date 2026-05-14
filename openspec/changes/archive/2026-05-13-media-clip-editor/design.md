## Context

El servidor corre en Ubuntu con FFmpeg 6.1.1 instalado nativamente. El PHP corre en un contenedor Docker (`tcloud_php`, imagen `php:8.4-fpm`) que **no tiene FFmpeg**. Los archivos locales se leen desde `storage->base_path` (ruta del filesystem montada en el contenedor). El stack frontend usa Alpine.js + Tailwind CSS. La tabla `users` solo tiene `role` (admin/user) — no existe sistema de feature flags previo.

## Goals / Non-Goals

**Goals:**
- FFmpeg disponible dentro del contenedor PHP (Dockerfile modificado).
- Feature flag `media_editor_enabled` por usuario, siempre true para admin.
- Modal editor con reproductor HTML5, controles de segmento (inicio/fin en segundos), múltiples segmentos por ejecución.
- Backend ejecuta FFmpeg de forma síncrona y devuelve la descarga.
- Archivo temporal limpiado tras la descarga.
- Registro en `media_edit_jobs` de cada uso.
- V1 soporta solo archivos en **storage local** (base_path accesible desde el contenedor).

**Non-Goals:**
- Soporte S3 en v1 (requeriría descarga previa del archivo al servidor).
- Procesamiento asíncrono / cola (se añade si los archivos son muy grandes).
- Preview del resultado antes de generar (el reproductor HTML5 con seekTo sirve como preview visual).
- Editor de línea de tiempo con waveform visual (v2).
- Billing automático en v1 (solo tracking de datos).

## Decisions

### 1. FFmpeg en el contenedor PHP (no en el host)
**Decisión**: Añadir `ffmpeg` al Dockerfile del PHP container con `apt-get install -y ffmpeg`. Reconstruir la imagen.

**Por qué**: PHP ejecuta comandos con `Process` (Symfony) o `shell_exec`. El contexto de ejecución es dentro del contenedor. Invocar ffmpeg del host requeriría un socket o SSH, lo que introduce complejidad y superficie de ataque innecesaria. Instalar en el contenedor es la práctica estándar.

**Alternativas descartadas**:
- *Montar el binario host en el contenedor*: frágil, depende de arquitectura del binario y librerías del host.
- *Microservicio ffmpeg separado*: overkill para v1.

### 2. Procesamiento síncrono con timeout extendido
**Decisión**: El endpoint `POST /files/{file}/clip` ejecuta FFmpeg de forma síncrona y devuelve la respuesta como `StreamedResponse`. Timeout PHP configurado a 120s para este endpoint.

**Por qué**: Las grabaciones típicas son de minutos, no horas. Con `-c copy` (sin re-encodificación para single segment) el procesamiento es casi instantáneo independientemente de la duración. Solo multi-segmento con audio requiere re-encode, que para grabaciones <1h es <30s.

**Riesgo → Mitigación**: Si los archivos son muy grandes → añadir procesamiento asíncrono con jobs en una iteración futura.

### 3. FFmpeg: `-c copy` para segmento único, re-encode para multi-segmento
**Decisión**:
- **1 segmento**: `ffmpeg -i input -ss {start} -to {end} -c copy output` — ultrarrápido, sin pérdida de calidad.
- **N segmentos**: concat con filter_complex. Para video mp4 requiere re-encode (`-c:v libx264 -c:a aac`). Para audio (mp3/m4a) requiere re-encode de audio (`-c:a libmp3lame` / `-c:a aac`).

**Por qué**: `-c copy` con concat puede producir archivos corruptos o con saltos. Re-encode es la opción correcta para multi-segmento. El overhead de calidad es mínimo con preset `fast`.

### 4. Registro en DB como base para billing futuro
**Decisión**: Crear tabla `media_edit_jobs` con: `user_id`, `source_file_id`, `source_file_name`, `segments_json`, `output_filename`, `status`, `created_at`. Insertar **antes** de ejecutar FFmpeg (status=processing), actualizar a done/failed tras el resultado.

**Por qué**: Si el usuario tiene 50 usos/mes en su plan, necesitas el historial. La tabla se crea ahora aunque el billing no esté activo — migrar datos retroactivos después es imposible. El costo de insertar un registro es irrelevante vs el costo de ejecutar FFmpeg.

**Estructura de billing futura**:
- Añadir `media_editor_monthly_limit` a `users` (null = ilimitado).
- Contar `media_edit_jobs WHERE user_id = X AND created_at >= inicio_mes`.

### 5. Feature flag como columna en users
**Decisión**: Añadir `media_editor_enabled BOOLEAN DEFAULT FALSE` a `users`. Admin siempre puede usar el editor (check `role === 'admin' OR media_editor_enabled`).

**Por qué**: Extensible, simple, sin tablas extra. Si en el futuro hay más features, se puede migrar a una tabla `user_features` — pero para 1 feature es over-engineering.

### 6. Acceso al archivo local desde el contenedor
**Decisión**: Usar `$storage->base_path . '/' . $file->path` para construir la ruta del archivo. Esta ruta debe ser accesible desde dentro del contenedor via volume mount.

**Verificación necesaria**: Confirmar que el volume mount del docker-compose expone `base_path` dentro del contenedor. Si no, se añade el mount necesario.

### 7. Modal UI: controles de segmento con inputs de tiempo
**Decisión**: La UI del modal mostrará:
- Reproductor HTML5 (`<video>` o `<audio>` según MIME) con el archivo vía `/files/{id}/view`.
- Un slot de segmento inicial con dos campos de tiempo (MM:SS o input numérico de segundos).
- Botón "＋ Agregar segmento" para añadir más.
- Lista de segmentos con botón de eliminar.
- Botón "✂ Generar corte" que hace POST y activa la descarga.

**Por qué**: Waveform interactivo (wavesurfer.js) añade 200KB de dependencia JS. Para v1, un reproductor estándar con inputs de tiempo es funcional, rápido de implementar y sin dependencias externas.

## Risks / Trade-offs

- **Timeout en archivos muy grandes** → Mitigación v1: documentar límite recomendado; v2: async jobs.
- **S3 no soportado en v1** → El botón ✂ se oculta para archivos en storages tipo S3. Mitigación: mensaje "Solo disponible en storage local".
- **Concurrencia**: múltiples usuarios generando clips simultáneamente consumen CPU y disco. → Mitigación: los archivos temp se eliminan inmediatamente. CPU es el cuello de botella real en multi-encode.
- **Volume mount faltante**: Si `base_path` del storage local no está montado en el contenedor, los archivos no son accesibles. → Verificar en `docker-compose.yml` antes de implementar.
