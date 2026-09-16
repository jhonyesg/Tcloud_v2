## Why

El operador reportó dudas sobre si los recortes generados desde el módulo **Mis Avisos** consumen el límite mensual del editor de medios (`media_editor_clip_limit`). Hoy la única forma de verificarlo es a través de la UI (login manual, abrir una mención, confirmar un recorte, luego revisar `/admin/media-editor`) — un proceso manual, no repetible y que no detecta regresiones.

El análisis del código muestra que **sí se cuentan** (un único endpoint `MediaClipController::clip()` con el check incondicional en `User::hasReachedClipLimit()`), pero esa conclusión es solo estática. Hace falta una verificación **automatizada y reproducible** que:

1. Confirme el conteo hoy (línea base).
2. Permita re-ejecutar la prueba tras cualquier cambio futuro en el flujo de clip o en la capability `can_clip`.
3. Documente el contrato operacional: "cualquier clip — venga de Mis Archivos, de Mis Avisos, del deep-link — consume el mismo cupo mensual, salvo previews, fallos o admin".

## What Changes

- Se agrega un nuevo harness de regresión PHP **ejecutable directamente contra PostgreSQL/Redis reales** (mismo patrón que `tests/harness_mis_avisos_viewer.php` y `tests/harness_storage_sync_is_file_linked.php`).
- El harness simula el flujo completo Mis Avisos → deep-link → `MediaClipController::clip()` con un usuario de prueba y un archivo real en storage local.
- Verifica tres aserciones:
  - **(a)** Un clip confirmado desde Mis Avisos **sí** crea un `MediaEditJob` con `status='done'` que se cuenta en `mediaEditorClipsThisMonth()`.
  - **(b)** Al alcanzar `media_editor_clip_limit`, el endpoint responde **HTTP 403** con el mensaje "Límite mensual alcanzado" — incluso cuando el clip fue originado desde Mis Avisos.
  - **(c)** Las previews (`preview=true`), los recortes con `status!='done'` y los recortes del admin **no** consumen cupo.
- No se modifica ningún comportamiento de producción. Es **puro tooling**.

### Reporting durante implementación (acordado con el operador)

- Mientras se ejecutan las tareas de `tasks.md`, Kilo debe **reportar el avance al usuario en cada paso**, con un mini-print del estado antes/después de cada comando. Esto alinea con el constraint del proyecto (`reportar_resultados_antes_de_comandos` en `AGENTS.md` / project memory).
- Formato esperado de cada print de avance:
  ```
  [Tarea X.Y] <descripción>
    → antes:  <estado previo resumido>
    → comando: <comando ejecutado>
    → después: <resultado resumido + exit code>
  ```
- Antes de avanzar al siguiente grupo de tareas, Kilo debe esperar confirmación del operador de que el print anterior reflejó lo esperado (constraint explícito del proyecto).

## Capabilities

### New Capabilities
- *(ninguna — el comportamiento del editor no cambia; este change es tooling puro)*

### Modified Capabilities
- *(ninguna)*

> Este change es **puro tooling** (harness de regresión). El comportamiento del sistema bajo prueba no se altera, por lo que se marca con `skip_specs: true` en `.openspec.yaml`.

## Impact

- **Archivos nuevos**:
  - `app/tests/harness_mis_avisos_clip_limit.php` — el harness ejecutable.
- **Archivos modificados**:
  - `openspec/changes/verify-mis-avisos-clip-counts-toward-editor-limit/.openspec.yaml` — agregar `skip_specs: true`.
  - `AGENTS.md` (sección "Harnesses de regresión") — documentar el nuevo harness con un mini-runbook.
- **Sin cambios** en: controllers, modelos, migraciones, vistas, rutas.
- **Sin migración** de BD.
- **Sin deploy** requerido (es código de `tests/` que no se carga en runtime de producción).

## Non-goals

- **No** cambia el comportamiento del límite mensual. Si hoy cuenta, seguirá contando.
- **No** cambia el mensaje del 403, el flujo del deep-link, ni la capability `can_clip`.
- **No** agrega UI nueva (ej: "te quedan X recortes este mes" en Mis Avisos). Eso sería un change separado si se quisiera.
- **No** convierte el harness en test PHPUnit formal. Es un script ejecutable contra BD real, mismo patrón que los demás `harness_*.php` ya existentes.
