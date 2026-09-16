## ADDED Requirements

### Requirement: Disparo manual con "Histórico completo" no falla por claves de ventana ausentes

El endpoint `POST /ia/avisos-inteligentes/scan/run-bg` SHALL iniciar el escaneo correctamente cuando el operador elige la opción "Histórico completo (sin límite de fechas)" en combinación con "Forzar re-escaneo" o sin él. Aunque el body del request NO incluya los campos `from` ni `to` (porque solo aplican a "Rango personalizado"), SHALL responder HTTP 200 o 202 con un JSON que contenga `runId`, sin lanzar `Undefined array key` ni devolver 500. El operador SHALL ver el modal transicionar a `phase: 'running'` con el progreso real del escaneo.

#### Scenario: Histórico completo + force sin from/to inicia correctamente
- **WHEN** el operador POST a `/scan/run-bg` con `{"noWindow": true, "force": true, "limit": 50}` (sin `from`, sin `to`, sin `preset`)
- **THEN** el endpoint responde 200/202 con `{runId: "...", status: "queued" | "running"}`
- **AND** el modal frontend transiciona a `phase: 'running'`
- **AND** el log NO contiene la excepción `Undefined array key "from"`

#### Scenario: Rango personalizado sigue funcionando (regresión)
- **WHEN** el operador POST a `/scan/run-bg` con `{"preset": "24h", "noWindow": false}`
- **THEN** el endpoint responde 200/202 con `{runId: "...", window_label: "Último día (24 h)"}`
- **AND** el comportamiento existente se preserva

### Requirement: Endpoints del módulo de avisos devuelven errores accionables ante excepciones internas

Los endpoints mutacionales del módulo Avisos Inteligentes (`runScanBackground`, `runFullScan`, `rewindWatermark`, `saveAssign`, y sus equivalentes de preview) SHALL estar envueltos en try/catch. Cuando una excepción interna ocurra (Undefined array key, error de BD, timeout de Redis, etc.), SHALL responder con HTTP 5xx y un JSON `{error: "<mensaje legible>", detail: "<excepción>"}` que el frontend puede mostrar al operador. Adicionalmente SHALL loguear con `Log::error()` incluyendo `exception`, `file`, `line`, y un resumen del request.

El comportamiento observable SHALL ser:
- HTTP 500/502/503 con `{"error": "..."}` legible en vez del genérico `{"message":"Server Error"}` de Laravel.
- Log en `storage/logs/laravel.log` con todos los campos para diagnóstico.
- Sin filtrar la traza completa al cliente (mantener el mensaje legible pero NO exponer paths internos del servidor).

#### Scenario: Excepción interna genera 5xx con mensaje útil
- **WHEN** cualquier excepción interna ocurre dentro del endpoint (e.g. error de BD, undefined key, timeout)
- **THEN** el endpoint responde con un código 5xx y un JSON `{error: "Error interno al <verbo>: <motivo>"}`
- **AND** el log de Laravel contiene `Log::error` con la traza y los datos de request (sanitizados)
- **AND** el operador ve el mensaje en el modal en vez de "Server Error" opaco

#### Scenario: Flujo feliz sigue devolviendo 200/202 sin cambios
- **WHEN** un request legítimo al endpoint (por ejemplo, lanzar un escaneo con preset válido) procesa sin excepción
- **THEN** el endpoint responde con el código de éxito (200/202) y el JSON esperado
- **AND** no se agrega overhead observable (no try/catch cambia latencia en el caso exitoso)
