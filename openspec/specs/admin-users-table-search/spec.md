# admin-users-table-search Specification

## Purpose
Define el cuadro de búsqueda en tiempo real de la tabla de usuarios en
`/admin/users`: filtra por email y username sin requerir Enter, soporta
estado vacío diferenciado y muestra contador de resultados visibles vs
totales.

## Requirements

### Requirement: Cuadro de búsqueda en tiempo real sobre email y username

La tabla de usuarios SHALL incluir un cuadro de texto de búsqueda que
filtra los registros visibles en tiempo real conforme el operador
escribe, sin requerir presionar Enter ni un botón de búsqueda. La
búsqueda SHALL coincidir (case-insensitive) si el texto aparece en el
campo `email` o en el campo `username` del usuario.

#### Scenario: Búsqueda por email parcial

- **WHEN** el operador escribe `masmedios` en el cuadro de búsqueda
- **THEN** la tabla solo muestra los usuarios cuyo `email` contiene
  `masmedios` (insensible a mayúsculas/minúsculas)

#### Scenario: Búsqueda por username parcial

- **WHEN** el operador escribe `pru` en el cuadro de búsqueda
- **THEN** la tabla solo muestra los usuarios cuyo `username` contiene
  `pru` (insensible a mayúsculas/minúsculas)

#### Scenario: Búsqueda sin coincidencias

- **WHEN** el texto del cuadro de búsqueda no coincide con ningún email
  ni username
- **THEN** la tabla muestra el mensaje "No se encontraron usuarios" y
  oculta las filas vacías

#### Scenario: Limpiar la búsqueda restaura la lista completa

- **WHEN** el operador borra el texto del cuadro de búsqueda
- **THEN** la tabla vuelve a mostrar todos los usuarios, respetando
  solo el ordenamiento activo (sin búsqueda)

### Requirement: Contador de resultados visibles vs totales

La interfaz SHALL mostrar cuántos usuarios se están visualizando del
total disponible, actualizándose en tiempo real al escribir o borrar
en el cuadro de búsqueda.

#### Scenario: Contador sin búsqueda activa

- **WHEN** el cuadro de búsqueda está vacío y no hay filtros activos
- **THEN** la interfaz muestra "Mostrando N usuarios" (donde N es el
  total real)

#### Scenario: Contador con búsqueda activa

- **WHEN** hay una búsqueda activa que reduce los resultados de 18 a 3
- **THEN** la interfaz muestra "Mostrando 3 de 18 usuarios"

### Requirement: La búsqueda coexiste con ordenamiento por encabezado

La búsqueda SHALL ser independiente del criterio de ordenamiento
activo: ambos se aplican sobre el mismo array `users` en este orden
(filter → sort) y SHALL poder combinarse sin reiniciar el otro.

#### Scenario: Buscar y luego ordenar

- **WHEN** el operador filtra por email y luego hace click en el
  encabezado "Username"
- **THEN** la tabla muestra los resultados filtrados ordenados
  alfabéticamente por username (ascendente en el primer click)
