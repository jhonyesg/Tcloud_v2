## MODIFIED Requirements

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

## ADDED Requirements

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
