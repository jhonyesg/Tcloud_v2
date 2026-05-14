## 1. Infraestructura — RAM disk

- [x] 1.1 Crear el directorio `/mnt/cliptemp` en el servidor (`mkdir -p /mnt/cliptemp`)
- [x] 1.2 Montar inmediatamente el tmpfs sin reiniciar: `mount -t tmpfs -o size=20G,mode=1777,noatime tmpfs /mnt/cliptemp`
- [x] 1.3 Verificar el mount: `df -hT /mnt/cliptemp` debe mostrar `tmpfs 20G`
- [x] 1.4 Editar `/etc/fstab`: añadir la línea `tmpfs /mnt/cliptemp tmpfs size=20G,mode=1777,noatime 0 0`
- [x] 1.5 Editar `/etc/fstab`: reducir `/dev/shm` de 40G a 20G en la línea existente
- [x] 1.6 Remount de `/dev/shm`: `mount -o remount,size=20G /dev/shm` y verificar con `df -h /dev/shm`

## 2. Backend — MediaClipController usa CLIP_TMP_DIR

- [x] 2.1 Añadir la constante `private const CLIP_TMP_DIR = '/mnt/cliptemp';` en `MediaClipController`
- [x] 2.2 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `serveTemp()` (lookup del token)
- [x] 2.3 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `processSequence()` (output del corte)
- [x] 2.4 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `buildSequenceCommand()` (concat list txt)
- [x] 2.5 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `previewSequence()` (output preview)
- [x] 2.6 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `previewLegacySegments()` (output preview legacy)
- [x] 2.7 Reemplazar `sys_get_temp_dir()` por `self::CLIP_TMP_DIR` en `processLegacySegments()` (output corte legacy)

## 3. Backend — FileController pasa metadatos al blade

- [x] 3.1 En `FileController::view()`, pasar `fileMime` y `fileName` a la vista junto con `fileId`

## 4. Frontend — Eliminar double fetch en preview.blade.php

- [x] 4.1 Reemplazar el estado inicial de Alpine.js: `file: { mime_type: @json($fileMime), name: @json($fileName) }, loading: false` (sin fetch inicial)
- [x] 4.2 Eliminar el método `loadFile()` y su llamada `x-init="loadFile()"` del componente Alpine
- [x] 4.3 Verificar que los `x-if` y `x-text` que usan `file.mime_type` y `file.name` siguen funcionando con los datos inyectados

## 5. Frontend — preload="metadata" en players

- [x] 5.1 Añadir `preload="metadata"` al elemento `<video>` en `preview.blade.php`
- [x] 5.2 Añadir `preload="metadata"` al elemento `<audio>` en `preview.blade.php`

## 6. Verificación

- [ ] 6.1 Generar un corte en el editor y confirmar que el archivo temporal aparece en `/mnt/cliptemp` (no en `/tmp`)
- [ ] 6.2 Confirmar que el corte se descarga correctamente y el archivo se borra de `/mnt/cliptemp` al terminar
- [ ] 6.3 Abrir un archivo de video en preview y confirmar que el player aparece sin delay de fetch
- [ ] 6.4 Confirmar que la barra de duración del video se muestra correctamente con `preload="metadata"`
- [ ] 6.5 Confirmar que el audio player muestra duración antes de dar play
- [ ] 6.6 Reiniciar el servidor y verificar que `/mnt/cliptemp` se monta automáticamente (fstab persistente)

<!-- Verificación de código/sistema: ✓ 7 paths apuntan a CLIP_TMP_DIR, ✓ /mnt/cliptemp y /dev/shm ambos en 20G, ✓ fstab persistente con ambas entradas, ✓ preload="metadata" en video y audio, ✓ blade usa vars del controller sin fetch. Pendiente: prueba funcional en browser. -->
