## ADDED Requirements

### Requirement: Plugins básicos disponibles por defecto
El sistema SHALL tener 5 plugins con `is_default = true` en la base de datos: `image-viewer-basic`, `video-player-basic`, `audio-player-basic`, `pdf-viewer-basic`, `text-editor-basic`. Todo usuario autenticado SHALL ver estas herramientas disponibles al abrir un archivo compatible sin necesitar asignación manual.

#### Scenario: Usuario nuevo ve herramientas sin asignación
- **WHEN** un usuario sin plugins asignados abre el detalle de un archivo de imagen
- **THEN** el sistema muestra al menos un plugin disponible para ese tipo MIME

#### Scenario: Query por MIME retorna plugin correcto
- **WHEN** se consulta `/file-tools/available?mime=video/mp4`
- **THEN** la respuesta incluye `video-player-basic` en `data`

### Requirement: Inicialización de plugins sin error de nombre
El sistema SHALL resolver la función de inicialización de un plugin convirtiendo los guiones del slug a guiones bajos antes de buscarla en `window`. El patrón es: `window[slug.replaceAll('-', '_') + '_init']`.

#### Scenario: Plugin de imagen se inicializa correctamente
- **WHEN** el usuario hace click en "Abrir con Visor de Imágenes" para un archivo PNG
- **THEN** el modal muestra la imagen renderizada (no el mensaje "Plugin no implementado correctamente")

#### Scenario: Plugin de video se inicializa correctamente
- **WHEN** el usuario hace click en un plugin de video para un archivo MP4
- **THEN** el modal muestra el reproductor `<video>` nativo con controles

### Requirement: Visor PDF renderiza el archivo real
El sistema SHALL usar un `<iframe>` apuntando a `/files/{id}/preview` para renderizar PDFs. El visor SHALL ocupar al menos el 80% de la altura del modal.

#### Scenario: PDF se renderiza en el modal
- **WHEN** el usuario abre un archivo PDF con el plugin pdf-viewer-basic
- **THEN** el modal muestra el contenido real del PDF (no texto placeholder)

#### Scenario: URL del iframe apunta a la ruta correcta
- **WHEN** se inicializa pdf-viewer-basic con un archivo de id=42
- **THEN** el `<iframe>` tiene `src="/files/42/preview"`

### Requirement: Todos los plugins incluyen botón de descarga
Cada plugin (imagen, video, audio, PDF, texto) SHALL incluir un botón o enlace con `href="/files/{id}/download"` visible en su interfaz.

#### Scenario: Botón de descarga presente en visor de imagen
- **WHEN** se abre un archivo de imagen con image-viewer-basic
- **THEN** existe un elemento con href que contiene `/download` en el contenedor del plugin

#### Scenario: Botón de descarga presente en reproductor de video
- **WHEN** se abre un archivo de video con video-player-basic
- **THEN** existe un elemento con href que contiene `/download` en el contenedor del plugin

### Requirement: Slugs de plugins usan sufijo -basic
Los directorios en `public/plugins/` y los slugs en la base de datos SHALL usar el sufijo `-basic` para los plugins estándar: `image-viewer-basic`, `video-player-basic`, `audio-player-basic`, `pdf-viewer-basic`.

#### Scenario: Directorio image-viewer-basic existe
- **WHEN** se verifica el sistema de archivos en `public/plugins/`
- **THEN** existe `image-viewer-basic/` con `manifest.json`, `viewer.js`, `viewer.css`

#### Scenario: No existen directorios -pro
- **WHEN** se lista `public/plugins/`
- **THEN** no existe ningún directorio con sufijo `-pro`
