# admin-users-table-sortable Specification

## Purpose
Define el comportamiento de los encabezados clicables de la tabla de
usuarios en `/admin/users`: ordenamiento ascendente al primer click,
descendente al segundo, indicador visual del criterio activo y default
`id` ascendente al cargar la página.

## Requirements

### Requirement: Encabezados de columna con ordenamiento clicable

Las columnas ID, Email, Username, Rol, Quota, Usado y Editor Medios
de la tabla de usuarios SHALL ser clicables para ordenar los
registros visibles de forma ascendente o descendente. La columna
Acciones SHALL NO ser ordenable.

#### Scenario: Primer click ordena ascendentemente

- **WHEN** el operador hace click en un encabezado que no es el
  criterio de ordenamiento activo
- **THEN** la tabla se ordena por esa columna en orden ascendente
  (A→Z para texto, 0→9 para números)

#### Scenario: Segundo click en el mismo encabezado invierte el orden

- **WHEN** el operador hace click en el encabezado que ya es el
  criterio de ordenamiento activo
- **THEN** la dirección se invierte (de asc a desc, o de desc a asc)

#### Scenario: Indicador visual del criterio activo

- **WHEN** una columna es el criterio de ordenamiento activo
- **THEN** su encabezado muestra `↑` si es ascendente o `↓` si es
  descendente, junto al nombre de la columna
- **THEN** las columnas inactivas muestran `↕` como indicador neutro

#### Scenario: Ordenamiento por defecto al cargar

- **WHEN** la página `/admin/users` carga por primera vez
- **THEN** la tabla se muestra ordenada por la columna ID en orden
  ascendente, independientemente del orden en que el backend
  devolvió los usuarios

### Requirement: Ordenamiento estable con búsqueda activa

El ordenamiento SHALL aplicarse sobre el subconjunto de usuarios
visibles tras la búsqueda, no sobre el array completo.

#### Scenario: Cambiar el criterio de sort con búsqueda activa

- **WHEN** hay una búsqueda activa que reduce los usuarios visibles y
  el operador hace click en otro encabezado
- **THEN** el nuevo ordenamiento se aplica únicamente sobre los
  usuarios que pasan el filtro de búsqueda

### Requirement: Ordenamiento de columnas con valores derivados

El ordenamiento SHALL funcionar sobre el valor crudo de la columna en
el objeto del usuario (lo que el backend envía en el JSON de
`/admin/users`):

- `id`, `email`, `username`, `role` → strings/numéricos directos
- `personal_quota_bytes`, `personal_used_bytes` → numéricos directos
- `media_editor_enabled` → se ordena por el valor booleano
  (true primero en asc, false primero en desc), igual que en la
  tabla de storages para `enabled`.

#### Scenario: Ordenar por Quota

- **WHEN** el operador hace click en el encabezado "Quota"
- **THEN** los usuarios se ordenan por `personal_quota_bytes` en orden
  ascendente (los `personal_quota_bytes = 0` aparecen primero, los
  ilimitados por valor numérico)
