## Context

El sistema Tcloud tiene un framework de plugins de herramientas de archivo (`FileToolPlugin`) con:
- Un seeder que registra 4 plugins pero solo `pdf-viewer-pro` tiene archivos JS/CSS reales
- Viewers inline en Blade templates (`shares/public.blade.php` usa Alpine.js + HTML5 nativo para image/video/audio)
- Un servicio `FileToolPluginService` que gestiona plugins activos, asignación por usuario y filtrado por MIME
- Un JS API: `window[slug + '_init'](options)` donde options incluye `{ container, file, config }`

Los viewers Blade actuales usan HTML5 nativo (`<video>`, `<audio>`, `<img>`) con Alpine.js para interactividad. No usan librerías externas.

## Goals / Non-Goals

**Goals:**
- Crear 4 plugins funcionales (image-viewer-pro, video-player-pro, audio-player-pro, text-editor-basic) en `public/plugins/`
- Cada plugin sigue el estándar: manifest.json + JS (init function) + CSS
- Plugins autocontenidos — no dependen de Alpine.js ni del DOM del Blade template
- Usar HTML5 nativo como base (sin dependencias externas como video.js)
- Actualizar el seeder para que los 4 plugins queden registrados correctamente

**Non-Goals:**
- Modificar los viewers inline existentes en Blade templates (siguen funcionando como fallback)
- Agregar librerías externas (pdf.js, video.js, etc.)
- Implementar funcionalidad de edición real en text-editor-basic (solo visualización con syntax highlight básico)
- Sistema de plugins dinámico en runtime (hot-loading)

## Decisions

### 1. HTML5 nativo vs librerías externas
**Decisión**: Usar HTML5 nativo (`<video>`, `<audio>`, `<img>`, `<pre>`)
**Razón**: Los viewers Blade existentes ya usan HTML5 nativo con éxito. Mantener consistencia. Sin dependencias = plugins más ligeros y sin problemas de licencia.
**Alternativa considerada**: video.js, plyr — rechazado por complejidad innecesaria para viewers base.

### 2. Estructura de cada plugin
**Decisión**: Cada plugin sigue la estructura de `pdf-viewer-pro`: IIFE que expone `window[slug]_init()`, manipula un `container` DOM, recibe `file` y `config`.
**Razón**: Patrón ya establecido y documentado en `PLUGINS.md`.

### 3. Text editor como viewer con syntax highlight
**Decisión**: `text-editor-basic` muestra contenido en `<pre><code>` con detección de lenguaje por extensión de archivo y colores básicos via CSS. No es un editor real (no guarda cambios).
**Razón**: El seeder lo define como `type: "editor"` pero el MVP es solo lectura. Se puede evolucionar después.

### 4. Manejo de recursos del archivo
**Decisión**: Los plugins reciben la URL del media vía `file.id` → `/media/{id}/preview` (o `/s/{token}/media/{file_id}/preview` para shares). Construyen la URL internamente.
**Razón**: Patrón ya usado en Blade templates. El `file` object ya contiene `id`.

## Risks / Trade-offs

- **[Riesgo] MIME types no coincidentes** → El seeder usa MIME types como `video/mkv` que no es estándar (debería ser `video/x-matroska`). Mitigación: Usar los MIME types que el sistema ya maneja.
- **[Riesgo] Conflictos CSS con el host** → Los plugins inyectan CSS en el DOM principal. Mitigación: Namespacing de clases CSS con prefijo del plugin.
- **[Trade-off] Sin librería de video avanzada** → No hay soporte nativo para subtítulos, calidad adaptativa, etc. Aceptable para viewers base.
- **[Trade-off] Text editor es solo lectura** → No permite editar/guardar. Aceptable como MVP.
