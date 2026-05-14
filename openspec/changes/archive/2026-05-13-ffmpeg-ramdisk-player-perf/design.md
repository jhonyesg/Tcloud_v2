## RAM disk: distribución de memoria

```
ANTES                          DESPUÉS
──────────────────────────────────────────────────────
/dev/shm   tmpfs  40 GB        /dev/shm   tmpfs  20 GB
(16 KB usados, desperdiciado)  (OS general purpose)

/tmp       ext4   disco        /mnt/cliptemp tmpfs 20 GB
(FFmpeg escribe aquí)          (FFmpeg escribe aquí — RAM)
```

El `/dev/shm` se reduce para liberar RAM que el kernel asignará al nuevo mount. El nuevo `/mnt/cliptemp` tiene un límite duro de 20 GB; si se agota (caso extremo de muchos cortes simultáneos) FFmpeg falla con error de espacio — aceptable, el límite de clips por usuario ya existe en la lógica de negocio.

## Cambio en /etc/fstab

```
# Línea existente (modificar size):
tmpfs  /dev/shm  tmpfs  defaults,size=20G  0  0

# Nueva línea:
tmpfs  /mnt/cliptemp  tmpfs  size=20G,mode=1777,noatime  0  0
```

El flag `noatime` evita actualizar el access time en cada lectura (innecesario en tmpfs, reduce operaciones de kernel).

## Cambio en MediaClipController

```php
// Antes (disperso en 6 lugares):
sys_get_temp_dir() . '/clip_xxx.mp4'
sys_get_temp_dir() . '/clippreview_token.mp4'
tempnam(sys_get_temp_dir(), 'ffconcat_') . '.txt'

// Después (constante al tope de la clase):
private const CLIP_TMP_DIR = '/mnt/cliptemp';

self::CLIP_TMP_DIR . '/clip_xxx.mp4'
self::CLIP_TMP_DIR . '/clippreview_token.mp4'
tempnam(self::CLIP_TMP_DIR, 'ffconcat_') . '.txt'
```

Los 6 puntos afectados (número de línea aproximado pre-edición):
- `serveTemp()` línea 86 — lookup de preview token
- `processSequence()` línea 133 — output del corte final
- `buildSequenceCommand()` línea 204 — concat list txt
- `previewSequence()` línea 237 — output del preview
- `previewLegacySegments()` línea 285 — output del preview (legacy)
- `processLegacySegments()` línea 348 — output del corte final (legacy)

## Eliminar double fetch en preview

### Antes
```
FileController::view($id)
  → File::findOrFail($id)       ← carga el file
  → return view('files.preview', ['fileId' => $id])
                                 ← descarta $file

Alpine.js x-init="loadFile()"
  → fetch('/files/' + id)        ← carga el file otra vez
  → { mime_type, name }
  → renderiza player
```

### Después
```
FileController::view($id)
  → File::findOrFail($id)
  → return view('files.preview', [
        'fileId'   => $id,
        'fileMime' => $file->mime_type,
        'fileName' => $file->name,
    ])

Alpine blade:
  x-data="{ file: { mime_type: @json($fileMime), name: @json($fileName) }, loading: false }"
  // Sin fetch inicial — player aparece de inmediato
```

## Añadir preload="metadata" en los players

En `preview.blade.php`:

```html
<!-- Video -->
<video controls preload="metadata" class="w-full max-w-4xl rounded" ...>

<!-- Audio -->
<audio controls preload="metadata" class="w-full max-w-lg">
```

`preload="metadata"` le dice al browser que descargue solo los headers del archivo (duración, resolución, número de pistas) antes de que el usuario le dé play. Permite mostrar la barra de progreso con duración real y hacer seek sin descargar el archivo completo. No aplica a imágenes ni PDF.
