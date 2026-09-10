## 1. Spike de verificación

- [x] 1.1 Confirmar con `dd($users->first())` que `withCount` +
  `select('users.*')` + `selectRaw('module_enabled')` exponen todas
  las columnas requeridas (`keywords_count`, `storages_count`,
  `storages_with_access`, `module_enabled`) en el JSON serializado.
  Archivo: `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`.
- [x] 1.2 Buscar consumidores del JSON de
  `GET /ia/avisos-inteligentes` con
  `rg -n "avisos-inteligentes" app/ resources/` y listar qué
  asumen del shape actual. Si alguno parsea campos inexistentes,
  ajustar.

## 2. Backend — controller

- [x] 2.1 Añadir `leftJoin('user_alerts_inteligentes as uai', ...)`
  + `selectRaw('COALESCE(uai.enabled, false) AS module_enabled')`
  en `AvisosInteligentesController::index()` (línea 40). No usar
  `withExists` (ver D2 en design.md).
- [x] 2.2 Implementar whitelist `sortMap` y `perPageAllowed` en el
  mismo método. Default `sort=''` → `orderByRaw('module_enabled DESC')
  + orderBy('users.username', 'asc')`. Default `per_page=25`.
- [x] 2.3 Implementar la rama `sort=module` con
  `$direction === 'asc' ? 'DESC' : 'ASC'` (D3 en design.md).
- [x] 2.4 Para cualquier `sort` válido distinto a `module`:
  `orderBy($sortMap[$sort], $direction)` seguido de
  `orderBy('users.id', 'asc')` como secundario estable (D6).
- [x] 2.5 Validar `per_page` con `in_array($perPage, [25,50,100],
  true)` y caer a 25 si inválido. Pasar a `->paginate($perPage)`.

## 3. Backend — partial de paginación reusable

- [x] 3.1 Reescribir `app/resources/views/mis-avisos/_pagination.blade.php`
  para que todas las referencias a `livePage`/`historyPage` pasen
  a interpolación `{{ $scope }}Page`, `{{ $scope }}LastPage`,
  `{{ $scope }}Total`, `{{ $scope }}PerPage`. Handlers:
  `goPage('{{ $scope }}', ...)` y `setPerPage('{{ $scope }}', ...)`.
  Eliminar el ternario `$mode === 'live' ? ... : ...`.
- [x] 3.2 Verificar que los dos callers existentes en
  `mis-avisos/index.blade.php` (`@include('mis-avisos._pagination',
  ['mode' => 'live', 'position' => 'top'])` etc.) sigan funcionando
  con el parámetro renombrado a `$scope`. Renombrar la clave de
  `mode` → `scope` en esos dos call sites (NO cambia el valor).

## 4. Frontend — Alpine state y métodos

- [x] 4.1 En el componente `avisosInteligentes()` (línea 982 de
  `index.blade.php`): renombrar `page` → `usersPage`, agregar
  `usersLastPage`, `usersTotal`, `usersPerPage: 25`,
  `usersSort: { column: '', direction: '' }`.
- [x] 4.2 Agregar los métodos: `setSort(scope, column)`,
  `sortIcon(scope, col)`, `sortIconClass(scope, col)`,
  `sortHeaderClass(scope, col)`, `pageList(current, last)`,
  `goPage(scope, page)`, `setPerPage(scope, n)`. Copiados
  literalmente de `mis-avisos/index.blade.php` (D8).
- [x] 4.3 Reescribir `load()` para que la URL incluya `sort`,
  `direction`, `per_page` desde el estado Alpine, y rellene
  `usersLastPage`, `usersTotal` desde la respuesta. Reemplazar
  `prevPage()`/`nextPage()` por `goPage('users', n)`.

## 5. Frontend — tabla y paginación en la vista

- [x] 5.1 Añadir `<th class="w-10">#</th>` al inicio del `<thead>`
  (línea 74) con estilo slate-400 centrado. No es sortable.
- [x] 5.2 Convertir los cinco `<th>` sortables (Usuario, Email,
  Módulo, Keywords, Acceso) en `<button>` con la misma estructura
  de Mis Avisos: `@click="setSort('users', '<col>')"`,
  `:class="sortHeaderClass(...)"`, `<i :class="sortIcon(...) + ' ' + sortIconClass(...)">`.
- [x] 5.3 Añadir `<td>` inicial en cada `<tr>` del `<tbody>`
  (línea 85) con
  `x-text="(usersPage - 1) * usersPerPage + idx + 1"` y clases
  `px-2 py-3 text-center text-xs text-slate-400 tabular-nums`.
  Calcular `idx` vía `x-for="(u, idx) in users"`.
- [x] 5.4 Reemplazar el bloque de paginación inferior (líneas
  123-131) por
  `@include('mis-avisos._pagination', ['scope' => 'users', 'position' => 'bottom'])`
  y agregar el mismo include con `position => 'top'` justo debajo
  de los filtros (línea 59).

## 6. Verificación

- [x] 6.1 Validar el change con
  `openspec validate --strict openspec/changes/avisos-clients-table-standard`.
- [x] 6.2 Compilar los assets (no hay build, pero) y reload de
  PHP-FPM: `systemctl reload php-fpm-84`.
- [x] 6.3 Verificación manual en `/ia/avisos-inteligentes`:
  carga inicial (activos primero), click en cada encabezado, click
  en `Módulo` dos veces, cambio de per-page, cambio de página
  desde la barra superior e inferior, filtro `module=on/off`
  combinado con sort.
- [x] 6.4 Verificación de no-regresión en `/mis-avisos`: las dos
  pestañas (En vivo, Histórico) siguen ordenando y paginando igual
  que antes. La maquinaría de sort es client-side sobre la página
  (no afectada por este cambio).
