## Why

Los viewers de imagen, video y audio ya existen como código inline en los Blade templates (`shares/public.blade.php` y `files/preview.blade.php`), pero NO están implementados como plugins en el sistema `FileToolPlugin`. Solo `pdf-viewer-pro` existe como plugin real en `public/plugins/`. Esto significa que no se pueden configurar, activar/desactivar, ni asignar a usuarios desde el módulo de herramientas. El objetivo es extraer estos viewers a plugins estándar que el módulo de herramientas pueda gestionar y el módulo de archivos pueda cargar dinámicamente.

## What Changes

- Crear el plugin `image-viewer-pro` extrayendo la lógica de visualización de imágenes (zoom, rotación, pan) del template `shares/public.blade.php`
- Crear el plugin `video-player-pro` extrayendo el reproductor de video con controles nativos y soporte para múltiples formatos
- Crear el plugin `audio-player-pro` extrayendo el reproductor de audio con controles y visualización de metadatos
- Crear el plugin `text-editor-basic` como editor/visor nuevo para archivos de texto plano y código (no existe actualmente)
- Actualizar el seeder `FileToolPluginSeeder.php` para agregar `audio-player-pro` (actualmente no registrado) y alinear slugs con los directorios reales

## Capabilities

### New Capabilities
- `image-viewer-plugin`: Visor de imágenes con zoom, rotación y soporte para png, jpeg, gif, webp, svg
- `video-player-plugin`: Reproductor de video con controles nativos y soporte para mp4, webm, ogg, mkv
- `audio-player-plugin`: Reproductor de audio con controles y visualización de metadatos para mp3, wav, ogg, flac
- `text-editor-plugin`: Editor/visor de texto con resaltado de sintaxis básico para txt, html, css, js, json

### Modified Capabilities

Ninguna capacidad existente cambia a nivel de requerimientos.

## Impact

- **Archivos nuevos**: 4 directorios en `public/plugins/` (image-viewer-pro, video-player-pro, audio-player-pro, text-editor-basic) cada uno con manifest.json, JS y CSS
- **Seeder**: Actualizar `FileToolPluginSeeder.php` — agregar `audio-player-pro`, corregir slugs para coincidir con directorios reales
- **Archivos de prueba existentes**: `Arhivos_pruebas/` contiene jpeg, webp, mp4, mkv, mp3, pdf — se pueden usar directamente para probar
- **Sin breaking changes**: Se agregan plugins nuevos sin modificar los viewers inline existentes en Blade
