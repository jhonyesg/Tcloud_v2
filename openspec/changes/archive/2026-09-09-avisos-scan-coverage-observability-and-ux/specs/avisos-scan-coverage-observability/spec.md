## Purpose

Asegura que la vista de Cobertura refleja el estado real de `keyword_scan_watermarks` inmediatamente después de cualquier mutación (sin esperar al TTL del cache), y que el "Escaneo completo" muestra progreso en tiempo real al administrador en lugar de bloquear el modal.

## ADDED Requirements

### Requirement: Cache de Cobertura se invalida con cada mutación de watermark

El sistema SHALL incluir un "epoch" entero (almacenado en `system_settings.coverage_cache_epoch`) en la clave de cache de `coveragePaginated`. Cada vez que `WatermarkReconciler` ejecuta una mutación (rewind explícito, ensureFor* que inserta, drift repair), SHALL incrementar el epoch de forma atómica vía `UPDATE system_settings SET value = value + 1 WHERE key = 'coverage_cache_epoch'`. La siguiente lectura de Cobertura SHALL usar la nueva clave, garantizar cache miss y devolver datos frescos. El incremento SHALL ser atómico en PostgreSQL (no requiere lock pesimista) y SHALL tolerar carrera entre dos mutaciones concurrentes.

#### Scenario: Rewind invalida cache inmediatamente
- **WHEN** el admin hace rewind del par (kw=5, storage=7) y refresca la UI
- **THEN** la siguiente llamada a `/scan/coverage` retorna `scanned_until=NULL` para ese par (sin esperar 60s)

#### Scenario: Dos rewinds concurrentes generan dos epochs distintos
- **WHEN** dos admins hacen rewind sobre pares distintos al mismo tiempo
- **THEN** el epoch se incrementa al menos dos veces; ambas operaciones ven datos frescos para sus pares respectivos (no hay race donde un rewind "esconda" el otro)

#### Scenario: Cache miss bajo carga
- **WHEN** se hacen N mutaciones por segundo sobre watermarks
- **THEN** cada lectura subsiguiente de `/scan/coverage` ve los datos más recientes; el cache hit ratio es cercano a 0% justo después de mutaciones masivas (esperado, correcto)

### Requirement: Full scan en background con runId y polling estructurado

El sistema SHALL ofrecer `POST /avisos-inteligentes/scan/full-bg` (admin) que ejecute `AvisosScanService::runFullScan` en un worker con cache key `full_scan_bg:{runId}` registrando estado cada iteración. SHALL devolver 202 con `{ runId, status: 'queued' }` y SHALL rechazar (409) si ya hay un full scan activo (pointer `full_scan_bg:active`). SHALL ofrecer `GET /scan/full-bg/{runId}/status` que devuelva el estado actual sin requerir permisos especiales (admin only) y `POST /scan/full-bg/{runId}/stop` para cancelación cooperativa.

#### Scenario: Lanzar full scan devuelve runId
- **WHEN** el admin hace `POST /scan/full-bg`
- **THEN** la respuesta es 202 con `{ runId: "fullscan_<id>", status: "queued" }` y el worker arranca en background

#### Scenario: Polling muestra progreso
- **WHEN** el cliente hace `GET /scan/full-bg/{runId}/status` mientras el worker corre
- **THEN** recibe `{ status: "running", iterations: 3, scanned: 600, hitsNew: 12, started_at: "2026-09-22 11:00:00", finished_at: null, duration_ms: 45000 }`

#### Scenario: Full scan termina
- **WHEN** el worker termina (por agotar tiempo, candidatos vacíos o stop solicitado)
- **THEN** el estado queda `done`/`stopped`/`failed`, `finished_at` poblado, y el pointer `full_scan_bg:active` se libera

#### Scenario: Lanzar segundo full scan cuando hay uno activo
- **WHEN** el admin intenta `POST /scan/full-bg` mientras hay un worker corriendo
- **THEN** recibe 409 con `{ error: "Ya hay un escaneo completo en curso", runId: "..." }`

### Requirement: UI muestra progreso en vivo del full scan

La UI SHALL hacer polling cada 2 segundos al endpoint de status del full scan mientras esté activo. SHALL mostrar en tiempo real: iteración actual, pares escaneados, hits nuevos, tiempo transcurrido (calculado de `started_at`). Al detectar `status: 'done'`, SHALL mostrar el resultado final y dejar de hacer polling. Al detectar `'failed'`, SHALL mostrar el error y ofrecer reintento.

#### Scenario: Progreso visible durante full scan
- **WHEN** el admin lanza un full scan y observa el modal
- **THEN** ve "Iteración 3 · 600 escaneadas · 12 hits nuevos · 45 s" actualizándose cada 2s

#### Scenario: Final del full scan
- **WHEN** el worker termina con `status: 'done'`
- **THEN** la UI muestra "Full scan completado: 12 iteraciones, 8000 escaneadas, 250 hits nuevos, 8 min 30 s"

#### Scenario: Stop manual
- **WHEN** el admin hace clic en "Detener" durante el full scan
- **THEN** la UI envía `POST /scan/full-bg/{runId}/stop` y el worker termina con `status: 'stopped'` en el siguiente polling

### Requirement: Validación de rewind sobre pares existentes

El sistema SHALL validar que `(keyword_id, storage_provider_id)` referenciados en `POST /scan/rewind` existen en `keywords` y `storage_providers` antes de invocar `rewindPair`. Si alguna referencia no existe, SHALL responder 404 con `{ error: "..." }` descriptivo. Esta validación evita no-ops silenciosos cuando el operador tiene IDs stale.

#### Scenario: Rewind sobre storage borrado
- **WHEN** el admin hace `POST /scan/rewind` con `keyword_id=5, storage_provider_id=9999` (storage_id inexistente)
- **THEN** recibe 404 con `{ error: "Storage 9999 no existe" }` y no se ejecuta ninguna mutación

#### Scenario: Rewind sobre keyword borrada
- **WHEN** el admin hace `POST /scan/rewind` con `keyword_id=99999, storage_provider_id=5` (keyword_id inexistente)
- **THEN** recibe 404 con `{ error: "Keyword 99999 no existe" }`

#### Scenario: Rewind normal sobre (k,s) válido
- **WHEN** el admin hace `POST /scan/rewind` con IDs válidos existentes
- **THEN** la operación se ejecuta normalmente y registra en `watermark_audit_log`

### Requirement: AvisosScanService::coverage() marcado como deprecated

El sistema SHALL marcar `AvisosScanService::coverage()` con `@deprecated since 2026-09-22` en su docblock apuntando al reemplazo `coveragePaginated()`. En `APP_DEBUG=true`, SHALL emitir `trigger_error(E_USER_DEPRECATED)` la primera vez que se invoca por request. En producción, SHALL seguir funcionando idénticamente al actual (no se borra, no se cambia el comportamiento).

#### Scenario: Caller actual sigue funcionando
- **WHEN** código legacy llama a `coverage()` con un `storageId`
- **THEN** recibe el mismo array que antes (todos los pares del storage, sin paginar), idéntico al comportamiento previo al change

#### Scenario: Caller en dev ve warning
- **WHEN** en `APP_DEBUG=true` se invoca `coverage()`
- **THEN** PHP emite `E_USER_DEPRECATED` con mensaje "coverage() is deprecated since 2026-09-22, use coveragePaginated() instead"
