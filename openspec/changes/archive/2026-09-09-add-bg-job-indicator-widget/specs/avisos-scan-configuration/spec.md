## ADDED Requirements

### Requirement: Recarga con escaneo activo no fuerza la pestaña ni abre el modal automáticamente

Cuando el operador recarga `/ia/avisos-inteligentes` mientras hay un escaneo activo (cache key `avisos_scan_bg:active` presente), el módulo SHALL seguir attachando el polling del progreso pero SHALL NO forzar `activeTab = 'escaneo'` ni abrir el modal `scanModal` automáticamente. La página SHALL mantener la pestaña que el operador tenía activa antes de la recarga. El modal SHALL abrirse solo cuando el operador (a) hace click explícito en "Escanear ahora" desde el módulo o (b) navega a la URL `/ia/avisos-inteligentes?focus=bg-avisos-scan-{runId}` desde el indicador global de background jobs.

#### Scenario: Recarga con escaneo activo no fuerza la pestaña ni abre el modal
- **WHEN** el operador recarga `/ia/avisos-inteligentes` mientras hay un escaneo activo
- **THEN** la página carga en la pestaña que el operador tenía activa antes de la recarga
- **AND** el modal `scanModal` NO se abre automáticamente
- **AND** el indicador global del layout muestra la card del escaneo en curso

#### Scenario: Lanzar un escaneo nuevo desde el módulo sigue abriendo el modal en fase confirm
- **WHEN** el operador hace click en "Escanear ahora" en `/ia/avisos-inteligentes`
- **THEN** se abre el modal en `phase: 'confirm'` (comportamiento existente, sin cambios)
