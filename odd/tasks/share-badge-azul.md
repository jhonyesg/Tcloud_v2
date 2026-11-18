# share-badge-azul

- Objective: En Mis Archivos, diferenciar a nivel visual en la columna Acciones qué carpetas/archivos ya tienen enlace compartido generado: el botón "Compartir" pasa de su estado opaco/gris a un fondo azulito cuando existe un link generado.
- Branch: `feat/share-badge-azul` (a crear desde `main`; la rama actual `feat/dashboard-perf-backup-guide` pertenece a otra feature — ver L2)
- Delivery strategy: `ask-on-risk` (config `odd/config.yaml`) · chain strategy por decidir al cruzar el presupuesto
- Test runner: `cd app && php tests/harness_share_badge.php` · `php tests/tailwind_coverage_check.php` · `php tests/harness_route_contract.php`
- Forecast authored changed lines: `~250` (1 vista Blade, 2 rutas de controller, 1 helper, 1 harness)

## Specs

- **S1 — El botón "Compartir" cambia de color cuando el archivo/carpeta ya tiene link generado**
  - Requirement (cita textual): "mi módulo, mis archivos, cuando yo voy a compartir me aparece, genero el enlace compartido y me gustaría poder cambiar a los que ya genere enlace, en la parte de acciones voy a enviarte una imagen, que es compartir que sale de un color opaco, se le coloquen hacia un fondo azulito."
  - Acceptance: en la vista de lista (y vista tarjetas) de Mis Archivos, el botón con `title="Compartir"` renderiza un fondo azul (`bg-blue-*`/`bg-indigo-*` — supuesto: paleta azul clara consistente con la UI actual) cuando el archivo/carpeta tiene al menos un enlace generado, y conserva su aspecto actual cuando no tiene. Ícono `index.blade.php:3174` (lista) y `index.blade.php:2998` (tarjetas) son los dos sites.
  - Check: `harness_share_badge.php` + `tailwind_coverage_check.php` (la clase azul nueva está en `public/css/app.css`).

- **S2 — El listado del servidor informa si hay link generado**
  - Requirement (cita textual): "De ese modo yo al ingresar ya sé que ahí esa carpeta ya tiene el link generado compartido. De esa manera diferencio a nivel visual rápidamente que he generado link compartido y que no se ha compartido link en el módulo de mis archivos."
  - Acceptance: `GET /files` (BD-first, `FileController::index`) y el listing fs-first (`indexFilesystemPrimary`) incluyen por cada entrada un flag booleano (supuesto: nombre `has_share_link`) resuelto con una sola query `shares.file_id IN (...)` hecha DESPUÉS de leer la cache `folder_listing:{...}` (así el flag no envejece con el cache de hasta 86400 s). Entradas sin `file_id` → `false`.
  - Check: `harness_share_badge.php` — payload con y sin share, y flag fresco tras crear el share aunque el listing esté cacheado.

- **S3 — "Ya genere link" = existe al menos un share para ese file_id (supuesto)**
  - Requirement (cita textual): "genero el enlace compartido … ya tiene el link generado compartido."
  - Acceptance (supuesto, a confirmar con el usuario si importa): el flag es `true` si hay ≥1 fila en `shares` con `file_id = <id>`. Supuestos adicionales marcados: (a) un share **vencido** también cuenta como "ya genere link" (el usuario ve en el modal su historial); (b) revocar el share (borra la fila) vuelve el botón a gris; (c) los enlaces de backup (`backup_requests`) cuentan igual porque son filas de `shares`.
  - Check: `harness_share_badge.php` — crear share → `has_share_link=true`; destruir share → `false`.

- **S4 — Frescura inmediata al generar el enlace desde el modal**
  - Requirement (cita textual): "cuando yo voy a compartir me aparece, genero el enlace compartido y me gustaría poder cambiar a los que ya genere enlace"
  - Acceptance (supuesto): al generar/revocar un enlace, (a) el cliente marca/limpia el flag en el objeto local (`generateShareLink()` en `index.blade.php:1534`) para reflejo inmediato sin recargar, y (b) `ShareController::store`/`destroy` incrementan `folder_gen:{storage}:{parent}` del file afectado para que la siguiente navegación al folder relea el listing. Supuesto: no se toca el TTL ni la estructura de la cache.
  - Check: `harness_share_badge.php` — POST `/shares` incrementa `folder_gen` del storage/parent.

## Tasks

- [x] **T1 — Helper de anotación `has_share_link` en ambos listings** (S2, S3) — inline — done — commit: - (sin commit: no se pidieron; ver L3) — evidence: `php tests/harness_share_badge.php` → secciones 1 y 6 en verde (flag en BD-first y fs-first) — risk: none
- [x] **T2 — Frescura: bump `folder_gen` en store/destroy de shares + reflejo local en `generateShareLink()`** (S4) — inline — done — commit: - (sin commit) — evidence: harness secciones 2-5: bump en store (0→1), destroy (1→2), bulk-delete (4→5), cache hit conserva el flag y revocación lo apaga — risk: none
- [x] **T3 — UI: fondo azul del botón Compartir (lista + tarjetas) + `npm run build`** (S1) — inline — done — commit: - (sin commit) — evidence: `npm run build` sin cambio de hash (las clases azules ya existían en `public/css/app.css`) + `php tests/tailwind_coverage_check.php` → 28011 clases verificadas, 0 faltantes — risk: none
- [x] **T4 — Harness `tests/harness_share_badge.php`** (S1, S2, S3, S4) — inline — done — commit: - (sin commit) — evidence: `php tests/harness_share_badge.php` → `OK: todas las aserciones pasan` (26 aserciones, exit 0) — risk: none
- [x] **T5 — Documentación: sección breve en AGENTS.md + Log de este documento** (S1) — inline — done — commit: - (sin commit) — evidence: sección "Badge azul del botón Compartir (share-badge-azul, 2026-11-18)" en AGENTS.md + entradas L3/L4 de este documento — risk: none

## Acceptance Criteria

1. Un archivo/carpeta sin ningún share muestra el botón "Compartir" con su aspecto actual (gris/opaco).
2. Un archivo/carpeta con ≥1 share muestra el botón "Compartir" con fondo azul, en vista lista y en vista tarjetas.
3. `GET /files` BD-first devuelve `has_share_link` por entrada; el valor se resuelve después del cache hit (no vive dentro de la entrada cacheada).
4. El listing fs-first (`meta.fs_primary === true`) también devuelve el flag.
5. Crear un enlace (`POST /shares`) hace que la entrada quede en `true` en la siguiente carga de la carpeta, incluso si el listing estaba cacheado con TTL alto.
6. Revocar el enlace (`DELETE /shares/{id}`) devuelve la entrada a `false` en la siguiente carga.
7. `php tests/tailwind_coverage_check.php` en verde (la clase azul existe en el CSS compilado).
8. `php tests/harness_route_contract.php` en verde (sin rutas huérfanas nuevas).

## Verification

Salida real de la última corrida (2026-11-18):

```bash
cd app
php tests/harness_share_badge.php       # OK: todas las aserciones pasan (26 ✓, exit 0)
php tests/tailwind_coverage_check.php    # 28011 clases verificadas, 0 faltantes (exit 0)
php tests/harness_route_contract.php     # OK: 300 rutas verificadas, 0 huerfanas (exit 0)
php tests/harness_shares_module.php      # OK: todas las aserciones pasan (exit 0)
php tests/harness_backup_requests.php    # OK: todas las aserciones pasan (exit 0)
php tests/harness_backup_mapping.php     # RESULTADO: OK (exit 0)
php tests/harness_mis_archivos_fs_first.php  # exit 0
npm run build                            # Done in 3872ms; md5 idéntico al anterior
```

## Progress

- T1..T5 done. Feature implementada y verificada. Sin commit (ver L3).
- Archivos tocados: `app/app/Http/Controllers/FileController.php` (+86),
  `app/app/Http/Controllers/ShareController.php` (+53),
  `app/app/Services/MisArchivos/BackupRequestService.php` (+26),
  `app/resources/views/files/index.blade.php` (+21),
  `app/tests/harness_share_badge.php` (nuevo),
  `AGENTS.md`, `odd/tasks/share-badge-azul.md`.

## Next Step

Feature completa. Próximo paso posible (requiere pedido del usuario):
commitear los cambios en una branch `feat/share-badge-azul`.

## Log

- **L1** — 2026-11-18 — Pedido original del usuario, textual: "Iniciar una feature nueva de Organic Driven Development: Necesito esto ya con lo siguiente, mi módulo, mis archivos, cuando yo voy a compartir me aparece, genero el enlace compartido y me gustaría poder cambiar a los que ya genere enlace, en la parte de acciones voy a enviarte una imagen, que es compartir que sale de un color opaco, se le coloquen hacia un fondo azulito. De ese modo yo al ingresar ya sé que ahí esa carpeta ya tiene el link generado compartido.De esa manera diferencio a nivel visual rápidamente que he generado link compartido y que no se ha compartido link en el módulo de mis archivos." (el mensaje repite el mismo pedido en un segundo párrafo que empieza "Si Necesito esto ya con lo siguiente…" y continúa con instrucciones de proceso para la agent)
- **L2** — 2026-11-18 — Nombre de feature elegido por el usuario: `share-badge-azul`. La rama activa del workspace es `feat/dashboard-perf-backup-guide` (pertenece a otra feature); la branch de esta feature se creará desde `main` al iniciar la implementación.
- **L3** — 2026-11-18 — Implementación completa (T1..T5). Decisión de rama: la rama activa `feat/dashboard-perf-backup-guide` contiene cambios sin commitear de otras features (StorageProvider, transcriptor, app.css) y `main` está a 2 meses (bf80f8f, 2026-09-18); crear `feat/share-badge-azul` desde `main` habría forzado un checkout con archivos sucios y una base sin los cambios recientes. Los cambios de esta feature quedaron en el working tree de la rama actual, SIN COMMIT, separados de los ajenos por path: mis archivos son FileController.php (parcial), ShareController.php (parcial), BackupRequestService.php, files/index.blade.php (parcial), tests/harness_share_badge.php, AGENTS.md y este documento. **Los 5 archivos ajenos no se tocaron**: CorreccionesController.php, StorageProvider.php, TodayPendingService.php, TranscriptionDiscoveryService.php, TranscriptorWorkEstimator.php (además de app.css ya sucio preexistente). El usuario no pidió commits; se pidió explícitamente no committear sin pedido.
- **L4** — 2026-11-18 — Verificación: 7 checks ejecutados, todos exit 0 (ver sección Verification). El harness nuevo cubre: flag en ambos listings, bump de folder_gen en store/destroy/bulk-delete, frescura del cache hit, y fs-first. Semántica implementada según supuestos de S3: cualquier fila en `shares` cuenta (incluidos vencidos y de backup); borrar el último apaga el badge.
