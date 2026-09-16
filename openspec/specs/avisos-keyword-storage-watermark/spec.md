# avisos-keyword-storage-watermark Specification

## Purpose
Rastrea la cobertura de escaneo de menciones por par `(keyword, storage)` para que el barrido automático y manual nunca re-procese un rango ya cubierto, habilite retroactivo automático al alta de keywords nuevas y permita un modo "escaneo completo" reanudable y auditable por par.

## Requirements

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

El sistema SHALL crear el watermark de un par `(keyword_id, storage_provider_id)` cuando se inserta una nueva keyword, con `scanned_until = NULL` (catch-up completo desde el inicio). Al asignar una keyword existente a un nuevo usuario (vía `UserKeyword::created`/`saved`), SHALL crear el watermark para cada par `(keyword, storage)` donde ese usuario tiene `transcription_access=true`, también con `scanned_until = NULL`. Al habilitar el módulo de avisos para un usuario (`UserAlertsInteligente::saved` con `enabled=true`), SHALL asegurar que existan watermarks NULL para todos los pares aplicables de las keywords que el usuario ya tiene. Al cambiar `user_storages.transcription_access` de `false` a `true`, SHALL crear (si no existía) o rewind-a-NULL (si existía) el watermark para cada `(keyword, storage)` aplicable. Al eliminar una keyword (`keywords.id` borrado) SHALL eliminar en cascada sus watermarks. Al eliminar un storage SHALL eliminar en cascada los watermarks de ese storage. Al cambiar `transcription_access` de `true` a `false` SHALL mantener el watermark persistido (no se borra); la condición `transcription_access=true` en el scan ya lo excluye. Toda creación SHALL pasar por `WatermarkReconciler` (servicio centralizado, no SQL inline en modelos).

#### Scenario: Admin asigna keyword existente a cliente nuevo
- **WHEN** un cliente sin módulo habilitado recibe una keyword ya existente vía `UserKeyword::created`
- **THEN** el watermark del par `(keyword, storage)` se crea con `scanned_until = NULL` para catch-up inmediato cuando el módulo se habilite

#### Scenario: Cliente pierde acceso y lo recupera
- **WHEN** un cliente cambia `user_storages.transcription_access` de `true` a `false` y luego de `false` a `true`
- **THEN** los watermarks existentes se mantienen (no se borran al perder acceso); al recuperar, las filas existentes quedan en su `scanned_until` original — sin rewind automático (decisión: histórico perdido durante la ventana sin acceso NO se regenera sin intervención explícita del admin)

#### Scenario: Hook centralizado evita duplicación
- **WHEN** múltiples modelos emiten eventos de creación de watermarks
- **THEN** todos delegan al mismo `WatermarkReconciler::ensureForUser/Keyword/Storage` y los tests unitarios del reconciler validan la familia completa

#### Scenario: Admin elimina una keyword huérfana
- **WHEN** se borra una keyword sin usuarios asignados
- **THEN** todos sus watermarks en `keyword_scan_watermarks` se eliminan automáticamente por la FK `cascadeOnDelete`

#### Scenario: Cliente añade keyword y la asigna a storage específico
- **WHEN** el cliente crea una keyword y la asigna explícitamente a un storage (vía `user_keyword_storage`)
- **THEN** el watermark del par `(keyword, storage)` se crea con `scanned_until = NULL` para catch-up inmediato

### Requirement: Comando de reconciliación periódica con reporte de drift

El sistema SHALL ofrecer `php artisan avisos:reconcile-watermarks [--dry-run] [--user=ID]` que ejecute `WatermarkReconciler::driftReport()`. El reporte SHALL listar: pares aplicables que NO existen en `keyword_scan_watermarks` (drift negativo — los que faltan) y pares existentes que NO corresponden a ningún usuario habilitado con acceso al storage (drift positivo — huérfanos). El comando SHALL aceptar `--dry-run` (solo reporte, sin modificar) y `--user=ID` (alcance por un usuario). Cuando se ejecuta sin `--dry-run` y existe drift negativo, SHALL crear los pares faltantes con `scanned_until = NULL` (catch-up) y SHALL reportar el conteo. Drift positivo SHALL listarse pero NO borrarse automáticamente (decisión del operador).

#### Scenario: Drift negativo detectado y reparado
- **WHEN** un admin ejecuta el comando sin `--dry-run` después de una asignación masiva
- **THEN** los pares faltantes se crean con `scanned_until = NULL`, se imprime "N pares creados", y se registra una fila en `watermark_audit_log` con `action='reconcile'`

#### Scenario: Drift positivo detectado pero no borrado
- **WHEN** un admin ejecuta el comando y existe un par huérfano (sin usuarios aplicables)
- **THEN** el reporte lo lista pero NO lo borra — se imprime "M pares huérfanos (revisar antes de borrar)"

#### Scenario: Reconciliación acotada por usuario
- **WHEN** el admin añade `--user=ID`
- **THEN** el reporte y la reparación se acotan a las keywords y storages de ese usuario, no al sistema completo

### Requirement: Auditoría de acciones administrativas sensibles

Toda mutación de `keyword_scan_watermarks` desde un endpoint administrativo (`POST /scan/rewind`, `POST /scan/full`) SHALL registrar una fila en `watermark_audit_log` con `actor_user_id = session('user_id')`, `action` según corresponda (`rewind_pair` para rewind, `full_scan` para full scan), `keyword_id`/`storage_id` aplicables, `before_value`/`after_value`/`metadata`. Toda mutación automática desde un hook SHALL registrar la misma fila con `actor_user_id = NULL` y `action = 'hook_auto'` o `'reconcile'`. El log SHALL ser append-only (la API no expone UPDATE/DELETE sobre `watermark_audit_log`).

#### Scenario: Rewind deja traza con actor
- **WHEN** el admin (user_id=5) ejecuta rewind sobre `(keyword=42, storage=7)`
- **THEN** existe una fila en `watermark_audit_log` con `actor_user_id=5, action='rewind_pair', keyword_id=42, storage_id=7, after_value=NULL, metadata={"reason":"admin","ip":"...","ua":"..."}`

#### Scenario: Hook automático deja traza con actor NULL
- **WHEN** el hook `UserAlertsInteligente::saved` crea un watermark
- **THEN** existe una fila con `actor_user_id=NULL, action='hook_auto', metadata={"trigger":"UserAlertsInteligente.saved","user_id":X}`

#### Scenario: Consulta de auditoría
- **WHEN** se necesita saber quién movió el par `(k,s)` a NULL
- **THEN** una query a `watermark_audit_log` con índice `(keyword_id, storage_id, created_at DESC)` responde en < 10 ms

### Requirement: UI con paginación, filtros, preview de rewind y polling de full scan

La vista de Cobertura SHALL paginar los resultados server-side (25/50/100 por página, orden estable `keyword_text ASC, storage_name ASC`), SHALL ofrecer filtros por storage y búsqueda LIKE por texto de keyword. El botón "Activar histórico" SHALL invocar primero `POST /rewind?preview=true` para mostrar cuántos pares se verán afectados antes de confirmar. El botón "Escaneo completo" SHALL correr en background y la UI SHALL hacer polling cada 2 segundos al endpoint de estado del run, mostrando iteración actual, pares procesados, hits nuevos, tiempo transcurrido.

#### Scenario: Paginación en cobertura con miles de pares
- **WHEN** existen > 1000 pares en `keyword_scan_watermarks`
- **THEN** la vista carga solo la página solicitada (25/50/100), con controles de paginación visibles

#### Scenario: Filtrar cobertura por storage
- **WHEN** el admin selecciona un storage específico en el filtro
- **THEN** solo se muestran los pares de ese storage, conteo actualizado, paginación recalculada

#### Scenario: Preview antes de rewind
- **WHEN** el admin hace clic en "Activar histórico" sobre un par
- **THEN** la UI consulta `POST /rewind?preview=true` y muestra "Esto procesará N transcripciones" antes del confirm final

#### Scenario: Full scan con progreso en vivo
- **WHEN** el admin lanza el full scan en background
- **THEN** la UI muestra cada 2 segundos: iteración, pares procesados, hits nuevos, tiempo transcurrido, hasta `status=done/error`
