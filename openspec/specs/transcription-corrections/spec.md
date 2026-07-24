## ADDED Requirements

### Requirement: Cliente puede reportar una corrección sobre texto de un segmento
El sistema SHALL permitir al cliente autenticado proponer un mapeo `wrong → correct` desde la vista de detalle de un match en `/mis-avisos`, abriendo un modal con el `wrong_text` (texto que el transcriptor escribió, tomado del segmento) y un campo para que el cliente escriba el `correct_text`.

#### Scenario: Cliente propone una corrección nueva
- **WHEN** el cliente abre el detalle de un match, clickea "Reportar corrección", ve `wrong_text = "presedente"`, escribe `correct_text = "presidente"` y hace submit
- **THEN** se crea una fila en `corrections` con `status=pending`, `proposed_by=cliente.id`, `wrong_text="presedente"`, `correct_text="presidente"`, `wrong_normalized="presedente"` (lowercase + ascii)

#### Scenario: Cliente intenta proponer sobre texto sin reporte visible
- **WHEN** el cliente intenta acceder a `/mis-avisos/corrections/new` sin un segmento asociado (origen inválido)
- **THEN** el sistema responde 422 "Debe seleccionar un segmento del transcriptor"

#### Scenario: Cliente NO puede proponer correcciones si el módulo está inactivo
- **WHEN** el usuario no tiene `user_alerts_inteligentes(enabled=true)` e intenta POST a `/mis-avisos/corrections`
- **THEN** el sistema responde 403 (mismo guard que `/mis-avisos`)

---

### Requirement: Admin puede agregar correcciones directamente sin pasar por aprobación
El sistema SHALL permitir al admin en `/ia/correcciones` crear correcciones que entran al diccionario con `status=approved` automáticamente (no requieren moderación porque el admin es de confianza).

#### Scenario: Admin agrega corrección directa
- **WHEN** el admin en `/ia/correcciones` hace click en "Nueva corrección", escribe `wrong="presedente"` y `correct="presidente"`, y guarda
- **THEN** se crea fila con `status=approved`, `proposed_by=admin.id`, `approved_by=admin.id`, `approved_at=NOW()`. La corrección está activa inmediatamente y se aplicará al próximo SRT que llegue con ese texto

#### Scenario: Admin agrega corrección con wrong_text que ya tiene una aprobada
- **WHEN** el admin agrega "presedente → presidente" pero ya existe una fila con mismo `wrong_normalized` y `status=approved`
- **THEN** el sistema actualiza `correct_text` de la fila existente (upsert por `wrong_normalized`), `updated_at=NOW()`, `applies_count` se preserva. No se crea duplicado

---

### Requirement: Admin ve la cola de correcciones pendientes y puede aprobar/rechazar
El sistema SHALL listar en `/ia/correcciones` las correcciones con `status=pending` y permitir aprobar (→approved) o rechazar (→rejected con motivo opcional).

#### Scenario: Cola de pendientes visible con badge
- **WHEN** el admin abre `/ia/correcciones`
- **THEN** ve dos pestañas: "Pendientes (N)" y "Aprobadas". El badge en la pestaña muestra el conteo de pendientes

#### Scenario: Admin aprueba una pendiente
- **WHEN** el admin en `/ia/correcciones/{correction}` clickea "Aprobar" (la pendiente propuesta por un cliente)
- **THEN** se actualiza `status=approved`, `approved_by=admin.id`, `approved_at=NOW()`. Si ya existe una approved para el mismo `wrong_normalized` (caso raro pero posible), se actualiza el `correct_text` de la approved existente y la propuesta queda como `status=merged` (no aparece en cola)

#### Scenario: Admin rechaza una pendiente
- **WHEN** el admin clickea "Rechazar" y opcionalmente escribe `rejected_reason="ya corregido por upstream"`
- **THEN** se actualiza `status=rejected`, `rejected_reason` se guarda. La corrección NO entra al diccionario activo

#### Scenario: Cliente ve estado de sus propuestas
- **WHEN** el cliente entra a `/mis-avisos/corrections/mine`
- **THEN** ve sus propuestas con estado (pending/approved/rejected) y, si fue rechazada, el motivo

---

### Requirement: Correcciones activas se aplican a segmentos nuevos en el parseo del SRT
El sistema SHALL, al guardar segmentos desde un SRT nuevo, aplicar todas las correcciones `status=approved` al campo `text` de cada `TranscriptionSegment`, dejando el campo `text_raw` con el original del transcriptor (inmutable).

#### Scenario: SRT nuevo se parsea y aplica correcciones
- **WHEN** el webhook recibe un SRT done, el `SrtParser` extrae segmentos con `text=raw_text`, y luego `CorrectionService::applyToSegments($segments)` reemplaza en `text` cada ocurrencia de cualquier `correction.wrong_normalized` por `correction.correct_text`
- **THEN** cada `TranscriptionSegment` queda con `text_raw=raw` y `text=corrected`. El matching posterior usa `text` (ya corregido)

#### Scenario: Múltiples correcciones se aplican en cadena
- **WHEN** hay 3 correcciones activas que afectan al mismo texto del segmento
- **THEN** se aplican las 3 iterativamente (orden por longitud descendente para evitar que un substring corto sobreescriba uno largo)

---

### Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones
El sistema SHALL exponer `php artisan transcription:apply-corrections [--dry-run] [--chunk=500]` que recorre TODOS los `TranscriptionSegment` en batches, reaplica el diccionario actual y actualiza `text`. NO re-envía emails (los AlertLog históricos quedan con el texto con el que se enviaron).

#### Scenario: Admin corre el comando retroactivo en dry-run
- **WHEN** el admin corre `php artisan transcription:apply-corrections --dry-run`
- **THEN** el sistema reporta cuántos segments serían modificados y muestra los primeros 10 cambios propuestos, sin tocar la BD

#### Scenario: Admin corre el comando real
- **WHEN** el admin corre `php artisan transcription:apply-corrections`
- **THEN** el sistema itera en chunks de 500, actualiza `text` por cada segment, e incrementa `applies_count` en cada corrección aplicada. Imprime progreso cada 1000 segments

#### Scenario: Comando respeta transacciones por chunk
- **WHEN** el comando está procesando
- **THEN** cada chunk de 500 segments corre dentro de su propia transacción. Si un chunk falla, los anteriores quedan guardados y el error se reporta (no se pierde progreso)

---

### Requirement: Matching de keywords usa `text` corregido, no `text_raw`
El sistema SHALL hacer el matching de keywords contra `transcription_segments.text` (corregido), NO contra `text_raw`. Esto garantiza que las alertas reflejen el texto que verá el cliente.

#### Scenario: Keyword matchea después de corrección
- **WHEN** un segmento tiene `text_raw="el presedente habla"` y `text="el presidente habla"` (por corrección aplicada), y el usuario tiene keyword `presidente`
- **THEN** el match se detecta sobre `text="el presidente habla"` y se registra `KeywordMatch` con `snippet="el presidente habla..."`

---

### Requirement: Métricas de aplicación por corrección
El sistema SHALL incrementar `applies_count` en cada corrección cada vez que se aplica (en parseo nuevo o comando retroactivo).

#### Scenario: Contador se incrementa al aplicar
- **WHEN** se aplica una corrección a 50 segmentos durante un `apply-corrections`
- **THEN** la columna `applies_count` de esa corrección se incrementa en 50

#### Scenario: Admin ve ranking de correcciones más aplicadas
- **WHEN** el admin abre `/ia/correcciones` y va a la pestaña "Aprobadas"
- **THEN** la tabla muestra `wrong → correct`, `applies_count`, fecha de aprobación y proponente original. Ordenable por applies_count DESC

---

### Requirement: Deduplicación por wrong_normalized dentro del estado aprobado
El sistema SHALL garantizar que solo exista UNA corrección `approved` activa por cada `wrong_normalized` usando un índice único parcial en Postgres.

#### Scenario: Intento de duplicado
- **WHEN** dos correcciones `approved` con mismo `wrong_normalized="presedente"` se intentarían crear
- **THEN** la BD rechaza la segunda por violación de índice parcial único `corrections_wrong_active_unique ON corrections(wrong_normalized) WHERE status='approved'`

#### Scenario: Aprobar una pendiente cuya wrong_normalized ya tiene approved
- **WHEN** el admin aprueba "presedente → presidente (pendiente)" pero ya existe approved "presedente → presedente"
- **THEN** la approved existente se actualiza con el nuevo `correct_text` y la aprobada anterior queda como `status=merged` (no se duplica)
