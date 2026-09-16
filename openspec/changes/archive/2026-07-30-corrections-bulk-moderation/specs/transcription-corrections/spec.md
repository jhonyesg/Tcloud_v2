## ADDED Requirements

### Requirement: Admin puede aprobar múltiples correcciones pendientes en lote

El sistema SHALL permitir al admin seleccionar N correcciones pendientes (N entre 1 y 500) y aprobarlas en una sola acción via POST `/ia/correcciones/bulk-approve`. La respuesta SHALL incluir `{approved: int, merged: int, errors: [{id, message}], bulk_action_id: string, undo_expires_at: ISO8601}` para que la UI muestre el resultado por item y habilite el undo. Las correcciones que ya tienen una `approved` con el mismo `wrong_normalized` se marcan como `merged` (no se duplican).

#### Scenario: Admin aprueba todas las pendientes de round3
- **WHEN** el admin selecciona 85 correcciones con `source='pending-round3-2026-07-29'` y hace click en "Aprobar 85"
- **THEN** se ejecuta POST `/ia/correcciones/bulk-approve` con `{ids: [103, 104, ..., 187]}`
- **THEN** el servidor responde `{approved: 82, merged: 3, errors: [], bulk_action_id: "01HX...", undo_expires_at: "2026-07-30T..."}`
- **THEN** la UI recarga y muestra 0 pendientes con `source='pending-round3-2026-07-29'`.
- **THEN** aparece un toast bottom-left con `[Deshacer]` visible hasta `undo_expires_at`.

#### Scenario: Admin aprueba un lote con algunas que ya no están pending
- **WHEN** el admin envía un lote donde 5 IDs son pending y 2 ya fueron aprobadas en otra sesión
- **THEN** los 5 IDs cambian a `approved`, los 2 van a `errors[]` con `message: "no está pendiente (status=approved)"`.

#### Scenario: Admin aprueba con IDs inválidos
- **WHEN** el admin envía IDs que no existen en la tabla (ej. `[999, 1000]`)
- **THEN** la respuesta es `{approved: 0, merged: 0, errors: [...], bulk_action_id: "01HX..."}` (no error 500). Los items inválidos se guardan en el log con `applied=false`.

---

### Requirement: Admin puede rechazar múltiples correcciones pendientes en lote con motivo común

El sistema SHALL permitir al admin rechazar N correcciones pendientes en una sola acción via POST `/correcciones/bulk-reject`. El `rejected_reason` es opcional y se aplica a TODAS las del lote (un único motivo compartido para todo el bloque). Las correcciones rechazadas pasan a `status='rejected'` y NO entran al diccionario activo.

#### Scenario: Admin rechaza un lote con motivo común
- **WHEN** el admin selecciona 5 correcciones, abre el modal rechazar, escribe motivo "falso positivo en word-boundary" y confirma
- **THEN** se ejecuta POST `/correcciones/bulk-reject` con `{ids: [1,2,3,4,5], rejected_reason: "falso positivo en word-boundary"}`
- **THEN** las 5 filas pasan a `status='rejected'` con `rejected_reason` compartido.
- **THEN** aparece toast con [Deshacer] hasta `undo_expires_at`.

---

### Requirement: Admin puede eliminar múltiples correcciones aprobadas en lote

El sistema SHALL permitir al admin eliminar N correcciones aprobadas en una sola acción via POST `/correcciones/bulk-destroy`. La acción es destructiva (DELETE físico). El toast de undo aparece igual pero su `performUndo` retorna 409 Conflict porque `bulk_destroy` no es reversible.

#### Scenario: Admin elimina 10 reglas obsoletas
- **WHEN** el admin selecciona 10 correcciones approved con `applies_count=0` y confirma "Eliminar 10"
- **THEN** se ejecuta POST `/correcciones/bulk-destroy` con `{ids: [...]}`
- **THEN** esas 10 filas se eliminan físicamente de la tabla. Sus snapshots quedan en `correction_bulk_action_items` para auditoría pero no se pueden restaurar.

---

### Requirement: Admin puede revertir una acción masiva dentro de una ventana de 5 minutos

El sistema SHALL permitir al admin revertir cualquier acción masiva (`bulk_approve` o `bulk_reject`) ejecutada por él mismo dentro de los últimos N minutos (configurable via `CORRECTIONS_UNDO_WINDOW_MINUTES`, default 5) via POST `/correcciones/undo/{bulkActionId}`. La reversión restaura el status de cada correction a `pending` y, en el caso de merges, restaura el `correct_text` original de la approved preexistente.

El sistema marca como `superseded_at` cualquier `correction_bulk_actions` previa del mismo admin que aún esté dentro de la ventana, para evitar que múltiples undo compitan. Solo el último bulk action del admin tiene undo activo.

#### Scenario: Admin revierte aprobación accidental dentro de la ventana
- **WHEN** el admin acaba de aprobar 10 reglas y hace click en [Deshacer] en el toast
- **THEN** se ejecuta POST `/correcciones/undo/01HX...`
- **THEN** el servidor restaura las 10 correcciones a `status='pending'` con `approved_by=null`, `approved_at=null`.
- **THEN** el bulk_action se marca `undone_at=NOW()`, `undone_by=admin.id`.
- **THEN** la UI recarga y las 10 reaparecen en pendientes.

#### Scenario: Admin intenta revertir un merge undo restaura el correct_text original
- **WHEN** el admin aprobó una pending que mergeó con una approved existente, cambiando su `correct_text`, y luego hace undo
- **THEN** la pending vuelve a `status='pending'`
- **THEN** la approved preexistente recupera su `correct_text` original (del snapshot `merge_previous_correct_text`).

#### Scenario: Undo falla porque la ventana expiró
- **WHEN** pasaron más de 5 minutos desde el bulk action
- **THEN** POST `/correcciones/undo/{id}` retorna 410 Gone con mensaje "La ventana de undo expiró".

#### Scenario: Undo falla porque ya fue revertida
- **WHEN** el admin clickea Deshacer dos veces seguidas
- **THEN** la segunda llamada retorna 409 Conflict con mensaje "Esta acción ya fue revertida".

#### Scenario: Undo falla porque fue superada por otra acción
- **WHEN** el admin hizo bulk A, luego bulk B (que marca A como superseded_at), luego intenta deshacer A
- **THEN** POST retorna 409 Conflict con mensaje "Esta acción ya no se puede revertir (fue superada por otra)".

#### Scenario: Undo de bulk_destroy no es posible
- **WHEN** el admin eliminó 10 reglas aprobadas y hace click en [Deshacer]
- **THEN** POST retorna 409 Conflict con mensaje "bulk_destroy no es reversible". El toast desaparece.

#### Scenario: Limitación documentada — undo no revierte applies_count
- **WHEN** entre la aprobación y el undo hubo un `corrections:apply-run` que incrementó `applies_count` de las reglas
- **THEN** el undo revierte el status pero NO decrementa `applies_count`. La UI muestra un warning en el toast: "Nota: si hubo retroactivo en esta ventana, los contadores de aplicación no se revirtieron."

---

### Requirement: Admin puede filtrar correcciones pendientes por source

El sistema SHALL permitir al admin filtrar la lista de pendientes por `source` via un dropdown arriba de la tabla. Las opciones se generan dinámicamente desde los valores únicos de `source` presentes en la tabla `corrections` con `status='pending'`. El filtro afecta la selección masiva (el botón "seleccionar todas" solo afecta las del filtro actual).

#### Scenario: Admin filtra por round3 y selecciona todas
- **WHEN** el admin selecciona "Round 3 (pending-round3-2026-07-29)" en el filtro source
- **THEN** la tabla solo muestra las 85 pendientes de round3.
- **THEN** el checkbox header "seleccionar todas" solo afecta esas 85 (no las 53 de round2 que están ocultas).