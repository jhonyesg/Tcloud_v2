## Decisiones de Diseño

### 1. Header sticky dentro del contenedor overflow-auto

El layout principal es:
```
<body>
  <div class="h-screen flex flex-col overflow-hidden">   ← contenedor raíz
    <header>navbar superior</header>                      ← flex-shrink-0
    <div class="flex flex-1 overflow-hidden">
      <aside>sidebar</aside>
      <main class="flex-1 overflow-auto bg-slate-50">    ← SCROLL AQUÍ
        @yield('content')                                 ← files/index.blade.php
          <header class="bg-white ...">                  ← ESTE header
          <main>grid de archivos</main>
      </main>
    </div>
  </div>
```

`sticky top-0` en el `<header>` del módulo de archivos funciona porque el ancestro scrollable es `<main class="overflow-auto">` — CSS sticky posiciona el elemento relativo al contenedor de scroll más cercano. No se requiere `position: fixed` ni cambios en el layout.

Se añade `z-10` para que quede por encima del contenido de la grilla durante el scroll. El shadow existente (`shadow-sm`) ya provee separación visual.

### 2. Patrón "Stale While Revalidate"

```
Tiempo →

t=0   restoreNavState()
       │
       ├── loadFiles(false, true)   fetch rápido (caché Redis)
       │         │
       │         └── [respuesta ~50-200ms]
       │               this.files = [datos cacheados]
       │               isLoadingFiles = false
       │               └── usuario ve contenido
       │
       └── silentSync()   lanzado inmediatamente sin await
                 │
                 └── fetch /files?sync=1   [respuesta ~500-2000ms]
                       ├── si datos === actuales: no-op
                       └── si datos !== actuales:
                             this.files = nuevosDatos
                             (sin spinner, sin toast, sin scroll reset)
```

**Por qué sin await en `silentSync()`:** el fast load ya resolvió. El silent sync debe correr en paralelo, no bloquear la UI.

**Por qué no usar el mismo `_fetchController`:** `loadFiles()` ya usa ese AbortController y puede cancelar fetches en curso. El silent sync es independiente y no debe ser cancelado por navegaciones del usuario.

### 3. Comparación de datos para detectar cambios

```javascript
// Fingerprint de los datos actuales
const fingerprint = (files) =>
    files.map(f => f.id + ':' + f.name + ':' + (f.size ?? 0) + ':' + (f.updated_at ?? '')).join('|');

if (fingerprint(newFiles) !== fingerprint(this.files)) {
    this.files = newFiles;
    this.currentPage = newPagination.page;
    this.hasMore = newPagination.has_more;
}
```

Detecta: archivos nuevos, borrados, renombrados, modificados (cambio de tamaño o fecha). Costo: O(n), ejecutado localmente en el browser después de que llegó la respuesta.

### 4. Restricción: solo cuando currentPage === 1

Si el usuario ha hecho scroll y cargado páginas adicionales (`currentPage > 1`), el silent sync no sobreescribe `this.files`. Razón: reemplazar el array causaría un salto visual y perdería el historial de páginas cargadas. En ese caso el usuario tiene el botón "Actualizar" disponible (y visible, gracias al header sticky).

### 5. Sin indicador visual de "actualizado"

La actualización es totalmente silenciosa. Mostrar un badge o texto "Actualizado" añade complejidad y puede ser molesto si ocurre en cada recarga. Alpine reactivity actualizará la grilla suavemente. Si en el futuro se quiere un indicador, se puede añadir como una mejora separada.
