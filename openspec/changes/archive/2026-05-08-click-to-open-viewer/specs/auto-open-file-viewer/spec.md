# Spec: auto-open-file-viewer

## Behavior

- Cuando el usuario hace click en un archivo (no carpeta) en la vista grid o lista de "Mis Archivos", el sistema debe:
  1. Consultar los plugins disponibles para el tipo MIME del archivo.
  2. Si existe al menos un plugin compatible: abrir el visor/reproductor directamente (modal de herramienta).
  3. Si no existe plugin compatible: no hacer nada (click sin efecto).
- Las carpetas mantienen su comportamiento actual (navegar dentro de la carpeta).
- El botón de compartir y las acciones secundarias no deben verse afectados.
- Si hay múltiples plugins compatibles, se abre el primero de la lista (el default).

## Acceptance Criteria

- [ ] Click en archivo de video abre el reproductor de video básico directamente.
- [ ] Click en imagen abre el visor de imágenes directamente.
- [ ] Click en PDF abre el visor PDF directamente.
- [ ] Click en archivo de texto abre el editor de texto directamente.
- [ ] Click en archivo sin plugin compatible (ej. `.zip`) no hace nada.
- [ ] Click en carpeta navega dentro de ella (sin cambios).
- [ ] El botón de compartir sigue funcionando en hover/acciones.
