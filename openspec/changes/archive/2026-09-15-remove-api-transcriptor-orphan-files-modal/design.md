## Context

Ver `proposal.md` para motivación. Estado actual: `app/resources/views/ia/api-transcriptor/index.blade.php` contiene un botón "Ver archivos" en la columna Acciones que llama `openFiles(s)` — esto dispara una cascada de llamadas a endpoints HTTP no registrados (`/storages/{id}/files`, `/storages/{id}/process-folder`, `/storages/{id}/process-day`) que devuelven 404 silencioso. El modal se abre y muestra "Esta carpeta está vacía · 0 carpetas, 0 archivos". La consola queda limpia (no hay error visible) pero la UX está rota.

El refactor archivado `2026-09-15-simplify-api-transcriptor-to-storage-and-config` retiró los endpoints y el botón del header "Escanear storages", pero dejó intactos el botón de Acciones y todo el HTML/JS del modal. Esa spec archivada documenta explícitamente la eliminación de "Navegación de archivos por storage (`storageFiles`, `process-folder`, `process-day`)".

La spec activa `openspec/specs/transcriptor-storage-files-srt-link/spec.md` describe el comportamiento del modal huérfano. Queda obsoleta al eliminar el código que la implementaba.

## Goals / Non-Goals

**Goals:**
- Que la columna Acciones de la tabla Storages no muestre el botón "Ver archivos" roto.
- Que el HTML/JS de `index.blade.php` quede libre del modal de archivos y todos sus handlers asociados.
- Que la spec `transcriptor-storage-files-srt-link` quede archivada como obsoleta (no se modifica; se mueve a `_archive/`).
- Sin tocar backend, routes, ni modelos.

**Non-Goals:**
- Reintroducir funcionalidad de drill-down de archivos (sería un change nuevo con su propio OpenSpec).
- Reorganizar la columna Acciones para añadir otro botón (puede venir después).
- Mover la spec a otra ubicación distinta a `_archive/`.

## Decisions

### D1. Eliminar el botón completo (no solo deshabilitar)

**Por qué**: El backend ya no expone los endpoints que el modal asumía. Mantener el botón visible (deshabilitado o no) perpetúa una expectativa rota. Es preferible eliminarlo y dejar la columna Acciones vacía para esa fila — la columna `Transcripción` justo a la izquierda sigue siendo el control interactivo relevante.

**Alternativa considerada**: Cambiar el botón a "Ver fallidos" re-apuntando a un endpoint real (snapshot error_count). Descartada porque el operador eligió "Opción A: limpiar" en la sesión de explore; el drill-down de fallidos sería un change nuevo.

### D2. Archivar la spec `transcriptor-storage-files-srt-link`, no eliminarla

**Por qué**: El historial de git ya registra el cambio de comportamiento. Pero la spec sigue siendo referenciada por otros cambios archivados y por la tabla de specs como evidencia histórica de qué decidió el equipo. Moviéndola a `_archive/transcriptor-storage-files-srt-link.md` preserva el rastro de auditoría sin que aparezca como spec "activa" en `openspec list`.

**Alternativa considerada**: Borrarla directamente. Descartada porque perdería el rastro histórico de la decisión original y rompería enlaces en otros artifacts.

### D3. Sin nueva spec — `skip_specs: true`

**Por qué**: El change no introduce comportamiento nuevo, solo elimina UI. Ningún requirement observable cambia para los módulos downstream (Correcciones, Avisos): siguen leyendo `Transcription` y `TranscriptionSegment` directamente desde los modelos como antes. El flag `skip_specs: true` en `.openspec.yaml` documenta esto explícitamente y evita forzar un delta vacío.

## Risks / Trade-offs

- **[Operador no avisa del cambio]**: si alguien tenía el flujo "click Ver archivos" memorizado, desaparece sin aviso. Mitigación: el cambio queda documentado en `openspec/changes/` (visible en `openspec list`); cualquier futura queja cita el change.
- **[Regresión visual menor]**: al eliminar el botón, la columna Acciones de la fila queda vacía, lo que cambia el ancho/espaciado de la tabla. Mitigación: el `align-top` y el espaciado de las celdas vecinas no dependen del contenido de Acciones, y el badge de snapshot que está justo después se centra verticalmente igual.
- **[Re-activación futura costosa]**: si en el futuro se quiere re-introducir el drill-down de archivos, hay que re-implementar desde cero (sin git archaeology basta para recuperar la versión anterior con `git log --diff-filter=D -- app/resources/views/ia/api-transcriptor/index.blade.php` + `git show <hash>:<path>`). Mitigación: el commit del change queda en git y la lógica original (modal + endpoints) sigue documentada en `transcriptor-storage-files-srt-link` archivada.

## Migration Plan

No requiere migración de BD. Deploy normal:
1. `git merge` del branch del change.
2. `php artisan view:clear && php artisan cache:clear` (Blade cacheada).
3. `php artisan config:cache` si se tocó `.env` (no aplica aquí).

**Rollback**: `git revert <commit>` + reload. Sin estado persistente, sin background jobs que limpiar.

## Open Questions

*(Ninguna — la decisión de producto ya está tomada en el change archivado `2026-09-15-simplify-api-transcriptor-to-storage-and-config`. El operador confirmó Opción A en la sesión de explore 2026-09-16.)*
