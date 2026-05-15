## ADDED Requirements

### Requirement: Sección "Sites Externos" visible solo para usuarios con sites asignados
El sistema SHALL mostrar la sección "Sites Externos" en el sidebar únicamente si el usuario autenticado tiene al menos un site externo activo asignado.

#### Scenario: Usuario con sites asignados
- **WHEN** el usuario tiene uno o más sites externos activos asignados
- **THEN** aparece la sección "Sites Externos" en el sidebar con los ítems correspondientes

#### Scenario: Usuario sin sites asignados
- **WHEN** el usuario no tiene ningún site externo asignado o todos están deshabilitados
- **THEN** la sección "Sites Externos" NO aparece en el sidebar

---

### Requirement: Cada site muestra nombre e icono personalizado
El sistema SHALL renderizar cada site en el sidebar con su nombre y su icono FontAwesome configurado por el admin.

#### Scenario: Renderizado de ítem en sidebar abierto
- **WHEN** el sidebar está expandido
- **THEN** cada site muestra icono (en el color configurado) + nombre truncado

#### Scenario: Renderizado de ítem en sidebar colapsado
- **WHEN** el sidebar está colapsado (solo iconos)
- **THEN** cada site muestra únicamente el icono con tooltip del nombre al hacer hover

---

### Requirement: Click en site navega al visor
El sistema SHALL navegar a `/sites/{site}` al hacer click en un ítem de la sección "Sites Externos".

#### Scenario: Navegación al visor
- **WHEN** el usuario hace click en un site del sidebar
- **THEN** el navegador navega a `/sites/{site}` cargando el iframe dentro del main
