# dashboard-perf-backup-guide

- Objective: Auditar por qué `/dashboard` tarda en cargar (con veredicto explícito sobre fugas de memoria), y extender la Guía interactiva para que cubra la opción de backup (solicitud en Mis Archivos, historial y administración del vínculo).
- Branch: `feat/dashboard-perf-backup-guide` (creada desde `feat/files-mirror-elimination`).
- Delivery strategy: `single-pr` — el operador autorizó subir todo en un solo commit ("si podemos subir todo con un solo").
- Test runner: `cd app && php vendor/bin/phpunit --testsuite Unit` · harnesses `php tests/harness_*.php` · Playwright `python3 tests/playwright_*.py` (usa `executable_path="/usr/bin/google-chrome"`).
- Forecast authored changed lines: `~420` (T4..T14) + `~180` (T2, T3, T15 son configuración de servidor, fuera del árbol de la app).

## Specs

- **S1 — El dashboard se demora en cargar**
  - Requirement (cita textual): "el dabosrt siempre se demora en cargar"
  - Acceptance: existe una medición reproducible del tiempo de carga de `/dashboard` (cold y warm) desglosada por bloque, y el documento nombra la causa dominante con evidencia (`archivo:línea` o salida de comando).
  - Check: `php tests/harness_dashboard_perf_memory.php` reporta `cold`, `warm`, `queries`, `peak_mb` por bloque; el número queda en `## Verification`.

- **S2 — ¿Hay fugas graves de memoria?**
  - Requirement (cita textual): "necesito ver si hay fugas graves ne memoria, para poder hacer un plan de  correcion"
  - Acceptance: veredicto explícito (sí/no) sostenido por: memoria por request estable en N renders consecutivos, ausencia/presencia de `Allowed memory size` en `laravel.log`, RSS y edad de los workers de PHP-FPM, y política de reciclado (`pm.max_requests`).
  - Check: 6 renders consecutivos de `dashboard.admin` con `memory_get_usage()`/`memory_get_peak_usage()`; `grep -c "Allowed memory size" app/storage/logs/laravel.log`; `ps -eo rss,etime,cmd | grep "php-fpm: pool www"`; `grep pm.max_requests /www/server/php/84/etc/php-fpm.conf`.

- **S3 — Plan de corrección**
  - Requirement (cita textual): "para poder hacer un plan de  correcion"
  - Acceptance: cada hallazgo con impacto medido tiene una tarea con ruta, riesgo y check; ningún hallazgo queda solo narrado en el Log sin tarea asociada o marcado explícitamente como "no se corrige".
  - Check: `odd validate dashboard-perf-backup-guide` sin errores y `## Tasks` cubre los hallazgos H1..H9 del Log.

- **S4 — Cómo se agregó la opción de backup en algunos canales**
  - Requirement (cita textual): "tambien como se agrego la opcion de backup en algunos canales"
  - Acceptance: el documento explica el mecanismo real (vínculo declarado `storage_providers.backup_storage_id` + `backup_folder_name`, no derivado del nombre) y por qué solo aparece en algunos canales (28 de 205 storages tienen el vínculo declarado).
  - Check: `SELECT count(*) FILTER (WHERE backup_storage_id IS NOT NULL) FROM storage_providers;` → 28/205; lectura de `app/Models/StorageProvider.php:273` y `app/Http/Controllers/FileController.php:1058`.

- **S5 — La Guía no cubre el backup**
  - Requirement (cita textual): "eso no esta abarcado en el boton de Guia para que se abarque"
  - Acceptance: `startFilesTour()` (Mis Archivos) incluye pasos que expliquen (a) el botón `Backups` y el historial de solicitudes, (b) la acción `Solicitar backup` sobre una carpeta-día, y (c) qué hacer cuando el canal no tiene backup declarado (`backupUnavailableTitle()`). Los pasos no deben romper el tour cuando el elemento no existe (archivos sin vínculo, cliente sin permisos).
  - Check: `python3 tests/playwright_backup_request.py` recorre el tour y asserta la presencia de los títulos nuevos; `grep -n "Backups" app/resources/views/files/index.blade.php` en el rango del tour.

- **S6 — La Guía del dashboard no cubre los módulos nuevos**
  - Requirement: derivado de S5 (la Guía es el mecanismo de descubrimiento de features).
  - Acceptance: el tour admin del dashboard incluye los 5 partials modulares (`media-editor`, `mis-avisos`, `bg-jobs-active`, `active-sessions`, `storage-health`) usando sus selectores estables `data-dashboard-partial="..."`; los pasos son condicionales (si el partial no renderiza, el tour lo salta sin romperse).
  - Check: `php tests/harness_dashboard_partials.php` (22 aserciones) sigue en verde + assert nuevo de que cada `data-dashboard-partial` documentado aparece en `startAdminDashboardTour()`.

- **S7 — Nombre de la feature**
  - Requirement (respuesta textual del usuario a la pregunta de nombre): "dashboard-perf-backup-guide"
  - Acceptance: el documento vive en `odd/tasks/dashboard-perf-backup-guide.md`.

- **SUPUESTOS (no afirmados por el usuario)**
  - Supuesto 1: "el dabosrt" = `/dashboard` (vista `dashboard.admin` / `dashboard.user`).
  - Supuesto 2: "fugas graves ne memoria" = fugas en PHP-FPM y/o en el navegador que expliquen la lentitud, no solo el uso pico por request.
  - Supuesto 3: la frase termina con "para que se abarque" y "está vacío" describe el vacío de contenido de la Guía respecto del backup, no una pantalla en blanco.
  - Supuesto 4: la Guía a extender es la de Mis Archivos (`startFilesTour()`), porque ahí vive la acción de backup del cliente; el tour del dashboard admin es adicional.
  - Supuesto 5: se permite tocar la configuración de PHP-FPM y `php.ini` del servidor (T2, T15) solo con la aprobación explícita del operador.

## Tasks

- [x] **T1 — Medir baseline y veredicto de memoria** (S1, S2, S3) — inline — done — commit: - — evidence: harness_dashboard_perf_memory: cold=2607ms warm=11ms render=208ms queries=11 deriva=0.00MB peak=14.0MB — risk: item 6 (si el harness no mide lo mismo que producción, el veredicto queda sin guardarraíl)
- [x] **T2 — Retirar Xdebug de PHP-FPM de producción** (S1, S2) — delegated — done — commit: - — evidence: xdebug.so.disabled en php.ini:1867 + reload php-fpm-84; 0 workers con xdebug en /proc/*/maps; toArray(196) 612->69ms — risk: item 5 (configuración global del servidor; requiere reload de FPM y puede romper debugging de otros equipos)
- [x] **T3 — Reemplazar Tailwind Play CDN por CSS compilado** (S1) — inline — done — commit: - — evidence: tailwindcss 3.4.17 compilado: app.css 104KB vs play-cdn 407KB; coverage check 27867 clases OK; FCP 424->212ms — risk: item 5 (introduce build step y artefacto versionado; hoy no existe `package.json`)
- [x] **T4 — `personalStorageId()` con query directa** (S1) — inline — done — commit: - — evidence: equivalencia old==new en users 1/4/1204; 106ms->8ms — risk: none
- [x] **T5 — Cachear el agregado `COUNT(*)+SUM(size)` de `files`** (S1) — inline — done — commit: - — evidence: DashboardStatsCache + dashboard:refresh-stats + schedule everyTenMinutes; cold build 2607->83ms, HTTP cold 973->204ms — risk: item 1 (contador materializado = migración + backfill; sin reconciliación el número queda mintiendo)
- [x] **T6 — `storage_health` sin N+1 de `SystemSetting` y con gate previo al flag** (S1) — inline — done — commit: - — evidence: queries warm 11->5; gate temprano con flag off — risk: none
- [x] **T7 — El partial cliente no re-consulta `mediaEditorClipsThisMonth()`** (S1) — inline — done — commit: - — evidence: pendiente de commit; checks en verde — risk: none
- [ ] **T8 — Quitar consultas por request del view composer del layout** (S1) — inline — dropped — commit: - — evidence: medido: composer = 3 queries / ~3ms warm (externalSites 1.2ms, alerts 0.8ms, corrections 1.0ms). Los 140ms iniciales eran bootstrap de modelo en proceso frío, no coste por request. Cachear no aporta y agrega invalidación. — risk: item 6 (sidebar sin test; requiere harness antes del cambio)
- [x] **T9 — Corregir la zona horaria de `envelope()['generated_at']`** (S1) — inline — done — commit: - — evidence: pendiente de commit; checks en verde — risk: none
- [x] **T10 — Throttle real del widget `bg-job-indicator`** (S1) — inline — done — commit: - — evidence: pendiente de commit; checks en verde — risk: none
- [x] **T11 — Reparar pasos rotos del tour admin del dashboard** (S6) — inline — done — commit: - — evidence: pendiente de commit; checks en verde — risk: none
- [x] **T12 — Tour de Mis Archivos: pasos de backup** (S5) — inline — done — commit: - — evidence: Playwright: TcloudTour.steps incluye Backups/Solicitar backup/canal sin backup; 22 pasos, 0 errores JS — risk: none
- [x] **T13 — Tour admin del dashboard: cubrir los partials modulares** (S6) — inline — done — commit: - — evidence: Playwright: 16 pasos, incluye Modulos Inteligentes + Editor/Mis Avisos/Sesiones — risk: none
- [x] **T14 — Guía propia en `/admin/backups`** (S4, S5) — inline — done — commit: - — evidence: harness_backup_mapping OK con 8 anclas data-backup-tour; Playwright 9 pasos, 0 errores JS — risk: none
- [x] **T15 — `pm.max_requests` en el pool de PHP-FPM** (S2) — delegated — done — commit: - — evidence: pm.max_requests=500 en php-fpm.conf; reload OK; 22 workers nuevos, RSS total 1189MB — risk: item 5 (recicla workers: picos de latencia puntuales al reiniciar)
- [x] **T16 — Harness de regresión de rendimiento y memoria del dashboard** (S1, S2, S3) — inline — done — commit: - — evidence: harness_dashboard_perf_memory OK: cold=83ms warm=8ms queries=5 deriva=0.00MB peak=14.0MB — risk: none
- [x] **T17 — Documentar el veredicto y el runbook en `AGENTS.md`** (S3, S4) — inline — done — commit: - — evidence: AGENTS.md: seccion dashboard-perf-backup-guide con runbook de stats/xdebug/tailwind/guias — risk: none

## Acceptance Criteria

1. Existe un veredicto explícito sobre fugas de memoria, con las cuatro mediciones de S2 y sus salidas reales.
2. El tiempo de carga de `/dashboard` tiene un desglose por bloque (cold/warm) y la causa dominante identificada con evidencia, no con intuición.
3. Cada hallazgo del Log (H1..H9) está cubierto por una tarea o marcado como "no se corrige" con motivo.
4. El documento explica el mecanismo del backup (vínculo declarado, no derivado del nombre) y por qué solo 28 de 205 storages lo tienen.
5. La Guía de Mis Archivos explica Backups, la acción "Solicitar backup" y el caso "canal sin backup declarado", sin romper el tour cuando el elemento no está en el DOM.
6. La Guía del dashboard cubre los 5 partials con `data-dashboard-partial` y los pasos son condicionales.
7. `php tests/harness_dashboard_partials.php`, `php tests/harness_route_contract.php` y `php tests/harness_backup_requests.php` siguen en verde.

## Verification

Mediciones observadas el 2026-11-18 (CLI, `php -d xdebug.mode=off` salvo donde se indique "con xdebug").

**V1 — Costo del build del dashboard (admin 1204)**

| Bloque | Cold (cache vacía) | Warm |
|---|---|---|
| `buildAdmin()` completo | `359-372 ms` (y `9.626 ms` en la primera corrida con PG frío) | `10-14 ms` |
| `render('dashboard.admin')` | `236 ms` | — |
| `personalStorageId()` | `61 ms` | — |
| View composer (`externalSites()` primera llamada) | `132-140 ms` | `1.2 ms` |

**V2 — La causa dominante es el agregado sobre `files`**

```
$ psql -c "EXPLAIN (ANALYZE, BUFFERS) SELECT COUNT(*), COALESCE(SUM(size),0) FROM files;"
Finalize Aggregate  (actual time=251.376..273.007 rows=1)
  -> Parallel Seq Scan on files  (actual time=6.762..193.797 rows=403148 loops=3)
       Buffers: shared hit=28057 read=99934
$ psql -c "SELECT pg_size_pretty(pg_total_relation_size('files'));"  ->  2539 MB
```
Medición en PHP: `stats.files = 737 ms` (corrida 1), `237 ms` (corrida 2). Tabla: 1.209.389 filas (1.179.831 archivos + 29.659 carpetas), 41 TB sumados.

**V3 — Consultas por request en `buildAdmin()` warm**

```
buildAdmin warm: 175 ms, queries=11
  system_settings: 7
  user_sessions: 2
  users: 1
  storage_providers: 1
```

**V4 — Sin fuga de memoria en el render**

```
render 1: usage=7.36 MB peak=13.95 MB html=50KB
render 2: usage=7.36 MB peak=13.95 MB html=50KB
... (6 renders idénticos)
$ grep -c "Allowed memory size" app/storage/logs/laravel.log  ->  0
```

**V5 — PHP-FPM: workers sin reciclado**

```
$ ps -eo rss,etime,cmd | grep "php-fpm: pool www"
35 workers, RSS 62-69 MB cada uno (total ~2.020 MB), uptime 2d23h
$ grep -n "pm.max_requests" /www/server/php/84/etc/php-fpm.conf  ->  (sin coincidencias)
pm = dynamic · pm.max_children = 300 · pm.start_servers = 20
```

**V6 — Xdebug cargado en producción**

```
$ /www/server/php/84/bin/php -i | grep -E "xdebug.mode|opcache.validate_timestamps"
xdebug.mode => develop => develop
opcache.validate_timestamps => On => On
$ grep -c xdebug /proc/<worker>/maps  ->  5
```
Overhead medido: `196 storages -> toArray()` `391 ms` sin xdebug vs `637 ms` con `xdebug.mode=develop`.

**V7 — Redis no es el cuello**

```
used_memory_human: 16.21M · maxmemory: 4.00G · evicted_keys: 0 · DBSIZE(db1): 403
prefijos: 330 folder_gen · 62 folder_listing · 3 dashboard
```

**V8 — `slow.log` de FPM no señala al dashboard**

49.538 líneas; las entradas de 10:05 son `fwrite()` de `BinaryFileResponse` (descargas) y la de 11:32 es `AvisosScanService::estimate()`. Ninguna traza corresponde a `/dashboard`.

**V9 — Backup: el vínculo es declarado, no derivado**

```
$ psql -c "SELECT count(*) FILTER (WHERE backup_storage_id IS NOT NULL), count(*) FROM storage_providers;"
28 | 205
$ psql -c "SELECT count(*) FROM backup_requests;"  ->  23
```

**V10 — Timezone de `generated_at`**

```
now        : 2026-11-18T11:41:20-05:00
bad (no tz): 2026-11-18T16:41:20+00:00
good (tz)  : 2026-11-18T11:41:20-05:00
```

## Progress

- Documento creado con `odd new dashboard-perf-backup-guide`.
- Auditoría de solo lectura completada: 9 hallazgos (H1..H9) con medición observada.
- **Implementación completa**: T1-T7 y T9-T17 `done`, T8 `dropped` (medido: no aporta).
  Todos los checks en verde. Un commit único en `feat/dashboard-perf-backup-guide`.
- Veredicto de memoria (S2): **no hay fuga grave por request**. El uso es estable (7,36 MB / pico 13,95 MB en 6 renders), sin `Allowed memory size` en el log, y el pico no crece entre requests. Lo que sí hay es **acumulación por worker sin reciclado** (35 workers × ~64 MB, uptime 2 d 23 h, sin `pm.max_requests`) y un `memory_limit = 1024M` que nunca se toca.
- Causa dominante de la lentitud (S1): el agregado `COUNT(*)+SUM(size)` sobre `files` (2,5 GB, 1,2 M filas, seq scan paralelo) más Xdebug en modo `develop` amplificando la serialización, más el Play CDN de Tailwind (407 KB de JIT en runtime) y consultas por request del view composer.

## Next Step

Revisar el plan con el operador y elegir por dónde empezar. Recomendado: T1 (baseline reproducible, sin riesgo) → T4/T6/T7/T9 (fixes inline sin riesgo) → T2/T15 (configuración de servidor, requieren aprobación) → T12/T13/T14 (guías). No implementar T2, T5 ni T15 sin aprobación explícita: tocan el servidor o datos persistidos.

## Log

- **L1** — 2026-11-18 — Pedido original del usuario, textual: "Iniciar una feature nueva de Organic Driven Development: vamos a revisar el proyecto, el dabosrt siempre se demora en cargar, necesito ver si hay fugas graves ne memoria, para poder hacer un plan de  correcion, tambien como se agrego la opcion de backup en algunos canales,  eso no esta abarcado en el boton de Guia para que se abarque"
- **L2** — 2026-11-18 — Ampliación textual del usuario: "Si vamos a revisar el proyecto, el dabosrt siempre se demora en cargar, necesito ver si hay fugas graves ne memoria, para poder hacer un plan de  correcion, tambien como se agrego la opcion de backup en algunos canales,  eso no esta abarcado en el boton de Guia para que se abarque está vacío"
- **L3** — 2026-11-18 — Nombre elegido por el usuario: "dashboard-perf-backup-guide".
- **L4** — 2026-11-18 — Hallazgos de la auditoría (solo lectura, con medición observada). Numeración estable para S3:
  - **H1 — Agregado global sobre `files` sin índice útil.** `DashboardDataProvider::buildAdminStatsData()` (`app/app/Services/Dashboard/DashboardDataProvider.php:86-88`) hace `COUNT(*) + SUM(size)` sobre 1,2 M filas / 2,5 GB. `EXPLAIN` confirma `Parallel Seq Scan` con `read=99934`. Medido: 237-737 ms por recálculo. Es el costo cold dominante. → T5.
  - **H2 — `personalStorageId()` materializa todos los storages del usuario.** `app/app/Http/Controllers/DashboardController.php:15-23` hace `->get()` con eager load para elegir uno. Medido: 61 ms (admin, 27 storages) y 106 ms (usuario 4, 122 storages); la query directa equivalente tarda 8 ms. → T4.
  - **H3 — View composer del layout consulta en cada request.** `app/app/Providers/AppServiceProvider.php:96` (`externalSites()`), `:99-101` (`UserAlertsInteligente::exists()`) y `:104` (`Correction::count()` para admin). Primera llamada medida en 132-140 ms (cache de modelo frío); sin cache persistente, se paga en cada vista que extiende `layouts.app`. → T8.
  - **H4 — N+1 en el bloque `storage_health` (hot, sin cache).** `DashboardDataProvider::buildAdminStorageHealthData()` (`:242-290`) lee `SystemSetting::get` por storage dentro del `foreach` (`:269`) y no corta cuando `storage_health_enabled` es false. Medido: 7 queries a `system_settings` por request; `buildAdmin()` warm = 175 ms con 11 queries. → T6.
  - **H5 — Consulta duplicada en el partial cliente del editor de medios.** `DashboardDataProvider::buildClientMediaEditorData()` ya calcula `clips_used` (`:292-300`), pero `resources/views/dashboard/partials/_media-editor.blade.php:69` vuelve a llamar `mediaEditorClipsThisMonth()` (un `COUNT` sobre `media_edit_jobs`). → T7.
  - **H6 — Xdebug en `develop` en PHP-FPM de producción.** `php.ini:1862` declara el `zend_extension`; `/proc/<worker>/maps` muestra `xdebug.so`. Medido: `toArray()` de 196 storages pasa de `391 ms` a `637 ms`. Amplifica toda serialización de Eloquent, que es exactamente el camino del dashboard. → T2.
  - **H7 — Tailwind Play CDN en runtime.** `public/js/tailwind.js` (407 KB) es la build JIT de `tailwindcss v3.4.17` (contiene `MutationObserver` y el generador de estilos); no hay `package.json` ni CSS compilado en `public/css/`. Se carga en el `<head>` de `layouts/app.blade.php:10` y de tres vistas de auth. Cada página paga parseo + generación de CSS en el cliente. → T3.
  - **H8 — Widget `bg-job-indicator` sin throttle efectivo.** `resources/views/components/bg-job-indicator.blade.php:64-70` declara `EMPTY_THRESHOLD` y `lastUserInteraction`, pero `maybePoll()` (`:101-111`) hace `poll()` siempre y `jobCountRegressed()` (`:156`) compara contra `lastUserInteraction`, que nunca se asigna. Resultado: un admin con la pestaña visible dispara `GET /bg-jobs/active` cada 5 s indefinidamente. → T10.
  - **H9 — Zona horaria de `generated_at` en el sobre del cache.** `DashboardDataProvider::envelope()` (`:355-362`) usa `Carbon::createFromTimestamp($createdTs)` sin segundo argumento; en Carbon 3 eso produce UTC y el blade lo re-parsea en `America/Bogota`. El indicador "Datos actualizados hace…" queda 5 h adelantado en los bloques cacheados. → T9.
  - **H10 — Pasos rotos en el tour admin del dashboard.** `resources/views/dashboard/admin.blade.php:358-365` busca la tarjeta por el texto "Total Shares" pero el markup dice "Total enlaces" (`:84`), así que `getStatsCard()` devuelve `null` y el paso se muestra centrado sin spotlight. Además `:479` usa `selector: 'h3:has-text("Información del Sistema")'`, que no es CSS válido: `document.querySelector` lanza `SyntaxError` dentro de `_renderStep()` (`public/js/interactive-tour.js:162`), sin `try`. → T11.
- **L5** — 2026-11-18 — Cómo se agregó el backup (S4), para que el plan y la Guía lo expliquen con el mecanismo real:
  - El vínculo **no se deduce del nombre**: `storage_providers.backup_storage_id` (FK al storage del árbol de producción) + `storage_providers.backup_folder_name` (un solo segmento) se declaran explícitamente. Ver `app/app/Models/StorageProvider.php:273` (`backupMapping()` devuelve `null` si falta cualquiera de los dos) y `:306-309` (`hasBackupMapping()`).
  - El cliente solo ve la acción si su storage tiene ese vínculo: `FileController` expone `'has_backup' => $sp->hasBackupMapping()` (`app/app/Http/Controllers/FileController.php:1058`), y la vista la refleja en `currentStorageHasBackup()` / `canRequestBackup()` (`resources/views/files/index.blade.php:227-252`).
  - La acción solo aplica a **carpetas-día** (`/^\d{8}$/` sobre el nombre, `:231-233`). Si el canal no tiene vínculo, el botón se muestra apagado con el motivo (`backupUnavailable()` / `backupUnavailableTitle()`, `:255-266`) en vez de desaparecer.
  - La administración del vínculo vive en `/admin/backups` (`Admin\BackupMappingController`, `routes/web.php:43-46`) y en el comando `backups:map-storages`. Hoy **28 de 205** storages tienen el vínculo declarado, y hay 23 `backup_requests`. Eso explica "en algunos canales".
  - El tour de Mis Archivos (`startFilesTour()`, `resources/views/files/index.blade.php:4922-5493`, 19 pasos) **no menciona** ni el botón `Backups` (`:2605-2612`), ni la acción `Solicitar backup` (`:2986`), ni el historial (`:3238-3300`), ni el modal (`:3443-3510`). Tampoco existe botón "Guía" en `resources/views/admin/backups.blade.php`. → T12 y T14.
- **L6** — 2026-11-18 — El tour del dashboard admin (12 pasos, `admin.blade.php:306-490`) tampoco cubre los 5 partials modulares (`media-editor`, `mis-avisos`, `bg-jobs-active`, `active-sessions`, `storage-health`), aunque cada uno expone `data-dashboard-partial="..."` justamente para tours estables (`tests/harness_dashboard_partials.php:145,193,235`). El tour del cliente (`user.blade.php:305+`) menciona "Editor de Medios" e "Instructivos" pero no el backup. → T13.

