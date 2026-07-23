## Por qué

El módulo "Mis Archivos" tiene dos regresiones de UX detectadas por usuarios:

**1. El encabezado desaparece al hacer scroll.**
La barra superior con los botones "Actualizar", "Volver a Storages", "Nueva Carpeta", "Subir Archivo" y el breadcrumb viven dentro del área `overflow-auto` del layout, por lo que desaparecen cuando el usuario desplaza hacia abajo en carpetas con muchos archivos. Esto obliga al usuario a volver al inicio para navegar o refrescar.

**2. Los archivos no se actualizan al recargar la página.**
Antes del commit `perf: smart mtime sync, Redis folder cache`, cada carga de página hacía una consulta directa al filesystem. Desde que se introdujo la caché Redis, `restoreNavState()` llama a `loadFiles(false, true)` sin `sync=1`, devolviendo datos cacheados potencialmente obsoletos. El usuario debe hacer clic en "Actualizar" manualmente para ver cambios recientes.

La solución adoptada para el punto 2 es el patrón **"stale while revalidate"**: mostrar los datos cacheados de inmediato (carga rápida) y luego lanzar un sync silencioso en segundo plano que actualice la lista si hay diferencias, sin spinner ni toast.

## Qué Cambia

- **Header sticky**: añadir `sticky top-0 z-10` al `<header>` de `files/index.blade.php` para que permanezca visible al hacer scroll.
- **Auto-refresh silencioso**: después de que `restoreNavState()` cargue los datos cacheados, disparar automáticamente un `silentSync()` que realiza `?sync=1` en segundo plano y actualiza `this.files` si los datos cambiaron, sin interrumpir al usuario.
- **Nuevo método `silentSync()`**: encapsulado, sin AbortController compartido, sin mostrar spinner ni toast de "X carpetas actualizadas". Solo actualiza si hay diferencia real en los datos (comparación por ID, nombre, tamaño y fecha).

## Capacidades

### Nuevas Capacidades

- `silent-background-sync`: Comportamiento de auto-actualización silenciosa al cargar la página — los datos cacheados se muestran de inmediato y un sync en segundo plano corrige cualquier diferencia sin interrumpir al usuario.

### Capacidades Modificadas

- `files-header-layout`: El header del módulo de archivos pasa de posicionamiento estático a `sticky`, haciéndolo siempre visible durante el scroll.

## Impacto

- **Archivo afectado**: `app/resources/views/files/index.blade.php`
  - Línea 2042: clase del `<header>` (sticky)
  - Método `restoreNavState()` (~línea 265): llamada post-carga al nuevo `silentSync()`
  - Nuevo método `silentSync()` en el componente Alpine `fileManager`
- **APIs**: ningún cambio en backend — `GET /files?sync=1` ya existe
- **Redis**: sin cambios — el silent sync consume el mismo endpoint que "Actualizar"
- **Sin migración requerida**
- **Sin breaking changes** — cambios puramente en frontend, backward-compatible
- **Restricción**: `silentSync()` solo se ejecuta cuando `currentPage === 1` (si el usuario ya cargó páginas adicionales vía infinite scroll, no se interfiere)
