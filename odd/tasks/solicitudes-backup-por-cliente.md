# solicitudes-backup-por-cliente

- Objective: Que el administrador vea, dentro del módulo Backup, qué clientes piden backup y cómo va cada solicitud, agrupado por cliente (hoy no existe esa superficie admin y el panel "Mis solicitudes de backup" le sale vacío al admin).
- Branch: `feat/solicitudes-backup-por-cliente` (creada desde `edf8c1bb`, el HEAD que YA traía el módulo de backup; `main` está atrás y NO tiene `BackupRequestService` ni `admin/backups.blade.php`)
- Delivery strategy: `ask-on-risk`
- Test runner: `php tests/harness_backup_control_por_cliente.php` (nuevo, T5) + `php tests/harness_backup_requests.php`; TDD desactivado (`odd/config.yaml` → `tdd.mode: off`)
- Forecast authored changed lines: `0`

## Specs

- **S1 — El módulo Backup ya existe y es del administrador**
  - Requirement: "Tengo un módulo que se llama backup, en el cual como administrador pues por asignación". Verificado: `GET /admin/backups` (`app/routes/web.php:43`) → `App\Http\Controllers\Admin\BackupMappingController::index` (`app/app/Http/Controllers/Admin/BackupMappingController.php:35`), bajo `auth` + `admin`; hoy administra SOLO el vínculo "storage de origen → carpeta del árbol" (`storage_providers.backup_storage_id` + `backup_folder_name`).
  - Acceptance: La pantalla de Backup sigue aplicando el vínculo y, en la MISMA pantalla, aparece la sección de solicitudes por cliente.
  - Check: `php tests/harness_backup_mapping.php` (sigue en verde) + `php tests/harness_route_contract.php`.
- **S2 — Ver qué clientes piden backup y cómo van sus solicitudes**
  - Requirement: "me gustaría ver qué clientes de esa manera yo puedo saber qué tal que montan en la solicitud solicitudes, los clientes de la peña normal".
  - Acceptance: La vista admin lista las solicitudes de `backup_requests` agrupadas por cliente: username, email, cantidad, y el detalle de cada solicitud (storage de origen / canal, día, estado, motivo, enlace vivo si lo hay).
  - Check: harness nuevo (T5): con fixtures, el endpoint devuelve el agregado por `user_id` y el detalle por solicitud.
- **S3 — Hoy no existe la vista agregada: el admin solo ve su propio historial vacío**
  - Requirement: "hay solicitudes de backup por cliente está vacío". Verificado en `tcloudstorage`: `backup_requests` tiene 27 filas de 3 clientes (24 `ready`, 3 `revoked`); el único listado es `BackupRequestService::listFor(User $user)` (`app/app/Services/MisArchivos/BackupRequestService.php:271`) → `GET /files/backup-requests`, filtrado por `user_id`. Para el admin —que no creó esas solicitudes— ese panel se ve vacío. NO es un fallo de captura: las filas existen.
  - Acceptance: El admin ve las 27 solicitudes de los 3 clientes sin impersonar a nadie.
  - Check: harness: el endpoint admin con sesión real de admin devuelve `clientes > 0` y el total coincide con el conteo de `backup_requests`.
- **S4 — El estado de cada solicitud se reporta con el vocabulario cerrado**
  - Requirement: `backup_requests.status` ∈ {`ready`, `unavailable`, `expired`, `revoked`} (CHECK en BD, `app/database/migrations/2026_11_06_120100_create_backup_requests_table.php:72`) y `BackupRequest::isLive()` (`app/app/Models/BackupRequest.php:111`) define si el enlace sirve.
  - Acceptance: Cada fila del detalle muestra su estado literal y solo marca enlace cuando `isLive()` es verdadero.
  - Check: harness: solicitud `ready` con share vivo → enlace presente; `ready` con vigencia vencida → sin enlace y estado `expired`.
- **S5 — La sección vive en el módulo Backup, no en el de Solicitud Puntual**
  - Requirement: **Supuesto** (a confirmar por el usuario): la vista se agrega dentro de `/admin/backups`, siguiendo el patrón ya construido en el módulo hermano: `App\Services\MisArchivos\PuntualReportService::porCliente()` + `GET /solicitud-puntual/control` + `GET /solicitud-puntual/control/clientes` + `GET /solicitud-puntual/control/exportar` (`app/routes/web.php:159-163`).
  - Acceptance: La ruta del reporte cuelga de `/admin/backups` y exige `auth` + `admin`.
  - Check: harness: cliente → 403; admin → 200.
- **S6 — El reporte es de solo lectura**
  - Requirement: **Supuesto**: el admin observa; no revoca ni edita. La revocación sigue siendo del cliente ("Revocar la solicitud en Mis Archivos es la única forma de desactivar ese enlace", `app/resources/views/admin/backups.blade.php:551`).
  - Acceptance: Un GET del reporte no modifica ninguna fila de `backup_requests` ni de `shares`.
  - Check: harness: conteos y `updated_at` de `backup_requests` y `shares` antes y después del GET.
- **S7 — El enlace vivo se muestra al admin**
  - Requirement: **Supuesto**: paridad con el control de puntuales, que expone los enlaces públicos de cada solicitud (`PuntualReportService::linksDe()`).
  - Acceptance: El detalle muestra la URL pública solo si `isLive()`; nunca expone el `token` de un enlace muerto.
  - Check: harness: solicitud revocada → sin URL.
- **S8 — El admin ve la demanda por canal, para priorizar asignaciones**
  - Requirement: "saber qué tal que montan en la solicitud solicitudes" — **Supuesto**: el motivo operativo del admin es saber qué canales se piden (y por tanto cuáles merece la pena asignar backup).
  - Acceptance: El reporte permite ver, por storage de origen, cuántas solicitudes recibió.
  - Check: harness: el agregado por storage de origen cuadra con el total de solicitudes.
- **S9 — La guía interactiva del módulo cubre la sección nueva**
  - Requirement: Contrato del repo (`AGENTS.md` → "Guías interactivas — contrato"): "Si se agrega un módulo o partial nuevo, hay que extender el tour".
  - Acceptance: `startBackupTour()` incluye un paso con ancla `data-backup-tour="..."` para la sección de solicitudes.
  - Check: grep del ancla en `app/resources/views/admin/backups.blade.php` + harness de rutas.
- **S10 — Exportación en texto plano (opcional)**
  - Requirement: **Supuesto**: paridad con `GET /solicitud-puntual/control/exportar`; el usuario NO lo pidió explícitamente. Si no se confirma, T4 se marca `dropped`.
  - Acceptance: `GET /admin/backups/control/exportar` devuelve un `.txt` con el mismo contenido del reporte.
  - Check: harness: 200 + `Content-Type: text/plain; charset=UTF-8`.

## Tasks

- [x] **T1 — Servicio `BackupRequestReportService`** (S2, S3, S4, S8) — inline — done — commit: 862e1950 —
  evidence: `php -r` contra `tcloudstorage` → totales `{solicitudes:27, clientes:3, vigentes:4, expiradas:20, revocadas:3, sin_material:0, canales:9}`; el estado efectivo pasa a vivir en `BackupRequest::effectiveStatus()` y `php tests/harness_backup_requests.php` → "OK: todas las aserciones pasan"
- [x] **T2 — Endpoint admin + rutas con `auth` + `admin`** (S5, S6, S7) — inline — done — commit: b344b576 —
  evidence: HTTP real con sesión de admin → `GET /admin/backups/control` 200 con `{solicitudes:27, clientes:3, vigentes:4, expiradas:20, revocadas:3, sin_material:0, canales:9}` y 9 canales; `GET /admin/backups/control/clientes` 200; `GET /admin/backups/control/exportar` 200 `text/plain; charset=UTF-8`. Con sesión de cliente: 403 en las TRES rutas. `php tests/harness_route_contract.php` → "OK: 320 rutas verificadas, 0 huerfanas" — risk: item 2 (expone datos de clientes y enlaces públicos; exige verificación independiente del guard de admin)
- [x] **T3 — Sección "Solicitudes por cliente" en `/admin/backups`** (S1, S2, S3, S8) — inline — done — commit: b3435a9d —
  evidence: render con sesión de admin → 200 y el markup nuevo en la página ("Solicitudes de backup por cliente", `data-backup-tour="requests"`, `toggleRequests()`, `loadRequests()`, `exportarRequests()`, `/admin/backups/control*`, "Demanda por canal"); `php tests/harness_backup_mapping.php` → "RESULTADO: OK" — risk: none
- [x] **T4 — Exportación TXT del reporte** (S10) — inline — done — commit: b344b576, b3435a9d —
  evidence: `GET /admin/backups/control/exportar` → 200 `text/plain; charset=UTF-8` con el reporte en texto plano; el botón "Exportar TXT" de la sección arma la query con los filtros de la pantalla — risk: none
- [x] **T5 — Harness de regresión `harness_backup_control_por_cliente.php`** (S2, S3, S4, S6, S7) — inline — done — commit: 87d0bb11 —
  evidence: `php tests/harness_backup_control_por_cliente.php` → exit 0, 57 aserciones en verde (agregado por cliente, estado efectivo, enlace solo si la solicitud está viva, demanda por canal, solo lectura, rutas con `auth`+`admin`, 403 del cliente, sección en la pantalla) y 0 filas residuales en `backup_requests`/`shares`/`files`/`users` — risk: none
- [x] **T6 — Extender la guía interactiva de `/admin/backups`** (S9) — inline — done — commit: 877af7cc —
  evidence: paso nuevo "Quién pidió backup" con el ancla `data-backup-tour="requests"`; `php tests/harness_backup_mapping.php` → la guía tiene las 9 anclas (incluida `requests`) — risk: none
- [x] **T7 — CSS compilado (Tailwind build + guardarraíl)** (S1, S3) — inline — done — commit: c7b9d78b —
  evidence: `npm run build` → `public/css/app.css` 87.8 KB; `php tests/tailwind_coverage_check.php` → "✓ Todas las clases estáticas del markup existen en el CSS compilado" (29884 clases) — risk: none
- [ ] **T8 — Documentar en `AGENTS.md` + este documento** (S1, S5) — inline — in_progress — commit: - — evidence: - — risk: none

## Acceptance Criteria

1. `GET /admin/backups` sigue respondiendo 200 para un admin autenticado y 403/302 para un cliente (el comportamiento actual no cambia).
2. La pantalla tiene una sección "Solicitudes por cliente" que lista los clientes con el total de solicitudes de cada uno y el detalle de cada solicitud (canal/storage de origen, día, estado, motivo, enlace vivo cuando `isLive()`).
3. El total que muestra el reporte coincide con el conteo real de `backup_requests` (hoy: 27 solicitudes de 3 clientes) sin filtros aplicados.
4. Un cliente autenticado que llama a la ruta del reporte recibe 403 y no ve datos de otros usuarios.
5. El estado mostrado es uno de `ready`, `unavailable`, `expired`, `revoked`; una solicitud `ready` con vigencia vencida se muestra `expired` y sin enlace.
6. El GET del reporte no muta filas: `backup_requests` y `shares` quedan con los mismos conteos y `updated_at`.
7. El enlace público solo aparece cuando `isLive()` es verdadero; las solicitudes revocadas o sin material no exponen URL.
8. El reporte permite ver la demanda por storage de origen (canal), y ese agregado cuadra con el total de solicitudes.
9. La ruta nueva está registrada, resuelve a un método existente y pasa `auth` + `admin` (contrato de rutas).
10. La guía interactiva de `/admin/backups` incluye un paso que lleva a la sección nueva.
11. Las clases nuevas del markup están en `public/css/app.css` (`php tests/tailwind_coverage_check.php` en verde).

## Verification

```bash
cd app
php tests/harness_backup_control_por_cliente.php   # nuevo (T5)
php tests/harness_backup_requests.php               # el módulo cliente sigue funcionando
php tests/harness_backup_mapping.php                # el módulo admin sigue funcionando
php tests/harness_route_contract.php                # ninguna ruta huérfana
php tests/tailwind_coverage_check.php               # clases nuevas en el CSS compilado
npm run build                                          # si cambian clases del markup (T7)
```

Verificación manual (admin):

1. `Administración → Backup` → sección "Solicitudes por cliente": 3 clientes y 27 solicitudes.
2. Desplegar un cliente: el detalle muestra canal, día, estado, motivo y el enlace cuando sigue vivo.
3. Un cliente (no admin) que entra a la URL del reporte recibe 403.

## Progress

- Exploración terminada (sin escribir código de aplicación): `Admin\BackupMappingController`, `BackupRequestService`, `BackupRequest`, `PuntualReportService`, `resources/views/admin/backups.blade.php`, `resources/views/files/index.blade.php`, `routes/web.php`.
- Evidencia observada: `psql` → `backup_requests` = 27 filas, 3 clientes, 24 `ready` / 3 `revoked`, primera `2026-11-09 13:21:50`, última `2026-11-19 16:34:08`.
- Documento de feature creado y nombre elegido por el usuario: `solicitudes-backup-por-cliente`.
- Implementación completa en la branch `feat/solicitudes-backup-por-cliente` (base `edf8c1bb`): servicio, endpoint admin, sección en la pantalla, exportación TXT, harness, guía y CSS compilado.
- Los cuatro supuestos de L5 quedaron implementados como estaban planteados (ubicación en `/admin/backups`, solo lectura, enlace vivo visible para el admin, exportación TXT incluida).
- Pendiente: T8 (documentación). No se hizo push ni PR.

## Next Step

## Next Step

Revisar en el navegador la sección "Solicitudes por cliente" de `/admin/backups` con la sesión de admin (27 solicitudes de 3 clientes) y decidir si se hace push de la branch.

## Log

- **L1** — 2026-11-20 — Pedido original del usuario, textual: "Iniciar una feature nueva de Organic Driven Development: Tengo un módulo que se llama backup, en el cual como administrador pues por asignación, pero me gustaría ver qué clientes de esa manera yo puedo saber qué tal que montan en la solicitud solicitudes, los clientes de la peña normal y hay solicitudes de backup por cliente está vacío"
- **L2** — 2026-11-20 — El usuario eligió el nombre de la feature: "solicitudes-backup-por-cliente".
- **L3** — 2026-11-20 — Hallazgos de exploración (con rutas y líneas): `GET /admin/backups` (`app/routes/web.php:43`) es la superficie admin del módulo Backup y hoy solo administra el vínculo storage→carpeta (`Admin\BackupMappingController::index`, `app/app/Http/Controllers/Admin/BackupMappingController.php:35`). El único listado de solicitudes es `BackupRequestService::listFor(User $user)` (`app/app/Services/MisArchivos/BackupRequestService.php:271`), filtrado por `user_id`, consumido por el panel "Mis solicitudes de backup" de Mis Archivos (`app/resources/views/files/index.blade.php:3268` → `x-show="!backupRequestsLoading && backupRequests.length === 0"`). No existe `BackupRequestReportService` ni ninguna vista admin agregada: `grep -c backup` en `app/resources/views/solicitud-puntual/index.blade.php` y en `app/resources/views/dashboard/` → 0. El patrón análogo ya existe en el módulo hermano: `PuntualReportService::porCliente()` (`app/app/Services/MisArchivos/PuntualReportService.php:37`) con sus rutas `GET /solicitud-puntual/control`, `/control/clientes`, `/control/exportar` (`app/routes/web.php:159-163`).
- **L4** — 2026-11-20 — Evidencia de datos (por qué el panel "está vacío"): `psql` sobre `tcloudstorage` → `backup_requests` = 27 filas, 3 clientes distintos, 24 `ready` + 3 `revoked`, ventana `2026-11-09 13:21:50` → `2026-11-19 16:34:08`. Las solicitudes EXISTEN; lo que está vacío es la vista del admin, porque el listado está filtrado por `user_id` y el admin no es quien las creó.
- **L5** — 2026-11-20 — Supuestos marcados (a confirmar con el usuario antes de cerrar T3/T4): (a) la vista vive dentro de `/admin/backups`, no mezclada con la pestaña "Control por cliente" de Solicitud Puntual; (b) el reporte es de solo lectura y el admin no revoca (la revocación sigue siendo del cliente); (c) el enlace vivo se muestra al admin, igual que en el control de puntuales; (d) la exportación TXT (S10/T4) es opcional y se puede `droppear` si el usuario no la quiere.
- **L6** — 2026-11-20 — Decisiones de repo aplicadas: `odd/config.yaml` → `tdd.mode: off` (verificación funcional, no ciclo RED→GREEN) y `delivery.strategy: ask-on-risk`. La branch se creó desde `edf8c1bb` (HEAD de `feat/dashboard-perf-backup-guide`), NO desde `main`: `main` no tiene el módulo de backup (`git ls-tree main -- app/app/Services/MisArchivos/` → vacío), así que desde `main` la feature no tendría ni el servicio de solicitudes ni la pantalla `/admin/backups`.
- **L7** — 2026-11-20 — El usuario autorizó la implementación ("listo procede ai mplmentarlo") y con eso confirmó los supuestos de L5: sección dentro de `/admin/backups`, reporte de solo lectura, enlace vivo visible para el admin y exportación TXT incluida.
- **L8** — 2026-11-20 — Una sola fuente de verdad del estado: `BackupRequestService::effectiveStatus()` (privado) delegó en el nuevo `BackupRequest::effectiveStatus()` (público). La lógica es idéntica y `php tests/harness_backup_requests.php` sigue en verde ("OK: todas las aserciones pasan"). Con eso el historial del cliente y el reporte del admin cuentan lo mismo.
- **L9** — 2026-11-20 — Verificaciones finales: `php tests/harness_backup_control_por_cliente.php` → exit 0 (57 aserciones); `php tests/harness_backup_requests.php` → OK; `php tests/harness_backup_mapping.php` → RESULTADO: OK; `php tests/harness_route_contract.php` → "OK: 320 rutas verificadas, 0 huerfanas"; `php tests/tailwind_coverage_check.php` → todas las clases en el CSS compilado. Sin filas residuales de fixtures en la BD.
- **L10** — 2026-11-20 — Riesgo item 2 (T2): verificado de forma independiente en el harness — las tres rutas exigen `auth` + `admin` en el middleware y el controller repite la guarda (`Session::get('user_id')` + `User::isAdmin()`); un cliente recibe 403 en las tres y el reporte no expone datos de otros usuarios.
