# Feature: Administración → Backup (mapeo de storages al árbol de producción)

## Specs

- **S1 — El vínculo se asigna desde la UI.** El par `storage_providers.backup_storage_id` +
  `backup_folder_name` hoy solo se puede escribir por consola (`backups:map-storages`) o SQL.
  El administrador necesita una pantalla para declararlo, porque los nombres de los dos lados
  NO se corresponden (`09 Redmas` ↔ `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`).
- **S2 — La pantalla lista los storages de origen con su carpeta de backup.** Cada fila muestra
  nombre, ruta base, carpeta de backup asignada y estado. Los storages sin mapear se distinguen
  de los mapeados y de los que tienen un vínculo inválido (destino borrado o no local).
- **S3 — Asignar y desasignar con validación EN SERVIDOR.** El servidor es la autoridad: el
  cliente no puede declarar un vínculo aunque manipule la petición.
- **S4 — Panel de cobertura.** De las carpetas de canal que existen en el árbol de producción,
  cuántas tienen un storage de origen declarado, cuántas quedan huérfanas y cuáles storages
  candidatos siguen sin backup.
- **S5 — Solo administrador.** La entrada vive en el sidebar, grupo "Administración", y todas
  las rutas pasan por `auth` + `admin`.
- **S6 — El cliente no ve nada de esto.** La pantalla es de configuración; el punto de entrada
  del cliente sigue siendo el botón "Solicitar backup" en la carpeta-día.
- **S7 — El módulo no inventa la correspondencia.** Las sugerencias son ayuda visual, nunca una
  escritura: un par dudoso se declara a mano.

## Tasks

- [x] **T1 — Servicio `BackupMappingService`** (S3, S4, S7) — inline — done — commit: 53ada4a —
  evidence: `php tests/harness_backup_mapping.php` → aserciones del servicio en verde (validación, unicidad, cobertura, idempotencia)
- [x] **T2 — Controller `Admin\BackupMappingController`** (S3, S5) — inline — done — commit: a279105 —
  evidence: harness: las 4 rutas registradas, resuelven al controller y exigen `auth` + `admin`; POST inválido 422 sin escribir, POST válido 200 escribiendo, DELETE 200 quitando
- [x] **T3 — Rutas + entrada del sidebar** (S5) — inline — done — commit: a279105 —
  evidence: harness: `GET /admin/backups` 200 con sesión de admin real; un cliente recibe 403
- [x] **T4 — Vista `admin/backups.blade.php`** (S1, S2, S4) — inline — done — commit: 9b921e8 —
  evidence: harness: la página contiene las 4 marcas esperadas y lista `01_Caracol_BK` (carpeta real del árbol)
- [x] **T5 — Harness `harness_backup_mapping.php`** (S3) — inline — done — commit: 148eed0 —
  evidence: `php tests/harness_backup_mapping.php` → `RESULTADO: OK` (PG + disco reales + HTTP real)
- [x] **T6 — Documentación (AGENTS.md + este documento)** (S1) — inline — done — commit: pendiente —
  evidence: sección "Administración del vínculo de backup" en AGENTS.md + este documento

## Acceptance Criteria

1. `GET /admin/backups` responde 200 para un admin autenticado y 302/403 para un cliente.
2. La vista lista los storages de origen con su carpeta de backup y distingue: mapeado, sin
   mapear y vínculo inválido.
3. El panel de cobertura muestra el total de carpetas del árbol, cuántas están mapeadas y
   cuáles quedan huérfanas.
4. `POST /admin/backups` con una carpeta **inexistente en disco** responde 422 y NO escribe.
5. `POST /admin/backups` con un nombre de carpeta que contiene `/` o `..` responde 422 y NO escribe.
6. `POST /admin/backups` con una carpeta ya asignada a otro storage responde 422 y NO escribe.
7. `POST /admin/backups` con un storage de origen dentro del árbol de backup responde 422.
8. `POST /admin/backups` válido escribe el par y `StorageProvider::backupMapping()` lo devuelve.
9. `DELETE /admin/backups/{storage}` borra el vínculo y el storage vuelve a `hasBackupMapping() === false`.
10. Un storage sin mapeo NO puede pedir backup: `BackupResolver` responde `no_mapping`.
11. Las rutas del módulo resuelven a métodos existentes (contrato de rutas).

## Verification

```bash
cd app
php tests/harness_backup_mapping.php     # contrato del módulo de mapeo
php tests/harness_route_contract.php     # ninguna ruta huérfana
php tests/harness_backup_requests.php    # el módulo cliente sigue funcionando
```

Verificación manual (admin):

1. `Administración → Backup`: la lista muestra los 21 storages mapeados y el resto sin mapear.
2. El panel de cobertura marca 7 carpetas de canal huérfanas.
3. Editar un storage, elegir una carpeta y guardar: toast verde; la fila queda "Mapeado".
4. Quitar el vínculo: la fila vuelve a "Sin mapear" y el botón "Solicitar backup" desaparece
   de las carpetas-día de ese canal.

## Progress

- 2026-11-09 — Feature creada tras la pregunta del operador sobre cómo se asigna el backup de
  un storage. El módulo cliente (`solicitud-backup-mis-archivos`) ya está implementado; lo que
  faltaba era la superficie de ADMINISTRACIÓN para declarar el vínculo.
- 2026-11-09 — T1–T6 completadas. La pantalla `/admin/backups` existe, con el panel de
  cobertura (28 carpetas del árbol, 21 mapeadas, 7 huérfanas), el listado de los 205 storages
  candidatos con su estado, el modal de asignación y la entrada "Backup" en el sidebar.
- Estado inicial medido: 21 de 28 carpetas del árbol con origen declarado; 7 huérfanas
  (`01_Caracol_internacional`, `02_Canal_Rcn_bk`, `04_Canal_UNO_BK`, `14_Cosmovicion_bk`,
  `19_Capital_BK`, `30_Calitv`, `51_Blu_BK`); `BACKUP_ROOT_STORAGE_ID` sin definir en `.env`,
  por lo que la pantalla deja elegir el árbol de destino.

## Next Step

Verificación en navegador del flujo completo (asignar → el cliente ve "Solicitar backup" →
solicitar → enlace) y declaración de los 7 vínculos pendientes con el operador.

## Log

- **L1** — 2026-11-09 — Pedido del operador: "yo como le asigno a un storage su ruta de backup"
  y "asumo que debería haber un módulo en la parte izquierda en administración que se llame
  backup, donde salga los storages y las rutas que se han asignado". Decisión tomada: entrada
  propia "Backup" en el sidebar de Administración.
- **L2** — 2026-11-09 — Estado real medido antes de escribir código: `StorageProvider::$fillable`
  incluye `backup_storage_id` y `backup_folder_name`, pero `StorageProviderController::store()` y
  `update()` arman la fila con una lista blanca que NO los incluye, y la vista
  `admin/storages.blade.php` no tiene ningún campo de backup. El vínculo solo se escribía por
  `php artisan backups:map-storages` o SQL.
- **L3** — 2026-11-09 — Decisión de diseño: las reglas del vínculo viven en un servicio
  (`BackupMappingService`), no en el controller. El comando `backups:map-storages` y la UI
  comparten las mismas validaciones, para que no exista una puerta trasera por consola con
  reglas más débiles.
- **L4** — 2026-11-09 — Decisión de diseño: la validación de existencia de la carpeta se hace en
  disco, con el guard de montaje (`MountGuard::isNetworkMountDown()`) ANTES de cualquier
  `is_dir()`, porque el árbol de producción es un export NFS en modo `hard` y un `stat()` sobre
  un mount caído deja PHP-FPM en espera indefinida. La contención se verifica por cadena, sin
  `realpath()`.
- **L5** — 2026-11-09 — Entrega: 5 commits (`53ada4a` servicio · `a279105` controller + rutas ·
  `9b921e8` vista + sidebar · `148eed0` harness · este commit de docs). El servicio quedó en un
  solo commit de 673 líneas, por encima del presupuesto de 400: partirlo exigiría duplicar las
  reglas de validación en dos clases y reintroducir la divergencia que T1 evita.
- **L6** — 2026-11-09 — Verificación: `harness_backup_mapping.php` en verde (servicio + rutas +
  HTTP real con sesión de admin), `harness_route_contract.php` → 297 rutas y 0 huérfanas,
  `harness_backup_requests.php` → sin regresión en el módulo cliente. En el camino se
  encontraron y corrigieron dos defectos propios: `sort()` sobre arrays de arrays
  ("Array to string conversion") y el paso de la cookie de sesión por el header en vez de por
  el parámetro `cookies` de `Request::create()` (Symfony no parsea ese header cuando el array
  de cookies ya viene vacío), que hacía que el harness viera un 302 en vez de la página.
- **L8** — 2026-11-09 — Defecto encontrado por el operador en la primera carga real: la página
  llegaba **sin header, sin panel lateral y sin footer**. Causa: a la vista le faltaba
  `@endsection`. Con la sección abierta, Blade imprimía el contenido ANTES del layout y
  concatenaba el HTML del layout después: la respuesta traía las dos cosas, pero en el orden
  equivocado (el contenido arriba, el chrome abajo del todo). El harness NO lo detectaba porque
  solo buscaba cadenas: el HTML contenía el panel lateral, más abajo.
  Corrección: `@endsection` antes de `@push('scripts')`, más dos aserciones que comparan
  POSICIONES (`<main` < contenido < `</body>`, y panel lateral antes del contenido). El test
  negativo confirma que sin `@endsection` fallan las dos.
- **L11** — 2026-11-09 — Cierre de las 7 carpetas huérfanas del árbol. Se buscaron las
  contrapartes por CONTENIDO (prefijo de archivo + días solapados), no por parecido de nombre:
  `01_Caracol_internacional` ↔ 32 Caracol Internacional (3/3 días), `02_Canal_Rcn_bk` ↔ 02 RCN Tv
  (14/14), `04_Canal_UNO_BK` ↔ 04 Canal Uno (14/14), `14_Cosmovicion_bk` ↔ 20 cosmovision
  (16/16), `19_Capital_BK` ↔ 06 Capital (13/13), `30_Calitv` ↔ 29 Cali Tv (8/8),
  `51_Blu_BK` ↔ 25 Blu Tv Digital (13/13). Cobertura final: **28/28 carpetas mapeadas, 0 huérfanas,
  0 vínculos rotos**, y los 28 resuelven el día de hoy como disponible.
  El caso especial fue **29 Cali Tv** (storage 33): su `base_path` apuntaba a
  `Disco_A/television/30_Calitv`, una ruta que YA NO EXISTE (el material está en
  `Disco_I/television/30_Calitv`). Con la ruta muerta, el cliente no veía ninguna carpeta-día y
  la acción de backup no tenía dónde aparecer. Se repuntó el storage (snapshot
  `storage_providers_remap_pre_20261109_160714`) y se sincronizó su catálogo (53 → 63 carpetas),
  junto con el de 32 Caracol Internacional (52 → 54).
  De ahí salió la mejora: el módulo de administración ahora marca los storages cuya **ruta base
  no existe** (`BackupMappingService::pathState()`), que antes eran invisibles para el operador.
- **L10** — 2026-11-09 — El operador preguntó por qué no veía la acción "Solicitar backup" en un
  storage al que tenía acceso de lectura. Causa: **la visibilidad la decide el vínculo declarado,
  no el permiso**. "02 RCN Tv" (storage 7) no tenía vínculo, así que `has_backup` venía false y
  la acción se ocultaba en silencio; el permiso `read` del usuario era correcto e irrelevante.
  Además, el árbol sí tiene `02_Canal_Rcn_bk`: era una de las 7 carpetas huérfanas.
  Corregido en dos partes:
  1. **Datos**: se declaró el vínculo `02 RCN Tv` → storage 34 + `02_Canal_Rcn_bk`, verificado
     por contenido (mismo prefijo de archivos `rcn_`, mismos nombres de carpeta-día
     `DDMMYYYY`, solapamiento de 26 días) y no por parecido de nombre. Verificado en vivo: el
     día `09112026` resuelve a
     `/www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_D/backup/02_Canal_Rcn_bk/09112026` y
     el usuario `Multiarchivo` (read) obtiene `can_request=true`.
  2. **UX**: cuando la carpeta es un día pero el canal NO tiene backup declarado, la acción
     aparece **apagada** con el motivo en el tooltip ("Este canal no tiene backup
     configurado"; para admin, "Decláralo en Administración → Backup"). Antes la ausencia era
     silenciosa y se leía como un módulo roto.
- **L9** — 2026-11-09 — Ajuste pedido por el operador: el listado se ordena **primero lo que ya
  está declarado** (para revisarlo) y abajo lo que falta por asignar. Antes el orden era el
  inverso (sin mapear primero). Orden final: `mapped` → `broken` → `unmapped`; el toolbar
  muestra el conteo por estado. El harness compara el índice del último mapeado con el del
  primero sin mapear, así que un cambio de orden rompe la aserción.
- **L7** — 2026-11-09 — Riesgo: **item 2 (seguridad)**. El módulo escribe el vínculo que decide
  qué material de producción puede pedir un cliente. La autorización (solo admin) está
  verificada por el harness, pero la superficie concede acceso al master de producción: lo
  razonable es que ladeclare el operador con revisión explícita de los 7 vínculos pendientes
  antes de darlos por buenos.
