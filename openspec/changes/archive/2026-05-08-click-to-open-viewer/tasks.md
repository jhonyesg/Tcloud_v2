## 1. Agregar método openFileViewer al Alpine component

- [x] 1.1 En `app/resources/views/files/index.blade.php`, agregar el método `openFileViewer(file)` dentro del Alpine.data component (después del método `closeToolModal`):
  ```js
  async openFileViewer(file) {
      if (file.is_folder) return;
      this.selectedFile = file;
      this.availableTools = [];
      this.selectedTool = null;
      await this.loadAvailableTools(file);
      if (this.availableTools.length > 0) {
          await this.launchTool(this.availableTools[0]);
      }
  },
  ```

## 2. Actualizar click handler en vista grid

- [x] 2.1 En la tarjeta de la vista grid (línea ~579-580), cambiar el `@click` del div interior `.flex.flex-col.items-center`:
  - Antes: `@click="file.is_folder && navigateToFolder(file.id, file.name)"`
  - Después: `@click="file.is_folder ? navigateToFolder(file.id, file.name) : openFileViewer(file)"`

## 3. Actualizar click handler en vista lista

- [x] 3.1 En la fila de la tabla lista (línea ~665), cambiar el `@click` del `<tr>`:
  - Antes: `@click="file.is_folder && navigateToFolder(file.id, file.name)"`
  - Después: `@click="file.is_folder ? navigateToFolder(file.id, file.name) : openFileViewer(file)"`
- [x] 3.2 Agregar `@click.stop` al botón de acciones (compartir) en la misma fila para evitar que el click propague al `<tr>` (ya existe en línea ~731 como `@click.stop="openDetailModal(file)"`, verificar que esté correcto).

## 4. Verificación

- [x] 4.1 Abrir el módulo "Mis Archivos", navegar a un storage, hacer click en un archivo de video → debe abrir el reproductor sin pasar por el modal de detalle.
- [x] 4.2 Hacer click en una imagen → debe abrir el visor de imágenes.
- [x] 4.3 Hacer click en un archivo `.zip` u otro sin plugin → no debe ocurrir nada.
- [x] 4.4 El botón de compartir (hover en grid, columna acciones en lista) sigue abriendo el modal de detalle.
