## 1. Plugin image-viewer-pro

- [x] 1.1 Crear directorio `public/plugins/image-viewer-pro/` con `manifest.json` (slug: image-viewer-pro, type: viewer, supported_mimes: image/png, image/jpeg, image/gif, image/webp, image/svg+xml)
- [x] 1.2 Implementar `viewer.js` — función `image_viewer_pro_init(options)` con visualización de imagen via `<img>`, zoom in/out/reset, rotación 90°, mouse wheel zoom, toolbar con nombre y tamaño de archivo
- [x] 1.3 Implementar `viewer.css` — estilos namespaced con `.image-viewer-pro`, toolbar, botones de control, contenedor de imagen responsive

## 2. Plugin video-player-pro

- [x] 2.1 Crear directorio `public/plugins/video-player-pro/` con `manifest.json` (slug: video-player-pro, type: player, supported_mimes: video/mp4, video/webm, video/ogg)
- [x] 2.2 Implementar `player.js` — función `video_player_pro_init(options)` con `<video>` nativo con controles, soporte de autoplay desde config, nombre de archivo debajo del reproductor
- [x] 2.3 Implementar `player.css` — estilos namespaced con `.video-player-pro`, video responsive (max-width 100%, max-height 70vh), contenedor centrado

## 3. Plugin audio-player-pro

- [x] 3.1 Crear directorio `public/plugins/audio-player-pro/` con `manifest.json` (slug: audio-player-pro, type: player, supported_mimes: audio/mpeg, audio/wav, audio/ogg, audio/flac, audio/mp4)
- [x] 3.2 Implementar `player.js` — función `audio_player_pro_init(options)` con `<audio>` nativo con controles, icono SVG de audio, nombre y tamaño de archivo en header
- [x] 3.3 Implementar `player.css` — estilos namespaced con `.audio-player-pro`, layout centrado con padding y fondo claro

## 4. Plugin text-editor-basic

- [x] 4.1 Crear directorio `public/plugins/text-editor-basic/` con `manifest.json` (slug: text-editor-basic, type: editor, supported_mimes: text/plain, text/html, text/css, text/javascript, application/json)
- [x] 4.2 Implementar `editor.js` — función `text_editor_basic_init(options)` que hace fetch a `/media/{file.id}/preview`, muestra contenido en `<pre><code>`, aplica syntax highlighting básico por tipo MIME, soporte para line numbers desde config, manejo de errores
- [x] 4.3 Implementar `editor.css` — estilos namespaced con `.text-editor-basic`, toolbar, área de código con scroll, colores de syntax highlight, números de línea

## 5. Actualizar seeder

- [x] 5.1 Actualizar `FileToolPluginSeeder.php` — agregar entrada para `audio-player-pro` y corregir slugs de los plugins existentes para coincidir con los directorios reales

## 6. Verificación

- [x] 6.1 Verificar que los 4 directorios de plugins existen con manifest.json, JS y CSS completos
- [x] 6.2 Verificar que el seeder inserta correctamente los 4 plugins y el de audio
