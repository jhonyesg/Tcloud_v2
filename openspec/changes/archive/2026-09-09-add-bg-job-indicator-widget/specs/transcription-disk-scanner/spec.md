## ADDED Requirements

### Requirement: Recarga con batch activo no fuerza la apertura del modal del escaneo de storages

Cuando el operador recarga `/ia/api-transcriptor` mientras hay un batch del transcriptor activo (cache key `transcription_batch:{runId}` con `status: starting|running|queued`), el módulo SHALL NO auto-abrir el modal de "Escanear storages". El polling del batch activo SHALL vivir en el indicador global del layout. El modal SHALL abrirse solo cuando el operador (a) hace click explícito en "Escanear storages" en el header del módulo, configurando un batch nuevo, o (b) navega a la URL `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}` desde el indicador global.

#### Scenario: Recarga con batch activo no fuerza el modal
- **WHEN** el operador recarga `/ia/api-transcriptor` mientras hay un batch activo
- **THEN** la página carga en el estado normal con el modal cerrado
- **AND** el indicador global del layout muestra el batch en curso
- **AND** el operador puede seguir interactuando con la página (cambiar de pestaña Storages/Trabajos/Configuración) sin nada le bloquee

#### Scenario: Click en el indicador global del batch abre el modal con el progreso
- **WHEN** el operador hace click en "Ver detalles" de una card del widget que corresponde a un batch del transcriptor
- **THEN** la URL resultante es `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}`
- **AND** el modal de "Escanear storages" se abre mostrando el progreso del batch activo
