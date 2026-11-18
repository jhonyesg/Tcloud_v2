# solicitud-puntual-tv-radio

- Objective: Crear un módulo de cliente "Solicitud Puntual de TV y Radio" donde el usuario pide una franja horaria o el día completo de un medio que **no** tiene asignado; el sistema valida que el material cubra el rango, genera enlaces de compartidos con vigencia de 3 días y deja un registro contable con el horario exacto pedido y los archivos exactos entregados con su tamaño.
- Branch: `feat/solicitud-puntual-tv-radio` (a crear desde `main`; la rama actual `feat/dashboard-perf-backup-guide` pertenece a otra feature y está OVER BUDGET — ver L2)
- Delivery strategy: `ask-on-risk` (config `odd/config.yaml`) · chain strategy por decidir al cruzar el presupuesto
- Test runner: `cd app && php tests/harness_solicitud_puntual.php` · `php vendor/bin/phpunit --testsuite Unit` · `openspec validate solicitud-puntual-tv-radio --strict`
- Change OpenSpec: `openspec/changes/solicitud-puntual-tv-radio/` (proposal · design · specs · tasks) — 4/4 artefactos, `validate --strict` en verde
- Forecast authored changed lines: `~1400` (9 grupos, ~42 tareas OpenSpec)

## Specs

- **S1 — Módulo de solicitud puntual de TV y Radio**
  - Requirement (cita textual): "quiero que revises el sistema quiero implementar un modulo llamado Solicitud Puntual de Tv y Radio ese modulo queh ace machea el storage con lso usuarios asignados, de ese modo, si un usuario va la modulo y quiere pedir un medio, que este le liste lo que el no tiene asignado, de ese modo el puede buscar el medio, seleccionar la fehca y el horario y se le registra"
  - Acceptance: existe `/solicitud-puntual`; el listado muestra únicamente medios no asignados; permite buscar por nombre, elegir fecha y rango horario, y registra la solicitud.
  - Check: `harness_solicitud_puntual.php` (catálogo complementario, filtro de búsqueda, filtro TV/Radio) + aserción de UI del listado.

- **S2 — El módulo lista solo lo que el usuario NO tiene asignado**
  - Requirement (cita textual): "Si un usuario ya los tiene asignados, no tiene lógica que le salga ahí en el módulo. Si el usuario tiene asignado, pues no le aparece porque ya lo tiene. Ese módulo es para los medios que no tienen asignado y el usuario necesita una franja o el día completo."
  - Acceptance: un medio se considera asignado si el usuario tiene lectura sobre su storage **o sobre cualquier ancestro** cuya ruta física contenga la ruta interna del medio. Los asignados no aparecen.
  - Check: `harness_solicitud_puntual.php` (caso "el medio asignado no aparece", caso "sub-canal de un storage asignado no aparece").

- **S3 — Validación de la duración**
  - Requirement (cita textual): "con fecha l oque se hace jeeplo si el cliente pide caracol de 14 nov de 12 a 13 se valida que  tenga la duracion"
  - Acceptance: la cobertura se valida contra la grilla de slots del medio, con el paso derivado de los datos (no constante): 15 min TV, 21 min radio nacional, ~14 min radio regional, 20 min en el árbol NFS. La disponibilidad se comprueba en disco, nunca solo en el catálogo.
  - Check: `harness_solicitud_puntual.php` (cobertura completa, cobertura parcial, material fuera de la ventana de retención) + `tests/Unit/PuntualResolverTest.php` (mediana del paso con tres cadencias).

- **S4 — El registro contable**
  - Requirement (cita textual): "Hay que tener presente que hay que colocar el horario exacto que pidió y los archivos exactos que se pasaron con su mil. De ese modo yo puedo llevar un control más fácil a nivel contable, manual que hago, cuánto solicito hicieron y pues realmente cuánto le pueda cobrar por ello."
  - Acceptance: la solicitud guarda solicitante, medio, fecha, rango, modo, estado, nº de archivos, bytes totales, nº de enlaces y vencimiento; el detalle por archivo guarda nombre, ruta y tamaño **snapshot**.
  - Check: `harness_solicitud_puntual.php` (snapshot sobrevive al borrado de la fila en `files`; agregación por cliente y período).

- **S5 — Enlaces de compartidos con vigencia de 3 días**
  - Requirement (cita textual): "y los link de compartidos con vigencia 3 dias que se le genero"
  - Acceptance: el enlace vence 3 días desde la solicitud; no admite `expires_at: null` ni extensión; la revocación lo deja de servir.
  - Check: `harness_solicitud_puntual.php` (vigencia, revocación, idempotencia de la revocación) + `harness_shares_module.php` extendido.

- **S6 — Modo día completo con recomendación por costo**
  - Requirement (cita textual): "También tenemos la opción, en el caso de que no enrique la franja, por un tema de costos siempre se le recomienda que si va a solicitar más de dos horas, mejor solicite el día ya que le sale más económico. Entonces el día es compartir la carpeta del día que ncesite el usuario"
  - Acceptance: por encima del umbral configurable (120 min por defecto) la interfaz compara franja vs día con el nº de enlaces y archivos de cada alternativa y señala el día; el cliente puede elegir la franja igual.
  - Check: `harness_solicitud_puntual.php` (recomendación con y sin superar el umbral; el cliente elige franja) + aserción de UI.

- **S7 — Entrega por franja: un enlace por archivo exacto**
  - Requirement (derivada de S3 y S5): un enlace por archivo exacto que cubre el rango; nunca un enlace a la carpeta del día en modo franja.
  - Acceptance: la franja de 12:00–13:00 en TV genera 4 enlaces, cada uno a su archivo; el historial muestra los 4 con su tamaño.
  - Check: `harness_solicitud_puntual.php` (conteo de enlaces, `file_id` de cada enlace, ausencia de enlace a la carpeta del día).

- **S8 — Entrega por día: un enlace sobre la carpeta del día**
  - Requirement (derivada de S6): un único enlace de solo lectura sobre la carpeta del día, con el detalle de archivos registrado a efectos contables.
  - Acceptance: el modo día genera 1 enlace; la fila de carpeta del día ya existe en `files` (verificado: `Antioquia/BLU/31082026` con `is_folder=true`).
  - Check: `harness_solicitud_puntual.php` (1 enlace, `file_id` = carpeta del día, items con nombre/ruta/bytes).

- **S9 — Historial paginado, buscable y ordenable**
  - Requirement (cita textual): "acá simplemente quedan son los registros y lo voy a les conservar por ahí hace mucho un año, organizados del más reciente al más antiguo, con una paginación, con un cuadro de búsqueda y con que los encabezados, al darle clic se puedan organizar ascendente, descendente, sería lo ideal"
  - Acceptance: historial del solicitante de más reciente a más antiguo, paginado, con buscador y encabezados que alternan asc/desc; estado con vocabulario cerrado `ready|expired|unavailable|revoked`.
  - Check: `harness_solicitud_puntual.php` (orden por defecto, orden por columna, filtro de búsqueda) + aserción de UI.

- **S10 — Remapeo de los medios de Emisoras 01/02/03/04**
  - Requirement (cita textual): "lo que sí se tiene que vincular es bien son los medios y en especial los que están dentro de emisora 01, 02, 03, 04. Estos toca es remapearlos y tener como en la parte de almir como un botón para remapearlos si yo llego a agregar y que aparezcan disponibles en el módulo que va de cara al cliente."
  - Acceptance: `/admin/medios` ofrece una acción de remapeo por storage contenedor; previsualiza antes de escribir; el comando `medios:remapear` comparte las validaciones; los ~84 sub-canales de RD1/RD4/RD6 y Radios/Bogota quedan declarados como medios y aparecen en el módulo cliente.
  - Check: `harness_solicitud_puntual.php` (el remapeo no crea storages ni altera el conteo de `files`; los sub-canales declarados aparecen en el complemento del cliente).

- **S11 — Ubicación en la navegación**
  - Requirement (cita textual): "este nuevo medio iría en navegación debajo de compartido como solicitudes o podría ir en parte del multimedia"
  - Acceptance: `/solicitud-puntual` entra en el grupo cliente del sidebar inmediatamente después de "Compartidos", con el nombre "Solicitud Puntual" (no "Solicitudes": el backup ya usa ese concepto dentro de Mis Archivos).
  - Check: aserción de UI + `data-nav-path="/solicitud-puntual"` en `layouts/app.blade.php`.

- **S12 — Ventana de retención de 48–50 días**
  - Requirement (cita textual): "Sí, el umbral actual es el óptimo, que son 48 a 50 días aproximadamente. Entonces no hay lío"
  - Acceptance: el módulo no extiende la retención; la ventana es la del árbol de origen (48 días de carpetas-día por canal, verificado). El árbol de producción (13 días, NFS) no se usa como fuente.
  - Check: `harness_solicitud_puntual.php` (una fecha fuera de la ventana se reporta como no disponible).

- **SUPUESTOS (no afirmados por el usuario)**
  - Supuesto 1: la vigencia de 3 días es **calendario** y se cuenta **desde el momento de la solicitud** (consistente con `config/backups.php`, que mide `link_validity_days` desde la creación).
  - Supuesto 2: el umbral de recomendación del día (120 min) es un **valor de configuración**, no el punto de equilibrio de una tarifa (no hay tarifas en el sistema y no se introducen).
  - Supuesto 3: la fuente del material es el **árbol de origen** (disco local, 48 días), no el árbol de producción NFS (13 días, grilla de 20 min, maestro distinto).
  - Supuesto 4: la longitud mínima del motivo es 10 caracteres (mismo valor que `config/backups.php`).

## Tasks

- [x] **T1 — Esquema: `medios`, `solicitud_puntual`, `solicitud_puntual_item` + índice único parcial** (S1,S4,S5) — inline — `caf6cda` — risk: item 1 (migración de esquema; aditiva y reversible) — 3 migraciones aplicadas, `down()` verificado, el índice parcial rechaza el duplicado con 23505
- [x] **T2 — Modelos `Medio`, `SolicitudPuntual`, `SolicitudPuntualItem` + relaciones inversas** (S1,S4,S5) — inline — `caf6cda` — risk: none — `Share::solicitudPuntualItem()` y `Share::solicitudPuntual()` para las dos familias de enlace; `StorageProvider::medios()`
- [x] **T3 — `MedioCatalogService::availableFor()`: el complemento no asignado** (S2) — inline — `a3ee014` — risk: none — regla de ancestro verificada con Punto (90 de 106 asignados, el sub-canal dentro de un storage asignado desaparece)
- [x] **T4 — `MedioCatalogService::detectSubchannels()` + `proposeName()` + `declare()` + `resolveTarget()`** (S10) — inline — `a3ee014`, `a0b26f5` — risk: none — 122 canales detectados en los 5 contenedores; 56 ya tienen storage propio y se reaprovechan
- [x] **T5 — Comandos `medios:bootstrap` y `medios:remapear`** (S10) — inline — `2ddd85b` — risk: item 1 (escribe `medios`; dry-run por defecto) — bootstrap 1,1 s / 124 canales; remapeo 214 detectados
- [x] **T6 — `Admin\MedioController` + rutas `/admin/medios`** (S10) — inline — `2ddd85b` — risk: item 2 (superficie HTTP de administración que escribe el catálogo) — 200 con sesión admin; 403 sin ella
- [x] **T7 — `PuntualResolver`: localización, cadencia y slots** (S3,S7) — inline — `a9d68f3` — risk: none — cobertura mínima de intervalos; paso por mediana; anclaje por nombre
- [x] **T8 — `PuntualResolver::coverage()` + `dayFolder()` + materialización** (S3,S8,S12) — inline — `a9d68f3` — risk: item 3 (el sync escribe `files`; idempotente, con guardas de purga) — Caracol TV 14/11 12:00-13:00 → 4 archivos, 904.352.402 bytes, 100 %
- [x] **T9 — `PuntualRequestService`: estimate, create, revoke, listFor, payload** (S4,S5,S6,S7,S8,S9) — inline — `73ae53c`, `575584a` — risk: item 2 (emite enlaces públicos a material no asignado) — ciclo completo verificado: alta, idempotencia, historial, revocación
- [x] **T10 — `config/puntual.php`** (S5,S6) — inline — `73ae53c` — risk: none — vigencia 3 días, umbral 120 min, motivo mínimo 10
- [x] **T11 — Guard de compartidos generalizado + fix del `use` faltante + bug del `confirm_count`** (S5,S9) — inline — `98fcb87` — risk: item 3 (cambia `ShareController` para las dos familias) — `isRequestManaged()` cubre las tres relaciones; el `catch (QueryException)` ya es alcanzable
- [x] **T12 — `SolicitudPuntualController` + rutas cliente** (S1,S2,S5,S9) — inline — `bbf33a2` — risk: item 2 (nueva superficie HTTP que emite enlaces) — 6 rutas con `auth`; throttle añadido después de la auditoría
- [x] **T13 — Vista del cliente: catálogo + modal de solicitud** (S1,S3,S6,S7,S8,S11) — inline — `e17ec48` — risk: none — 58.530 bytes renderizados
- [x] **T14 — Vista del cliente: historial paginado, buscable y ordenable** (S9) — inline — `e17ec48` — risk: none
- [x] **T15 — Alpine `puntualManager()` + sidebar + build de Tailwind + guía interactiva** (S1,S6,S9,S11) — inline — `e17ec48` — risk: none — el CSS compilado bajaba de 106 KB a 89 KB (el commiteado arrastraba utilidades muertas del Play CDN retirado)
- [x] **T16 — Vista de administración: medios declarados + acción de remapeo** (S10) — inline — `2ddd85b` — risk: none
- [x] **T17 — `tests/harness_solicitud_puntual.php`** (S2,S3,S4,S5,S6,S7,S8,S9,S10,S12) — inline — `98fcb87`, `575584a` — risk: none — 46 aserciones, todas en verde
- [x] **T18 — Extender `harness_route_contract.php` y `harness_shares_module.php`** (S5,S9,S11,S12) — inline — `5c964f1` — risk: none — 312 rutas 0 huérfanas; 8 aserciones nuevas del guard
- [x] **T19 — `tests/Unit/PuntualResolverTest.php`** (S3,S10) — inline — `5c964f1` — risk: none — 11 tests
- [x] **T20 — Verificador independiente de seguridad** (S2,S5) — delegated — `575584a` — risk: item 2 — 2 CRÍTICOS, 2 ALTOS, 2 MEDIOS, todos corregidos y re-verificados (15/15)
- [x] **T21 — Documentar el contrato en `AGENTS.md` y cerrar el change** (todas) — inline — `6b32839` — risk: none

## Acceptance Criteria

1. `/solicitud-puntual` lista únicamente los medios que el solicitante no tiene asignados, con búsqueda por nombre y filtro TV/Radio.
2. El usuario elige fecha y rango horario; el sistema muestra la cobertura real (slots, archivos, bytes) antes de confirmar.
3. La cobertura se valida contra la grilla del medio con paso derivado de los datos, y la disponibilidad se comprueba en disco.
4. La entrega por franja genera un enlace por archivo exacto y nunca un enlace a la carpeta del día.
5. La entrega por día genera un único enlace sobre la carpeta del día.
6. Por encima del umbral (120 min) la interfaz recomienda el día comparando enlaces y archivos, sin bloquear la franja.
7. Todos los enlaces vencen 3 días desde la solicitud y no admiten quedar sin vencimiento ni ser extendidos.
8. La solicitud registra el horario exacto, los archivos exactos y sus bytes; el registro sobrevive al prune del catálogo.
9. El historial es paginado, buscable y ordenable, y solo muestra las solicitudes del solicitante.
10. El guard de compartidos protege los enlaces de ambos flujos en edición, borrado y depuración masiva.
11. El remapeo declara los sub-canales de los contenedores Emisoras **sin** crear storages ni mover filas de `files`.
12. `openspec validate solicitud-puntual-tv-radio --strict` en verde y `harness_route_contract.php` sin rutas huérfanas nuevas.

## Verification

Checks observados (ejecutados contra PostgreSQL, Redis y el disco de producción):

| Check | Resultado |
|---|---|
| `php tests/harness_solicitud_puntual.php` | OK — 46 aserciones |
| `php vendor/bin/phpunit tests/Unit/PuntualResolverTest.php` | OK — 11 tests, 16 assertions |
| `php vendor/bin/phpunit --testsuite Unit` | 418 tests · 10 errores · 7 fallos — **idéntico a HEAD** (407/10/7): los 17 son preexistentes |
| `php tests/harness_route_contract.php` | OK — 312 rutas verificadas, 0 huérfanas |
| `php tests/harness_shares_module.php` | OK — todas las aserciones |
| `php tests/harness_backup_requests.php` | OK — todas las aserciones |
| `php tests/tailwind_coverage_check.php` | OK — 28.919 clases verificadas |
| `php artisan view:cache` | OK — Blade compilado |
| Flujo HTTP real (sesión de Punto) | catálogo 140 medios · estimate 200 · alta 201 con 4 enlaces · historial 200 · revocación 200 · motivo corto 422 |
| Verificador independiente | 2 CRÍTICOS + 2 ALTOS + 2 MEDIOS → corregidos y re-verificados 15/15 |

## Progress

- 2026-11-18 — Exploración completa sobre el código y la BD de producción. Hallazgos que condicionan el diseño: `files` no tiene columna de duración; coexisten dos formatos de `path`; la grilla de emisión no es uniforme entre medios (15/21/~14/20 min); los sub-canales de Emisoras 01/02/03/04 son 122 y no son storages; el árbol de origen retiene 48 días y el de producción 13.
- 2026-11-18 — Decisiones del usuario resueltas y registradas en Specs: S2 (solo no asignados), S4 (registro contable sin tarifas), S9 (historial paginado/buscable/ordenable), S12 (48–50 días), S6 (umbral de 2 h → día), S10 (botón de remapeo en admin), S11 (navegación).
- 2026-11-18 — Change OpenSpec `solicitud-puntual-tv-radio` creado con 4/4 artefactos y `validate --strict` en verde.
- 2026-11-18 — Implementación en un **worktree aislado** (`/tmp/kilo/tcloud-puntual`, rama `feat/solicitud-puntual-tv-radio` desde `f0d4890`): el checkout principal tenía cambios sin commitear de otra feature, y `StorageProvider.php` —un archivo que este change toca— estaba entre ellos. 12 commits, una unidad de trabajo cada uno.
- 2026-11-18 — Auditoría de seguridad por subagente independiente (riesgo alto del change): 2 CRÍTICOS, 2 ALTOS, 2 MEDIOS. Todos corregidos y re-verificados con 15/15 comprobaciones antes de cerrar.
- 2026-11-18 — Catálogo poblado en producción: 253 medios activos (35 TV + 218 radio), 128 con ruta interna. El medio del árbol de producción (`35 Red Mas BK`) quedó dado de baja.

## Next Step

El change queda listo para archivar. Lo que falta es del lado del operador, no del código:

1. **Decidir el merge.** La rama `feat/solicitud-puntual-tv-radio` está en el worktree `/tmp/kilo/tcloud-puntual`, no en el checkout principal. El CSS compilado (`app/public/css/app.css`) hay que **reconstruirlo en el destino**: el checkout principal tenía un build pendiente de otra feature y las dos ramas tocan el mismo archivo.
2. **Revisar el catálogo con el operador.** 253 medios y 122 sub-canales declarados a partir de la detección física. Los nombres y las regiones son propuestos por el sistema y editables en `/admin/medios`.
3. **Abrir los tickets de los hallazgos preexistentes** que el verificador encontró fuera de este change: `can_create_shares` sin aplicar en `POST /shares`, `FileController::index` con `sync=1` sin comprobar permiso, el soft-404 con HTTP 200 del visor público y la ruta huérfana de transcripción.

## Log

- **L1** — 2026-11-18 — Pedido original del usuario, textual:

  > "quiero que revises el sistema quiero implementar un modulo llamado Solicitud Puntual de Tv y Radio ese modulo queh ace machea el storage con lso usuarios asignados, de ese modo, si un usuario va la modulo y quiere pedir un medio, que este le liste lo que el no tiene asignado, de ese modo el puede buscar el medio, seleccionar la fehca y el horario y se le registra, con fecha l oque se hace jeeplo si el cliente pide caracol de 14 nov de 12 a 13 se valida que  tenga la duracion y en la solciitud quede le registro de el usuariol la hora medio, que fechay rano pidio y los link de compartidos con vigencia 3 dias que se le genero"

- **L2** — 2026-11-18 — Estado del worktree al arrancar: la rama actual es `feat/dashboard-perf-backup-guide` (otra feature, `odd status` la reporta OVER BUDGET 48508/400). Este change **no** debe implementarse sobre esa rama: crear `feat/solicitud-puntual-tv-radio` desde `main`. Hay archivos sin trackear ajenos (`AGENTS.md.bak`, `odd/tasks/dashboard-perf-backup-guide.md`) que no se tocan.

- **L3** — 2026-11-18 — TDD: `odd/config.yaml` declara `tdd.mode: off` (source: config) → verificación funcional y estructural proporcional, sin ciclo RED/GREEN obligatorio.

- **L4** — 2026-11-18 — Decisiones del usuario durante la exploración (citadas textuales en Specs S2, S4, S6, S9, S10, S11, S12): el módulo es solo el complemento de lo no asignado; no hay tarifas, solo registro; el historial va paginado, buscable y ordenable; por encima de dos horas se recomienda el día; los sub-canales de Emisoras 01/02/03/04 se remapean desde un botón de admin; el módulo va bajo "Compartidos".

- **L5** — 2026-11-18 — Hallazgo de la exploración que condiciona toda la arquitectura: los sub-canonales de RD1/RD4/RD6 viven dentro del `base_path` del storage contenedor. Crearles un `storage_providers` propio hace que el cron `storage:sync --all` (15 min) vuelva a crear sus filas bajo el contenedor → **duplicación del catálogo** y navegación BD-first huérfana. Por eso el medio se modela como un índice `(storage_id, subpath)` en una tabla nueva, sin tocar `storage_providers` ni `files`. Precedente explícito en el repo: la propuesta `solicitud-backup-mis-archivos` ya descartó ese patrón.

- **L6** — 2026-11-18 — Segundo hallazgo: `files` tiene UNIQUE `(storage_provider_id, path)` y conviven dos formatos de `path` para un mismo storage (el relativo al `base_path` y un legacy relativo a la raíz Tcloud). Un ancla por prefijo de `path` deja filas fuera. El material se localiza por `storage_provider_id` + regex del **nombre** (que ya lleva `DDMMYYYY_HHMMSS`) + sufijo de `path`.

- **L7** — 2026-11-18 — Tercer hallazgo: la grilla de emisión **no es uniforme** (15 min TV, 21 min radio nacional, ~14 min irregular en radio regional, 20 min en el árbol NFS de producción). El paso se deriva por medio con la mediana de los deltas entre inicios de captura consecutivos. Una constante global daría cobertura falsa en tres de los cuatro casos.

- **L8** — 2026-11-18 — Cuarto hallazgo (bug preexistente, no del change): `BackupRequestService` usa `catch (QueryException $e)` sin el `use Illuminate\Database\QueryException`. En PHP eso resuelve a `App\Services\MisArchivos\QueryException`, que no existe: una violación real de `23505` no entra al catch y sube como 500 en vez de devolver la solicitud ganadora. El harness actual no lo detecta porque provoca el `23505` con SQL crudo. Se corrige en T11, que también generaliza el guard de compartidos.

- **L9** — 2026-11-18 — El checkout principal tenía cambios SIN COMMITear de otra feature (`StorageProvider.php` entre ellos, +197 líneas), y `f0d4890` tiene 19 commits que `main` no tiene: **el módulo de backup vive en esa cadena, no en `main`**. Implementar sobre `main` habríaSquared la base del módulo de backup del que este change es patrón. Se creó un worktree aislado con `.env` y `node_modules` enlazados (el `.env` que lee Laravel es `app/.env`, NO el de la raíz del repo: hay dos, y el de la raíz tiene `REDIS_PASSWORD=null` — el config cacheado es el que tiene el valor bueno).

- **L10** — 2026-11-18 — Se inventaron dos tablas con nombre singular (`medios`, `solicitud_puntual`) y Laravel las pluralizaba (`medios` ok, `solicitud_puntuals` no). Los modelos fijan el nombre de tabla explícito, como `watermark_audit_log`.

- **L11** — 2026-11-18 — **Auditoría de seguridad (subagente independiente, T20).** Hallazgos y correcciones:
  - **CRÍTICO C1**: la ficha de cobertura devolvía `file_id` y `path`, que eran el oráculo de un hueco PREEXISTENTE en `/media/{id}/preview` (`MediaPreviewController` no comprobaba permiso). Con el oráculo, la vigencia de 3 días y la revocación eran decorativas. Corregido por los dos lados: la respuesta ya no lleva identificadores internos y `MediaPreviewController` comprueba el mismo permiso que `FileController::preview()`.
  - **CRÍTICO C2**: el árbol de producción (`Disco_D/backup`) estaba en el catálogo del cliente y era pedible con enlaces de 3 días. El design lo excluía como fuente. Corregido: `flatChannelStorages()`, `availableFor()` y `declare()` lo excluyen; el medio `35 Red Mas BK` quedó dado de baja.
  - **ALTO A1**: `create()` no revalidaba que el medio estuviera activo ni que no estuviera asignado al solicitante (el catálogo filtraba, un POST directo se saltaba el filtro).
  - **ALTO A2**: `estimate()` materializaba el catálogo: escribía filas en `files` desde una API de solo lectura, y cada petición podía disparar `scandir` síncronos sobre NFS `hard`.
  - **MEDIO M1/M2**: `24:00`, `25:00`, `abc` y `-1:00` daban 500 opaco o se persistían.
  - **Preexistentes, fuera de scope** (con repro para ticket): `can_create_shares` sin aplicar en `POST /shares`, `FileController::index` con `sync=1` sin comprobar permiso, soft-404 con HTTP 200 en el visor público, ruta huérfana de transcripción.

- **L12** — 2026-11-18 — El harness del change usa una fila **sintética** de `files` para probar que el snapshot contable sobrevive al prune: una versión anterior borraba una fila de catálogo REAL de producción. El `storage:sync --force` se usó para reindexar el storage afectado.

- **L13** — 2026-11-18 — La suite Unit da 418 tests / 10 errores / 7 fallos con estos cambios, frente a **407 / 10 / 7 en HEAD limpio** (worktree separado). La diferencia son los 11 tests nuevos: cero regresiones. Los 17 problemas son preexistentes.
