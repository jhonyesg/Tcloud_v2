## ADDED Requirements

### Requirement: Alpine.js modales deben estar ocultos durante inicialización
Los modales controlados por Alpine.js con `x-show` NO DEBEN mostrarse brevemente al cargar la página. El sistema DEBE garantizar que los modales estén ocultos hasta que Alpine.js haya evaluado sus condiciones.

#### Scenario: Modal no visible durante carga de página
- **WHEN** la página con modales Alpine.js se carga en el navegador
- **THEN** los modales NO DEBEN ser visibles aunque sea por un instante
- **AND** la transición de ocultamiento (si existe) no debe ejecutarse

#### Scenario: Modal visible después de inicialización correcta
- **WHEN** Alpine.js se inicializa completamente
- **THEN** los modales cuyo `x-show` evaluate a `true` DEBEN ser visibles
- **AND** los modales cuyo `x-show` evaluate a `false` DEBEN permanecer ocultos

#### Scenario: Solución x-cloak aplicada correctamente
- **WHEN** un elemento tiene el atributo `x-cloak`
- **THEN** el elemento DEBE estar oculto mediante CSS (`display: none`)
- **AND** una vez que Alpine.js procese el elemento, el atributo `x-cloak` DEBE ser removido
- **AND** la regla CSS `[x-cloak] { display: none !important; }` DEBE estar definida en el layout global
