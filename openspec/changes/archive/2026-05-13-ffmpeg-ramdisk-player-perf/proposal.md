## Why

Los cortes generados por el editor de medios se escriben en `/tmp`, que reside en disco (ext4). El servidor tiene 78 GB de RAM con 65 GB disponibles y `/dev/shm` montado como tmpfs a 40 GB pero usando solo 16 KB. Redirigir la escritura temporal de FFmpeg a RAM elimina la latencia de disco en la generación y streaming de cortes. Además, el player de preview hace un fetch extra innecesario para obtener el mime_type antes de mostrar el reproductor, retrasando la carga.

## What Changes

### RAM disk para FFmpeg
- Reducir `/dev/shm` de 40 GB a 20 GB (remount + fstab).
- Crear un nuevo tmpfs de 20 GB en `/mnt/cliptemp` dedicado a FFmpeg (fstab persistente).
- Actualizar `MediaClipController` para usar `/mnt/cliptemp` en lugar de `sys_get_temp_dir()` en los 6 puntos donde se crean archivos temporales (cortes, previews, concat lists).

### Player de audio y video
- Eliminar el doble round-trip en `preview.blade.php`: `FileController::view()` ya carga `$file` de BD, pasar `mime_type` y `name` directamente al blade para que Alpine no haga un `fetch('/files/<id>')` adicional.
- Añadir `preload="metadata"` en los elementos `<video>` y `<audio>` para que el browser cargue duración e índice antes del play.

## Capabilities

### New Capabilities
- Ninguna.

### Modified Capabilities
- `media-clip-editor`: Los cortes y previews se generan en RAM; misma API, mejor rendimiento.
- Player de preview: carga sin fetch extra de metadatos; duración disponible antes del play.

## Impact

**Infraestructura del servidor**:
- `/etc/fstab`: remount de `/dev/shm` a `size=20G`; nueva entrada para `/mnt/cliptemp size=20G`.

**Controller**:
- `app/app/Http/Controllers/MediaClipController.php` — constante `CLIP_TMP_DIR = '/mnt/cliptemp'`; reemplazar las 6 ocurrencias de `sys_get_temp_dir()`.

**Controller**:
- `app/app/Http/Controllers/FileController.php` — método `view()`: pasar `fileMime` y `fileName` a la vista.

**Vistas**:
- `app/resources/views/files/preview.blade.php` — Alpine.js usa las variables del blade en lugar de hacer fetch; añadir `preload="metadata"`.

**Sin migraciones. Sin nuevas rutas. Sin nuevas dependencias.**

## Non-Goals

- `X-Accel-Redirect` (optimización nginx para streaming, cambio separado).
- PDF.js (degrada rendimiento para PDFs pequeños, no aplicable en este proyecto).
- Cambios al editor de ondas de audio (WaveSurfer.js).
- Thumbnails de timeline (son caché persistente, no aplica RAM disk).
