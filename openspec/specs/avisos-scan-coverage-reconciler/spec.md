# avisos-scan-coverage-reconciler Specification

## Purpose
Mantiene la cobertura de escaneo sincronizada automáticamente ante cambios de scope (alta de keyword, asignación a usuario, transición de acceso a storage) sin intervención manual, registra toda acción administrativa sensible sobre watermarks para auditoría, y prepara la tabla `segment_keyword_hits` para escalar vía particionamiento mensual.

## Requirements

### Requirement: La cobertura se mantiene sincronizada por hooks reactivos en cualquier pivote de scope

El sistema SHALL reaccionar a los siguientes eventos creando `keyword_scan_watermarks` faltantes con `scanned_until = NULL` para cada `(keyword_id, storage_provider_id)` aplicable: creación de una keyword nueva (`Keyword::created`), asignación de una keyword existente a un usuario (`UserKeyword::created`/`saved`), habilitación del módulo de avisos para un usuario (`UserAlertsInteligente::saved` con `enabled=true`), y transición `transcription_access=false→true` en `user_storages`. El sistema SHALL reaccionar también al evento `UserStorage::created` cuando la fila se inserta directamente con `transcription_access=true` (caso no cubierto por `updated`). La creación SHALL ser idempotente (`ON CONFLICT DO NOTHING`) y SHALL delegarse al servicio `WatermarkReconciler`.

#### Scenario: Usuario recibe keyword preexistente
- **WHEN** un admin asigna una keyword ya existente a un usuario que no la tenía
- **THEN** en el siguiente request donde el usuario interactúe con esa keyword, el par `(keyword, storage)` correspondiente a cada storage con acceso del usuario aparece con `scanned_until = NULL` para catch-up retroactivo

#### Scenario: Usuario pierde acceso a storage
- **WHEN** un usuario cambia `user_storages.transcription_access` de `true` a `false`
- **THEN** los watermarks existentes para ese usuario en ese storage NO se borran; la ausencia de acceso simplemente excluye los pares del próximo escaneo

#### Scenario: Usuario recupera acceso a storage
- **WHEN** un usuario cambia `user_storages.transcription_access` de `false` a `true`
- **THEN** se crea (si no existía) o se rewind-a-NULL (si existía) el watermark para cada `(keyword, storage)` aplicable del usuario, iniciando catch-up sobre las transcripciones nuevas desde la recuperación

#### Scenario: Hooks duplican lógica sin divergir
- **WHEN** se crean `WatermarkReconciler` y los hooks delegados
- **THEN** cualquier disparador de creación de watermarks pasa por la misma función; las pruebas unitarias del reconciler son suficientes para validar toda la familia de hooks

#### Scenario: Alta directa de UserStorage con acceso
- **WHEN** un INSERT directo crea una fila en `user_storages` con `transcription_access=true` (caso `created`, no UPDATE)
- **THEN** se dispara `WatermarkReconciler::ensureForUser` y los pares `(keyword, storage)` aplicables del usuario se crean con `scanned_until = NULL`

#### Scenario: Alta directa de UserStorage sin acceso
- **WHEN** un INSERT directo crea una fila en `user_storages` con `transcription_access=false`
- **THEN** NO se crea ningún watermark (la condición `transcription_access=true` los excluiría del scan de todas formas)

### Requirement: Comando de reconciliación periódica con reporte de drift

El sistema SHALL ofrecer `php artisan avisos:reconcile-watermarks [--dry-run] [--user=ID]` que ejecute `WatermarkReconciler::driftReport()`. El reporte SHALL listar: pares aplicables que NO existen en `keyword_scan_watermarks` (drift negativo — los que faltan) y pares existentes que NO corresponden a ningún usuario habilitado con acceso al storage (drift positivo — huérfanos). El comando SHALL aceptar `--dry-run` (solo reporte, sin modificar) y `--user=ID` (alcance por un usuario). Cuando se ejecuta sin `--dry-run` y existe drift negativo, SHALL crear los pares faltantes con `scanned_until = NULL` (catch-up) y SHALL reportar el conteo. Drift positivo SHALL listarse pero NO borrarse automáticamente (decisión del operador).

#### Scenario: Drift negativo detectado y reparado
- **WHEN** un admin ejecuta el comando sin `--dry-run` después de una asignación masiva
- **THEN** los pares faltantes se crean con `scanned_until = NULL`, se imprime "N pares creados", y se registra una fila en `watermark_audit_log` con `action='reconcile'`

#### Scenario: Drift positivo detectado pero no borrado
- **WHEN** un admin ejecuta el comando y existe un par huérfano (sin usuarios aplicables)
- **THEN** el reporte lo lista pero NO lo borra — se imprime "M pares huérfanos (revisar antes de borrar)"

#### Scenario: Reconciliación detecta drift negativo
- **WHEN** un admin ejecuta el comando en `--dry-run` después de operaciones administrativas masivas (importación de keywords, migraciones)
- **THEN** el reporte lista cuántos pares faltan y cuáles son, sin modificar el estado

#### Scenario: Reconciliación repara drift negativo
- **WHEN** un admin ejecuta el comando sin `--dry-run` y existe drift negativo
- **THEN** los pares faltantes se crean con `scanned_until = NULL` y se imprime el conteo de pares creados

#### Scenario: Reconciliación reporta drift positivo
- **WHEN** existen watermarks huérfanos (ej. porque se eliminó el último `UserKeyword` correspondiente)
- **THEN** el reporte los lista pero NO los borra automáticamente — el operador decide vía DELETE explícito o vía `AvisosScanService::rewindWatermark()`

#### Scenario: Reconciliación acotada por usuario
- **WHEN** el admin añade `--user=ID`
- **THEN** el reporte y la reparación se acotan a las keywords y storages de ese usuario, no al sistema completo

#### Scenario: Drift positivo borrado vía endpoint bajo confirmación
- **WHEN** el admin hace `POST /scan/reconcile` con `{ fix_orphans: true, confirmed: true }` y existen 3 pares huérfanos
- **THEN** los 3 pares se borran de `keyword_scan_watermarks`, se registra una fila en `watermark_audit_log` con `action='reconcile'` y `metadata={deleted: 3}`, y la respuesta confirma el borrado

#### Scenario: Fix orphans sin confirmación previa
- **WHEN** el admin hace `POST /scan/reconcile` con `{ fix_orphans: true }` pero sin `confirmed: true`
- **THEN** recibe 409 con `{ needs_confirmation: true, orphans_count: 3, message: "Confirma para borrar 3 pares huérfanos" }` y no se ejecuta ningún borrado

### Requirement: Auditoría de acciones administrativas sensibles sobre watermarks

El sistema SHALL registrar en `watermark_audit_log` toda acción que modifique cobertura: rewind por par individual (`action='rewind_pair'`), rewind por reconciliador (`action='rewind_pair_auto'`), full scan masivo (`action='full_scan'`), y cualquier mutación automática por hook (`action='hook_auto'`). Cada registro SHALL incluir `actor_user_id` (NULL para acciones del sistema), `keyword_id`, `storage_id`, `before_value` (scanned_until antes), `after_value` (scanned_until después), `metadata` JSON con el contexto completo, y `created_at`. La operación SHALL ser append-only (no UPDATE ni DELETE sobre la tabla).

#### Scenario: Admin lanza rewind por par
- **WHEN** un admin (autenticado) hace `POST /avisos-inteligentes/scan/rewind` con `keyword_id=X`, `storage_id=Y`
- **THEN** existe una fila en `watermark_audit_log` con `actor_user_id=<session user>`, `action='rewind_pair'`, `keyword_id=X`, `storage_id=Y`, `before_value=<current scanned_until>`, `after_value=NULL`

#### Scenario: Hook reactivo crea watermark
- **WHEN** un hook reactivo crea un watermark automáticamente
- **THEN** existe una fila en `watermark_audit_log` con `actor_user_id=NULL`, `action='hook_auto'`, `keyword_id`, `storage_id`, `metadata=<razón del hook>`

#### Scenario: Admin consulta quién movió un par
- **WHEN** un admin necesita saber quién puso `scanned_until=NULL` en un par específico
- **THEN** una query a `watermark_audit_log WHERE keyword_id=? AND storage_id=? AND action='rewind_pair' ORDER BY created_at DESC` devuelve la respuesta con índice dedicado

### Requirement: Segmentación mensual de segment_keyword_hits para soportar DROP PARTITION O(1)

El sistema SHALL particionar la tabla `segment_keyword_hits` por `matched_at` en intervalos mensuales. La tabla padre queda vacía; cada partición `segment_keyword_hits_YYYY_MM` contiene exactamente las filas de ese mes. La PK compuesta SHALL incluir `matched_at`. La partición del mes actual y la partición default (catch-all) SHALL existir siempre. Un comando CLI `avisos:ensure-month-partition --month=YYYY-MM` SHALL crear la partición de un mes dado (idempotente). La retención de histórico SHALL poder realizarse vía `DROP TABLE segment_keyword_hits_YYYY_MM` (O(1)) cuando se decida.

#### Scenario: Inserción nueva en partición del mes actual
- **WHEN** se inserta una fila en `segment_keyword_hits` con `matched_at = '2026-09-15 12:00:00'`
- **THEN** la fila queda en `segment_keyword_hits_2026_09` automáticamente (PG dirige por la PK)

#### Scenario: DROP PARTITION para retención
- **WHEN** se decide borrar el historial de septiembre 2026 con `DROP TABLE segment_keyword_hits_2026_09`
- **THEN** las filas de ese mes desaparecen instantáneamente (O(1)) sin vacuum ni lock sobre la tabla padre

#### Scenario: Crear partición de un mes nuevo
- **WHEN** se ejecuta `php artisan avisos:ensure-month-partition --month=2026-10`
- **THEN** la partición `segment_keyword_hits_2026_10` queda creada y los inserts futuros de octubre caen en ella

#### Scenario: Crear partición dos veces es idempotente
- **WHEN** se ejecuta `php artisan avisos:ensure-month-partition --month=2026-10` dos veces
- **THEN** la segunda ejecución no falla ni crea duplicados (verifica existencia vía `pg_class`)

### Requirement: Paginación server-side y filtros en la vista de Cobertura

La UI de Cobertura SHALL ofrecer paginación server-side (25/50/100 filas por página), filtro por storage y búsqueda por texto de keyword (LIKE). El endpoint `GET /avisos-inteligentes/scan/coverage` SHALL aceptar `page`, `per_page` (default 25), `storageId`, `q` como query params. SHALL mantener el orden estable `(keyword_text ASC, storage_name ASC)` entre páginas.

#### Scenario: Cobertura con miles de pares renderiza rápido
- **WHEN** la cobertura tiene > 1000 pares
- **THEN** la vista carga solo la página solicitada (25/50/100), con paginación visible al pie

#### Scenario: Filtrar cobertura por storage
- **WHEN** el admin selecciona un storage específico en el filtro
- **THEN** solo se muestran los pares de ese storage, con conteo total actualizado

#### Scenario: Buscar por texto de keyword
- **WHEN** el admin escribe en el campo de búsqueda
- **THEN** la lista se reduce a keywords que coincidan (case-insensitive LIKE)

### Requirement: Preview y feedback en tiempo real para acciones administrativas

La pestaña Cobertura SHALL ofrecer, antes de ejecutar rewind o full scan, una estimación de pares afectados (preview sin mutación), y durante el full scan SHALL hacer polling cada 2 segundos para mostrar progreso en vivo (iteración actual, pares escaneados, hits encontrados, fallos).

#### Scenario: Preview antes de rewind
- **WHEN** el admin hace clic en "Activar histórico" sobre un par
- **THEN** la UI consulta `POST /rewind?preview=true` y muestra "Esto procesará N transcripciones" antes de confirmar

#### Scenario: Full scan con progreso en vivo
- **WHEN** el admin lanza el full scan en background
- **THEN** la UI muestra cada 2 segundos: iteración actual, pares procesados, hits nuevos, tiempo transcurrido, hasta que el proceso termine

### Requirement: WatermarkReconciler expone método bumpEpoch para invalidación de cache

El sistema SHALL exponer `WatermarkReconciler::bumpEpoch(): int` que incremente atómicamente el contador `coverage_cache_epoch` en `system_settings` y retorne el nuevo valor. Cada método público del reconciler que ejecute una mutación (`rewindPair`, `ensureForUser`, `ensureForKeyword`, `ensureForStorage`) SHALL llamar a `bumpEpoch()` antes de retornar.

#### Scenario: Rewind incrementa epoch
- **WHEN** el admin hace rewind del par (kw=5, storage=7)
- **THEN** `WatermarkReconciler::bumpEpoch()` incrementa `coverage_cache_epoch` en 1 (atómico en PG)

#### Scenario: EnsureForUser incrementa epoch cuando inserta
- **WHEN** `ensureForUser(userId)` inserta 3 watermarks nuevos
- **THEN** `coverage_cache_epoch` se incrementa en 1 una sola vez (no 3 veces), evitando inflación innecesaria

#### Scenario: Asegurar sin inserciones NO incrementa epoch
- **WHEN** `ensureForKeyword(kwId)` se ejecuta pero todas las filas ya existían (idempotente, 0 inserts)
- **THEN** `coverage_cache_epoch` NO se incrementa (no hay mutación real)
