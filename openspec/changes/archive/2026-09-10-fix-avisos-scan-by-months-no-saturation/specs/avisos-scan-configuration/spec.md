## ADDED Requirements

### Requirement: Catch-up histórico manual usa modo mensual cuando no hay ventana temporal

Cuando el operador elige la combinación `noWindow=true && force=true && !from && !to && !preset` en `/ia/avisos-inteligentes`, el endpoint `POST /scan/run-bg` SHALL activar el modo de iteración mensual en lugar del path clásico. La respuesta 202 SHALL incluir `mode: 'monthly'` y los campos del plan (`month_count`, `first_month`, `last_month`). El comportamiento del path clásico (presets, from/to, force sin noWindow) SHALL seguir intacto.

#### Scenario: Histórico completo + force usa modo mensual
- **WHEN** el operador POST a `/scan/run-bg` con `{"noWindow": true, "force": true, "limit": 50}` y la BD tiene datos de marzo 2024 a septiembre 2026
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'monthly', month_count: 30, first_month: '2024-03', last_month: '2026-09'}`
- **AND** el worker itera mes por mes secuencialmente en lugar de un solo scan monolítico
- **AND** el cache del run contiene `month_plan` y `current_month`

#### Scenario: Otros flujos siguen usando modo clásico
- **WHEN** el operador POST a `/scan/run-bg` con cualquier otra combinación (`preset`, `from/to`, `force` sin `noWindow`)
- **THEN** la respuesta es `{runId, status: 'queued', mode: 'classic', window_label: '...'}`
- **AND** el comportamiento existente no cambia (el path clásico sigue vigente)

### Requirement: El modo mensual tiene un interruptor operativo

El modo mensual SHALL activarse únicamente cuando se cumple la combinación de configuración y el feature flag operativo está habilitado. Si el flag está deshabilitado, el endpoint SHALL conservar el comportamiento clásico o rechazar explícitamente el lanzamiento antes de iniciar trabajo pesado, según la configuración de despliegue.

#### Scenario: El flag desactivado impide la activación mensual
- **WHEN** llega una solicitud de histórico completo y forzado con el flag mensual desactivado
- **THEN** el sistema no inicia el worker mensual
- **AND** el operador puede desactivar el modo sin revertir el código
