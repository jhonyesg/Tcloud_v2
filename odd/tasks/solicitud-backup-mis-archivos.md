# solicitud-backup-mis-archivos

- Objective: Agregar a Mis Archivos un submódulo de "Solicitar backup" sobre las carpetas-día (`DDMMYYYY`): el cliente pide el material de producción de un día, el sistema crea automáticamente un enlace público de solo lectura con 2 días de vigencia, y el cliente ve el enlace en el historial de solicitudes.
- Branch: `feat/files-mirror-elimination` (worktree con cambios locales ajenos sin commitear; ver L2)
- Delivery strategy: `ask-on-risk` · chain strategy por decidir cuando se cruce el presupuesto (ver L6)
- Test runner: `cd app && php vendor/bin/phpunit --testsuite Unit` · harnesses `php tests/harness_*.php` · `openspec validate <change> --strict`
- Change OpenSpec: `openspec/changes/solicitud-backup-mis-archivos/` (proposal · design · specs · tasks)
- Forecast authored changed lines: `~900` (45 tareas OpenSpec, 9 unidades de trabajo)

## Specs

- **S1 — Submódulo de backup sobre las carpetas de los meses**
  - Requirement (cita textual): "Necesito el módulo mis archivos por integrar un submódulo parecido a las opciones que tiene, pero específicamente sobre las carpetas de los meses, sobre las fechas, a qué voy con ello."
  - Acceptance: en el listado de Mis Archivos, una carpeta cuyo nombre sea un día `DDMMYYYY` de un storage con backup mapeado muestra una acción "Solicitar backup".
  - Check: Playwright/manual sobre `/files` en el storage `01 Caracol Tv`; el botón solo aparece sobre `05112026`, `04112026`, `03112026` y no sobre archivos ni sobre carpetas que no son día.

- **S2 — Modal con explicación mínima y solicitud**
  - Requirement (cita textual): "hay un botón que abre un pequeño modal y que va a hacer ese modal va a decir solicitar backup entonces solicito bacat que sucedió que por lo menos haga una pequeña explicación y hace la solicitud"
  - Acceptance: el modal muestra "Solicitar backup", la ficha resuelta y un campo de motivo obligatorio; sin motivo válido no se crea nada.
  - Check: `harness_backup_requests.php` (motivo vacío / corto / válido) + aserción de UI del botón deshabilitado.

- **S3 — El caso de Caracol y su ruta de backup**
  - Requirement (cita textual): "entonces yo lo que hago es que le digo ah listo caracol tiene esta ruta y el backup es la siguiente /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_D/backup/01_Caracol_BK"
  - Acceptance: el storage `01 Caracol Tv` (id 6) queda mapeado al storage 34 (`Disco_D/backup`) con la carpeta `01_Caracol_BK`; el día `05112026` resuelve a `files.id = 8619379`.
  - Check: `SELECT backup_storage_id, backup_folder_name FROM storage_providers WHERE id = 6` + query de la fila del día.

- **S4 — Enlace automático al material del día**
  - Requirement (cita textual): "lo único que acá yo necesito es enlazar que cuando alguien pide backup automáticamente lo que él hace es que busca el recurso con la carpeta en día y lo comparte."
  - Acceptance: al confirmar, existe un `Share` de solo lectura sobre la carpeta-día del backup, creado sin intervención de un administrador.
  - Check: `harness_backup_requests.php` + fila en `shares` con `permissions = 'read'`.

- **S5 — Vigencia del enlace**
  - Requirement (cita textual): "Podría ser un link de duración de dos días hábiles." + decisión textual del usuario: "por defecto daler 2 dias de vigencia al link generado"
  - Acceptance: `expires_at` = creación + 2 días (días calendario, `config('backups.link_validity_days')`).
  - Check: aserción del harness sobre `link_expires_at` y `shares.expires_at`.

- **S6 — El cliente vuelve y encuentra su enlace**
  - Requirement (cita textual): "De ese modo el cliente va nuevamente, va a buscar a esa solicitud de backup que hizo y ve el link directo que se abre en una pestaña nueva."
  - Acceptance: existe una vista de historial en Mis Archivos con las solicitudes propias; cada solicitud viva muestra su enlace con apertura en pestaña nueva.
  - Check: `GET /files/backup-requests` devuelve solo las propias; la vista tiene el tercer `viewMode` (`backups`).

- **S7 — Mapeo del vínculo (decisión del usuario)**
  - Requirement (cita textual del usuario al elegir): "Opción D — FK en el storage origen (Recomendada)"
  - Acceptance: `storage_providers` tiene `backup_storage_id` + `backup_folder_name`; NO se crean storages nuevos por canal de backup ni se mueven filas de `files`.
  - Check: `SELECT count(*) FROM storage_providers` sin increase por canal; `files` sin filas movidas; grep sin `backup_of_storage_id`.

- **S8 — Solicitud sin aprobación (decisión del usuario)**
  - Requirement (cita textual del usuario al elegir): "Automático al instante (Recomendado)"
  - Acceptance: el `Share` se crea en el mismo POST de la solicitud; no existe estado intermedio ni bandeja de aprobación.
  - Check: `harness_backup_requests.php` (el POST devuelve el enlace en la misma respuesta).

- **S9 — Autorización del pedido**
  - Requirement (derivada del spec `backup-request-workflow`): solo quien tiene lectura sobre el storage de origen puede pedir el backup; el cliente no recibe permisos sobre el storage de backup.
  - Acceptance: un usuario sin `read` sobre el origen recibe 403; el cliente no ve el árbol de backup en su listado de storages.
  - Check: aserciones del harness + `user_storages` sin filas nuevas para el cliente.

- **SUPUESTOS (no afirmados por el usuario)**
  - Supuesto 1: "la carpeta en día" del lado del backup tiene el **mismo** nombre `DDMMYYYY` que la carpeta del lado origen (verificado: `Canal_caracol/05112026` ↔ `01_Caracol_BK/05112026`).
  - Supuesto 2: la vigencia de 2 días es **calendario**, no hábil: el usuario respondió "2 dias de vigencia" y el repo no tiene helper de días hábiles.
  - Supuesto 3: la longitud mínima del motivo es 10 caracteres (valor de diseño, no del usuario).
  - Supuesto 4: el backup se considera "no disponible" si la carpeta-día no existe en el árbol; el árbol rota con ~13 días de retención y la copia del día en curso llega a medida que el encoder escribe.

## Tasks

- [ ] **T1 — Esquema: dos columnas en `storage_providers` + tabla `backup_requests`** (S3,S5,S7) — inline — pending — commit: - — evidence: - — risk: item 1 (migración de esquema; aditiva y reversible)
- [ ] **T2 — Config `backups.php` + modelo `BackupRequest`** (S5) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T3 — Helpers de mapeo en `StorageProvider`** (S7,S9) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T4 — `BackupResolver`: día válido, resolución anclada en `backup_storage_id`, contención por cadena, `MountGuard`** (S1,S3,S4) — inline — pending — commit: - — evidence: - — risk: item 2 (decide a qué material se da acceso)
- [ ] **T5 — `BackupRequestService`: create/revoke/listFor, idempotencia, auditoría** (S4,S5,S6,S8,S9) — inline — pending — commit: - — evidence: - — risk: item 2 (crea un enlace público al master de producción)
- [ ] **T6 — API: endpoints + rutas bajo `auth`** (S4,S6,S9) — inline — pending — commit: - — evidence: - — risk: item 2 (nueva superficie HTTP que emite enlaces públicos)
- [ ] **T7 — UI: botón en ACCIONES + modal de solicitud** (S1,S2,S4) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T8 — UI: tercer `viewMode` `backups` + historial** (S6) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T9 — Comando `backups:map-storages` (dry-run por defecto)** (S3,S7) — inline — pending — commit: - — evidence: - — risk: item 1 (escribe `storage_providers`; dry-run por defecto y snapshot)
- [ ] **T10 — Harness `tests/harness_backup_requests.php`** (S2,S4,S5,S6,S8,S9) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T11 — Verificación: harnesses existentes + `openspec validate`** (S1,S2,S3,S4,S5,S6,S7,S8,S9) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T12 — Documentar el contrato en `AGENTS.md`** (S1,S2,S3,S4,S5,S6,S7,S8,S9) — inline — pending — commit: - — evidence: - — risk: none
- [ ] **T13 — Verificador independiente del flujo de escalada** (S4,S5,S9) — delegated — pending — commit: - — evidence: - — risk: item 2 (revisión de seguridad obligatoria)
- [ ] **T14 — Mapeo real de los storages de origen** (S3,S7) — inline — pending — commit: - — evidence: - — risk: item 1 (UPDATE sobre `storage_providers`)

## Acceptance Criteria

1. Un usuario con `read` sobre el storage de origen ve el botón "Solicitar backup" en sus carpetas-día y solo en ellas.
2. El modal exige un motivo de longitud mínima y explica la disponibilidad del backup **antes** de enviar.
3. Al confirmar, existe un `Share` de solo lectura sobre la carpeta-día del backup, con `expires_at` = creación + 2 días, y la solicitud queda en estado `ready` con su `public_url`.
4. La resolución de la carpeta-día usa el `backup_storage_id` declarado y nunca una búsqueda por nombre entre storages (las 189 filas duplicadas del storage 5 con otro rango de fechas no son alcanzables).
5. Un usuario sin `read` sobre el origen no puede crear solicitudes; el cliente no recibe permisos sobre el storage de backup.
6. El historial lista solo las solicitudes del usuario, muestra el enlace de las vivas, permite abrirlo en pestaña nueva y explica las no disponibles.
7. La revocación invalida el enlace de inmediato y deja la solicitud en `revoked`.
8. Si la carpeta-día desaparece del árbol de backup, la solicitud sobrevive y el historial la reporta como no disponible (no hay enlace roto).
9. `shares.file_id` con `ON DELETE CASCADE` no deja solicitudes colgando.
10. `openspec validate solicitud-backup-mis-archivos --strict` en verde y `harness_route_contract.php` sin rutas huérfanas nuevas.

## Verification

Checks observados (se completan a medida que avanza la implementación):

| Check | Resultado |
|---|---|
| `openspec validate solicitud-backup-mis-archivos --strict` | pendiente |
| `php tests/harness_backup_requests.php` | pendiente |
| `php tests/harness_route_contract.php` | pendiente |
| `php tests/harness_shares_module.php` | pendiente |
| `php tests/harness_merged_into_rename_compat.php` | pendiente |
| `php tests/harness_modules_rename_audit.php` | pendiente |
| `php vendor/bin/phpunit tests/Unit/BackupResolverTest.php` | pendiente |
| `php artisan backups:map-storages` (dry-run) | pendiente |

## Progress

- 2026-11-06 — Feature creada. Change OpenSpec `solicitud-backup-mis-archivos` con 4/4 artefactos y `openspec validate --strict` en verde. Decisiones del usuario ya resueltas: mapeo opción D, enlace automático, vigencia 2 días.
- 2026-11-06 — T1 en curso.

## Next Step

T1: migraciones (`backup_storage_id` + `backup_folder_name` en `storage_providers`; tabla `backup_requests`).

## Log

- **L1** — 2026-11-06 — Pedido original del usuario, textual:

  > "Necesito el módulo mis archivos por integrar un submódulo parecido a las opciones que tiene, pero específicamente sobre las carpetas de los meses, sobre las fechas, a qué voy con ello. En el ejemplo hay un botón que abre un pequeño modal y que va a hacer ese modal va a decir solicitar backup entonces solicito bacat que sucedió que por lo menos haga una pequeña explicación y hace la solicitud que hace ello en este caso que es caracol entonces yo lo que hago es que le digo ah listo caracol tiene esta ruta y el backup es la siguiente /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_D/backup/01_Caracol_BK Entonces como estamos manejando casi la misma estructura que maneja Producción, lo único que acá yo necesito es enlazar que cuando alguien pide backup automáticamente lo que él hace es que busca el recurso con la carpeta en día y lo comparte. Podría ser un link de duración de dos días hábiles. De ese modo el cliente va nuevamente, va a buscar a esa solicitud de backup que hizo y ve el link directo que se abre en una pestaña nueva. por ello quiero que lo explore para que lo entiendas lo finamos y asi poder contruir un bueno propose para ello"

- **L2** — 2026-11-06 — Estado del worktree al arrancar: `git status` muestra `AGENTS.md` modificado y varios archivos sin trackear (`AGENTS.md.bak`, `CLAUDE.md`, `app/hermes_fix_espejos.php` … `app/hermes_fix_v6.php`) que **no** pertenecen a esta feature. No se tocan ni se commitean. El árbol de `openspec/` está limpio: el change `solicitud-backup-mis-archivos` es lo único sin trackear de este trabajo.

- **L3** — 2026-11-06 — TDD: `odd/config.yaml` declara `tdd.mode: off` (source: config) → verificación funcional/estructural proporcional, sin ciclo RED/GREEN obligatorio.

- **L4** — 2026-11-06 — Decisiones tomadas por el usuario durante la exploración (citadas textuales en Specs S5, S7, S8): mapeo por FK en el storage origen; creación automática del enlace sin aprobación; vigencia por defecto de 2 días.

- **L5** — 2026-11-06 — Hallazgo de la exploración que condiciona la implementación: `files` tiene índice UNIQUE `(storage_provider_id, path)` y existen **dos catálogos paralelos** del mismo árbol de backup — el storage 34 (`Disco_D/backup`, 8.707 filas, días 24 oct–5 nov) y el storage 5 (`00 Discos`, 189 filas `Disco_D/backup/01_Caracol_BK/**`, días 18–20 oct). Por eso la resolución se ancla en `backup_storage_id` y nunca busca por nombre. El design registra además que `shares.file_id` es `ON DELETE CASCADE`: cuando la carpeta-día rota fuera del árbol, el share muere solo, y por eso `backup_requests` es la fuente de verdad.

- **L6** — 2026-11-06 — Riesgo de entrega: `odd lines` reporta la branch en 37.488 líneas modificadas contra un presupuesto de 400 (acumulado histórico de la branch, no de esta feature). El presupuesto de esta feature se mide sobre su propio diff. Al cruzar ~400 líneas acumuladas de esta feature se aplica la estrategia `ask-on-risk` (pregunta única con el menú ordenado de ODD).

- **L7** — 2026-11-06 — **Prueba bk previa a implementar** (la que pidió el usuario). Verificación en producción, sin crear nada: el enlace público usado ya existía.

  1. **Montaje**: `192.168.0.131:/media/difusor02/Backup_TV` está montado en `Tcloud/Disco_D` (nfs4, modo **hard**); `backup/` es un subdirectorio del montaje, no un montaje propio. 28 carpetas raíz.
  2. **Estructura**: las 28 carpetas raíz tienen carpetas-día `DDMMYYYY` y **el día de hoy (05112026) está en las 28**. Retención 13 días típica (24 oct–5 nov), rango real 1–26 según canal.
  3. **El catálogo no sirve como disponibilidad**: el master (storage 34) tiene 538 carpetas-día catalogadas y **379 (70%) ya no existen en disco**. La disponibilidad se tiene que verificar en disco (tarea 2.5 del change), nunca leer del catálogo.
  4. **Duplicidad confirmada** (coherente con L5): 136 carpetas-día están duplicadas entre el master (34) y la raíz (5 `00 Discos`), y 13 entre el master (34) y el sub-storage 131 (`05_Redmas`), con otra forma de path. El resolver debe anclarse en `backup_storage_id` y nunca buscar por nombre.
  5. **La cadena funciona de punta a punta**: `shares.id=1298` (enlace ya vivo en producción) apunta a `files.id=8616067` = `03_City_BK/05112026`. `GET /s/{token}` → **HTTP 200**, 123 KB de página y los **70 archivos** del día listados; `GET /s/{token}/download/8619376` → **HTTP 200**, `content-length: 200135107` (idéntico al tamaño en disco), `content-disposition` correcto y los **primeros 64 KB byte a byte iguales** al archivo del NFS. No se creó ningún enlace para la prueba; solo se escribieron filas de `share_access_log`.
  6. **La escalada de privilegio es real**: el master (34) solo tiene `jsuarez` con `full`; el sub-storage 131 da `read` a 3 usuarios. El cliente **no** tiene acceso al master → el enlace público es la única concesión. Exige verificador independiente.
  7. **La premise "copia de mayor calidad" no se sostiene**: la fuente graba bloques de ~930 s (15,5 min) y el backup bloques de ~1.230 s (20,5 min) → **segmentación distinta**. El bitrate va por canal: City 1,21 Mbps (backup) vs 2,79 (fuente) → el backup es PEOR; Caracol 2,80 vs 1,78 → mejor. El texto del modal no debe prometer "mejor calidad" sino "copia del master de producción".
  8. **Integridad**: 5 de 75 bloques del backup de HOY (28 canales × 3 primeros) **no son reproducibles** (`moov atom not found`): Telecafe, Caracol Ahora, Blu y negocios_ditu ×2. Control en el árbol de origen: 0 de 6 corruptos. "Existe en disco" ≠ "reproducible" → la disponibilidad del módulo debería poder reportar bloque corrupto.
  9. **`MountGuard::isNetworkMount()` devuelve false** para `Disco_D/backup/...` (solo matchea el path exacto del montaje) → el resolver **no** puede usar esa llamada para proteger el `stat()` sobre un NFS en modo `hard`. La lógica de ancestro existe pero vive como método privado en `FixFilesModifiedAtTimezoneCommand::enclosingNetworkMount()`; hay que extraerla a un helper compartido antes de escribir el resolver.
  10. **El mapeo no es deducible del nombre** y sigue vacío (0 filas): la numeración del backup no corresponde a la de la plataforma (`09 Redmas` ↔ `05_Redmas`, `05 Institucional` ↔ `22_Institucional_BK`, `13_Telecafe_BK` y `13_Telepacifico_Bk` comparten prefijo). El vínculo tiene que ser explícito y confirmado por el operador.

  Consecuencia para T2: el resolver tiene que (a) anclar en `backup_storage_id`, (b) verificar en disco con el guard de ancestro de montaje, (c) parsear el día desde el **último segmento** del path (`03_City_BK/05112026`, no `^\d{8}$` sobre el path completo) y (d) no prometer calidad superior.
