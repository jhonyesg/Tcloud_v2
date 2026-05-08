## Context

El módulo "Mis Archivos" usa Alpine.js con `Alpine.data('fileManager', ...)`. Los plugins están en `public/plugins/<slug>/` y se cargan dinámicamente vía `loadPluginResources(tool)`. El método `launchTool(tool)` ya existe y carga el plugin y lo muestra en `toolModal`. El método `loadAvailableTools(file)` consulta `/file-tools/available?mime=...` para obtener los plugins compatibles.

Actualmente el click en archivos en grid (`@click="file.is_folder && navigateToFolder(...)"`) solo actúa para carpetas. En lista la fila también solo navega carpetas.

## Goals / Non-Goals

**Goals:**
- Click en archivo con visor compatible → abre visor directamente (sin pasar por modal de detalle)
- Fallback: si no hay visor → comportamiento actual (nada en grid, nada en lista)
- No romper el botón de compartir (sigue disponible en hover/acciones)

**Non-Goals:**
- No cambiar el modal de detalle ni las herramientas desde ese modal
- No pre-cargar disponibilidad de plugins al listar archivos (evitar N+1 requests)

## Approach

Agregar método `openFileViewer(file)` en el Alpine component:

```js
async openFileViewer(file) {
    if (file.is_folder) return;
    this.selectedFile = file;
    await this.loadAvailableTools(file);
    if (this.availableTools.length > 0) {
        await this.launchTool(this.availableTools[0]);
    }
    // sin visor: no hacer nada
}
```

Cambiar el `@click` en ambas vistas para llamar `openFileViewer(file)` en lugar del handler actual que ignora archivos.

En grid (línea ~580):
- Antes: `@click="file.is_folder && navigateToFolder(file.id, file.name)"` en el div interior
- Después: mover el `@click` al div contenedor de la tarjeta para cubrir todo el click area: `@click="file.is_folder ? navigateToFolder(file.id, file.name) : openFileViewer(file)"`

En lista (línea ~665):
- Antes: `@click="file.is_folder && navigateToFolder(file.id, file.name)"` en el `<tr>`
- Después: `@click="file.is_folder ? navigateToFolder(file.id, file.name) : openFileViewer(file)"` (y `@click.stop` en el botón de acciones para que no propague)

## Data Flow

```
user clicks file (non-folder)
    → openFileViewer(file)
        → selectedFile = file
        → loadAvailableTools(file)  [fetch /file-tools/available?mime=...]
            → availableTools = [tool1, ...]
        → if availableTools.length > 0
            → launchTool(availableTools[0])
                → toolModal.open = true, loading = true
                → loadPluginResources(tool)
                    → inject <script> and <link>
                    → initializePlugin(tool)
                        → window[slug_init]({ file, container, config })
```
