# Change: archivar corrección al pasarla a exclusión dinámica

## Why

El admin reportó el 2026-08-01:

> *"veo que sale que se excluyó, pero en teoría, si se excluyó, desaparece dependiente, porque se pasa como una exclusión."*

Concretamente: cuando el admin hace click "🛡 Excluir" en una fila de Pendientes (o Aprobadas), la fila sigue ahí después del toast. Inconsistente: si el admin ya decidió "esto es una marca/exclusión, no se traduce", mantenerlo en Pendientes es ruido — la siguiente corrida AI Suggest lo va a saltar de todas formas, y el admin ya lo catalogó semánticamente.

La operación de "convertir en exclusión" debe ser **transaccional** desde la UI: si creaste la exclusión, archivá la corrección. Hoy son dos acciones separadas que requieren dos clicks.

## What Changes

### 1. Backend: endpoint `/ia/correcciones/protected-terms` extendido

El body bulk (`{terms: [...]}`) acepta por cada ítem un campo opcional `correction_id`. Cuando un ítem se crea exitosamente y trae `correction_id`, el controller adicionalmente llama `CorrectionService::reject($correction, $admin, 'moved_to_exclusion: <term>')` para archivar la corrección asociada con motivo trazable.

Comportamiento:
- Si el término falla por duplicado (`'X' ya existe`), NO se archiva la corrección (igual que antes — no tocamos nada si la exclusión no se creó).
- Si el término se crea OK, se archiva la corrección vinculada.
- Sin `correction_id`, no se hace nada extra (compatibilidad con el caller del subpanel Exclusiones).

Para el modo single del subpanel Exclusiones (`{term, category?, notes?}`), nada cambia.

### 2. UI: atajos (single + bulk) envían `correction_id`

- `openExcludeForPending(c)` / `openExcludeForApproved(c)`: el método `submitExcludeShortcut` envía `{term, notes, correction_id: c.id}`.
- `submitExcludeBulk()` arma el payload con `correction_ids` por cada término (id = correction.id; viene del array `ids` ya disponible).

### 3. Refresco de tablas

Tras éxito:
- En la pestaña Pendientes o Aprobadas (de donde vino la fila), recargar la lista (`loadPending()` o `loadApproved()`) para que la fila rechazada desaparezca visualmente.
- Si la admin está en el subpanel Exclusiones, `loadExclusiones()` también.
- El toast verde ahora menciona: "Exclusión agregada + N correcciones archivadas".

### 4. Spec delta

- 1 ADDED Requirement: "Cuando el admin crea una exclusión desde un atajo de fila, la corrección asociada se archiva con motivo `moved_to_exclusion: <term>` en la misma operación HTTP".

## Non-goals

- **No se archiva si la exclusión es duplicada**: el backend solo archiva cuando el término realmente se crea (no cuando ya existía).
- **No se agrega UI para "solo archivar sin crear exclusión"**: esa acción no encaja con la decisión actual; queda como follow-up si el admin lo pide.
- **No se aprueba automáticamente**: si la corrección era Aprobada, se rechaza (no se preserva la aprobación) porque contradeciría la decisión de excluirla. Por feedback 2026-08-01: "Archivar la corrección (rejected con motivo 'moved_to_exclusion')".
- **No se migran exclusiones previas**: las que ya estaban creadas antes de este cambio no afectan las correcciones ya aprobadas/pendientes (sería una limpieza agresiva que no fue el alcance).

## Impact

- **Specs affected**: `transcription-corrections` (1 ADDED Requirement; el requisito previo del shortcut ahora se MODIFICA para agregar la archivación automática).
- **Code affected (modificados)**:
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (`protectedTermsStore` extendido: por cada bulk-item exitoso con `correction_id`, llamar `CorrectionService::reject`).
  - `app/resources/views/ia/correcciones/index.blade.php` (modales single/bulk envían `correction_id`; refresco de tablas post-éxito).
- **Migrations**: ninguna.
- **Riesgos**: bajo. El reject es idempotente (rechazar un pendiente que ya estaba rechazado no rompe). El refresh de tablas es defensivo: si falla, la fila quedará visualmente pero el toast avisa qué se hizo.

## Open questions (resueltas)

- **¿Conservar aprobación si venia de Aprobadas?** No — el admin decidió "no se traduce", así que la aprobación queda invalidada. Se rechaza.
- **¿Motivo fijo?** Sí, `moved_to_exclusion: <term>`. Trazabilidad limpia.
- **¿Bulk respeta consistencia atómica?** No — cada ítem se procesa individualmente; duplicados no afectan a los demás. Coherente con el flujo bulk ya existente.
