# Spec: transcription-corrections

## Purpose

Define el sistema de correcciones de transcripción (`corrections`): cómo clientes y admin proponen/crean correcciones `wrong → correct`, cómo se aprueban/rechazan, y cómo se aplican al campo `text` de los segmentos durante el parseo del SRT, manteniendo `text_raw` inmutable.

---

## Requirements

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
El sistema SHALL, al guardar segmentos desde un SRT nuevo, aplicar todas las correcciones `status=approved` al campo `text` de cada `TranscriptionSegment`, dejando el campo `text_raw` con el original del transcriptor (inmutable). El método `CorrectionService::applyToSegments(array $segments): array` SHALL retornar el array mutado para que `TranscriptionProcessor` lo consuma directamente; la mutación SHALL hacerse por referencia de índice (`$segments[$i]['text'] = ...`), no por copia local. El sistema SHALL aplicar primero el diccionario de correcciones (`applyToSegments`) y luego el pase de coherencia IA sobre los segmentos con inglés residual, en ese orden. El diccionario sigue siendo el primer barrido (rápido y gratis); la IA solo complementa donde el diccionario no cubre.

#### Scenario: SRT nuevo se parsea y aplica correcciones
- **WHEN** el polling recoge un SRT de un job done, el `SrtParser` extrae segmentos con `text=raw_text`, y luego `CorrectionService::applyToSegments($segments)` retorna el array con `text` corregido
- **THEN** cada `TranscriptionSegment` queda con `text_raw=raw` y `text=corrected`. El matching posterior usa `text` (ya corregido)

#### Scenario: Múltiples correcciones se aplican en cadena
- **WHEN** hay 3 correcciones activas que afectan al mismo texto del segmento
- **THEN** se aplican las 3 iterativamente (orden por longitud descendente para evitar que un substring corto sobreescriba uno largo)

#### Scenario: Diccionario primero, IA después
- **WHEN** se persisten los segmentos de una transcripción
- **THEN** el sistema aplica primero el diccionario de correcciones a todos los segmentos
- **AND** luego aplica el pase de coherencia IA solo a los segmentos que aún tienen inglés residual
- **AND** el resultado final en `text` es español coherente, sin spanglish

#### Scenario: applyToSegments es idempotente
- **WHEN** se invoca `applyToSegments()` dos veces seguidas sobre el mismo array
- **THEN** el resultado final es idéntico (no dobletea reemplazos)

---

### Requirement: Matching de keywords usa `text` corregido, no `text_raw`
El sistema SHALL hacer el matching de keywords contra `transcription_segments.text` (corregido), NO contra `text_raw`. Esto garantiza que las alertas reflejen el texto que verá el cliente.

#### Scenario: Keyword matchea después de corrección
- **WHEN** un segmento tiene `text_raw="el presedente habla"` y `text="el presidente habla"` (por corrección aplicada), y el usuario tiene keyword `presidente`
- **THEN** el match se detecta sobre `text="el presidente habla"` y se registra `KeywordMatch` con `snippet="el presidente habla..."`

---

### Requirement: Métricas de aplicación por corrección
El sistema SHALL incrementar `applies_count` en cada corrección cada vez que se aplica a un segmento (en parseo nuevo o comando retroactivo). El conteo SHALL ser idempotente: re-ejecutar el retroactivo sobre un segmento ya corregido NO incrementa el contador. El cálculo se hace por delta dentro de cada chunk, no como acumulador entre chunks.

#### Scenario: Contador se incrementa al aplicar
- **WHEN** se aplica una corrección a 50 segmentos durante un `corrections:apply-run`
- **THEN** la columna `applies_count` de esa corrección se incrementa en 50

#### Scenario: Admin ve ranking de correcciones más aplicadas
- **WHEN** el admin abre `/ia/correcciones` y va a la pestaña "Aprobadas"
- **THEN** la tabla muestra `wrong → correct`, `applies_count`, fecha de aprobación y proponente original. Ordenable por applies_count DESC

#### Scenario: Re-ejecución no sobrecuenta
- **WHEN** se corre `corrections:apply-run` dos veces seguidas sobre el mismo corpus
- **THEN** el segundo run no incrementa `applies_count` para segmentos que ya estaban corregidos (la métrica refleja cuántos segmentos acabó modificando la corrección, no cuántas veces el worker la vio)

---

### Requirement: Deduplicación por wrong_normalized dentro del estado aprobado
El sistema SHALL garantizar que solo exista UNA corrección `approved` activa por cada `wrong_normalized` usando un índice único parcial en Postgres.

#### Scenario: Intento de duplicado
- **WHEN** dos correcciones `approved` con mismo `wrong_normalized="presedente"` se intentarían crear
- **THEN** la BD rechaza la segunda por violación de índice parcial único `corrections_wrong_active_unique ON corrections(wrong_normalized) WHERE status='approved'`

#### Scenario: Aprobar una pendiente cuya wrong_normalized ya tiene approved
- **WHEN** el admin aprueba "presedente → presidente (pendiente)" pero ya existe approved "presedente → presedente"
- **THEN** la approved existente se actualiza con el nuevo `correct_text` y la aprobada anterior queda como `status=merged` (no se duplica)

---

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

---


### Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones
El sistema SHALL exponer `php artisan transcription:apply-corrections [--dry-run] [--chunk=500]` y su variante con tracking `php artisan corrections:apply-run --run-id=<id> [--chunk=N] [--days=N]`, que recorren los `TranscriptionSegment` en batches, reaplican el diccionario actual y actualizan `text`. NO re-envían emails (los AlertLog históricos quedan con el texto con el que se enviaron). El retroactivo respeta word-boundary (`\b` alrededor de `wrong_normalized`), lo que evita introducir falsos positivos catastróficos al re-aplicar el diccionario actual sobre los 9.98M segmentos.

Cuando corre con `--run-id`, el comando SHALL reportar progreso real en la cache key `corrections_apply:{runId}` **después de cada chunk**: `processed` (conteo acumulado de segments leídos), `updated` (conteo parcial de segments modificados, no solo al final), `total`, `progress` (lastId diagnóstico) y `last_progress_at` (heartbeat ISO8601). La conversión de la colección de correcciones aprobadas a pares primitivos ordenados SHALL ejecutarse una sola vez por corrida (no por segmento).

#### Scenario: Admin corre el comando retroactivo en dry-run
- **WHEN** el admin corre `php artisan transcription:apply-corrections --dry-run`
- **THEN** el sistema reporta cuántos segments serían modificados y muestra los primeros 10 cambios propuestos, sin tocar la BD

#### Scenario: Admin corre el comando real
- **WHEN** el admin corre `php artisan transcription:apply-corrections`
- **THEN** el sistema itera en chunks de 500, actualiza `text` por cada segment, e incrementa `applies_count` en cada corrección aplicada. Imprime progreso cada 1000 segments

#### Scenario: Comando respeta transacciones por chunk
- **WHEN** el comando está procesando
- **THEN** cada chunk de 500 segments corre dentro de su propia transacción. Si un chunk falla, los anteriores quedan guardados y el error se reporta (no se pierde progreso)

#### Scenario: Progreso visible durante la corrida, no solo al final
- **WHEN** el admin lanza Re-aplicar con scope "último día" (214,396 segments) y han pasado 2 minutos
- **THEN** la cache key del run muestra `processed > 0` creciendo por chunk, `updated` con el conteo parcial de modificados y `last_progress_at` con antigüedad menor a 1 minuto
- **THEN** la UI muestra la barra avanzando (ej. "48,500 / 214,396 segmentos (22%)") en vez de quedarse en 0% hasta el final

#### Scenario: Pares de correcciones se preparan una sola vez
- **WHEN** una corrida procesa 214,396 segments con N correcciones aprobadas
- **THEN** la conversión de la colección a pares ordenados por longitud DESC ocurre UNA vez al inicio de la corrida (no 214,396 veces)

#### Scenario: Admin lanza el modo real desde UI
- **WHEN** el admin confirma la aplicación retroactiva en el modal
- **THEN** la UI recibe `{runId}`, hace polling cada 2 segundos a `GET /ia/correcciones/apply-retroactive/{runId}` hasta que `status` sea `done` o `error`

#### Scenario: Cache TTL de 4h por runId
- **WHEN** el comando inicia una corrida con `--run-id=<id>`
- **THEN** la cache key `corrections_apply:{runId}` se crea con TTL 4h, suficiente para una corrida real (~2h) con margen, antes de expirar

---


### Requirement: El sistema impide corridas retroactivas duplicadas en paralelo
El sistema SHALL mantener una cache key fija `corrections_apply:active` apuntando al `runId` de la corrida vigente. `POST /ia/correcciones/apply-retroactive` SHALL responder `409 Conflict` con el `runId` vigente cuando ya existe una corrida en estado `queued` o `running`, en vez de lanzar un proceso paralelo. El puntero se crea atómicamente (SET NX) y se limpia cuando la corrida termina en `done` o `error`. Un puntero cuyo run está en `done`/`error`, no existe, o lleva más de 5 minutos en `queued` sin `started_at` SHALL considerarse huérfano y no bloquear una nueva corrida.

#### Scenario: Admin hace doble click en Re-aplicar
- **WHEN** el admin confirma Re-aplicar mientras otra corrida está `running`
- **THEN** el servidor responde 409 con `{runId, status}` de la corrida vigente y NO lanza un segundo proceso `corrections:apply-run` (verificable: un solo proceso en `ps aux`)
- **THEN** la UI se re-adjunta al polling de la corrida vigente en vez de mostrar error

#### Scenario: Puntero huérfano no bloquea nuevas corridas
- **WHEN** existe un puntero `corrections_apply:active` cuyo run quedó en `queued` hace 30 minutos sin `started_at` (el proceso nunca arrancó)
- **THEN** un nuevo POST apply-retroactive responde 202 y lanza la corrida normalmente, reemplazando el puntero

#### Scenario: Puntero se limpia al terminar
- **WHEN** una corrida termina en `done` o `error`
- **THEN** `corrections_apply:active` se elimina y un POST posterior responde 202 con un `runId` nuevo

---

### Requirement: Admin re-adjunta una corrida en curso al recargar la página
El sistema SHALL exponer `GET /ia/correcciones/apply-retroactive-active` que retorna el estado completo de la corrida vigente (200 con `{runId, status, processed, total, updated, last_progress_at, ...}`) o 204 si no hay ninguna. Al cargar `/ia/correcciones`, la UI SHALL consultar este endpoint y, si hay corrida activa, mostrar su progreso en un banner persistente del módulo y arrancar el polling automáticamente, sin intervención del admin.

#### Scenario: Admin recarga durante una corrida
- **WHEN** el admin recarga `/ia/correcciones` a mitad de una corrida `running`
- **THEN** la página muestra el banner "Re-aplicar en curso" con el porcentaje actual en menos de 3 segundos y el polling continúa donde iba

#### Scenario: No hay corrida activa
- **WHEN** el admin carga `/ia/correcciones` sin corrida vigente
- **THEN** el endpoint responde 204 y la página no muestra banner ni hace polling

#### Scenario: Corrida terminó mientras la página estaba cerrada
- **WHEN** el admin carga la página después de que la corrida llegó a `done` (puntero ya limpiado)
- **THEN** el endpoint responde 204 y la UI arranca en estado inicial normal

---

### Requirement: UI detecta y comunica una corrida estancada
Mientras el polling está activo, la UI SHALL comparar `last_progress_at` contra el reloj local: si el estado es `running` y el heartbeat supera 3 minutos sin actualizarse, SHALL mostrar un aviso visible (ámbar) indicando que la corrida parece detenida y la hora del último avance. El aviso no detiene el polling (una corrida viva retoma el estado verde sola al próximo chunk).

#### Scenario: Proceso muere a mitad de corrida
- **WHEN** el proceso `corrections:apply-run` es killado a mitad de la corrida y pasan 3 minutos
- **THEN** la UI muestra "Sin avances desde las HH:MM — la corrida pudo haberse detenido" en vez de esperar indefinidamente con la barra congelada

#### Scenario: Corrida lenta pero viva no genera falsa alarma
- **WHEN** una corrida procesa chunks en menos de 3 minutos cada uno
- **THEN** el aviso de estancada nunca aparece (el heartbeat se renueva por chunk)

#### Scenario: Estados legibles en español
- **WHEN** el polling recibe `status: "queued"`, `"running"`, `"done"` o `"error"`
- **THEN** la UI muestra "En cola…", "Procesando…", "Terminada" o "Falló" respectivamente (no el status crudo en inglés)

---

### Requirement: AI suggest EN↔ES corre cada 2 horas de forma automática
El sistema SHALL programar `Schedule::command('corrections:ai-suggest --days=1 --sample=200')->everyTwoHours()->withoutOverlapping(10)` en `routes/console.php`, de modo que el suggester LLM-powered (`2026-08-01-corrections-ai-suggest-context-aware`) se ejecute ~12 veces al día **sin intervención del admin**. Cada corrida SHALL producir candidatos `pending` con `source='ai-suggest-YYYY-MM-DD'` para que el admin los apruebe desde `/ia/correcciones` y, una vez `approved`, las correcciones fluyan automáticamente a `CorrectionService::applyToSegments()` (SRT nuevos) y al retroactivo manual (`corrections:apply-run`). El botón "AI Suggest" manual SHALL seguir disponible para corridas fuera de schedule. La salida de cada corrida SHALL appendear a `storage/logs/ai-suggest-scheduled.log` para diagnóstico.

#### Scenario: El admin espera varias horas sin tocar el módulo
- **WHEN** el admin no interactúa con `/ia/correcciones` durante 8 horas
- **THEN** el scheduler habrá disparado ~4 corridas de `corrections:ai-suggest --days=1 --sample=200`, las nuevas `pending` están en la tabla `corrections` con `source='ai-suggest-YYYY-MM-DD'` y aparecen en el badge "AI Suggest" de la UI con su `last_ai_suggest_at` actualizado
- **THEN** el admin ve los nuevos pending al refrescar la pestaña y puede aprobarlos en lote

#### Scenario: Corrida automática coincide con botón manual
- **WHEN** la próxima corrida automática está por disparar Y el admin clickea "AI Suggest" en la UI al mismo tiempo
- **THEN** `withoutOverlapping(10)` previene que arranquen dos procesos LLM-burning simultáneos: el que llegó primero mantiene el lock 10 minutos; el otro espera o se salta (comportamiento determinista de `withoutOverlapping`)

#### Scenario: Corrida automática no descarrila por cambios de presupuesto
- **WHEN** el admin configura `enabled=false` en AI Settings (UI)
- **THEN** la corrida automática sale con warn `LLM_CORRECTION_ENABLED=false` y `exit 0` sin gastar tokens (defensa existente en `AiSuggestEnEsCorrectionsCommand::handle()`)

#### Scenario: Log persistente permite diagnosticar sin re-correr
- **WHEN** el admin o Kilo necesita ver qué hizo la última corrida automática
- **THEN** `storage/logs/ai-suggest-scheduled.log` contiene el stdout de la corrida (línea de inicio `AI suggest EN↔ES: days=1 sample=200 model=...`, contadores `Mined/Inserted/Skipped/Rejected`, cualquier warn/error del post-filtro)
- **THEN** NO se requiere re-correr el comando para diagnóstico — basta `tail -100 storage/logs/ai-suggest-scheduled.log`

---


### Requirement: AI suggest inserta correcciones con estado configurable (pending o approved)

El sistema SHALL permitir al admin configurar vía `LlmCorrectionSettings::bool('auto_approve')` (override desde `/ia/correcciones → AI Settings`) o vía flag CLI `--auto-approve` en `corrections:ai-suggest`, si las correcciones detectadas por el suggester LLM-powered se insertan como `pending` (revisión manual) o como `approved` (aplicación automática a SRT nuevos + retroactivo manual). Cuando auto-approve está activo, las correcciones SHALL insertarse con `status='approved'`, `approved_by` igual al admin invocante, `approved_at` igual a `now()`, y se registrar el total auto-aprobado en el log final del comando (`Auto-approved: N`). El filtro defensivo (`LlmCorrectionSuggester::looksLikeBrandOrProperNoun` + lista `protected_brands`) SHALL seguir aplicando antes de la inserción. Las correcciones rechazadas por el filtro SHALL contar en `rejected_by_filter` y NUNCA insertarse, ni en auto-approve ni en pending. La reversión de un auto-aprobado erróneo SHALL hacerse con el botón "Eliminar" de la tabla de aprobadas (operación existente).

#### Scenario: Admin corre `corrections:ai-suggest` con auto-approve activo
- **WHEN** el setting `auto_approve=true` y el LLM devuelve un candidato "in las ofertas nunca termina" → "en las ofertas nunca termina"
- **THEN** la fila se inserta en `corrections` con `status='approved'`, `approved_by=<admin>`, `approved_at=<timestamp>`, `source='ai-suggest-YYYY-MM-DD'`
- **THEN** el log final imprime `Auto-approved: 1` además de `Inserted: 1`
- **THEN** la nueva corrección se aplica automáticamente al próximo `corrections:apply-run` retroactivo y al próximo SRT nuevo (vía `CorrectionService::applyToSegments`)

#### Scenario: Admin apaga auto-approve en AI Settings
- **WHEN** el admin cambia el toggle `auto_approve` a false desde `/ia/correcciones → AI Settings`
- **THEN** la próxima corrida (manual o cron) inserta con `status='pending'`, mostrando la fila en la pestaña "Pendientes" para revisión

#### Scenario: Auto-aprobado inserta basura por falso positivo del filtro
- **WHEN** una corrección auto-aprobada resulta ser errónea (ej. false positive que el filtro defensivo no detectó)
- **THEN** el admin abre la pestaña "Aprobadas", selecciona la fila y usa "Eliminar" (botón existente en cada fila)
- **THEN** la fila desaparece del diccionario activo y deja de aplicar a SRT nuevos

#### Scenario: Open English es marca y queda excluida
- **WHEN** un segmento contiene "Open English" como término
- **THEN** cualquier candidato cuyo `wrong` matchee Open English (completo o sub-frase) es rechazado por `looksLikeBrandOrProperNoun` antes de inserción
- **THEN** `Open English` queda intacta en el `text` corregido

---


### Requirement: `protected_brands` incluye empresas hispanas de enseñanza de inglés y otras marcas regionales
La lista `protected_brands` en `config/llm-correction.php` SHALL incluir explícitamente: `'open english'`, `'openenglish'`, `'ef education first'`, `'british council'`, `'epm'`, `'isa'`, `'grupo argos'`, `'nutresa'`, además de las marcas software/hardware/medios ya existentes. El admin SHALL poder agregar nuevas entradas sin tocar código más allá del config (rotación rápida ante incidentes). El prompt del sistema y el post-filtro PHP SHALL consumir esta misma lista como única fuente de verdad.

#### Scenario: Open English entra a la lista
- **WHEN** el admin agrega 'open english' a `config/llm-correction.php` y corre `php artisan config:clear`
- **THEN** `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Open English')` retorna `true` (incluyendo variantes de capitalización)
- **THEN** el prompt del sistema contiene "do NOT propose changes on: open english" en la lista de marcas protegidas

#### Scenario: Empresa regional queda excluida
- **WHEN** un segmento contiene "EPM" o "ISA" como sigla
- **THEN** el post-filtro regex de "sigla todo mayúsculas" los marca como `looksLikeBrandOrProperNoun=true` y no se traducen al español

---

### Requirement: Tablas de pendientes y aprobadas soportan búsqueda libre y filtro por origen
Las pestañas **Pendientes** y **Aprobadas** del módulo `/ia/correcciones` SHALL exponer: (1) un input `<input type="search">` que filtra las filas cuyo `wrong_text` o `correct_text` contenga el texto (case-insensitive), (2) un dropdown de filtro por `source` con conteo por source y opción "Todos", (3) un indicador "X visibles / Y totales" cuando hay filtro activo. La pestaña Aprobadas SHALL cargarse vía AJAX (`GET /correcciones/approved`) con paginación server-side: la búsqueda y el filtro por source se envían como query params y la tabla muestra solo la página solicitada (default 50 ítems), con controles de paginación y selección en lote que se acumula a través de páginas.

#### Scenario: Admin busca "Open English" en la tabla de aprobadas
- **WHEN** el admin escribe "open english" en el campo de búsqueda de la pestaña Aprobadas
- **THEN** el cliente pide `GET /correcciones/approved?search=open+english` y las filas cuyo `wrong_text` o `correct_text` contengan "open english" se muestran paginadas; las demás se ocultan
- **THEN** el indicador muestra "X visibles / Y totales"

#### Scenario: Admin filtra por source=ai-suggest en Pendientes
- **WHEN** el admin selecciona `source='ai-suggest-YYYY-MM-DD'` en el dropdown
- **THEN** solo las correcciones de ese lote se muestran
- **THEN** el indicador muestra "M visibles / N totales"

#### Scenario: Pestaña Aprobadas carga vía AJAX
- **WHEN** el admin hace click en la pestaña "Aprobadas"
- **THEN** se dispara `GET /correcciones/approved` y la tabla se puebla con la primera página desde la respuesta JSON (sin recargar la página)
- **WHEN** hay 0 aprobadas
- **THEN** se muestra "No hay correcciones aprobadas"

---

### Requirement: Sub-tab "AI Suggest Results" muestra historial de auto-aprobaciones
El módulo `/ia/correcciones` SHALL exponer una sub-tab "AI Suggest Results" accesible desde el sidebar, alimentada por `GET /correcciones/ai-suggest-results`, que retorna: (1) el resumen de las últimas 5 corridas AI Suggest (`source`, `last_run_at`, `count_auto_approved`, `count_pending`, `count_rejected`), (2) la lista de correcciones auto-aprobadas por AI Suggest paginada (default 50 por página) con búsqueda libre y filtro por source aplicados en el servidor. La sub-tab SHALL mantener la misma estética de tabla y soportar filtro por fecha del lote.

#### Scenario: Admin revisa historial de auto-aprobaciones
- **WHEN** el admin hace click en "AI Suggest Results"
- **THEN** la página muestra dos secciones: "Resumen de corridas" (5 últimas) y "Auto-aprobadas" (tabla paginada con búsqueda libre)
- **THEN** el admin puede buscar por texto (`wrong_text` o `correct_text`) y filtrar por `source` (fecha del lote), paginando el resultado
- **THEN** las correcciones auto-aprobadas incorrectas pueden eliminarse con el mismo botón "Eliminar" que las demás

---


### Requirement: Admin puede gestionar exclusiones dinámicas desde UI
El sistema SHALL exponer `/ia/correcciones → Exclusiones`, una pestaña top-level (entre "Aprobadas" y "Contexto sensible") donde el admin puede agregar, archivar y restaurar términos que el AI Suggest NUNCA va a traducir (ej. eventos comerciales como "Black Friday", "San Valentín"; marcas regionales como "Open English"; nombres propios recurrentes en emisiones específicas). La pestaña SHALL mostrar un badge morado con el conteo de exclusiones activas (`archived_at IS NULL`). El icono de la pestaña SHALL ser `fa-ban`, distinto del `fa-shield-halved` usado por "Contexto sensible". Cada exclusión SHALL persistir en la tabla `correction_protected_terms` con metadatos `term`, `category`, `notes`, `created_by`, `created_at`, `archived_at`. El motor `LlmCorrectionSuggester::looksLikeBrandOrProperNoun` SHALL consultar estas exclusiones (concat. con la lista `protected_brands` del config) tanto en el system prompt del LLM como en el post-filtro PHP. Los cambios SHALL aplicar a la próxima corrida en ≤5 minutos por cache TTL. Términos múltiples palabras y caracteres especiales del español (ñ, tildes) SHALL soportarse correctamente.

#### Scenario: Admin agrega "Black Friday" desde UI
- **WHEN** el admin abre `/ia/correcciones → Exclusiones → Agregar exclusión`, escribe `black friday`, categoría `event`, notas `Black Friday NO se traduce — nombre propio del evento comercial`, y guarda
- **THEN** la fila aparece en la tabla de Exclusiones activas con `category=event`, `notes=<texto>`, `created_by=<admin>`, `created_at=<timestamp>`
- **THEN** una nueva fila en `correction_protected_terms` con `term='black friday'` (lowercase normalizado) y `archived_at=null`
- **THEN** en ≤5 minutos, la próxima corrida de `corrections:ai-suggest` rechaza cualquier candidato cuyo `wrong` matchee "black friday" (completo o sub-frase) y lo cuenta en `rejected_by_filter`

#### Scenario: Admin archiva un término que ya no aplica
- **WHEN** el admin hace click en "Archivar" junto a la fila "open english"
- **THEN** la fila desaparece del listado activo y aparece en el listado archivadas con `archived_at=<timestamp>`
- **THEN** `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Open English')` retorna `false` para el término archivado (y se exceptúa si no hay otra coincidencia vía `protected_brands` estático)

#### Scenario: Admin restaura una exclusión archivada
- **WHEN** el admin activa "Mostrar archivadas" y hace click en "Restaurar" junto a una fila archivada
- **THEN** la fila vuelve a estar activa (sin duplicar) y se quita de archivadas

#### Scenario: Admin intenta agregar un término duplicado
- **WHEN** el admin intenta agregar "black friday" cuando ya existe activo
- **THEN** el endpoint responde 422 con `error: "'black friday' ya existe entre las exclusiones activas."` y la fila no se duplica

#### Scenario: Término multi-palabra con caracteres especiales
- **WHEN** el admin agrega "San Valentín" y luego un SRT contiene "el san valentín más esperado"
- **THEN** el post-filtro `str_contains` lo detecta case-insensitive (`'san valentín' ⊂ 'el san valentín más esperado'`) y rechaza el candidato
- **THEN** el system prompt del LLM incluye "do NOT propose changes on: san valentín" en la lista combinada `protected_brands` ∪ exclusiones dinámicas

#### Scenario: Cache TTL entre corrida y admin
- **WHEN** el admin agrega "Copa América" a las 10:00 y la última corrida AI Suggest fue a las 09:30 con cache de la lista en memoria
- **THEN** la próxima corrida a las 11:00 (≤5 min) ya ve "copa américa" en la lista. Si el admin necesita efecto inmediato, puede correr `php artisan cache:forget correction_protected_terms:active`

#### Scenario: Atajo en tabla de pendientes
- **WHEN** el admin está revisando la tabla de Pendientes, encuentra una fila con `wrong_text="Open English"`, y hace click en el botón "Excluir" de la fila
- **THEN** se abre un modal pre-llenado con `term="Open English"`, `notes="Agregada desde pendientes — corrección #<id>: Open English → ..."`
- **WHEN** el admin ajusta el `term` a `"open english"` (lowercase) y hace click en "Agregar exclusión"
- **THEN** el endpoint responde 201 con la fila creada
- **THEN** el modal cierra con toast verde "Exclusión 'open english' agregada"
- **THEN** la fila de pendientes sigue siendo pending (no se aprobó ni rechazó automáticamente)

#### Scenario: Bulk excluir seleccionadas en Pendientes
- **WHEN** el admin selecciona 3 pendientes distintas y hace click en el botón "Excluir 3" del bottom-bar bulk
- **THEN** se abre un modal bulk con textarea "Nota compartida" y checkbox "Enumerar notas con índice"
- **WHEN** el admin guarda
- **THEN** el endpoint recibe `{terms: [{term, notes: "Limpieza batch — #1"}, ...]}` (3 ítems)
- **THEN** la respuesta es 201 con 3 ids o 207 si alguna es duplicada, mostrando toast "3 creadas, 0 duplicadas"

#### Scenario: Atajo desde Aprobadas
- **WHEN** el admin revisa la tabla de Aprobadas, ve una fila que considera exclusión válida, y hace click "Excluir" en esa fila
- **THEN** el modal se abre pre-llenado con el `wrong_text` aprobado
- **WHEN** el admin guarda
- **THEN** la corrección queda aprobada (sigue activa) y se crea la exclusión en paralelo
- **THEN** si el admin luego quiere revertir la aprobación, usa el botón "Eliminar" existente; la exclusión queda independientemente

#### Scenario: Cambio aplica en próxima corrida AI Suggest
- **WHEN** el admin agrega una exclusión por atajo (de Pendientes o Aprobadas) a las 10:00
- **THEN** en ≤5 minutos (cache TTL), la próxima corrida AI Suggest ve el nuevo término en su lista de exclusiones dinámicas y lo cuenta en `rejected_by_filter` si el LLM lo intenta proponer de nuevo

---


### Requirement: Atajo "Excluir" archiva la corrección asociada en la misma operación
Cuando el admin hace click "Excluir" en una fila de Pendientes o Aprobadas (atajo contextual) y la creación de la exclusión devuelve 201, la corrección asociada SHALL archivarse automáticamente (`status='rejected'`, `rejected_reason='moved_to_exclusion: <term>'`) en la misma transacción HTTP. La fila SHALL desaparecer del tab Pendientes o Aprobadas al refrescar la lista. Lo mismo aplica al bulk "Excluir N seleccionadas": cada corrección vinculada se archiva con su motivo trazable. El alta manual desde la pestaña top-level "Exclusiones" (sin `correction_id`) NO archiva ninguna corrección porque no hay association.

#### Scenario: Admin excluye una pendiente y la fila desaparece
- **WHEN** el admin clickea "🛡 Excluir" en una fila de Pendientes, modal pre-llenado aparece con `correction_id=<id>`, ajusta el término y guarda
- **THEN** el backend crea la exclusión (201) Y archiva la corrección (`status='rejected'`, `rejected_reason='moved_to_exclusion: <term>'`) en la misma respuesta HTTP
- **THEN** la UI muestra toast verde "Exclusión agregada + 1 corrección archivada"
- **THEN** la UI recarga `loadPending()` y la fila desaparece del tab Pendientes

#### Scenario: Bulk excluir N archivamientos múltiples
- **WHEN** el admin selecciona 3 pendientes y clickea "🛡 Excluir 3" en el bottom-bar bulk, modal aparece, guarda
- **THEN** el backend crea 3 exclusiones y archiva 3 correcciones con sus respectivos motivos `moved_to_exclusion: <term>`
- **THEN** la UI recarga `loadPending()` y desaparecen las 3 filas del tab Pendientes
- **THEN** el toast muestra "3 creadas, 0 duplicadas, 3 archivadas"

#### Scenario: Si la exclusión falla por duplicado, no se archiva la corrección
- **WHEN** el admin clickea Excluir en una fila cuyo `wrong_text` ya es una exclusión activa
- **THEN** el backend devuelve 422 con `error="'X' ya existe entre las exclusiones activas."`
- **THEN** la corrección NO se archiva (mantiene su status actual)
- **THEN** el toast rojo muestra el mensaje del backend

#### Scenario: Alta manual desde pestaña Exclusiones NO archiva
- **WHEN** el admin abre `/ia/correcciones → Exclusiones → Agregar exclusión` y guarda un término sin `correction_id`
- **THEN** solo se crea la exclusión; ninguna corrección se archiva (no hay association)
- **THEN** la fila en Pendientes/Aprobadas queda intacta

---


### Requirement: Botón "Ver" del banner de proceso retroactivo abre modal de detalle de progreso

El banner de "Re-aplicar en curso" SHALL exponer un botón **"Ver"** que abre un modal dedicado al detalle del progreso cuando ya existe un run vivo (`runId` set). El modal SHALL mostrar:
- Texto de status (`runStatusText` traducida: "En cola…" / "Procesando…" / "Terminada" / "Falló").
- Barra de progreso con porcentaje (`runProgressPct`) y contador (`runProgress`).
- Aviso ámbar "Sin avances desde las HH:MM" si la corrida está estancada (`runStuck`).
- Botón **"Refrescar estado"** que ejecuta `pollRun()` inmediatamente (sin esperar el intervalo de 2s).
- Botón **"Cerrar"** que cierra el modal sin alterar el estado del run subyacente.

El modal SHALL **NO** mostrar el selector de scope ni el botón "Confirmar y aplicar" — el admin no debe poder lanzar una segunda corrida mientras otra está en curso (esa lógica ya vive en el 409 anti-duplicados del backend).

#### Scenario: Admin recarga la página, ve el banner y hace click "Ver"
- **WHEN** el admin recarga `/ia/correcciones` mientras hay un run retroactivo en curso, ve el banner "Re-aplicar en curso · X%", y hace click en "Ver"
- **THEN** se abre un modal con la barra de progreso, contador de segmentos, status text, y un aviso ámbar si está estancado
- **THEN** el modal **NO** muestra dropdown de scope ni el botón "Confirmar y aplicar"
- **WHEN** el admin hace click en "Refrescar estado"
- **THEN** se ejecuta un poll inmediato a `/ia/correcciones/apply-retroactive/{runId}` y el modal re-renderiza con los datos frescos
- **WHEN** el admin hace click en "Cerrar"
- **THEN** el modal cierra sin resetear `runId`, sin afectar el polling del banner, y el run sigue corriendo en background

#### Scenario: Botón "Re-aplicar" del header sigue abriendo modal de nuevo launch
- **WHEN** el admin clickea el botón "Re-aplicar" del header del módulo (no hay run en curso)
- **THEN** se abre el modal de launch con dropdown de scope y botón "Confirmar y aplicar" (comportamiento existente, no roto por este cambio)

#### Scenario: No se puede ver progreso en modal cuando no hay run vivo
- **WHEN** el admin hace click en "Re-aplicar" sin haber ningún run vivo
- **THEN** el modal muestra el dropdown de scope — la vista de progreso solo aparece cuando hay `runId` (banner minimizado no se muestra sin run, así que el admin no llega a "Ver")

#### Scenario: Modal de progreso se cierra automáticamente al terminar el run
- **WHEN** el poll detecta `status='done'` y el admin tiene el modal abierto
- **THEN** el polling se detiene (no más `/apply-retroactive/{id}`), el banner se oculta, el toast verde de éxito ya se mostró; el modal puede quedar abierto con su último estado, pero el botón "Refrescar" desaparece (no queda nada que refrescar)

---

### Requirement: El pase IA propone pares aprendidos como correcciones pending

El sistema SHALL, cuando el pase de coherencia IA corrige un segmento, extraer los pares `wrong → correct` (diferencia entre `text_raw` y `text`) y proponerlos como correcciones `pending` para revisión humana.

#### Scenario: Segmento corregido por IA genera un par aprendido
- **WHEN** el pase IA corrige "in this moment" → "en este momento" en un segmento
- **THEN** el sistema extrae el par `wrong="in this moment"`, `correct="en este momento"`
- **AND** lo inserta como `Correction` con `status=pending`, `source='ai-coherence-learn'`, `risk_level=medium`

#### Scenario: Par ya existente no se duplica
- **WHEN** el par extraído ya existe como `pending` o `approved` (mismo `wrong_normalized`)
- **THEN** el sistema NO crea duplicado (idempotencia)

#### Scenario: Par de baja calidad se descarta
- **WHEN** el par es un nombre propio, marca, o un segmento entero (> 4 palabras)
- **THEN** el sistema NO lo propone (filtro de calidad)

#### Scenario: Tope por transcripción
- **WHEN** una transcripción genera más de N pares aprendidos
- **THEN** el sistema solo propone los primeros N (control de volumen)

#### Scenario: Admin aprueba el par aprendido
- **WHEN** el admin aprueba un par `pending` con `source='ai-coherence-learn'`
- **THEN** entra al diccionario activo y se aplicará en la primera pasada de transcripciones futuras

---

### Requirement: Segmentos con inglés residual se corrigen con IA a español coherente

El sistema SHALL, al persistir los segmentos de una transcripción, aplicar un pase de coherencia IA sobre los segmentos que el diccionario no pudo corregir (inglés residual), produciendo un `text` en español coherente.

#### Scenario: Segmento con spanglish se corrige con IA
- **WHEN** un segmento tiene inglés residual (score >= `ai_coherence_threshold`) y `ai_coherence_enabled=true`
- **THEN** el sistema envía el segmento al LLM configurado y guarda el texto corregido en `text`
- **AND** `text_raw` conserva el original del transcriptor (inmutable)

#### Scenario: Segmento sin inglés residual no se toca
- **WHEN** un segmento tiene score < `ai_coherence_threshold`
- **THEN** el sistema NO lo envía al LLM (ahorro de costo/latencia) y conserva el texto del diccionario

#### Scenario: Tope de segmentos por transcripción
- **WHEN** una transcripción tiene más de `ai_coherence_max_segments` segmentos flagged
- **THEN** el sistema solo corrige los primeros N (los más recientes) y deja el resto con el texto del diccionario

#### Scenario: Fallo del LLM no rompe el parseo
- **WHEN** el LLM falla (timeout, HTTP error, respuesta inválida)
- **THEN** el sistema conserva el texto del diccionario (sin IA) y loguea el error
- **AND** la transcripción se guarda normalmente (state=done)

#### Scenario: Nombres propios y marcas se respetan
- **WHEN** el LLM corrige un segmento con nombres propios (Cali, Bogotá, Quindío) o marcas
- **THEN** el texto corregido conserva esos nombres sin alterarlos

---

### Requirement: Bugfix de mutación del array en `applyToSegments`

El método `CorrectionService::applyToSegments(array $segments): array` SHALL retornar el array de segmentos con `text` corregido. La mutación SHALL hacerse por referencia de índice sobre el array original, no sobre una copia local. `TranscriptionProcessor` SHALL consumir el return y reemplazar el array de segmentos en su scope para que los `INSERT` posteriores escriban `text` ya corregido (no el `text_raw` del transcriptor).

#### Scenario: El retorno del método se usa en TranscriptionProcessor
- **WHEN** `TranscriptionProcessor::processDoneWithSrt()` llama `applyToSegments($segmentsForCorrections)`
- **THEN** el return reemplaza `$segmentsForCorrections` en el scope del proceso y los `INSERT` posteriores escriben `text` ya corregido

---

### Requirement: Bugfix de CSRF selector en UI admin

El selector `document.querySelector('meta[name="csrf-token"]')` SHALL estar correctamente cerrado en todos los scripts de Alpine.js que hacen fetch al backend. El bug pre-existente en `ia/correcciones/index.blade.php:213` bloqueaba las acciones Alpine (aprobar, rechazar, nueva, re-aplicar) con error de sintaxis silencioso, dejando la página sin handlers funcionales.

#### Scenario: Acciones Alpine funcionales
- **WHEN** el admin en `/ia/correcciones` hace click en "Aprobar" sobre una corrección pendiente
- **THEN** la petición POST a `/ia/correcciones/{id}/approve` se envía con header `X-CSRF-TOKEN` válido y la consola del navegador no muestra error de sintaxis

---

### Requirement: Seed inicial con realizaciones reales del corpus

El sistema SHALL disponer de un seeder `CorreccionesDictionarySeeder` que inserta las correcciones detectadas en el corpus de producción. Las realizaciones iniciales son:

```
"Active to"            → "Activa tu"
"active to"            → "Activa tu"
"valor the time"       → "valorar el tiempo"
"orgular"              → "orgullo"
"with orgasm"          → "with orgullo"
"applicate vacunes"    → "aplicarse vacunas"
```

Estas realizaciones fueron detectadas en la cuña radial "Bogotá Modo Metro" repetida en al menos 7 emisoras el 2026-07-24.

#### Scenario: Seeder es idempotente
- **WHEN** el admin corre `php artisan db:seed --class=CorreccionesDictionarySeeder` dos veces
- **THEN** la tabla `corrections` no tiene duplicados gracias a `upsertApproved()` que actualiza el `correct_text` de la fila approved existente

#### Scenario: Seeder requiere admin
- **WHEN** el seeder corre y no existe un usuario con `role='admin'`
- **THEN** el seeder aborta con mensaje claro

---

### Requirement: Comando async con `runId` desacoplado de la petición HTTP

El sistema SHALL exponer `php artisan corrections:apply-run {--run-id=required} {--dry-run} {--chunk=500}` que ejecuta el retroactivo como un proceso desacoplado de la petición HTTP. El `runId` es una clave de `Cache` con TTL 4h que mantiene el estado de la corrida (`corrections_apply:{runId}`). El controller SHALL generar el `runId`, inicializar la cache y lanzar el comando vía `execBackground("php artisan corrections:apply-run --run-id={runId} --chunk=500")`, retornando el `runId` al cliente para que haga polling.

#### Scenario: Comando se lanza desde controller
- **WHEN** el controller recibe `POST /ia/correcciones/apply-retroactive` con `{dry_run: false, chunk: 500}`
- **THEN** genera `runId`, inicializa cache, ejecuta `execBackground("php artisan corrections:apply-run --run-id={runId} --chunk=500")`, retorna `{runId}` al cliente

#### Scenario: Cache tiene TTL apropiado
- **WHEN** el comando inicia una corrida
- **THEN** la cache key `corrections_apply:{runId}` tiene TTL 4h, suficiente para una corrida real (~2h) con margen

#### Scenario: Comando aborta si runId no existe
- **WHEN** se ejecuta `php artisan corrections:apply-run --run-id=orphan`
- **THEN** el comando aborta con mensaje claro y no modifica BD

#### Scenario: Admin lanza desde CLI en dry-run
- **WHEN** el admin corre `php artisan corrections:apply-run --run-id=cli_<timestamp> --dry-run`
- **THEN** el sistema cuenta cuántos segmentos serían modificados con el diccionario actual sin tocar la BD

#### Scenario: Polling refleja estado durante corrida
- **WHEN** el cliente hace `GET /ia/correcciones/apply-retroactive/{runId}` durante una corrida
- **THEN** recibe `{status: 'running', progress: <last_id>, total: <count>, updated: <count_so_far>, started_at, finished_at, error_message}`

---

### REMOVED: Endpoint `preview-retroactive`

El endpoint `GET /ia/correcciones/preview-retroactive` (que ejecutaba un dry-run completo dentro de la petición HTTP) SHALL ser eliminado y reemplazado por el flujo async `POST /ia/correcciones/apply-retroactive` (lanzar runId) + `GET /ia/correcciones/apply-retroactive/{runId}` (polling). El endpoint preview SHALL responder 404; la UI ya no debe llamarlo.

#### Scenario: Eliminación del endpoint preview
- **WHEN** la UI solicita el preview del retroactivo
- **THEN** el endpoint devuelve 404; la UI debe lanzar el runId para tener el conteo real

---

### Requirement: Diccionario se bootstrappea desde análisis de corpus

El sistema SHALL permitir al admin sembrar el diccionario con N correcciones detectadas en una pasada de análisis sobre `transcription_segments.text_raw` reciente. Las detecciones de **alta confianza** (≥2 apariciones del wrong en la muestra y forma correcta más frecuente en el corpus, o ratio wrong:right ≥1:2) entran como `status=approved` y aplican inmediatamente. Las de **confianza media** (≥1 aparición y forma correcta existente) entran como `status=pending` para revisión admin. Cada corrección del bootstrap SHALL tener `source='bootstrapping-YYYY-MM-DD'` para permitir rollback selectivo.

#### Scenario: Bootstrap de alta confianza aplica a nuevos SRT
- **WHEN** el admin corre el seeder de bootstrap que inserta `in the world → en el mundo` con `source='bootstrapping-2026-07-29'` y `status='approved'`
- **THEN** los SRT nuevos que lleguen con `text_raw` conteniendo `in the world` se guardan con `text="...en el mundo..."` automáticamente.

#### Scenario: Bootstrap de confianza media queda en cola
- **WHEN** el seeder inserta `region → región` con `status='pending'` (caso donde `region` puede ser verbo válido en algunos contextos)
- **THEN** el admin ve la corrección en `/ia/correcciones` pestaña "Pendientes" y decide aprobar/rechazar manualmente.

#### Scenario: Rollback selectivo por source
- **WHEN** el admin ejecuta `UPDATE corrections SET status='rejected' WHERE source='bootstrapping-2026-07-29' AND applies_count < 5` para revertir reglas de bajo impacto
- **THEN** las reglas afectadas dejan de aplicar al próximo SRT nuevo y al próximo retroactivo. Las correcciones con `applies_count >= 5` se mantienen (señal de impacto real).

---

### Requirement: Bugfix de word-boundary en `applyToText`

El sistema SHALL garantizar que las correcciones NO apliquen cuando `wrong_normalized` aparece como substring dentro de otra palabra. La capa de matching SHALL usar `\b` (PCRE word-boundary) alrededor de `wrong_normalized` en lugar de `str_ireplace` plano.

#### Scenario: "Active to" ya no rompe "attractive"
- **WHEN** existe la corrección `Active to → Activa tu` (status=approved) y llega un segmento con `text_raw="the attractive touristic destination"`
- **THEN** `text` queda `"the attractive touristic destination"` (palabra intacta). NO se produce `"the attrActiva tuuristic destination"`.

#### Scenario: "in the world" sigue matcheando como frase completa
- **WHEN** existe la corrección `in the world → en el mundo` y llega `text_raw="peace in the world today"`
- **THEN** `text` queda `"peace en el mundo today"` (frase completa reemplazada). El `\b` al inicio y fin de la frase permite matchear entre espacios/puntuación.

#### Scenario: Frase con puntuación al borde matchea
- **WHEN** llega `text_raw="the situation, in the world of politics,"`
- **THEN** `text` queda `"the situation, en el mundo de politics,"`. La coma actúa como borde de palabra válido.

#### Scenario: Orden por longitud DESC preservado
- **WHEN** existen correcciones `the world` (corta) y `in the world` (larga) y el texto contiene `in the world`
- **THEN** se aplica primero la larga (`in the world → en el mundo`); la corta queda sin match (correcto, porque `\b the world\b` no matchea dentro de "en el mundo" ya procesado).

---

### Requirement: System can automatically detect EN-ES mix patterns in transcriptions

El sistema SHALL exponer un miner (`App\Services\Ia\EnEsMixMiner`) con dos estrategias:

**A. Mapeos conocidos**: una lista hardcoded de frases EN que el ASR mete en español con su reemplazo natural ES. El miner cuenta cuántos segmentos del corpus las contienen (filtrado por `days_back`); si supera `min_freq` (default 3) y no está en el diccionario, propone como pending.

**B. Detección abierta**: tokeniza segmentos y busca secuencias `FUNCTION_EN + NOUN_ES` donde `FUNCTION_EN` ∈ {the, a, in, of, on, at, by, for, with, to, from, and, or, but, is, are, was, were, this, that, ...} y `NOUN_ES` ∈ lista de sustantivos comunes en español. Si la frecuencia en español >> frecuencia en inglés, sugiere reemplazo con heurística `prep_es + article + noun`.

#### Scenario: "in the world" aparece 200 veces en los últimos 30 días
- **WHEN** `php artisan corrections:mine-en-es --days=30 --min-freq=3` se ejecuta
- **AND** "in the world" aparece 200 veces en el corpus reciente
- **AND** no hay una approved correction con `wrong_normalized='in the world'`
- **THEN** el miner retorna un candidato `{wrong: "in the world", correct: "en el mundo", freq: 200, strategy: "known"}`.

#### Scenario: "in the world" ya está aprobado
- **WHEN** el diccionario tiene `wrong_normalized='in the world'` approved
- **THEN** el miner NO lo propone (ya cubierto).

#### Scenario: Frecuencia baja no genera candidato
- **WHEN** "in the world" aparece 2 veces en los últimos 30 días (por debajo de min_freq=3)
- **THEN** el miner NO lo propone.

---

### Requirement: Admin can trigger a mining pass on demand

El sistema SHALL exponer el comando `php artisan corrections:mine-en-es` con flags:
- `--days=N` (default 30): ventana de análisis
- `--min-freq=N` (default 3): frecuencia mínima para proponer
- `--strategy=known|open|both` (default both)
- `--dry-run`: solo muestra candidatos, no inserta

El comando invoca `CorrectionService::mineEnEsMix()` que es idempotente: si la regla ya está pending, no la duplica. Las reglas minadas se identifican con `source='mining-YYYY-MM-DD'`.

#### Scenario: Admin corre mining en dry-run para revisar primero
- **WHEN** admin corre `php artisan corrections:mine-en-es --days=30 --dry-run`
- **THEN** se imprime una tabla con `wrong, correct, freq, strategy` y NO se inserta nada.

#### Scenario: Admin corre mining real
- **WHEN** admin corre `php artisan corrections:mine-en-es --days=30`
- **THEN** se insertan N filas en `corrections` con `status='pending'` y `source='mining-2026-07-30'`.
- **THEN** la respuesta del comando muestra `Mined: X, Inserted: Y, Skipped: Z`.

#### Scenario: Mining idempotente
- **WHEN** admin corre mining 2 veces seguidas
- **THEN** la segunda corrida no duplica las pending existentes.

---

### Requirement: Mining runs weekly via scheduler

El sistema SHALL agendar `corrections:mine-en-es --days=14 --min-freq=5` los domingos a las 02:00 via `Schedule::command()` en `routes/console.php`. El scheduler usa `withoutOverlapping(120)` para evitar conflictos con retroactivo. El admin puede revisar los candidatos generados en `/ia/correcciones` con la bulk moderation UI.

#### Scenario: Cron ejecuta mining automático
- **WHEN** el scheduler dispara el comando cada domingo 02:00
- **THEN** se ejecuta el miner con la ventana de 14 días y min_freq=5
- **AND** los candidatos generados se acumulan como pending
- **AND** el admin los ve la próxima vez que abra `/ia/correcciones`.

#### Scenario: Mining y retroactivo corriendo en paralelo
- **WHEN** hay un `corrections:apply-run` activo y se dispara el miner
- **THEN** el `withoutOverlapping(120)` previene que ambos corran al mismo tiempo.
- **THEN** el miner espera (hasta 120 min) o se skipea si el lock no se libera.

---

### Requirement: Admin can prune inactive correction rules in bulk

El sistema SHALL permitir al admin identificar y eliminar en bulk las correcciones aprobadas con `applies_count = 0` (reglas que nunca se han aplicado) creadas hace más de N días.

#### Scenario: Admin audita la efectividad del diccionario

- **WHEN** admin ejecuta `php artisan corrections:dictionary-audit`
- **THEN** el comando imprime un reporte con: totales por status, distribución de `applies_count` en buckets (0 / 1-5 / 6-20 / 21-100 / 100+), top 30 unigramas/bigramas/trigramas dentro de `wrong_text`, conteo de duplicados exactos y conflictos, conteo de clusters con Jaccard ≥60%.

#### Scenario: Admin filtra y elimina inactivas desde la UI

- **WHEN** admin está en `/ia/correcciones` tab Aprobadas y activa el filtro "Solo inactivas" con "Creadas hace más de 30 días"
- **THEN** la tabla muestra solo las correcciones con `applies_count = 0` y `created_at <= now() - 30 días`.
- **AND** aparece un botón naranja "Eliminar N inactivas".
- **WHEN** admin hace click en el botón y confirma el modal
- **THEN** el frontend llama `POST /ia/correcciones/bulk-destroy-inactive` con `{min_age_days: 30, max_count: 500}`.
- **THEN** el backend devuelve `{destroyed, bulk_action_id, undo_expires_at}`.
- **AND** la lista de aprobadas se recarga sin esas filas.

#### Scenario: Bulk destroy respeta cap defensivo

- **WHEN** hay 5,000 inactivas candidatas y `max_count: 500`
- **THEN** el endpoint destruye solo las 500 más antiguas (ordenadas por `id` asc) y devuelve `destroyed: 500`.
- **AND** un segundo POST con los mismos parámetros destruiría las siguientes 500.

#### Scenario: Bulk destroy registra la acción

- **WHEN** el endpoint destruye N correcciones
- **THEN** se crea una fila en `correction_bulk_actions` con `action='bulk_destroy'`, `actor_user_id`, `created_at`, `expires_at = created_at + config('corrections.undo_window_minutes')`.
- **AND** se crean N filas en `correction_bulk_action_items` con los `correction_id` borrados.

#### Scenario: UI sort por applications

- **WHEN** admin hace click en el header de la columna "Aplicaciones"
- **THEN** las filas se ordenan asc/desc por `applies_count`.
- **AND** un ícono de flecha indica la dirección actual del sort.

#### Scenario: Badge de effectiveness

- **WHEN** admin ve una corrección aprobada
- **THEN** la fila muestra un dot de color junto al `applies_count`: verde (≥100), ámbar (1-99), rojo (0).
- **AND** el dot permite identificar visualmente las reglas "killer" vs las inactivas.

### Requirement: Admin can see atomicity suggestions for any approved correction

El sistema SHALL, para cada corrección aprobada, extraer los tokens sueltos (unigramas) y bigramas contenidos en su `wrong_text` que aún NO estén en el diccionario como standalone, y proponer traducciones tentativas basadas en la frecuencia de uso en otras correcciones aprobadas.

#### Scenario: Admin expande atomicity de una frase larga

- **WHEN** admin hace click en "Ver atomicidad" en una fila aprobada
- **THEN** el frontend llama `GET /ia/correcciones/{id}/atomicity-suggestions`.
- **AND** la respuesta es JSON con listas `unigrams`, `bigrams`, y `already_in_dict_unigrams`/`already_in_dict_bigrams`.
- **THEN** el panel renderiza los candidatos con checkboxes + un input `correct` editable inline.
- **AND** los candidatos con `confidence='high'` muestran el `suggested_correct` prellenado.
- **AND** los candidatos con `confidence='low'` muestran el campo `correct` vacío para que el admin lo llene.

#### Scenario: Confidence alta cuando hay consenso ≥80%

- **WHEN** un token aparece en 5 correcciones aprobadas y 4 de ellas lo traducen a la misma cadena
- **THEN** el sistema retorna `confidence='high'` y `suggested_correct=<esa cadena>`.

#### Scenario: Confidence baja cuando no hay consenso

- **WHEN** un token aparece en 4 correcciones aprobadas y se traduce a 3 cadenas distintas con distribución 50/25/25
- **THEN** el sistema retorna `confidence='low'` y `suggested_correct=null`.

#### Scenario: Dedupe contra diccionario existente

- **WHEN** un token ya está como corrección aprobada standalone (ej: `the` → `el`)
- **THEN** NO aparece en la lista de sugerencias atómicas.
- **AND** sí aparece en `already_in_dict_unigrams` para feedback visual.

#### Scenario: Admin acepta varias sugerencias atómicas en bulk

- **WHEN** admin marca 3 candidatos en el panel y hace click en "Agregar 3 como nuevas correcciones"
- **THEN** el frontend llama `POST /ia/correcciones/{id}/atomicity-suggestions/bulk-add` con `{items: [{wrong, correct}, ...]}`.
- **AND** el backend crea 3 correcciones nuevas con `status='approved'`, `source='atomicity-from-{parentId}'`, `proposed_by` y `approved_by` = admin actual.
- **THEN** el toast confirma "3 correcciones agregadas" y la lista de aprobadas se recarga con las nuevas filas.

#### Scenario: Stopwords filtradas

- **WHEN** la corrección es "the touristic attractives of the country"
- **THEN** la lista de unigramas sugeridos NO incluye `the` ni `of` (stopwords).
- **AND** SÍ incluye `touristic`, `attractives`, `country`.

### Requirement: LLM suggester prefers atomic (unigram/bigram) candidates over long phrases

El sistema SHALL, en el suggester LLM-powered (`corrections:ai-suggest`), sesgar la generación de candidatos hacia reglas atómicas (palabras sueltas y bigramas) y reportar métricas que permitan diagnosticar el efecto.

#### Scenario: System prompt incluye regla de atomicidad

- **WHEN** el suggester construye el system prompt
- **THEN** el prompt contiene una sección "REGLA DE ATOMICIDAD" que instruye al LLM a:
  - Preferir la versión más corta (palabra suelta) si aparece ≥3 veces en el corpus.
  - Solo proponer frases de >4 palabras si aparecen ≥8 veces textuales Y sus palabras constituyentes NO son traducibles individualmente.
  - Penalizar frases con >8 palabras salvo frecuencia ≥15.
  - No proponer nunca frases con >12 palabras.

#### Scenario: Post-filtro descarta frases largas con baja frecuencia

- **WHEN** el LLM retorna un candidato con `wrong` de 7 palabras y `freq: 2`
- **THEN** el post-filtro PHP lo descarta con `reason='rejected_by_length'`.
- **AND** el contador `rejected_by_length` se incrementa en el output del comando.

#### Scenario: LLM retorna atomic_candidates para frases largas aceptadas

- **WHEN** el LLM retorna un candidato principal con `wrong` de 9 palabras y `freq: 12` (válido)
- **AND** el JSON del LLM incluye un campo `atomic_candidates: [{wrong: "...", correct: "..."}, ...]`
- **THEN** el sistema extrae los `atomic_candidates` y los inserta como correcciones adicionales con `source='ai-suggest-YYYY-MM-DD-atomic-from-{parent_candidate_id}'`.
- **AND** el contador `promoted_to_atomic` se incrementa.

#### Scenario: Reporte final incluye contadores nuevos

- **WHEN** admin corre `php artisan corrections:ai-suggest --days=1`
- **THEN** el output final muestra:
  ```
  Mined: N
  Inserted: N
  Skipped (duplicate): N
  Rejected by filter: N  (marcas/siglas)
  Rejected by length: N  (frases demasiado largas)   ← NUEVO
  Promoted to atomic: N  (bigramas extraídos)        ← NUEVO
  ```

### Requirement: System flags context-shifting corrections and excludes them from automatic application

El sistema SHALL proteger el tono y contexto original de las transcripciones identificando correcciones cuyo `wrong_text` contiene patrones que cambian el registro (muletillas, falsos amigos, palabras ambiguas) y excluyéndolas del `applyToText()` automático.

#### Scenario: Columna risk_level en correcciones

- **WHEN** se ejecuta la migración `2026_08_02_xxxxxx_add_risk_level_to_corrections.php`
- **THEN** la tabla `corrections` tiene una nueva columna `risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'low'`.
- **AND** las correcciones existentes quedan con `risk_level='low'` (default), requiriendo un backfill explícito (`php artisan corrections:context-audit --apply`) para marcar las sensibles.

#### Scenario: Blocklist de muletillas y falsos amigos

- **WHEN** admin inspecciona `app/config/corrections.php` bajo `context_sensitive.terms`
- **THEN** existen dos listas: `filler_words` (≥11 entradas: like, you know, i mean, basically, literally, honestly, obviously, sort of, kind of, right, okay) y `false_friends` (≥7 entradas: actually, eventually, sensitive, sympathetic, actual, realize, eventual) con `risk` y `note` por entrada.

#### Scenario: ContextShiftAuditor detecta false friend unsafe

- **WHEN** una corrección aprobada tiene `wrong_text="actually"` y `correct_text="actualmente"`
- **AND** la blocklist marca `actually` con `unsafe=['actualmente']`
- **THEN** `ContextShiftAuditor::evaluateOne()` retorna `{risk: 'high', reason: "false friend: 'actually' translated as 'actualmente' (unsafe); safe: en realidad, de hecho, la verdad"}`.

#### Scenario: ContextShiftAuditor detecta filler word

- **WHEN** una corrección aprobada tiene `wrong_text="you know, it's complicated"` y `correct_text="sabes, es complicado"`
- **THEN** `evaluateOne()` retorna `{risk: 'high', reason: "contains 'you know' (muletilla)"}`.

#### Scenario: ContextShiftAuditor retorna null para corrección segura

- **WHEN** una corrección tiene `wrong_text="the president"` y `correct_text="el presidente"`
- **THEN** `evaluateOne()` retorna `null` (sin issue; no se sugiere cambio de risk).

#### Scenario: applyToText omite risk=high por default

- **WHEN** existen correcciones con `risk_level='high'` en el diccionario
- **AND** se llama `Correction::applyToText($text)` (sin parámetros extra)
- **THEN** las reglas `risk='high'` NO se aplican al texto.
- **AND** solo `risk='low'` y `risk='medium'` se ejecutan.

#### Scenario: applyToText incluye risk=high cuando se pide

- **WHEN** admin llama `Correction::applyToText($text, includeHighRisk: true)` explícitamente
- **THEN** las reglas `risk='high'` SÍ se aplican.

#### Scenario: Command retroactivo respeta risk=high

- **WHEN** admin corre `php artisan corrections:apply-run` (sin flag)
- **THEN** el comando pasa `includeHighRisk=false` a `applyToText()`.
- **WHEN** admin corre `php artisan corrections:apply-run --include-high-risk`
- **THEN** el comando pasa `includeHighRisk=true`.

#### Scenario: CLI context-audit dry-run

- **WHEN** admin corre `php artisan corrections:context-audit` (sin `--apply`)
- **THEN** el comando NO modifica la BD.
- **AND** imprime una tabla con: id, wrong, correct, current_risk, suggested_risk, reason.
- **AND** al final muestra "N correcciones marcadas como risk distinto de low" sin haber persistido nada.

#### Scenario: CLI context-audit --apply

- **WHEN** admin corre `php artisan corrections:context-audit --apply`
- **THEN** el comando persiste los cambios en `corrections.risk_level` solo donde el valor actual es `'low'` (no pisa overrides manuales).
- **AND** retorna `{updated: N, skipped_manual: M}`.

#### Scenario: Pre-approval safeguard al aprobar corrección con filler

- **WHEN** admin aprueba `POST /correcciones` con `wrong="you know"` y `correct="sabes"`
- **THEN** el endpoint retorna `201` con body `{correction: {...}, context_warning: {risk: 'high', matched: 'you know', type: 'filler', note: 'muletilla'}}`.
- **AND** el frontend muestra un modal de confirmación: "Esta corrección contiene `you know` (muletilla). ¿Confirmas que debe aplicarse en todos los contextos?"
- **WHEN** el admin cancela
- **THEN** la corrección NO queda persistida.

#### Scenario: Pre-approval safeguard al aprobar false friend

- **WHEN** admin aprueba `POST /correcciones` con `wrong="actually"` y `correct="actualmente"`
- **THEN** el endpoint retorna `{correction: {...}, context_warning: {risk: 'high', matched: 'actually', type: 'false_friend', note: 'falso amigo — safe: en realidad, de hecho'}}`.
- **AND** el frontend sugiere automáticamente la traducción segura como alternativa inline.

#### Scenario: UI tab "Contexto sensible" lista reglas flagged

- **WHEN** admin hace click en la tab "Contexto sensible"
- **THEN** la tabla lista todas las correcciones con `risk_level IN ('medium', 'high')`.
- **AND** cada fila muestra: id, original, corrección, badge de risk, razón del flag, acciones (cambiar a low / editar / eliminar).

#### Scenario: Override manual de risk

- **WHEN** admin en la tab "Contexto sensible" hace click en "Cambiar a low" para una corrección
- **THEN** se setea `risk_level='low'` en esa corrección.
- **AND** la próxima corrida de `corrections:context-audit --apply` NO la vuelve a marcar (skip por no ser 'low'... wait, sí la va a marcar porque ahora es 'low'; el override es one-shot, documentar).

> **Nota de implementación**: el override manual persiste hasta la próxima corrida del auditor (que sí lo sobrescribirá). Esto es intencional para que el admin pueda "limpiar" el flag después de editar manualmente la traducción. Si quiere preservar el override permanentemente, debe editar la regla en sí (`wrong_text`/`correct_text`) de modo que no matchee la blocklist.

#### Scenario: Badge risk_level en tab Aprobadas

- **WHEN** admin ve la lista de correcciones aprobadas
- **THEN** cada fila tiene un dot de color junto al `applies_count`: verde (risk=low), ámbar (risk=medium), rojo (risk=high).
- **AND** un tooltip al hover muestra la razón del flag cuando risk != 'low'.

#### Scenario: Migración + backfill idempotente

- **WHEN** admin corre `php artisan migrate` por segunda vez sin nuevos cambios
- **THEN** la migración es no-op (idempotente).
- **WHEN** admin corre `php artisan corrections:context-audit --apply` dos veces seguidas
- **THEN** la segunda corrida actualiza 0 (todas las que matchearon ya tienen `risk != 'low'`).

#### Scenario: Bulk apply de sugerencias del auditor

- **WHEN** admin hace click en "Aplicar sugerencias del auditor" en la tab Contexto sensible
- **THEN** se llama `POST /correcciones/context-audit` que ejecuta `ContextShiftAuditor::applyToDb(false)`.
- **AND** retorna `{updated: N, skipped_manual: M}` y muestra un toast con el conteo.
- **AND** la tabla se recarga con los nuevos `risk_level`.

---

### Requirement: El extractor `ai-coherence-learn` produce pares auditables

El sistema SHALL garantizar que cualquier corrección insertada con `source='ai-coherence-learn'` cumpla simultáneamente:

1. `source_segment_id` está poblado (no NULL), apuntando a un `transcription_segments` existente.
2. `wrong_text` tiene **5 palabras o más** contadas sobre Unicode español. Política (cambios 2026-08-18, feedback admin): las reglas de 1-4 palabras son find/replace demasiado genérico que ignora contexto y produce espanglish (lesson learned del 2026-08-15: 2.465 reglas auto-aprobadas palabra-por-palabra, 205.000 aplicaciones dañinas). Solo sobreviven reglas con suficientes palabras para preservar tono, intención y registro del segmento. El extractor SHALL **NO emitir** el segmento entero como `wrong` aunque haya cambiado completo, sino solo el fragmento mínimo que cambió (diff word-level entre el texto pre-IA y post-IA).
3. `wrong_text` pasa el filtro `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` (excluir marcas y nombres propios).
4. `EnEsRuleClassifier::classify(wrong, correct)` no retorna bucket `NOISE` ni `QUARANTINE`. Si retorna `QUARANTINE` (traducción EN→ES literal), el extractor SHALL descartar el par silenciosamente en logs de debug, no proponerlo.

#### Scenario: Pase de coherencia corrige un segmento y extrae un par válido
- **WHEN** `TranscriptionCoherencePass` recibe un segmento con `id=12345, text="The cooperativas están dotadas of two motors"` (pre-IA, post-diccionario) y la IA responde `text="Las cooperativas están dotadas de dos motores"`
- **THEN** el extractor identifica que el cambio fue el segmento completo de 9 palabras y el diff word-level NO encuentra ningún par de 5+ palabras (todos los cambios son swaps individuales the↔las, of↔de, two↔dos de 1 palabra cada uno). El extractor SHALL descartar todos los pares por la regla de longitud y NO crear ninguna fila `Correction`. Loggea `info('par descartado por longitud <5 palabras: {wrong}')`.

#### Scenario: Pase de coherencia intenta emitir el segmento entero y el extractor lo descarta
- **WHEN** la IA reescribe completamente un segmento de 9 palabras sin producir ningún par de 5+ palabras
- **THEN** el extractor NO crea ninguna fila `Correction` (no hay nada traducible como find/replace útil) y loggea `info('TranscriptionCoherencePass: sin pares extraíbles del segmento {id}')`.

#### Scenario: Pase de coherencia propone un par single-word (3 o menos palabras) y el extractor lo descarta
- **WHEN** el diff word-level produce `wrong="the", correct="la"` (1 palabra cada uno, swap típico de EN→ES)
- **THEN** el extractor descarta el par por la regla `wc < 5` antes de llamar a `proposeLearned()`, loggea `info('par descartado por longitud <5 palabras: the→la')`. Esto evita que el ruido vuelva a llenar la cola de pendientes cada 2 minutos cuando corre la cron `transcription:tick`.

#### Scenario: Pase de coherencia propone un par que es marca propia
- **WHEN** la IA cambia "Open English" → "Open English" (sin cambio) o cambia un nombre propio detectado por `looksLikeBrandOrProperNoun()`
- **THEN** el extractor descarta el par antes de llamar a `proposeLearned()`, evita filas `Correction` espurias, y loggea `info('par descartado por brand/proper noun: {wrong}')`.

#### Scenario: Pase de coherencia propone una traducción EN→ES literal larga (5+ palabras)
- **WHEN** la IA cambia "the aprueba today in this moment emergency" → "la aprueba hoy en este momento de emergencia" (par de 7 palabras) y el `EnEsRuleClassifier` lo marca como `REVIEW` (no `QUARANTINE` por la longitud pero sí contenido traducido)
- **THEN** el extractor emite el par como pendiente `risk_level='medium'`. El admin lo revisará manualmente. Loggea `info('par propuesto: {wrong}→{correct}')`.

---

### Requirement: El extractor `ai-coherence-learn` popula `source_segment_id` mediante hidratación post-INSERT

El sistema SHALL garantizar que cualquier corrección insertada con `source='ai-coherence-learn'` tenga `source_segment_id` poblado (no NULL) **dentro de la misma transacción** que crea los `transcription_segments`. La hidratación se ejecuta como un único `UPDATE` con JOIN entre `corrections` y `transcription_segments` filtrado por `transcription_id`, `source='ai-coherence-learn'`, `source_segment_id IS NULL`, `created_at > now() - 5 minutes` y `position(c.wrong_text in ts.text_raw) > 0`.

#### Scenario: Hidratación exitosa tras el pase IA
- **WHEN** el pase de coherencia inserta 3 filas `corrections` con `wrong_text='the'`, `wrong_text='of'`, `wrong_text='two'` (source_segment_id=null todavía), y luego el caller `TranscriptionProcessor::persistSegmentsAndUpdate` ejecuta `INSERT INTO transcription_segments` para esa transcripción y llama a `$coherencePass->hydrateCoherenceLearnedSourceSegments($transcriptionId)`
- **THEN** el UPDATE-JOIN resuelve cada `wrong_text` contra `position(wrong_text in ts.text_raw)`, popula `source_segment_id` con el `ts.id` correspondiente, y el log `info('TranscriptionCoherencePass: hydrated N source_segment_id(s)')` reporta el conteo.

#### Scenario: Hidratación parcial cuando un wrong_text no se encuentra en ningún text_raw
- **WHEN** la IA emite un par `wrong='xyz'` que no aparece textualmente en ningún segmento de esa transcripción
- **THEN** esa fila queda con `source_segment_id` NULL y SHALL ser marcada como `triage:orphan` por la Capa 2 del comando `corrections:triage-pending`. Las otras filas que sí matcheen se hidratan normalmente.

---

### Requirement: El admin puede ejecutar triage en capas desde `/ia/correcciones`

El sistema SHALL exponer en `/ia/correcciones` un botón "Triage pendientes (N)" en el header que ejecute el flujo definido en la capability `corrections-pending-triage`. Esta capability es transversal: usa el scheduler existente del módulo y la cache de runs.

#### Scenario: Admin dispara triage desde el header de correcciones
- **WHEN** el admin hace click en "Triage pendientes (6.035)" en el header de `/ia/correcciones`
- **THEN** la UI abre un modal de confirmación que muestra conteo actual, opciones `[dry-run] [auto-approve-keep] [cancelar]` y al confirmar hace POST a `/ia/correcciones/triage-pending` con el body correspondiente. La UI abre el modal de progreso que muestra las capas en tiempo real via polling a `/ia/correcciones/triage-pending/{runId}` (mismo patrón que `applyRetroactive`).

#### Scenario: Triage termina y la UI refresca el conteo
- **WHEN** el run del triage termina (status=done o error según cache)
- **THEN** la UI refresca el conteo de pending en el header (debe bajar significativamente), muestra el reporte por capa, y si hubo auto-approve abre el toast de undo con el `bulk_action_id`.
