## Context

El file manager autenticado (`files/index.blade.php`) muestra botones de escritura ("Subir Archivo", "Nueva Carpeta") y el overlay de drag-and-drop siempre que el usuario esté dentro de un storage (`viewMode === 'files'`), sin importar el nivel de permisos asignado. El backend valida permisos y responde 403, pero el usuario ya hizo clic y ve un error.

La API `/user/storages` ya devuelve el campo `permissions` por cada storage. La vista pública de shares (`shares/public.blade.php`) ya implementa esta lógica correctamente con `@if(in_array($share->permissions, ['write', 'upload', 'full']))`.

Stack: Blade + Alpine.js (sin build step), TailwindCSS via CDN.

## Goals / Non-Goals

**Goals:**
- Ocultar controles de escritura (subir, nueva carpeta, drag-and-drop) cuando el permiso del storage activo es `read`.
- Reutilizar la información de permisos que la API ya devuelve.
- Mantener la validación server-side como respaldo de seguridad.

**Non-Goals:**
- No cambiar la API backend.
- No añadir un sistema de permisos nuevo.
- No modificar la vista pública de shares (ya funciona correctamente).
- No cambiar los permisos de admin (los admins siempre ven todo).

## Decisions

### 1. Variable reactiva `currentStoragePermission` en Alpine.js

**Decisión**: Añadir una propiedad `currentStoragePermission: 'read'` al data del componente `fileManager`, y actualizarla en `enterStorage()` buscando en `availableStorages`.

**Alternativa considerada**: Hacer una llamada AJAX adicional al entrar a un storage para consultar permisos. **Rechazada** porque la API de storages ya devuelve los permisos en la carga inicial — hacer otra llamada sería redundante.

**Por qué**: La data ya está disponible en `availableStorages[i].permissions`. Solo hay que guardarla en una variable accesible desde el template.

### 2. Helper `canWrite()` en Alpine.js

**Decisión**: Añadir un método `canWrite()` que retorne `true` si `currentStoragePermission` es `write`, `upload` o `full`, o si el usuario es admin.

**Alternativa considerada**: Usar `x-show` inline con la comparación directa. **Rechazada** porque se repite en 6+ lugares y es más difícil de mantener.

**Por qué**: Un método centralizado hace la lógica legible y mantenible.

### 3. Condicionar con `x-show` (no con `x-if`)

**Decisión**: Usar `x-show` para ocultar los controles, no `x-if`.

**Por qué**: `x-show` usa `display: none` y es más rápido para alternar visibilidad. Los controles se muestran/ocultan al cambiar de storage, lo cual es un toggle frecuente. `x-if` añadiría/eliminaría DOM, que es más costoso.

### 4. Persistir permiso en `saveNavState()` / `restoreNavState()`

**Decisión**: Guardar `currentStoragePermission` en localStorage junto con el resto del estado de navegación, y restaurarlo al recargar.

**Alternativa considerada**: No persistir y siempre buscar en `availableStorages`. **Viable pero menos robusta** — si el orden de storages cambia, el mapeo por ID sigue siendo correcto, así que persistir es más seguro.

### 5. Condicionar drag-and-drop

**Decisión**: Añadir la condición `canWrite()` al handler de drop y al overlay visual.

**Por qué**: El drag-and-drop es otro vector de intento de subida. Si el usuario arrastra un archivo y lo suelta, el backend lo rechazaría con 403. Mejor no mostrar el overlay y no procesar el drop.

## Risks / Trade-offs

- **[Riesgo] Permisos desactualizados en localStorage** → Mitigación: Al restaurar nav state, buscar el permiso actualizado desde `availableStorages` por ID, no usar el valor cacheado directamente.
- **[Riesgo] Admin bypass** → Mitigación: `canWrite()` debe retornar `true` para admins independientemente del permiso del storage. Verificar que `User::isAdmin()` se refleje en el frontend (actualmente no hay flag de admin en Alpine.js — se puede omitir el check de admin en el frontend ya que los admins tienen permisos `full` en sus storages asignados).
- **[Trade-off] No hay feedback al arrastrar archivos en read-only** → El usuario no ve overlay ni mensaje. Esto es aceptable — el comportamiento esperado es "no pasa nada", similar a arrastrar sobre cualquier elemento no-drop.
