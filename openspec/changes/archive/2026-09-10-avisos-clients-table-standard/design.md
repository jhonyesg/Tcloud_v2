## Context

La pestaña `Clientes` de `avisosInteligentes()` ya lista usuarios con sus
counts (`keywords_count`, `storages_count`, `storages_with_access`) y el
flag `alerts_inteligente.enabled`, pero su controller ordena
hardcoded por `username` y su frontend solo expone `Anterior`/`Siguiente`
sin sort headers. El operador no puede reorganizar la vista y pierde
tiempo identificando activos.

El estándar del proyecto (`project.md` → decisión
`standard_tabla_paginacion_sort_mis_avisos`) exige el patrón de Mis
Avisos en todos los módulos. El partial reusable vive en
`resources/views/mis-avisos/_pagination.blade.php` pero está
hardcodeado a los scopes `live`/`history`; el resto de helpers JS
(`pageList`, `setSort`, `sortIcon`, etc.) viven inline dentro del
`misAvisosPage()` Alpine en `mis-avisos/index.blade.php`.

## Goals / Non-Goals

**Goals:**
- Hacer que el admin ordene/filtre/navegue la tabla de clientes con el
  mismo UX de Mis Avisos, sin implementar dos estándares divergentes.
- Mantener el shape JSON de la respuesta (compatible con callers que
  ya consuman `current_page`, `last_page`, `prev_page_url`,
  `next_page_url`, `data`).
- Dejar el partial de paginación listo para reuso futuro (papelera,
  listados de auditoría, etc.) sin tocar los callers actuales de Mis
  Avisos.

**Non-Goals:**
- No se introducen nuevos endpoints, ni migraciones, ni cambios de
  modelo.
- No se modifica el modal `Asignar`, el detalle por cliente
  (`/ia/avisos-inteligentes/{userId}`), ni las pestañas `Escaneo`,
  `Dashboard`, `Cobertura`, `Auditoría`.
- No se refactoriza `_pagination.blade.php` para llevarlo a
  `resources/views/components/`; eso queda como deuda futura.
- No se introduce lógica de sort/paginate server-side para las
  pestañas `Cobertura` y `Auditoría` (ya tienen su propia maquinaría
  interna que no entra en este cambio).

## Decisions

### D1. Orden server-side (no client-side como Mis Avisos)

**Decisión**: `?sort=<column>&direction=<asc|desc>` se envía al
endpoint y el controller ordena antes de paginar.

**Por qué**: el orden del listado admin es relevante **entre páginas**
(un operador espera que "Keywords desc" traiga al cliente con más
keywords de TODOS, no que reordene los 25 visibles). Replicar el
client-side de Mis Avisos sería engañoso a escala.

**Alternativa descartada** — client-side con `_sortedGroups` copiado:
mismo JS, mismo icono, pero solo ordena la página actual. Con cientos
de clientes es contraproducente.

### D2. Default activos primero vía `leftJoin` + `selectRaw`

**Decisión**: en el controller, añadir un `leftJoin` a
`user_alerts_inteligentes as uai` y un
`selectRaw('COALESCE(uai.enabled, false) AS module_enabled')`.
El default ordena por `module_enabled DESC, users.username ASC`.

**Por qué**: ya tenemos la relación definida y es índice UNIQUE por
`user_id`. `COALESCE` colapsa los `NULL` (clientes sin fila en
`user_alerts_inteligentes`) a `false`, así que no hay que tratar
`NULLS FIRST/LAST`. El boolean derivado se expone en el JSON de
respuesta con la misma forma que el resto de campos calculados
(`keywords_count`, etc.).

**Alternativa descartada** — `withExists('alertsInteligente as
module_active')`: no distingue "no tiene módulo" de "tiene módulo
deshabilitado", porque `withExists` solo cuenta filas que satisfacen
la relación, no el valor de `enabled`. Necesitamos el valor real.

### D3. Whitelist cerrada de `sort` y whitelist de `per_page`

**Decisión**: arrays PHP literales en el controller:

```
sortMap = [
    'username'             => 'users.username',
    'email'                => 'users.email',
    'module'               => 'module_enabled',
    'keywords_count'       => 'keywords_count',
    'storages_count'       => 'storages_count',
    'storages_with_access' => 'storages_with_access',
]
perPageAllowed = [25, 50, 100]
```

**Por qué**: `sort` se mapea a columnas reales o alias calculados;
cualquier valor no presente cae al default. `per_page` se valida con
`in_array()` para evitar que la URL fuerce queries masivas.

### D4. Generalizar `_pagination.blade.php` por nombre de scope

**Decisión**: el partial pasa de un ternario `$mode === 'live' ? ...`
a una interpolación directa de un parámetro `$scope` (string). Las
expresiones Alpine leídas son `{{ $scope }}Page`,
`{{ $scope }}LastPage`, `{{ $scope }}Total`, `{{ $scope }}PerPage`,
y los handlers invocados son `goPage('{{ $scope }}', n)` /
`setPerPage('{{ $scope }}', n)`. Los callers de Mis Avisos siguen
pasando `$mode = 'live'` o `'history'` y no cambian.

**Por qué**: la API de los métodos `goPage`/`setPerPage` ya aceptaba
el modo como primer argumento (Live/History). Reusarla para admin
con `'users'` no requiere cambios en `misAvisosPage()`.

**Alternativa descartada** — duplicar el partial: contradice el
principio del estándar ("un solo lugar que mantener", literal en el
comentario del propio archivo).

### D5. Estado Alpine renombrado a namespace `users*`

**Decisión**: el componente `avisosInteligentes()` reemplaza `page`
por `usersPage`, introduce `usersLastPage`, `usersTotal`,
`usersPerPage`, `usersSort = { column: '', direction: '' }`, y los
métodos `setSort(scope, column)`, `sortIcon(scope, col)`,
`sortIconClass(scope, col)`, `sortHeaderClass(scope, col)`,
`pageList(current, last)`, `goPage(scope, page)`,
`setPerPage(scope, n)`. `load()` consume estos estados para componer
la URL y la respuesta llena los `usersLastPage`/`usersTotal`.

**Por qué**: el namespace explícito (`users*`) hace que el componente
sea coherente con su responsabilidad y permite que el partial
genérico funcione sin indirección.

### D6. Sort secundario estable por `users.id ASC`

**Decisión**: cada `orderBy` primario va seguido de
`orderBy('users.id', 'asc')`.

**Por qué**: en empates (dos clientes con 0 keywords, dos emails
idénticos en casos raros, etc.) el orden entre páginas no debe
"bailear". `users.id` es la clave primaria, así que es estable y
gratis (índice clustered).

### D7. Numeración `#` como `<td>` adicional, no como columna del thead

**Decisión**: el thead tiene `<th class="w-10">#</th>` (etiqueta
centrada, slate-400) y cada `<tr>` abre con
`<td class="px-2 py-3 text-center text-xs text-slate-400 tabular-nums"
x-text="(usersPage - 1) * usersPerPage + idx + 1"></td>` donde
`idx` es el índice dentro del `x-for`.

**Por qué**: el conteo depende de `usersPage` y `usersPerPage`, que
son estado Alpine; declararlo en el binding mantiene la numeración
correcta ante cualquier cambio de página o per-page sin lógica
adicional. `tabular-nums` evita que los dígitos "bailen" entre filas.

### D8. Reutilización de helpers JS vía duplicación controlada

**Decisión**: los métodos `setSort`, `sortIcon`, `sortIconClass`,
`sortHeaderClass`, `pageList` se duplican literalmente desde
`mis-avisos/index.blade.php` dentro del componente
`avisosInteligentes()`.

**Por qué**: extraerlos a un Alpine mixin global o a un archivo JS
externo obliga a tocar el bundler/layout y no compensa para ~40
líneas. La duplicación se justifica por estar acotada y por la
voluntad de mantener el cambio quirúrgico. (Deuda futura: crear
`resources/js/alpine-mixins/table-nav.js` y registrarlo en el layout.)

**Alternativa descartada** — mixin global registrado en el layout:
implica cambios en `resources/views/layouts/app.blade.php` y un
script adicional cargado en TODAS las páginas; fuera del scope.

## Risks / Trade-offs

- **R1**: Ordenar por `withCount` aliases (`keywords_count`, etc.) en
  Laravel requiere que la columna exista en el `SELECT` del query.
  Con `withCount()` Laravel ya las inyecta — pero al combinarlo con
  `->select('users.*')` + `selectRaw(...)` hay que confirmar que el
  `select('users.*')` no pisa los aliases. **Mitigación**: en tasks.md
  el spike #1 hace un `dd($users->first())` antes de comprometerse.

- **R2**: El JSON de respuesta ahora incluye una columna extra
  (`module_enabled`) que antes no existía. Algún consumidor externo
  podría tener parseadores estrictos. **Mitigación**: ninguno conocido
  (la tabla se consume solo desde esta vista); verificar en tasks.md
  que no hay otros lectores de `GET /ia/avisos-inteligentes` JSON.

- **R3**: Reordenar el `<th>`/`<td>` (ahora con `#` primero) puede
  romper selectores CSS o pruebas E2E que asuman el orden previo.
  **Mitigación**: en tasks.md añadir una búsqueda con `rg -n
  'avisos-inteligentes' app/` para detectar consumidores del DOM.

- **R4**: `goPage`/`setPerPage` aceptaban `mode` como `'live'` o
  `'history'`; ahora también `'users'`. Si un caller externo los usa
  con un valor inválido, el partial cae en
  `usersPage`/`usersLastPage`/etc. y devuelve `undefined`. En Mis
  Avisos esto no aplica porque el scope se valida vía `$mode`. En
  admin el único caller es este componente, así que está acotado.
  **Mitigación**: documentar en design que la API de `goPage` es
  `(scope, n)` con scope ∈ {live, history, users}.

- **R5**: Duplicar `pageList` y los sort helpers implica dos lugares
  donde corregir si cambia el estándar visual (iconos, ventana ±2,
  colores). **Mitigación**: agregar TODO documentado en design para
  extraer a mixin global cuando se sume un tercer consumidor.

## Migration Plan

Sin migración de BD. Sin cambio de rutas ni de modelo. El cambio es
backward-compatible:

1. **Deploy**: merge del branch. El JSON de respuesta gana
   `module_enabled` y respeta `sort`/`direction`/`per_page` si están
   presentes; sin ellos, el comportamiento es idéntico al actual
   (orden por `username`, `per_page=25`).
2. **Verificación post-deploy**:
   - Carga inicial: orden = default (activos primero); los clientes
     sin cambios visibles confirman que el `COALESCE` no introduce
     regresiones.
   - `?sort=email&direction=desc`: respuesta ordenada por email desc.
   - `?per_page=999`: el servidor responde con 25 filas por página.
   - Click en `Módulo` dos veces: alterna entre asc y desc sin
     pisar el default.
3. **Rollback**: `git revert <commit>` + reload de PHP-FPM. Sin
   migraciones que revertir, sin workers que reiniciar (el código
   vive en controller + blade, no en comandos Artisan).

## Open Questions

Ninguna. Las decisiones subsidiarias (per-page opciones
`[25,50,100]`, colores de iconos idénticos a Mis Avisos, semántica
asc=activos para la columna Módulo, scope=`'users'` para el partial
generalizado) están cerradas en la conversación y reflejadas en
tasks.md.
