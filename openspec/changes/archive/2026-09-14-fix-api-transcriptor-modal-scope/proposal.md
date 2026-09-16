## Why

El modal "Archivos — <storage>" (`x-show="showFiles"`) del módulo `/ia/api-transcriptor` se renderiza como **hermano** del wrapper `<div x-data="apiTranscriptor(...)">` en vez de como hijo. Alpine.js evalúa sus directivas (`x-text`, `:class`, `@click`, etc.) contra el scope del sidebar (que no expone `showFiles`, `filesMode`, `currentStorage`, `breadcrumb`, etc.), generando 225+ `Alpine Expression Error: X is not defined` y, sobre todo, **un fallo funcional**: el título del modal queda vacío y los botones de modo no muestran el resaltado activo cuando el operador abre el explorador de un storage.

Verificado con Playwright (jsuarez / T3cn0l0g14) tanto con Alpine cargado como bloqueado — el bug es estructural del HTML servido, no de Alpine.

## What Changes

- Corregir el balance de `<div>` en `app/resources/views/ia/api-transcriptor/index.blade.php` para que el modal quede dentro del scope `x-data="apiTranscriptor(...)"`.
- Sin cambios funcionales al componente Alpine, sin migración, sin tocar el controller.
- Sin nuevos endpoints ni cambios en BD.

## Capabilities

### New Capabilities

_Ninguna._

### Modified Capabilities

- `transcriptor-storage-files-srt-link`: añadir requisito de que el modal del explorador de archivos se monte dentro del scope `apiTranscriptor`, de modo que `currentStorage?.name` y los bindings de `filesMode` se evalúen correctamente.

## Impact

- **Código**: `app/resources/views/ia/api-transcriptor/index.blade.php` — eliminado un único `</div>` redundante al final del bloque storages (antes del comentario `<!-- Modal navegador de archivos de un storage -->`).
- **APIs**: ninguna.
- **Migración**: no requerida.
- **Cache de vistas**: `php artisan view:clear` ejecutado para forzar recompilación.
- **Servidor**: ninguno (cambio no toca workers PHP-FPM).
- **Riesgo**: bajo — la fix es eliminar exactamente una línea `</div>` que estaba cerrando el wrapper y el storages tab prematuramente.
- **Regression test**: nuevo `tests/harness_api_transcriptor_modal_scope.php` con 8 aserciones estáticas — **PASS**.

## Caveats

- **Validación con navegador real pendiente (Task 5.4)**: el harness cubre estructura DOM y bindings Alpine, pero la confirmación visual final (consola sin warnings `Alpine Expression Error`, modal abre con título correcto, botones de modo resaltan) debe hacerla el operador con login `jsuarez / T3cn0l0g14`.
- **Sesión Redis pre-existente (NO parte de este change)**: al intentar validar con `curl --resolve cloud.mediaserver.com.co:80:127.0.0.1`, la cookie se establece correctamente al hacer login, pero el siguiente request a `/ia/api-transcriptor` rebota a `/login`. Esto es consistente con el bug documentado en `AGENTS.md` §"Regla crítica: sesiones en Redis" (DB lógica 2, conexión `session`). El login programático + render directo vía `view()->render()` con `session()->put('user_id', $user->id)` sí funciona — usado por el harness.

## Non-goals

- No refactorizamos el componente `apiTranscriptor` ni reorganizamos los tabs.
- No tocamos el banner de "carpetas sin archivos" (que sí funciona porque está dentro del scope correcto).
- No cambiamos el contrato del endpoint `/ia/api-transcriptor` ni de `toggleStorage`.
- No abordamos las 225 warnings históricas (algunas vienen de inner `x-data` scopes legítimos y son ruido aceptable).
