## Purpose

Rastrea la cobertura de escaneo de menciones por par `(keyword, storage)` para que el barrido automático y manual nunca re-procese un rango ya cubierto, habilite retroactivo automático al alta de keywords nuevas y permita un modo "escaneo completo" reanudable y auditable por par.

## ADDED Requirements

### Requirement: Cobertura de escaneo registrada por par keyword × storage

El sistema SHALL persistir, por cada par `(keyword_id, storage_provider_id)` con al menos un usuario habilitado, un registro de cobertura con: `scanned_until` (último `finished_at` de transcripción ya procesada para esa keyword en ese storage, o NULL si nunca se procesó), `last_scan_run_id` (última corrida que lo actualizó), `last_scanned_at` (momento de la última actualización), contadores de diagnóstico `candidates_total` y `hits_total`, y timestamps de auditoría. La clave primaria SHALL ser la compuesta `(keyword_id, storage_provider_id)`. Un índice SHALL permitir barridos "¿qué pares tienen `scanned_until` atrasado en un storage dado?" y otro SHALL permitir "¿qué pares están totalmente sin escanear?".

#### Scenario: Par nuevo arranca con scanned_until NULL
- **WHEN** se crea una keyword nueva y existe al menos un storage con usuarios habilitados que tienen acceso
- **THEN** se inserta una fila en la tabla de watermarks por cada storage aplicable con `scanned_until = NULL`

#### Scenario: Par inexistente no se crea automáticamente
- **WHEN** un storage no tiene usuarios habilitados con acceso o no hay keywords asignadas
- **THEN** no se crea ninguna fila para ese par hasta que ocurra un alta explícita de keyword o asignación

### Requirement: Avance del watermark es monotónico y race-safe

El sistema SHALL avanzar `scanned_until` de cada par `(keyword_id, storage_provider_id)` únicamente hacia adelante (nunca hacia atrás), de forma atómica, cuando el escaneo procesa una transcripción con `finished_at` posterior al valor actual. Si dos corridas concurrentes intentan actualizar el mismo par, el resultado SHALL ser el `MAX(finished_at)` observado, sin pérdida ni duplicación. La operación SHALL completarse sin necesidad de locks pesimistas.

#### Scenario: Corrida cron y manual concurrentes sobre el mismo par
- **WHEN** el cron automático y un disparo manual procesan transcripciones distintas del mismo par `(k, s)` simultáneamente
- **THEN** el `scanned_until` final es el mayor de los dos `finished_at` procesados, sin pisarse mutuamente y sin generar hits duplicados

#### Scenario: Corrida sin transcripciones nuevas no retrocede el cursor
- **WHEN** una corrida de escaneo automático no encuentra candidatos para un par
- **THEN** el `scanned_until` de ese par permanece en su valor previo

### Requirement: Catch-up automático de keywords nuevas sin re-escanear keywords ya cubiertas

El sistema SHALL detectar, en cada corrida, los pares `(keyword_id, storage_provider_id)` con `scanned_until = NULL` (nunca escaneados) y SHALL incluirlos en la selección de candidatos sin afectar a los pares ya cubiertos. El barrido SHALL procesar únicamente el rango faltante de cada par sin re-procesar rangos ya cubiertos por otras keywords.

#### Scenario: Cliente agrega una keyword nueva
- **WHEN** un cliente agrega una keyword que no existía antes
- **THEN** en la siguiente corrida automática, esa keyword escanea el histórico completo de los storages a los que el cliente tiene acceso, sin re-escanear las transcripciones que otras keywords ya tienen cubiertas

#### Scenario: Keyword con hit previo en pocas transcripciones recibe catch-up completo
- **WHEN** una keyword tiene `scanned_until = NULL` y existe hit en una sola transcripción antigua
- **THEN** la siguiente corrida escanea TODAS las transcripciones posteriores del storage, no sólo desde la del único hit

### Requirement: El "escaneo completo" es un modo explícito, reanudable y acotado en tiempo

El sistema SHALL ofrecer un modo "escaneo completo" que recorra TODOS los pares `(keyword_id, storage_provider_id)` con `scanned_until` atrasado respecto al `MAX(finished_at)` de su storage, en lotes configurables (`avisos_scan_full_batch`, default 200), con un límite de tiempo por corrida (`avisos_scan_full_max_runtime_seconds`, default 600s). La corrida SHALL registrar su progreso y SHALL ser reanudable: si se interrumpe, la siguiente corrida retoma donde quedó, sin re-procesar rangos ya cubiertos. El modo SHALL estar disponible vía CLI para admin y SHALL mostrar el progreso (pares procesados / pares pendientes / cobertura estimada).

#### Scenario: Admin lanza escaneo completo de noche
- **WHEN** el admin lanza el modo "escaneo completo" y hay 8000 pares pendientes
- **THEN** la corrida procesa en lotes de 200 hasta agotar el límite de tiempo o terminar; el admin ve el progreso; una corrida posterior retoma los pares que faltaron

#### Scenario: Corrida completa agotada por tiempo reanuda limpiamente
- **WHEN** la corrida completa llega al límite de tiempo configurado habiendo procesado 6000 de 8000 pares
- **THEN** los 2000 pares pendientes quedan intactos y la siguiente corrida los continúa sin re-procesar los 6000 ya cubiertos

### Requirement: Derivación correcta del watermark al alta, baja y reasignación de keywords

El sistema SHALL crear el watermark de un par `(keyword_id, storage_provider_id)` cuando se inserta una nueva keyword, con `scanned_until = NULL` (catch-up completo desde el inicio). Al asignar una keyword existente a un nuevo storage (vía `user_keyword_storage` o acceso de storage), SHALL crear el watermark para ese par también con `scanned_until = NULL`. Al eliminar una keyword (`keywords.id` borrado) SHALL eliminar en cascada sus watermarks. Al eliminar un storage SHALL eliminar en cascada los watermarks de ese storage. Al borrar una `user_keyword` o `user_keyword_storage` SHALL evaluar si quedan otros usuarios con acceso para mantener o eliminar el watermark (mantener si sí, eliminar si no).

#### Scenario: Admin elimina una keyword huérfana
- **WHEN** se borra una keyword sin usuarios asignados
- **THEN** todos sus watermarks en `keyword_scan_watermarks` se eliminan automáticamente por la FK `cascadeOnDelete`

#### Scenario: Cliente añade keyword y la asigna a storage específico
- **WHEN** el cliente crea una keyword y la asigna explícitamente a un storage (vía `user_keyword_storage`)
- **THEN** el watermark del par `(keyword, storage)` se crea con `scanned_until = NULL` para catch-up inmediato
