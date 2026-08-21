# Change: Editar y eliminar correcciones pendientes

## Why

El tab **Pendientes** de `/ia/correcciones` solo expone tres acciones por fila: `Aprobar`, `Rechazar` y `Excluir`. Esto deja al administrador sin recurso cuando una sugerencia automática es **errónea pero cercana a la correcta** — el caso típico es una regla propuesta por el miner/AI Suggest con el `correct_text` mal escrito.

Ejemplo concreto observado: la sugerencia `io → Io` cuando la corrección correcta es `io → Tio`. La palabra `Tio` ya existía como approved pero el miner propuso una variante capitalizada porque el segmento original comenzaba frase. El admin no puede:

- Corregir el `correct_text` antes de aprobar, así que tiene que **rechazar** una sugerencia que casi servía y luego proponer la buena manualmente en otro flujo.
- **Eliminar** una sugerencia que no es una corrección ni un falso positivo: por ejemplo, la palabra suelta `D` propuesta como regla después de un n-gram mining. Hoy tiene que marcarla como `rejected`, lo cual la contabiliza como "rechazada" en estadísticas y la mantiene viva en la BD para auditoría, sin ser útil para nada de eso. Semánticamente **rechazar ≠ eliminar**:
  - **rechazar** = "esta es una corrección mala" → conserva `status='rejected'` + `rejected_reason` para auditoría y métricas.
  - **eliminar** = "esto no es siquiera una corrección, es ruido del flujo origen" → desaparece de la BD y no infla métricas.

## What Changes

### 1. Botón "Editar" por fila en el tab Pendientes

Cada fila de `/ia/correcciones` (tab Pendientes) mostrará un botón `Editar` que abre un modal con:

- campo editable `Texto incorrecto` (`wrong_text`);
- campo editable `Corrección` (`correct_text`);
- campo de solo-lectura `Origen` (`source`: `mining-*`, `ai-suggest-*`, etc.);
- campo de solo-lectura `Propuesto por` (`proposed_by.username`) y fecha.

Al guardar, se recalcula `wrong_normalized` con `Keyword::asciiLower(trim($wrong))` y se persiste. Si el nuevo `wrong_normalized` colisiona con una corrección `approved` existente, se sigue la misma semántica de `propose()`: la fila editada pasa a `status='merged'`. Si colisiona con otra `pending`, se hace upsert (re-uso de la fila existente) para no duplicar.

Endpoint nuevo: `PATCH /ia/correcciones/{id}` con validación de que `status='pending'` (no se puede editar approved/rejected desde acá).

### 2. Botón "Eliminar" por fila en el tab Pendientes

Botón rojo discreto al final de las acciones por fila. Modal de confirmación con texto claro:

> Esta sugerencia no representa una corrección (no la vamos a rechazar, la vamos a eliminar). ¿Continuar?

Reutiliza `DELETE /ia/correcciones/{id}` (ya existe en el controlador y funciona sobre cualquier status). Solo se agrega el botón y el modal de confirmación.

### 3. Acción masiva "Eliminar N" en el sticky bar

El sticky bar inferior del tab Pendientes (`index.blade.php:1333-1362`) actualmente ofrece `Aprobar`, `Rechazar`, `Excluir`. Se agrega un cuarto botón `Eliminar N` con confirmación batch (estilo del modal de Rechazo en lote).

Reutiliza `POST /correcciones/bulk-destroy` (ya implementado en `CorrectionService::bulkDestroy()`).

### 4. Validación y semántica

- `PATCH /correcciones/{id}` rechaza si `status != 'pending'` con 409 Conflict.
- `PATCH` valida `wrong_text` y `correct_text` no vacíos y `max:500`.
- `DELETE /correcciones/{id}` mantiene su comportamiento actual (sin status-check) — la UI simplemente no expone el botón para `approved`/`rejected` (ya está así en otras pestañas).
- Si una corrección `pending` editada colisiona con una `pending` existente del mismo `wrong_normalized`, se actualiza la fila existente (no se crea nueva) — comportamiento consistente con `CorrectionService::propose()`.

### 5. Tour guiado

`TcloudTour` debe actualizarse para que el paso del tab Pendientes mencione los nuevos botones `Editar` y `Eliminar`. Aplica `interactive_tours_must_include_new_features`.

## Non-Goals

- No se introduce un `status='discarded'` nuevo. Eliminar = `DELETE` físico. Si en el futuro se necesita distinguir "descartado" de "eliminado", se hará en un change aparte.
- No se editan correcciones `approved` o `rejected` desde acá. Las approved tienen flujo de `destroy` + nueva propuesta (o `setRiskLevel`).
- No se agrega un campo `last_edited_by` / `last_edited_at`. Trazabilidad suficiente con `updated_at` + bitácora existente (`correction_bulk_actions` para bulk).
