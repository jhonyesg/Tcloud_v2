# Delta: transcriptor-state-visibility

## MODIFIED Requirements

### Requirement: Sub-tab Pendientes incluye filas en estado pending de BD

El sistema SHALL mostrar, en la sub-tab "Pendientes" del módulo API Transcriptor
(`/ia/api-transcriptor`), las filas de `Transcription` cuyo `state` sea `pending`, `queued` o
`processing`. El filtrado SHALL resolverse en servidor mediante `?scope=pending` (ver
capability `transcriptor-jobs-listing`), no con `x-show` sobre las filas ya cargadas.

El contador `jobsPendingCount` SHALL leerse de `stats.local` como
`pending + queued + processing`, y NO contando el array `jobs` del cliente — ese array
contiene como mucho `per_page` filas.

Los estados terminales SHALL repartirse en **dos** sub-tabs: `jobsCompletedCount` cuenta
solo `done`, y una tercera sub-tab **Fallidos** cuenta `error + dead`.

#### Scenario: Fila en state='pending' aparece en sub-tab Pendientes
- **WHEN** el usuario activa la sub-tab Pendientes
- **THEN** se pide `?scope=pending` y las filas en `pending` son visibles con badge gris
  distinguible del de `queued`

#### Scenario: Fila en state='pending' no aparece en Completados ni en Fallidos
- **WHEN** una `Transcription` tiene `state = "pending"`
- **THEN** no la cuentan ni `jobsCompletedCount` ni `jobsFailedCount`, y no aparece al
  activar `jobsSubTab === 'completed'` ni `'failed'`

#### Scenario: Filas dead no aparecen en Completados
- **WHEN** existen 4.134 filas en `dead` y 88.514 en `done`
- **THEN** la sub-tab Completados muestra únicamente filas `done`
- **AND** las `dead` aparecen en la sub-tab Fallidos

#### Scenario: Cero filas coinciden con el scope activo
- **WHEN** el scope activo no devuelve filas
- **THEN** se muestra el placeholder correspondiente: "Sin trabajos pendientes",
  "Sin trabajos completados" o "Sin trabajos fallidos"
- **AND** si hay búsqueda o filtro de estado activos, el subtexto indica que ningún trabajo
  coincide con el filtro

### Requirement: Filtro `state` incluye opción `pending`

El sistema SHALL poblar el `<select x-model="stateFilter">` de la sub-tab Trabajos con los
estados que pertenecen al scope activo: `pending|queued|processing` en Pendientes, `done` en
Completados y `error|dead` en Fallidos, además de la opción "Todos".

Al cambiar de sub-tab, si el `stateFilter` vigente no pertenece al nuevo scope, el sistema
SHALL limpiarlo antes de recargar.

> Antes el `<select>` era una lista fija a la que le faltaban `queued` y `processing`, pese
> a que ambos estados podían aparecer en la tabla.

#### Scenario: Opciones dependen de la sub-tab activa
- **WHEN** el usuario está en la sub-tab Fallidos
- **THEN** el `<select>` ofrece "Todos", `error` y `dead`, y ninguna otra opción

#### Scenario: Filtro incompatible se limpia al cambiar de sub-tab
- **WHEN** el usuario filtra por `dead` en Fallidos y pulsa Completados
- **THEN** `stateFilter` vuelve a `""` y la tabla muestra todos los `done`
