## Context

`Mis Archivos` (`app/resources/views/files/index.blade.php`) usa Alpine.js con un store `fileManager`. Hay tres puntos que disparan requests a `/files`: `loadFiles()`, `loadMore()` y `silentSync()`. Solo `loadFiles` y `loadMore` llevan `AbortController` para cancelar la request cuando el usuario navega a otra carpeta. `silentSync()` se dispara al final de cada `loadFiles` exitoso (`if (thenSync) this.silentSync()`) y por defecto no se puede abortar; cuando llega la response, hace `this.files = newFiles` si el fingerprint difiere — **sin** validar que `(currentFolder, currentStorage)` no hayan cambiado desde que se disparó la request.

El escenario que rompe la UI es: usuario navega X→Y→X→Z→X (o similar) en milisegundos. Las responses de `silentSync(Y)` y `silentSync(Z)` pueden llegar después de la última carga de X y pisar `this.files`, mostrando contenido de otra carpeta o dejándola vacía si la response tardía tenía `[]`. Mis Archivos ya valida el contexto capturado con `_prevFolder`/`_prevBreadcrumbs` cuando la response falla (4xx), pero NO cuando llega OK pero obsoleta.

## Goals / Non-Goals

**Goals:**
- Garantizar que el contenido de `this.files` solo se actualice con responses que correspondan al `(currentFolder, currentStorage)` actual en el momento de llegada.
- Cubrir `silentSync`, `loadFiles` y `loadMore` con la misma defensa.
- Sin migraciones, sin cambios de backend, sin tocar el `AbortController` existente de `loadFiles`/`loadMore`.

**Non-Goals:**
- No agregar `AbortController` nuevo a `silentSync` (la guarda de generación es suficiente y más simple).
- No propagar el contador al backend.
- No cambiar el contrato HTTP.

## Decisions

### Decisión 1: Contador `_navGen` interno de Alpine en lugar de AbortController

Elegimos un contador monotonamente creciente `_navGen` (entero, inicia en 0) como estado del componente. Cada vez que arranca una operación que pueda devolver datos que afecten `this.files`, captura `myGen = ++this._navGen`. Cuando vuelve la response, valida:

```js
if (this._navGen !== myGen) return; // obsoleto: el usuario ya navegó
if (this.currentFolder !== capturedFolder) return; // belt-and-suspenders
if (this.currentStorage !== capturedStorage) return;
```

**Por qué no solo AbortController en `silentSync`**: agregar un controller nuevo es factible pero la asimetría entre `loadFiles` (con abort) y `silentSync` (sin abort) sigue siendo un foco de bugs futuros. La generación cubre el problema general ("cualquier response tardía") y mantiene `silentSync` simple.

**Por qué no confiar solo en comparar `currentFolder` en el momento de llegada**: race dentro del mismo folder (p.ej. usuario hace click en el mismo folder dos veces rápido, o navega a X, vuelve a X, luego a X de nuevo). El contador de generación distingue "esta response vino de la 3ª navegación" vs "esta response vino de la 1ª", que es lo que necesitamos.

**Por qué interno a Alpine sin tocar `localStorage`**: cada pestaña tiene su propia máquina de estados; sincronizar vía `localStorage` introduce condiciones de carrera entre pestañas. El problema es intra-pestaña, la solución también.

### Decisión 2: Puntos donde se incrementa `_navGen`

`_navGen` se incrementa en el cuerpo de cada función de navegación, **antes** de que `this.currentFolder` (o `this.currentStorage`) cambie:

- `navigateToFolder(folderId, name)` — incrementa al inicio.
- `navigateToBreadcrumb(breadcrumb, index)` — incrementa al inicio.
- `goToStorageRoot()` — incrementa al inicio.
- `enterStorage(storageId, name)` — incrementa al inicio (porque cambia storage y resetea folder).
- `navigateToRoot()` — incrementa al inicio (porque limpia storage y folder).
- `restoreNavState()` — incrementa al inicio si restaura una carpeta no raíz (no incrementa si la pestaña arranca en vista de storages).
- `refreshFiles()` — incrementa antes de delegar a `loadFiles(true)`.

NO se incrementa en `setFilesViewMode()` ni en operaciones de upload/delete — esas afectan `this.files` directamente vía mutaciones locales (push/splice), no por responses de `/files`.

### Decisión 3: Captura al inicio vs después de validar prerrequisitos

Las tres funciones (`loadFiles`, `loadMore`, `silentSync`) tienen un early-return por prerrequisitos faltantes (sin storage, en página >1, etc.). El incremento del `_navGen` y la captura de `myGen`/`capturedFolder`/`capturedStorage` se hacen **después** del early-return y **antes** de `apiFetch()`. Si la request no se dispara, no hay response que pueda llegar tarde, no necesitamos reservar una generación.

### Decisión 4: Validación silenciosa en `.then()`

La response tardía se descarta con `return null` (después de restaurar flags como `isLoadingFiles = false` solo si corresponde a esta generación). NO se muestra toast, NO se registra en consola, NO se aborta explícitamente — el navegador ya cerró esa request o el dato simplemente se ignora.

Para `loadFiles`: si la response es obsoleta, los flags `isLoadingFiles`, `isNavigating`, etc. deben quedar en el estado que dejó la response válida siguiente, no en un estado intermedio. Por eso los flags se setean ANTES de validar obsolescencia.

Para `silentSync`: como no toca flags visuales (solo `this.files`), simplemente no hace nada.

Para `loadMore`: como solo concatena a `this.files`, simplemente no concatena. Si la response llega obsoleta, el usuario ya está en otra carpeta y el contenido adicional no aplica.

## Risks / Trade-offs

- **[Riesgo] Si una navegación falla (4xx en `loadFiles`) y deja `isLoadingFiles` en `true` mientras otra request válida se está cargando, el orden de los `.then()` puede hacer que la response obsoleta revierta los flags correctos** → Mitigación: en `loadFiles`, setear `isLoadingFiles = false` y `isNavigating = false` ANTES de validar `myGen === _navGen`, así la response obsoleta no contamine los flags de la siguiente navegación.

- **[Riesgo] `navigateToFolder` y `navigateToBreadcrumb` tienen `if (this.isNavigating) return;` al inicio — si la guarda de generación no se incrementa en esos early-returns, una request válida podría sobrevivir si llegó justo antes del guard y no se incrementa** → Mitigación: el `isNavigating` corto-circuita toda navegación concurrente, así que la situación "request válida sobrevive" no se da en la práctica; pero aun así documentamos que `_navGen` solo se incrementa cuando sí se ejecuta la navegación.

- **[Riesgo] Pestañas de Mis Archivos abiertas en paralelo** → Mitigación: cada Alpine store es por-pestaña. Confirmamos con `Requirement: "Estado de generación es interno de la pestaña Alpine"`.

- **[Trade-off] Defense-in-depth con dos checks (`myGen !== _navGen` y `capturedFolder !== currentFolder`)** → aceptable: ambos son O(1) comparaciones de enteros y strings. Si en el futuro queremos simplificar, `_navGen` solo basta; el segundo check es redundante pero barato.

## Migration Plan

Sin migración de BD. Sin cambio de rutas. Sin cambio de contratos.

**Deploy**: merge y release normal. Las guardas son aditivas: si por bug de implementación `_navGen` no se incrementara, el comportamiento cae al actual (no rompe nada).

**Rollback**: revert del merge. Sin estado persistente que limpiar.

**Verificación post-deploy**:
1. Manual: navegar X→Y→X→Z→X rápido en un storage con varias carpetas. La carpeta X final debe mostrar sus archivos, no los de Y/Z ni vacío.
2. Console del navegador: confirmar que las responses obsoletas no se loggean como error (silencio intencional).
3. Network tab: confirmar que las requests obsoletas llegan pero el frontend las ignora.

## Open Questions

- **(Resuelto) ¿La guarda debe aplicarse también al flujo de búsqueda `searchFiles`?** Decidido que sí por consistencia, pero búsqueda es un path separado (`searchQuery` + `/files?q=...`). El change actual NO toca búsqueda para mantener blast radius pequeño; si tras release se reproduce el bug similar en search, abrir change aparte.

- **(Resuelto) ¿Vale la pena distinguir "navegación obsoleta" de "carpeta borrada en backend" y dar feedback al usuario?** Decidido que no — la próxima navegación correcta ya da el feedback (carpeta vacía real). Un toast en cada descarte ensuciaría la UX sin valor real.
