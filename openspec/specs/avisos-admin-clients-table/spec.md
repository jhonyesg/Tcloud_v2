# avisos-admin-clients-table Specification

## Purpose

Define el comportamiento de orden, paginación, numeración y selector de
tamaño de la tabla de clientes de la pestaña `Clientes` de Avisos
Inteligentes (`/ia/avisos-inteligentes`), siguiendo el estándar visual
de Mis Avisos (sort por columna, paginación superior e inferior con
elipsis ventana ±2, per-page configurable).

## Requirements

### Requirement: Orden por columna server-side

La tabla SHALL permitir ordenar las filas haciendo clic en el encabezado
de las columnas `Usuario`, `Email`, `Módulo`, `Keywords` y `Acceso`.
El orden SHALL aplicarse server-side y SHALL expresarse en los query
params `sort` y `direction` (`asc` o `desc`). El backend SHALL aceptar
únicamente columnas de una whitelist cerrada; cualquier valor fuera de
la whitelist SHALL ser ignorado y tratado como "sin orden explícito".

#### Scenario: Ordenar por email ascendente

- **WHEN** el administrador hace clic en el encabezado `Email` desde el
  estado sin orden explícito
- **THEN** la URL contiene `?sort=email&direction=asc` y el servidor
  retorna los usuarios ordenados por `users.email` ascendente, con
  criterio secundario estable por `users.id` ascendente

#### Scenario: Alternar dirección del mismo encabezado

- **WHEN** el administrador hace clic dos veces consecutivas en el
  encabezado `Keywords`
- **THEN** la primera carga aplica `direction=asc` y la segunda
  `direction=desc`, manteniendo la misma columna `sort=keywords_count`

#### Scenario: Columna fuera de whitelist

- **WHEN** la URL contiene `?sort=password&direction=asc`
- **THEN** el servidor ignora el parámetro y retorna el orden por
  defecto (activos primero, luego `username` asc)

### Requirement: Orden por defecto activos primero

Sin query param `sort` (o con `sort=` vacío), la tabla SHALL ordenar
las filas poniendo primero a los clientes cuyo módulo de avisos está
activo (`user_alerts_inteligentes.enabled = true`), seguidos por los
inactivos o sin módulo, desempatados por `username` ascendente.

#### Scenario: Carga inicial sin filtros ni orden explícito

- **WHEN** el administrador abre la pestaña `Clientes` sin filtros ni
  orden previo
- **THEN** la primera página muestra los clientes activos primero y
  dentro de cada grupo, los usernames en orden alfabético ascendente

#### Scenario: Filtro combinado con orden por defecto

- **WHEN** el administrador selecciona el filtro `Módulo activo`
  (`module=on`) y no ha tocado ningún encabezado
- **THEN** la lista, ya compuesta solo por activos, se mantiene
  ordenada por `username` ascendente (el default se aplica sin
  incoherencias porque el subconjunto ya excluye inactivos)

### Requirement: Orden explícito descarta el default

Cuando el administrador hace clic en cualquier encabezado de columna
sortable, el default "activos primero" SHALL dejar de aplicarse: la
lista SHALL ordenarse únicamente por la columna seleccionada y su
dirección. Para volver al default, el administrador SHALL hacer clic
en el encabezado `Módulo`.

#### Scenario: Click en Keywords descarta el default

- **WHEN** la tabla está en el orden por defecto (activos primero) y
  el administrador hace clic en `Keywords` descendente
- **THEN** la lista se reordena por `keywords_count` descendente sin
  agrupar primero por estado del módulo; un cliente inactivo con
  muchas keywords puede aparecer por encima de un activo con menos

#### Scenario: Volver al default haciendo clic en Módulo

- **WHEN** el administrador hace clic en el encabezado `Módulo` desde
  cualquier otro orden explícito
- **THEN** la URL pasa a `?sort=module&direction=asc` y la lista
  vuelve a mostrar activos primero (semántica: asc = activos primero)

### Requirement: Semántica de asc/desc en la columna Módulo

Cuando `sort=module`, el parámetro `direction` SHALL interpretarse
así: `asc` = módulo activo primero, `desc` = módulo inactivo primero.
Hacer clic en el encabezado `Módulo` SHALL alternar entre estas dos
visiones.

#### Scenario: Asc en Módulo = activos primero

- **WHEN** la URL contiene `?sort=module&direction=asc`
- **THEN** los clientes con `enabled=true` aparecen antes que los
  inactivos o sin módulo

#### Scenario: Desc en Módulo = inactivos primero

- **WHEN** la URL contiene `?sort=module&direction=desc`
- **THEN** los clientes sin módulo o con `enabled=false` aparecen
  antes que los activos

### Requirement: Paginación superior e inferior con elipsis y per-page

La tabla SHALL presentar controles de paginación idénticos en la parte
superior e inferior: indicador "Página X de Y · N cliente(s)", botones
Anterior/Siguiente con iconos y estado deshabilitado coherente,
números de página renderizados con una ventana de elipsis ±2, y un
selector de tamaño de página con opciones fijas 25, 50 y 100. El
tamaño elegido SHALL enviarse al servidor como `per_page` y SHALL
ser validado contra una whitelist (cualquier valor inválido cae al
default 25).

#### Scenario: Cambiar de página desde la barra superior

- **WHEN** el administrador está en la página 3 y pulsa "Siguiente" en
  la paginación superior
- **THEN** la tabla carga la página 4 sin requerir scroll al final

#### Scenario: Elipsis en páginas intermedias

- **WHEN** la última página es 20 y el administrador está en la página 5
- **THEN** la paginación muestra los enlaces a páginas 1, 3, 4, 5, 6,
  7 y 20 con elipsis entre 1 y 3, y entre 7 y 20

#### Scenario: Selector de tamaño 50

- **WHEN** el administrador selecciona `50 / pág.`
- **THEN** la URL contiene `&per_page=50`, el servidor retorna 50
  filas por página y el total de páginas se recalcula

#### Scenario: per_page fuera de whitelist

- **WHEN** la URL contiene `&per_page=999`
- **THEN** el servidor trata el valor como 25 (default de la
  whitelist) y la respuesta refleja 25 filas por página

### Requirement: Numeración de filas por página

La tabla SHALL incluir una primera columna `#` a la izquierda de
`Usuario` que muestra el índice secuencial de cada fila dentro de la
página actual, calculado como `(currentPage - 1) * perPage + index + 1`.

#### Scenario: Primera fila de la primera página

- **WHEN** la página actual es 1 y `per_page=25`
- **THEN** la primera fila muestra `# 1`, la segunda `# 2`, y así
  sucesivamente hasta `# 25`

#### Scenario: Primera fila de la página 3 con 50 por página

- **WHEN** la página actual es 3 y `per_page=50`
- **THEN** la primera fila de la página muestra `# 101` (50 filas en
  página 1, 50 en página 2, índice 1 de la página 3)

### Requirement: Iconos de orden coherentes con el estándar del proyecto

Los encabezados sortable SHALL mostrar un icono FontAwesome que
refleje el estado de orden: `fa-sort` (slate-300) cuando la columna
no está activa, `fa-arrow-up` (violet-600) cuando está activa en
ascendente, `fa-arrow-down` (amber-600) cuando está activa en
descendente. El icono SHALL ser el único indicador visible de la
dirección (no se añade texto "asc"/"desc" al encabezado).

#### Scenario: Icono por defecto sin orden explícito

- **WHEN** ningún encabezado está activo
- **THEN** todos los encabezados sortable muestran `fa-sort` en color
  slate-300, sin realce

#### Scenario: Icono tras ordenar Email ascendente

- **WHEN** la URL es `?sort=email&direction=asc`
- **THEN** el encabezado `Email` muestra `fa-arrow-up` en violet-600 y
  el resto de encabezados sortable mantienen `fa-sort` slate-300
