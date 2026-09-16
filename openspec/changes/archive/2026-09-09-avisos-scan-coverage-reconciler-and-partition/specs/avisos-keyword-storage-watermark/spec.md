## Purpose

(Para delta de capability existente, esta sección se ignora — el Purpose vive en `openspec/specs/avisos-keyword-storage-watermark/spec.md`.)

## MODIFIED Requirements

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

## ADDED Requirements

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
