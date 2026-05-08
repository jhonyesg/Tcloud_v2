## 1. Migración de base de datos

- [x] 1.1 Crear migración Laravel `2024_01_01_000011_rename_plugin_slugs_to_basic.php` que actualiza los 4 slugs de `-pro` a `-basic` en `file_tool_plugins` y setea `is_default = true` en todos los registros

## 2. Renombrar directorios y actualizar manifests

- [x] 2.1 Renombrar `public/plugins/image-viewer-pro/` → `image-viewer-basic/` y actualizar `manifest.json` (slug, name)
- [x] 2.2 Renombrar `public/plugins/video-player-pro/` → `video-player-basic/` y actualizar `manifest.json`
- [x] 2.3 Renombrar `public/plugins/audio-player-pro/` → `audio-player-basic/` y actualizar `manifest.json`
- [x] 2.4 Renombrar `public/plugins/pdf-viewer-pro/` → `pdf-viewer-basic/` y actualizar `manifest.json`

## 3. Actualizar JS de cada plugin (rename función + botón descarga)

- [x] 3.1 En `image-viewer-basic/viewer.js`: renombrar función a `image_viewer_basic_init`, registrar como `window.image_viewer_basic_init`, agregar botón de descarga con `href="/files/{file.id}/download"`
- [x] 3.2 En `video-player-basic/player.js`: renombrar función a `video_player_basic_init`, registrar como `window.video_player_basic_init`, agregar enlace de descarga
- [x] 3.3 En `audio-player-basic/player.js`: renombrar función a `audio_player_basic_init`, registrar como `window.audio_player_basic_init`, agregar enlace de descarga
- [x] 3.4 Crear `pdf-viewer-basic/viewer.js` con función `pdf_viewer_basic_init` que usa `<iframe src="/files/{file.id}/preview" style="height:80vh">` en lugar del placeholder, registrar como `window.pdf_viewer_basic_init`, agregar botón de descarga
- [x] 3.5 En `text-editor-basic/editor.js`: agregar botón de descarga con `href="/files/{file.id}/download"` (el nombre de función ya es correcto)

## 4. Actualizar CSS de cada plugin

- [x] 4.1 En `image-viewer-basic/viewer.css`: renombrar selector `.image-viewer-pro` → `.image-viewer-basic`
- [x] 4.2 En `video-player-basic/player.css`: renombrar selector `.video-player-pro` → `.video-player-basic`
- [x] 4.3 En `audio-player-basic/player.css`: renombrar selector `.audio-player-pro` → `.audio-player-basic`
- [x] 4.4 Crear `pdf-viewer-basic/viewer.css` con estilos namespaced `.pdf-viewer-basic` para el iframe y toolbar
- [x] 4.5 En `text-editor-basic/editor.css`: agregar estilo para el botón de descarga si es necesario

## 5. Actualizar el Seeder

- [x] 5.1 En `FileToolPluginSeeder.php`: actualizar los 4 slugs de `-pro` a `-basic`, actualizar paths de recursos JS/CSS, agregar `'is_default' => true` a los 5 plugins

## 6. Fix crítico en el módulo Mis Archivos

- [x] 6.1 En `app/resources/views/files/index.blade.php` línea ~288: cambiar la búsqueda de la función init de `window[tool.slug + '_init']` a `window[tool.slug.replaceAll('-', '_') + '_init']`

## 7. Verificación

- [x] 7.1 Verificar que los 4 directorios `-basic` existen con `manifest.json`, JS y CSS correctos, y que no existen los directorios `-pro`
- [x] 7.2 Verificar que el seeder tiene `is_default: true` en los 5 plugins y slugs correctos
- [x] 7.3 Verificar que `files/index.blade.php` tiene el fix del replaceAll
