## ADDED Requirements

### Requirement: Estado de carga explícito del listado inicial de storages

Al cargar `/admin/storages`, mientras el fetch `GET /admin/storages` esté en vuelo, la vista SHALL mostrar un indicador de carga ("Cargando storages…") en lugar del mensaje de lista vacía. El sistema MUST NOT mostrar "No hay storages configurados" mientras la petición esté pendiente. Si el fetch falla, SHALL mostrar un toast rojo con el motivo y MUST NOT dejar la pantalla vacía en silencio.

#### Scenario: Indicador de carga mientras el listado está en vuelo
- **WHEN** el admin entra a `/admin/storages` y el fetch del listado aún no respondió
- **THEN** la vista muestra "Cargando storages…" y NO muestra "No hay storages configurados"

#### Scenario: Listado cargado correctamente
- **WHEN** el fetch responde 2xx
- **THEN** el indicador de carga desaparece y la tabla muestra los storages

#### Scenario: Error de carga del listado muestra toast y no queda en blanco
- **WHEN** el fetch del listado responde con error (no-2xx) o falla por red
- **THEN** aparece un toast rojo con `err.error || err.message || ("HTTP " + res.status)`
- **AND** la vista sale del estado "Cargando" y muestra un mensaje de error accionable en lugar de "No hay storages configurados"

#### Scenario: El estado de carga se limpia siempre
- **WHEN** el fetch del listado termina, con éxito o error
- **THEN** el flag `loadingStorages` vuelve a `false` (garantizado por `try/finally`)
