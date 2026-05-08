## Context

El sistema de plugins permite que el módulo "Mis Archivos" cargue visores/reproductores dinámicamente según el tipo MIME del archivo seleccionado. Cada plugin es un directorio en `public/plugins/<slug>/` con `manifest.json`, un archivo JS y uno CSS. El JS registra una función `window.<slug_con_guiones_bajos>_init(options)` que recibe `{ file, container, config }`.

El módulo `files/index.blade.php` carga los plugins disponibles vía `/file-tools/available?mime=...`, luego carga sus recursos JS/CSS dinámicamente y llama `window[tool.slug + '_init']`. El bug es que los slugs usan guiones (`image-viewer-pro`) pero los nombres de función JS usan guiones bajos (`image_viewer_pro_init`), haciendo que nunca coincidan.

Estado actual de los plugins:
- `image-viewer-pro`: funcional en lógica, solo falla por el mismatch
- `video-player-pro`: funcional, falla por mismatch, usa `/media/{id}/preview`
- `audio-player-pro`: funcional, falla por mismatch, usa `/media/{id}/preview`
- `pdf-viewer-pro`: placeholder — muestra texto estático, no renderiza PDF
- `text-editor-basic`: funcional, nombre ya correcto

## Goals / Non-Goals

**Goals:**
- Corregir el mismatch de nombres para que todos los plugins se inicialicen
- Renombrar plugins de `-pro` a `-basic` (reflejar que es funcionalidad estándar)
- Agregar `is_default = true` en el seeder para acceso automático a usuarios nuevos
- Crear `pdf-viewer-basic` real con `<iframe>` nativo del browser
- Agregar botón de descarga a todos los plugins apuntando a `/files/{id}/download`
- Limpiar datos obsoletos en la base de datos (slugs viejos)

**Non-Goals:**
- Implementar funcionalidades "pro" avanzadas (eso es trabajo futuro)
- Cambiar la arquitectura de carga dinámica de plugins
- Soporte para formatos adicionales de archivo
- Edición de archivos (solo visualización/reproducción)

## Decisions

**D1 — Fix del mismatch: convertir en el frontend, no en los plugins**

Opción A: Cambiar `files/index.blade.php` para convertir el slug antes de buscar la función:
```js
const fnName = tool.slug.replaceAll('-', '_') + '_init';
window[fnName](...)
```
Opción B: Cambiar cada plugin para registrar también la versión con guiones.

**Decisión: Opción A.** Un solo cambio en un lugar vs. cambiar 5 archivos. Además, la convención JS de no usar guiones en nombres de variable/función es correcta — el frontend debe adaptar el slug al naming JS.

**D2 — PDF viewer: `<iframe>` nativo**

Opción A: `<iframe src="/files/{id}/preview">` — usa el visor PDF del browser del usuario.
Opción B: Integrar PDF.js — renderizado propio, más control.

**Decisión: Opción A.** Todos los browsers modernos tienen visor PDF nativo. PDF.js agrega 300KB+ de dependencia innecesaria para funcionalidad básica. La ruta `/files/{id}/preview` ya existe y devuelve el PDF con headers correctos.

**D3 — Migración de slugs en base de datos**

El seeder usa `insertOrIgnore`, por lo que si los registros viejos (`image-viewer-pro`) ya existen, los nuevos (`image-viewer-basic`) no colisionan pero los viejos quedan huérfanos. La limpieza correcta es:

1. Truncar `file_tool_plugins` y `user_file_tool_plugins` antes de re-seedear, O
2. Crear una migración que haga UPDATE de los slugs viejos.

**Decisión: Migración SQL** que actualiza los slugs existentes + agrega `is_default = true`. Más seguro que truncar si ya hay asignaciones de usuario.

**D4 — Botón de descarga en plugins**

Todos los plugins agregan un botón/enlace con `href="/files/{file.id}/download"` en su toolbar o footer. La ruta ya existe en el sistema.

## Risks / Trade-offs

- **Usuarios con plugins asignados manualmente** → Al cambiar slugs, las asignaciones en `user_file_tool_plugins` apuntarán a IDs que cambiarán de nombre. La migración actualiza slugs sin cambiar IDs, por lo que las relaciones se mantienen. ✓
- **Browser sin soporte de PDF nativo** → Muy raro en 2025, pero si ocurre el `<iframe>` mostrará un botón de descarga del browser. Aceptable para funcionalidad básica.
- **Archivos multimedia en storages remotos** → La ruta `/media/{id}/preview` ya maneja streaming desde storages. No hay riesgo adicional.

## Migration Plan

1. Crear migración Laravel que actualice slugs en `file_tool_plugins`: `image-viewer-pro → image-viewer-basic`, etc., y setea `is_default = true` en todos.
2. Renombrar directorios en `public/plugins/`.
3. Actualizar manifests, JS y CSS dentro de cada directorio renombrado.
4. Actualizar seeder para que refleje el estado final (para fresh installs).
5. Fix en `files/index.blade.php` (una línea).

**Rollback**: revertir la migración con `migrate:rollback`, restaurar directorios viejos desde git.

## Open Questions

- ¿El `<iframe>` para PDF debe tener altura fija (ej. 600px) o adaptarse al viewport del modal? → Se usará `height: 80vh` para aprovechar el espacio del modal.
