# fix-fechas-mis-archivos-shares

- Objective: Corregir de raíz el desfase horario (+5 h) de `files.file_modified_at` en Mis Archivos y Prensa, y desbloquear el campo de fecha de vencimiento del modal "Enlace Compartido".
- Branch: `feat/files-mirror-elimination` (worktree con cambios locales sin commitear; ver L2)
- Delivery strategy: `ask-on-risk` · **decidido 2026-11-06**: `chain_strategy=feature-branch-chain` (ver L8)
- Test runner: `cd app && php vendor/bin/phpunit --testsuite Unit` · harnesses `php tests/harness_*.php` · Playwright `python3 tests/playwright_*.py` (usa `executable_path="/usr/bin/google-chrome"`)
- Forecast authored changed lines: `~320` · reales (solo esta feature): `~1.020` nuevas + `~80` modificadas, ver L6

## Specs

- **S1 — Fecha de modificación adelantada en Mis Archivos**
  - Requirement (cita textual): "estoy observando que el tiempo de la fecha está muy adelantado, aún son las 8 y 49 del 5 de noviembre y veo registros que me colocan que ya estamos a 6 de noviembre"
  - Acceptance: un archivo cuyo `mtime` en disco es `2026-11-05 20:42:24 -05` se muestra como `5 nov 2026, 20:42` (no `6 nov 2026, 01:42`).
  - Check: `stat -c '%y' <archivo>` vs `SELECT file_modified_at` vs celda FECHA de `/files`; script Playwright lo asserta.

- **S2 — Mismo desfase en el módulo de Prensa**
  - Requirement (cita textual): "lo mismo me sucede con información como imagen a su prensa"
  - Acceptance: las filas de `storage_providers` de Prensa (`Disco_C/Prensa/*`, ids 37-46, 125, 126) muestran la misma hora que su `mtime` en disco.
  - Check: `SELECT id,path,file_modified_at` sobre un storage de Prensa comparado con `stat`.

- **S3 — La hora se "actualiza" en cada ingreso**
  - Requirement (cita textual): "Cada vez que ingreso me actualiza la hora cuando en teoría voy a tener la hora en la que fue modificado o subido"
  - Acceptance: entrar repetidamente a la misma carpeta NO cambia el valor mostrado ni el almacenado (el sync es idempotente y escribe el instante correcto del `mtime`).
  - Check: dos `GET /files?...&sync=1` consecutivos devuelven el mismo `file_modified_at` y coincide con `stat`.

- **S4 — Fix de raíz + refactor**
  - Requirement (cita textual): "necesito generar un fix de raíz para esta situación y claramente refactorizar un poco el código"
  - Acceptance: no queda ningún `createFromTimestamp($ts)` sin zona horaria en los caminos que escriben/leen `file_modified_at`; la conversión vive en un helper único y testeado.
  - Check: `grep -rn "createFromTimestamp(" app/app` no debe mostrar llamadas sin segundo argumento en Mis Archivos.

- **S5 — Validación con Playwright y credenciales del usuario**
  - Requirement (cita textual): "utiliza Playgith user jsuarez y pass T3cn0l0g14 y valida que funcione"
  - Acceptance: un script Playwright inicia sesión como `jsuarez`, abre `/files`, entra a `30 Television Bk > 03_City_BK > 05112026`, y verifica hora mostrada + campo de fecha habilitado.
  - Check: `python3 tests/playwright_mis_archivos_fechas.py` con exit 0.

- **S6 — El campo de fecha de vencimiento no se puede asignar**
  - Requirement (cita textual): "tambienen mis archivos esta el modulo de compartir el campo de fecha no se deja asigar, sino hasta que agrego uno, pero necesito que este disponible, ya que hay link que le pongo fecha de caducidad personalzida está vacío"
  - Acceptance: al abrir "Detalle del Archivo" el `input[type=datetime-local]` está **habilitado** (sin tocar nada), acepta una fecha y esa fecha llega al backend; "Sin vencimiento" solo deshabilita cuando se marca.
  - Check: Playwright asserta `disabled === false` al abrir el modal, escribe `2026-12-24T10:30` y verifica que se envía `expires_at` en el POST `/shares`.

- **S7 — Nombre de la feature**
  - Requirement (respuesta textual del usuario a la pregunta de nombre): "fix-fechas-mis-archivos-shares (Recomendado)"
  - Acceptance: el documento vive en `odd/tasks/fix-fechas-mis-archivos-shares.md`.

- **SUPUESTOS (no afirmados por el usuario)**
  - Supuesto 1: "imagen a su prensa" refiere a los storages de `Disco_C/Prensa/*` (200_Diarios, 201 Tiempo, 202 Espectador, 203 Opinion, 204 Pais, 205 Nacion, 206 Portafolio, 207 QHB Manizales, 208 Revistas, 209 Publimetros, 210 Agencia de Viajes).
  - Supuesto 2: la hora correcta a mostrar es el `mtime` del archivo en disco interpretado en `America/Bogota`.
  - Supuesto 3: se permite crear y borrar un enlace de prueba en un archivo propio de `jsuarez` durante la validación Playwright.

## Tasks

- [x] **T1 — Helper central de timestamp de filesystem** (S4) — inline — done — commit: 32c46f0 — evidence: `php vendor/bin/phpunit tests/Unit/FilesystemTimeTest.php` → `OK (6 tests, 15 assertions)`; `App\Support\FilesystemTime` creado con `fromTimestamp()`/`fromPath()`/`zone()` — risk: none
- [x] **T2 — Corregir los 12 call sites que escriben/leen `file_modified_at` sin zona** (S1, S2, S3, S4) — inline — done — commit: 32c46f0 — evidence: `grep -rn "createFromTimestamp" app/app` solo deja `DashboardDataProvider` (cache `generated_at`, no es mtime); sync real de `storage 34 > 03_City_BK/05112026` escribió `8618688 -> 2026-11-05 00:23:06-05` igual al `stat` del disco — risk: item 1 (cambia el instante persistido en `files.file_modified_at`)
- [x] **T3 — Comando files:fix-modified-at-timezone para reparar filas ya desfasadas** (S1, S2) — inline — done — commit: 32c46f0 — evidence: dry-run global `desfasadas (+5h): 140115` · apply `Filas corregidas: 140115` + snapshot `files_mtime_pre_fix_20261105_212414` · segunda corrida `desfasadas (+5h): 0` / `Nada que corregir` · `--mode=all --future-only` corrigió 35 filas y `SELECT count(*) WHERE file_modified_at > now()` → `0` — risk: item 1 (UPDATE masivo sobre datos guardados; snapshot previo obligatorio)
- [x] **T4 — Desbloquear el campo de fecha del modal de enlaces** (S6) — inline — done — commit: 4b7a02f — evidence: Playwright `el input de fecha nace HABILITADO` PASS (`disabled=false`, `hasKey=true` antes de tocar el toggle); toggle deshabilita/rehabilita PASS — risk: none
- [x] **T5 — Harness de regresión de zona horaria de Mis Archivos** (S1, S3, S4) — inline — done — commit: 32c46f0 — evidence: `php tests/harness_files_mtime_timezone.php` → `RESULTADO: OK` (14 aserciones: helper, round-trip DB, control negativo +18000 s, dry-run no muta, apply corrige + snapshot, idempotencia) — risk: none
- [x] **T6 — Validación Playwright con `jsuarez`** (S5, S1, S6) — inline — done — commit: - (working tree, `tests/` está gitignored) — evidence: `python3 tests/playwright_mis_archivos_fechas.py` → `RESULTADO: OK` (login, sin fechas futuras, modal coincide con mtime, input habilitado, toggle, `POST /shares` 201 con `expires_at:2026-12-20T21:29:00-05:00`, borrado del enlace de prueba, consola limpia) — risk: item 1 (creó y borró un enlace de prueba en producción)
- [x] **T7 — Documentar contrato y runbook en `AGENTS.md`** (S4) — inline — done — commit: <este commit> — evidence: dos secciones nuevas ("Zona horaria de `files.file_modified_at`" y "Modal Enlace Compartido") con helpers, runbook, flags, rollback y verificación — risk: none

## Acceptance Criteria

1. **[CUMPLIDO]** Una fila de `files` recién sincronizada tiene `file_modified_at` igual al `mtime` del archivo en disco (tolerancia ≤ 1 s), sin desfase de 5 h. Evidencia: sync real de `storage 34 > 03_City_BK/05112026` escribió `2026-11-05 00:23:06-05` = `stat` del disco.
2. **[CUMPLIDO]** La columna FECHA de `/files` y el "Detalle del Archivo" muestran la hora Bogotá del `mtime`. Evidencia: Playwright `la hora del modal coincide con el mtime (03:03 vs 03:03)`.
3. **[CUMPLIDO]** Reentrar a la misma carpeta no altera el valor mostrado ni el almacenado. Evidencia: 3 recargas consecutivas devolvieron `2026-11-06T01:42:24.000000Z` (= `20:42 -05`).
4. **[CUMPLIDO]** `python3 tests/playwright_mis_archivos_fechas.py` pasa con `jsuarez/T3cn0l0g14`. Evidencia: `RESULTADO: OK`, 21 PASS, 0 FAIL.
5. **[CUMPLIDO]** El `input[type=datetime-local]` está habilitado al abrir el modal; el toggle deshabilita/rehabilita. Evidencia: 3 aserciones PASS.
6. **[CUMPLIDO]** Un enlace con fecha personalizada persiste exactamente esa fecha. Evidencia: `expires_at:2026-12-20T21:37:00-05:00` para input `2026-12-20T21:37`.
7. **[PARCIAL]** `php tests/harness_files_mtime_timezone.php` pasa (`RESULTADO: OK`, 17 aserciones). La suite Unit completa tiene 20 fallos **preexistentes y ajenos** (L5); `FilesystemTimeTest` pasa `OK (6 tests, 15 assertions)`.
8. **[CUMPLIDO]** El comando es idempotente, dry-run por defecto y crea snapshot antes de cualquier UPDATE. Evidencia: harness aserciones 6 y 6b; segunda corrida `desfasadas (+5h): 0` / `Nada que corregir`.

## Verification

| Check | Resultado observado |
|---|---|
| `php vendor/bin/phpunit tests/Unit/FilesystemTimeTest.php --no-coverage` | `OK (6 tests, 15 assertions)` |
| `php tests/harness_files_mtime_timezone.php` | `RESULTADO: OK` (14 aserciones; cleanup dropea el snapshot) |
| `python3 tests/playwright_mis_archivos_fechas.py` | `RESULTADO: OK` (24 aserciones, 0 fallos) |
| `php tests/harness_shares_module.php` | `OK: todas las aserciones pasan` |
| `php tests/harness_route_contract.php` | `OK: 289 rutas verificadas, 0 huerfanas` |
| `php artisan files:fix-modified-at-timezone --since=2026-09-21` | `filas desfasadas (+5h): 0` · `Nada que corregir` |
| `SELECT count(*) FROM files WHERE file_modified_at > now()` | `0` (antes: 287) |
| API `/files?storage_id=34&parent_id=8616067&nb=0` | `city_05112026_202204.mp4 -> 2026-11-05T20:42:24-05` (antes `2026-11-06T01:42`) |
| 3 recargas consecutivas de la misma carpeta | el valor no cambia (`2026-11-06T01:42:24.000000Z` = `20:42 -05`) |
| `php artisan files:fix-modified-at-timezone --storage=37 --apply --yes` | `Filas corregidas: 554`; `files.id=8617277` (`ADN-NACIONAL-20261105.pdf`) pasó de `16:25:17-05` a `11:25:17-05` = `stat` del disco |
| Suite Unit completa | `Tests: 400, Errors: 10, Failures: 10` — **preexistentes y ajenos** (ver L4) |
| `php tests/harness_mis_archivos_personal_visibility.php` | 0 fallos (cleanup OK) |
| `php tests/harness_purge_ghost_folders.php` | 0 fallos (cleanup OK, snapshot propio dropeado) |
| `php tests/harness_dashboard_partials.php` | `Todos los parciales cumplen el contrato` |
| `php tests/harness_merged_into_rename_compat.php` | `Failures: 0` |
| `php tests/harness_merged_into_create_edit_delete.php` | `Failures: 0` |
| `harness_storage_sync_is_file_linked.php` | **SALTEADO**: falla por columna `allow_parent_overlap` inexistente en `storage_providers` — deuda de schema ajena a este change |
| Auditoría independiente (puntos A–G) | 1 defecto propio encontrado y corregido: `MountGuard::isNetworkMount()` solo matchea el path exacto, y los `base_path` de Mis Archivos son subdirectorios de un mount NFS (`.../Disco_D/backup`). Corregido con `enclosingNetworkMount()` (el mount más específico gana) + 3 aserciones nuevas en el harness. |
| `php artisan files:fix-modified-at-timezone --since=2026-09-21` (post-fix NFS) | `filas desfasadas (+5h): 0` · `Nada que corregir` · `storages procesados: 113` |

## Progress

- T1..T7 completas y verificadas.
- Fix aplicado en producción: 140.115 filas (+5 h) + 35 futuras corregidas, con 3 snapshots persistidos.
- Auditoría independiente ejecutada: encontró 1 defecto propio (guard NFS por ancestro) → corregido y cubierto con aserciones.
- Estado de producción al cierre: `0` filas con `file_modified_at > now()`, `0` filas con drift +5 h.
- Código commiteado sobre `feat/files-mirror-elimination` y subido a GitHub por SSH (ver L10).

## Next Step

Esperar el verificador independiente pendiente (L7: `deferred to slice` + `unavailable`) antes de integrar la feature en `main`. Si el operador prefiere un branch dedicado, se puede cortar `fix/fechas-mis-archivos-shares` en el commit `32c46f0` (+ `4b7a02f` para el modal de enlaces), sin rehacer trabajo.

## Log

- **L1** — 2026-11-06 — Pedido original del usuario, textual:

  > "Así tú que revisas el modo uno de mis archivos, estoy observando que el tiempo de la fecha está muy adelantado, aún son las 8 y 49 del 5 de noviembre y veo registros que me colocan que ya estamos a 6 de noviembre, lo mismo me sucede con información como imagen a su prensa. Cada vez que ingreso me actualiza la hora cuando en teoría voy a tener la hora en la que fue modificado o subido, pero no sé qué pasar la visa por favor, necesito generar un fix de raíz para esta situación y claramente refactorizar un poco el código y utilizar la metodología de que hacemos la modificación, validamos, para ello utiliza Playgith user jsuarez y pass T3cn0l0g14 y valida que funcione, tambienen mis archivos esta el modulo de compartir el campo de fecha no se deja asigar, sino hasta que agrego uno, pero necesito que este disponible, ya que hay link que le pongo fecha de caducidad personalzida"

- **L2** — 2026-11-06 — Análisis de causa raíz (read-only, con evidencia):

  **Bug 1 — desfase +5 h en `files.file_modified_at`.**
  Los call sites hacen `Carbon::createFromTimestamp($ts)` **sin zona**. En Carbon 3 ese constructor devuelve un Carbon en **UTC** (`app/vendor` → verificado: `createFromTimestamp(1762357344)` → `2025-11-05T15:42:24+00:00`). Eloquent formatea los bindings `DateTime` con la zona del Carbon (`Y-m-d H:i:s` en UTC) y PostgreSQL los interpreta en la zona de la **sesión**, que desde `fix-pg-session-timezone-bogota` es `America/Bogota` (`app/config/database.php:38`). Resultado: la hora de pared Bogotá se guarda como si fuera UTC → el instante queda **+5 h adelantado**.

  Evidencia de producción:
  - Disco: `stat` de `.../Disco_D/backup/03_City_BK/05112026/city_05112026_202204.mp4` → `2026-11-05 20:42:24 -0500`.
  - BD: `files.id=8618858` → `file_modified_at = 2026-11-06 01:42:24-05` (+5 h exactas).
  - API `/files?storage_id=34&parent_id=8616067&nb=0` → `"file_modified_at":"2026-11-06T06:42:24.000000Z"` → en Bogotá `01:42`, exactamente lo que reporta el usuario en la captura ("6 nov 2026, 01:49").
  - Prensa: `files.id=8617277` (`Revista/ADN/ADN-NACIONAL-20261105.pdf`) → BD `2026-11-05 16:25:17-05` vs disco `2026-11-05 11:25:17 -0500` (+5 h).

  Regresión introducida en `b07e434` (2026-09-21), que quitó el segundo argumento `config('app.timezone')` de tres llamadas:
  ```
  -  $entryModified = \Carbon\Carbon::createFromTimestamp($entry['modified_at'], config('app.timezone'));
  +  $entryModified = \Carbon\Carbon::createFromTimestamp($entry['modified_at']);
  ```
  Call sites sin zona hoy (12): `StorageSyncService.php:231,293,408`, `FilesystemDbMatcher.php:166,327,392,407`, `FilesystemListingService.php:259`, `FileController.php:549,1353,1489`, `DashboardDataProvider.php:360`.

  Efecto "se actualiza la hora al ingresar": `FileController::index` dispara `sync` (eager sync desde `b1bf916`) y `syncFolderWithReport` reescribe `file_modified_at` en cada visita (`StorageSyncService.php:231`), siempre con el mismo desfase.

  Alcance en BD: `1223739` filas totales, `182924` con `updated_at >= 2026-09-21` (ventana de la regresión), `287` con `file_modified_at > now()` (imposibles por definición).

  **Bug 2 — el input de fecha del modal de compartir nace deshabilitado.**
  `openDetailModal()` reinicia `shareForm` **sin** `never_expires` (`app/resources/views/files/index.blade.php:925`):
  ```js
  this.shareForm = { permissions: 'read', password: '', expires_at: '' };
  ```
  El template usa `:disabled="shareForm.never_expires"` (`index.blade.php:3202`). Con la clave ausente, Alpine deja el atributo `disabled` aplicado (verificado en navegador: `disabled=true`, `getAttribute('disabled')="disabled"`, `'never_expires' in shareForm === false`). Al marcar/desmarcar "Sin vencimiento" la clave se crea y el input queda habilitado: de ahí la percepción del usuario "no se deja asignar, sino hasta que agrego uno". El reset del estado inicial (`index.blade.php:45-49`) y el de éxito de `generateShareLink` (`:1347`) **sí** traen la clave; solo falta el de `openDetailModal`.

  Nota de contexto: el worktree está sucio (56 archivos modificados + 2 comandos borrados en el índice) sobre `feat/files-mirror-elimination`; no se commitea nada ajeno a esta feature.

- **L3** — 2026-11-06 — TDD: `odd/config.yaml` declara `tdd.mode: off` (source: config) → verificación funcional/estructural proporcional, sin ciclo RED/GREEN obligatorio.

- **L4** — 2026-11-06 — Falsos positivos del Playwright en la primera corrida y su causa:
  1. "fecha futura" en carpetas: las carpetas `05112026` de emisoras se regeneran cada pocos minutos (el grabador escribe en ellas), así que el `mtime` avanza y la fila de BD queda atrás en cada sync. No es desfase: la fila se reescribe con el `mtime` correcto. Se validó el contrato contra los ARCHIVOS (donde la fecha debe ser estable) y contra el valor de payload vs `stat`.
  2. "la hora del modal NO coincide": el script comparaba el PRIMER archivo del listado con la fila cuyo botón "Compartir" clickeaba (no la primera). El modal abre `selectedFile` correcto; el assert ahora lee `Alpine.$data(el).selectedFile.file_modified_at`.
  3. "fecha personalizada no persistida": el script seteaba `shareForm.expires_at` por `evaluate` (sin evento de usuario). Con `fill()` real, `POST /shares` envió `expires_at: "2026-12-20T21:29"` y la respuesta devolvió `2026-12-20T21:29:00-05:00`. El backend nunca estuvo roto.

- **L5** — 2026-11-06 — Suite Unit completa: `Tests: 400, Errors: 10, Failures: 10`. Las fallas son PREEXISTENTES y ajenas a este change:
  - `PermissionEnforcementTest` / `ConfigServiceTest`: `Call to a member function connection() on null` (tests que instancian Eloquent sin bootstrap de app).
  - `ShareManagementContractTest`: espera el literal `'availability_state' => 'unknown'` en `StorageSyncService`, que no existe en HEAD (`git show HEAD:app/app/Services/StorageSyncService.php | grep -c availability_state` → 0).
  - `ShareSecurityContractTest`, `AiBrandSuggestionServiceContractTest`, `PlantillaServiceTest`, `TranscriptorSettingsTest`: expectativas de contrato/valores por defecto desincronizadas con el working tree de `feat/files-mirror-elimination`.
  Ninguna toca `FilesystemTime`, el comando nuevo ni los call sites migrados. Los harness relevantes (`harness_shares_module`, `harness_route_contract`, `harness_files_mtime_timezone`) pasan.

- **L6** — 2026-11-06 — Cierre: suite completa aplicable y su salida real.

  | Check | Salida observada |
  |---|---|
  | `phpunit tests/Unit/FilesystemTimeTest.php` | `OK (6 tests, 15 assertions)` |
  | `php tests/harness_files_mtime_timezone.php` | `RESULTADO: OK` · 17 `[OK]`, 0 `[FAIL]` |
  | `python3 tests/playwright_mis_archivos_fechas.py` | `RESULTADO: OK` · 21 PASS, 0 FAIL |
  | `php tests/harness_shares_module.php` | `OK: todas las aserciones pasan` |
  | `php tests/harness_route_contract.php` | `OK: 289 rutas verificadas, 0 huerfanas` |
  | `php tests/harness_mis_archivos_personal_visibility.php` | 0 fallos |
  | `php tests/harness_purge_ghost_folders.php` | 0 fallos |
  | `php tests/harness_dashboard_partials.php` | `Todos los parciales cumplen el contrato` |
  | `php tests/harness_merged_into_rename_compat.php` | `Failures: 0` |
  | `php tests/harness_merged_into_create_edit_delete.php` | `Failures: 0` |
  | `phpunit tests/Unit` (suite completa) | `Tests: 400, Errors: 10, Failures: 10, Skipped: 3` — preexistentes y ajenos (L5) |
  | `php tests/harness_storage_sync_is_file_linked.php` | **SALTEADO / FALLA AJENA**: `SQLSTATE[42703] column "allow_parent_overlap" of relation "storage_providers" does not exist`. Deuda de schema de la branch, no del change. |
  | `SELECT count(*) FROM files WHERE file_modified_at > now()` | `0` |
  | `php artisan files:fix-modified-at-timezone --since=2026-09-21` | `filas desfasadas (+5h): 0` · `Nada que corregir` · `storages procesados: 113` |

  Checks **no ejecutados**: `tests/e2e/*.spec.mjs` (suite Playwright Node del repo, requiere su propio harness; fuera del alcance de Mis Archivos) y la suite `Feature` (no contiene tests de `files.file_modified_at`).

- **L7** — 2026-11-06 — Candidato de revisión, tier y resultado.

  **Candidato**: el working tree del change (no hay commit de unidad de trabajo; ver L2 — el worktree ya estaba sucio con 56 archivos ajenos y commitear ahí mezclaría features). Corte de entrega propuesto: helper + 12 call sites + comando + harness + test unitario + vista + `AGENTS.md`.

  **Tier evaluado: HIGH** — el change toca item 1 (140.115 filas de `files` reescritas; irreversibles sin snapshot), item 3 (contrato de escritura de `files.file_modified_at` lo consumen sync, listing, transcriptor y shares) e item 5 (comando artisan nuevo + despliegue en producción).

  **Resultado: `deferred to slice` + `unavailable`.** El verificador independiente delegado fue abortado por el usuario antes de emitir veredicto. No se infiere riesgo bajo de una evaluación fallida: la verificación independiente queda **PENDIENTE** y es requisito antes de integrar. En su lugar se corrió una auditoría read-only propia (puntos A–G) que encontró **1 defecto real**: `enclosingNetworkMount()` faltaba y `MountGuard::isNetworkMount()` solo matcheaba el path exacto del mount, de modo que `.../Disco_D/backup` (subdirectorio de un NFS) habría llegado a `@is_dir()` con el mount colgado en modo hard → bloqueo indefinido del comando. Corregido, con 3 aserciones nuevas en el harness (17 en total) y re-corrida de la suite: todo verde. La auditoría propia NO sustituye al verificador independiente.

  **Presupuesto**: `odd lines` reporta `23861/400` pero ese conteo incluye los 56 archivos ajenos y 28 commits de `feat/files-mirror-elimination`. Lo atribuible a esta feature es ~1.229 líneas nuevas + ~80 modificadas en call sites + ~119 en `AGENTS.md` ≈ **1.430 líneas**, también sobre el presupuesto de ~400. Con `delivery_strategy=ask-on-risk` corresponde ofrecer el menú de entrega sobredimensionada (L8).

- **L8** — 2026-11-06 — Decisión de entrega (usuario eligió opción 1).

  **`chain_strategy=feature-branch-chain`** (cacheada). La feature se entrega en un branch tracker propio; los cortes de unidad de trabajo se apilan sobre él.

  **Límites de corte** (cada uno ~400 líneas modificadas, con sus tests y docs):

  | Corte | Contenido | Archivos |
  |---|---|---|
  | C1 | Helper central + migración de call sites + test unitario | `app/Support/FilesystemTime.php`, `StorageSyncService`, `FilesystemDbMatcher`, `FilesystemListingService`, `FileController` (3 sitios), `tests/Unit/FilesystemTimeTest.php` |
  | C2 | Comando de reparación + harness (incluye guard NFS por ancestro) | `Console/Commands/FixFilesModifiedAtTimezoneCommand.php`, `tests/harness_files_mtime_timezone.php` |
  | C3 | Campo de fecha del modal + E2E | `resources/views/files/index.blade.php` (solo el reset de `shareForm`), `tests/playwright_mis_archivos_fechas.py` |
  | C4 | Docs y runbook | `AGENTS.md` (2 secciones nuevas) + este documento |

  **Nota sobre el conteo**: C1 incluye `FileController.php` con 303 adiciones en el diff actual, pero la enorme mayoría son de la feature ajena `feat/files-mirror-elimination`; lo atribuible a este change son 3 líneas. El corte real de C1 es ~600 líneas contando el helper (83), el test (86) y las modificaciones de call sites.

  Elegir la forma NO autoriza push, creación de PR ni merge. Nada de eso se ejecutó.

- **L9** — 2026-11-06 — Cierre: el espejo de recuperación.

  `odd mirror fix-fechas-mis-archivos-shares` → escribió y releyó
  `~/.local/share/odd/mirror/tcloud_v2/fix-fechas-mis-archivos-shares.md` (backend
  `file`, disponible).
  `odd validate` → `Feature document is valid.`

- **L10** — 2026-11-06 — Entrega: commits y push.

  El worktree arrastraba 56 archivos ajenos a esta feature (regresión de
  compartidos, cupos de canales, auditoría de storages, lista de admin,
  retirada mirror). El operador pidió subirlos a GitHub, así que se
  commiteó **el conjunto pendiente** agrupado por feature, cada commit con
  sus tests y docs:

  | Commit | Contenido |
  |---|---|
  | `cfa4a98` | contrato del módulo de compartidos + disponibilidad de archivo |
  | `7d68085` | cupos por par y adopción remota de canales |
  | `c813e0e` | identidad física de `files` + retirada de la familia mirror |
  | `f85d82e` | auditoría de legibilidad y reparación de ownership |
  | `5db6e78` | listado de admin storages sin `files_count` |
  | `32c46f0` | **esta feature**: helper `FilesystemTime`, 12 call sites, comando de reparación, harness y test unitario |
  | `4b7a02f` | modal de enlaces (campo de fecha) + borrado de carpetas + modales |
  | `f504606`, `471e934` | rutas de adopción de canales; comando de remapeo Disco_G→Disco_H |
  | `97d91df` | fixes de harness y archivado de changes |

  Push por SSH a `origin/feat/files-mirror-elimination` (fast-forward, sin
  force). **Excluidos a propósito**: `AGENTS.md.bak` (copia vieja de
  AGENTS.md) y `app/hermes_fix_*.php` (6 scripts de uso único que mutaron
  datos en producción; no son código del proyecto).

  El estado de `git ls-remote` antes del push confirmó que el remoto
  seguía en `b128381`: los cambios **no** estaban en GitHub.
