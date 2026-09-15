## ADDED Requirements

### Requirement: El modal "Ver archivos" se monta dentro del scope `apiTranscriptor`
El sistema SHALL renderizar el contenedor del modal "Ver archivos" (elemento con `x-show="showFiles"`) como **hijo directo** del `<div x-data="apiTranscriptor(...)">` en `app/resources/views/ia/api-transcriptor/index.blade.php`. Esto garantiza que las directivas Alpine del modal (`x-text`, `:class`, `x-show`, `@click`) resuelvan `showFiles`, `filesMode`, `currentStorage`, `filesSearch`, `breadcrumb`, `filesLoading`, `syncing`, `colFilters` y `filesSort` contra el scope del componente, no contra el scope del sidebar.

#### Scenario: El modal abre con el título del storage correcto
- **WHEN** el operador abre el explorador de un storage haciendo click en el botón correspondiente (banner, tarjeta de storage, o `data.openFiles({...})`)
- **THEN** el `<h2>` del modal renderiza "Archivos — <nombre del storage>" (no vacío) porque `currentStorage.name` resuelve desde el scope `apiTranscriptor`

#### Scenario: Botones de modo muestran el resaltado activo
- **WHEN** el modal está abierto en modo `browse` (estado inicial tras `openFiles()`)
- **THEN** el botón "Explorar" aplica las clases de resaltado activo (`:class="filesMode === 'browse' ? ..."`) y los botones "Hoy" / "Ayer" no

#### Scenario: Consola del navegador libre de errores del modal
- **WHEN** el operador navega a `/ia/api-transcriptor` con un navegador moderno (Chrome ≥ 120) logueado como jsuarez
- **THEN** la consola del navegador NO emite `Alpine Expression Error: showFiles/currentStorage/filesMode/breadcrumb/filesSearch/filesLoading/syncing/colFilters/filesSort is not defined` originados desde elementos del modal "Ver archivos"

#### Scenario: HTML servido contiene el modal dentro del wrapper de Alpine
- **WHEN** un test automatizado pide `GET /ia/api-transcriptor` autenticado
- **THEN** el HTML resultante contiene `<div x-show="showFiles"` en algún punto posterior a `<div x-data="apiTranscriptor(...)"` y anterior al cierre `</div>` de ese wrapper (es decir, anidado, no como hermano)
