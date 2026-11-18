## 1. Esquema de datos

- [x] 1.1 **Migración** — crear tabla `medios` (`id`, `storage_id` FK `storage_providers` CASCADE, `subpath` varchar(500) NOT NULL DEFAULT '', `name` varchar(255), `media_kind` varchar(10) CHECK IN ('tv','radio'), `region` varchar(120) nullable, `activo` boolean DEFAULT true, `created_at`/`updated_at`) con índice único parcial `(storage_id, subpath)` donde `activo` y `name` no vacío. `down()` la elimina.
- [x] 1.2 **Migración** — crear tabla `solicitud_puntual` (`id`, `user_id` FK `users` CASCADE, `medio_id` FK `medios` CASCADE, `fecha` date, `modo` varchar(10) CHECK IN ('franja','dia'), `hora_inicio` time, `hora_fin` time, `duracion_minutos` integer, `status` varchar(20) CHECK IN ('ready','partial','unavailable','revoked') DEFAULT 'unavailable', `motivo` text, `cobertura` jsonb nullable, `archivos_total` integer DEFAULT 0, `bytes_total` bigint DEFAULT 0, `links_total` integer DEFAULT 0, `share_id` FK `shares` SET NULL nullable, `link_expires_at` timestamptz nullable, `created_at`/`updated_at`) con índices `(user_id, created_at DESC)` y `(user_id, status)`.
- [x] 1.3 **Migración** — crear tabla `solicitud_puntual_item` (`id`, `solicitud_id` FK `solicitud_puntual` CASCADE, `file_id` FK `files` SET NULL nullable, `share_id` FK `shares` SET NULL nullable, `slot_inicio` timestamptz, `slot_fin` timestamptz, `capture_inicio` timestamptz nullable, `capture_fin` timestamptz nullable, `name` varchar(255), `path` varchar(500), `size` bigint) con único `(solicitud_id, slot_inicio)` e índice `(solicitud_id)`.
- [x] 1.4 **Migración** — índice único parcial de idempotencia `idx_solicitud_puntual_unique_live` sobre `(user_id, medio_id, fecha, hora_inicio, hora_fin)` donde `status IN ('ready','partial')`. Verificar en el harness que un duplicado directo lanza `23505`.
- [x] 1.5 Modelos `App\Models\Medio`, `App\Models\SolicitudPuntual`, `App\Models\SolicitudPuntualItem` con `$fillable`, `$casts` (`cobertura` array, `size`/`bytes_total` integer, fechas datetime) y relaciones (`medio()`, `user()`, `share()`, `items()`, `sourceStorage()`, `sourceFolder()`).
- [x] 1.6 Relaciones inversas: `Share::solicitudPuntualItem()` (HasOne, enlace de un slot), `Share::solicitudPuntual()` (HasOne, enlace de la carpeta del día) y `StorageProvider::medios()` (HasMany). Verificar que `Share::backupRequest()` sigue intacto.

## 2. Catálogo de medios (backend)

- [x] 2.1 `App\Services\MisArchivos\MedioCatalogService::availableFor(User $user): Collection` — devuelve solo los medios **no asignados**: se excluye todo medio cuyo storage o cualquier ancestro por ruta física esté en los `user_storages` del usuario con permiso de lectura. Implementar el chequeo de ancestro por prefijo de `base_path` normalizado con límite de segmento (`/`).
- [x] 2.2 `MedioCatalogService::detectSubchannels(StorageProvider $storage): array` — recorre el layout físico (plano y agrupado por subcarpeta) y devuelve las carpetas que contienen subcarpetas-día `^\d{8}$` válidas. Excluye la denylist de metadatos (`System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, `.Trash*`, `lost+found`, todo lo que empiece por punto).
- [x] 2.3 `MedioCatalogService::proposeName(string $subpath): array` — propone `name` y `region` a partir de la ruta interna (`<canal> · <región>` cuando hay carpeta regional; el nombre del canal cuando no). Debe desambiguar homónimos entre regiones.
- [x] 2.4 `MedioCatalogService::declare(StorageProvider $storage, string $subpath, string $name, string $region, string $kind): Medio` — declara el medio sin tocar `storage_providers` ni `files`.
- [x] 2.4b `MedioCatalogService::resolveTarget()` — si el sub-canal YA tiene un storage propio (56 de 122: `Antioquia/BLU` → storage 72), el medio se declara sobre ese storage con subpath vacío, no sobre el contenedor: ese storage es el dueño de la ruta física y de sus filas en `files`, y declararlo sobre el contenedor crearía una tercera identidad del mismo árbol.
- [ ] 2.5 Comando `medios:bootstrap` — siembra el catálogo con los storages hoja que tienen media (heurística leaf + `mime_type` video/audio), en modo informe por defecto; `--apply --yes` escribe. No debe crear storages.
- [ ] 2.6 Comando `medios:remapear --storage=N [--todos] [--apply --yes]` — reutiliza `detectSubchannels()` y `proposeName()` (mismas validaciones que la UI), informe por defecto, y al aplicar declara solo los sub-canales no declarados.
- [ ] 2.7 `App\Http\Controllers\Admin\MedioController` — `index` (vista), `detect` (JSON de sub-canales detectados), `store` (declarar), `update` (editar nombre/región/activo), `destroy` (dar de baja). Todas bajo `['auth','admin']`.
- [ ] 2.8 Rutas admin `/admin/medios` (`GET`), `/admin/medios/detect` (`GET`), `/admin/medios` (`POST`), `/admin/medios/{medio}` (`PUT`), `/admin/medios/{medio}` (`DELETE` con `whereNumber`).

## 3. Resolución de material y cobertura

- [ ] 3.1 `App\Services\MisArchivos\PuntualResolver::filesForDay(Medio $medio, Carbon $date): Collection` — localiza por `storage_provider_id` + `name ~ '_{DDMMYYYY}_[0-9]{6}\.'` + sufijo de `path` (`%/{subpath}/{DDMMYYYY}/` o `%/{DDMMYYYY}/` cuando `subpath` es vacío). Debe tolerar los dos formatos de `path` coexistentes.
- [ ] 3.2 `PuntualResolver::deriveStep(Collection $files): int` — paso en segundos = mediana de los deltas entre inicios de captura consecutivos. Devolver `null` si no hay al menos dos archivos.
- [ ] 3.3 `PuntualResolver::gridSlots(Collection $files, int $step): Collection` — slot nominal de cada archivo (`floor(inicio/step)*step`) con su inicio real y su fin real (`file_modified_at` vía `App\Support\FilesystemTime`).
- [ ] 3.4 `PuntualResolver::coverage(Medio $medio, Carbon $date, string $desde, string $hasta): array` — slots nominales dentro del rango, archivos resueltos, slots faltantes, `cobertura` `completa|parcial`, `archivos`, `bytes`. Verificar en disco la existencia de cada archivo candidato (fuera de catálogo = no disponible).
- [ ] 3.5 `PuntualResolver::dayFolder(Medio $medio, Carbon $date): ?File` — devuelve la fila de carpeta del día; si no existe, materializarla por la vía canónica `StorageSyncService::syncFolder()` (nunca SQL inline).
- [ ] 3.6 Verificar el rendimiento de `filesForDay()` contra el storage más grande que participa como medio y registrar la medición en el harness.

## 4. Solicitud, enlaces y vigencia

- [ ] 4.1 `config/puntual.php` con `link_validity_days` (3), `umbral_dia_minutos` (120), `min_motivo_length` (10), `max_franja_minutos`.
- [ ] 4.2 `App\Services\MisArchivos\PuntualRequestService::estimate(User $user, Medio $medio, string $fecha, string $desde, string $hasta): array` — ficha de cobertura con números reales (archivos, bytes, slots) y comparación franja vs día con el número de enlaces de cada alternativa y la marca de recomendación.
- [ ] 4.3 `PuntualRequestService::create(User $user, Medio $medio, string $fecha, string $desde, string $hasta, string $motivo): SolicitudPuntual` — validar motivo, rango y cobertura; en modo `franja` crear un `Share` de solo lectura por archivo exacto; en modo `dia` crear un `Share` sobre la carpeta del día; persistir `solicitud_puntual` + `solicitud_puntual_item` con snapshot de `name`/`path`/`size`. Todo en una transacción.
- [ ] 4.4 Idempotencia: devolver la solicitud viva existente para el mismo `(user, medio, fecha, desde, hasta)`; capturar `23505` (con `use Illuminate\Database\QueryException` correcto) para devolver la solicitud ganadora en carrera.
- [ ] 4.5 `PuntualRequestService::revoke(User $user, SolicitudPuntual $s): SolicitudPuntual` — idempotente, borra los `Share` vinculados, invalida la cache de metadatos públicos (`share:meta:{token}`) y marca `revoked`.
- [ ] 4.6 `PuntualRequestService::listFor(User $user, array $filtros): array` — historial paginado (más reciente primero), con búsqueda por medio/fecha/estado y ordenamiento por columna (fecha, medio, minutos, bytes).
- [ ] 4.7 `PuntualRequestService::payload(SolicitudPuntual $s): array` — estado con vocabulario cerrado `ready|expired|unavailable|revoked`, detalle de items con nombre, ruta, bytes, instante de captura y enlace público cuando la solicitud está viva.

## 5. Guard de compartidos (afecta a los dos flujos)

- [ ] 5.1 Generalizar `ShareController::isBackupManaged()` a `isRequestManaged()`: una enlace está gestionado si tiene `backupRequest` o `solicitudPuntualItem`.
- [ ] 5.2 Aplicar el guard en `update()` y `destroy()` con respuesta `403` y el mensaje que indique que hay que revocar desde el módulo de origen.
- [ ] 5.3 Actualizar `backupManagedIds()` → `managedIds()` y usarlo en `bulkPreview()` y `bulkDelete()`, reportando `protected_count` y `protected_ids`. Corregir la discrepancia de `confirm_count` entre previsualización y depuración cuando hay enlaces protegidos.
- [ ] 5.4 **Corrección de bug**: añadir `use Illuminate\Database\QueryException;` en `App\Services\MisArchivos\BackupRequestService` (hoy el `catch` resuelve a una clase inexistente y una violación real de `23505` sube como 500). Cubrirlo con una aserción en el harness de backup.

## 6. API del módulo cliente (backend)

- [ ] 6.1 `App\Http\Controllers\SolicitudPuntualController::catalog()` — lista de medios no asignados con búsqueda y filtro por tipo. Autorización por `session('user_id')` (nunca `auth()->user()`).
- [ ] 6.2 `::estimate()` — ficha de cobertura + recomendación para un medio, fecha y rango. Devuelve `422` con motivo cuando el rango es inválido o el material no existe.
- [ ] 6.3 `::store()` — crea la solicitud; devuelve `201` con el registro y los enlaces.
- [ ] 6.4 `::history()` — historial paginado/buscable/ordenable del solicitante.
- [ ] 6.5 `::destroy()` — revoca una solicitud propia.
- [ ] 6.6 Rutas `/solicitud-puntual` (`GET` catálogo), `/solicitud-puntual/estimate` (`POST`), `/solicitud-puntual` (`POST`), `/solicitud-puntual/historial` (`GET`), `/solicitud-puntual/{id}` (`DELETE` con `whereNumber`), todas bajo `auth`. Declarar **antes** de cualquier resource con comodín en la misma prefijo.

## 7. Interfaz del cliente

- [ ] 7.1 Vista `resources/views/solicitud-puntual/index.blade.php` — catálogo de medios no asignados con buscador, filtro TV/Radio y contador.
- [ ] 7.2 Modal de solicitud: selector de fecha, campos de hora de inicio y fin, ficha de cobertura en vivo (slots, archivos, bytes) y bloque de comparación franja vs día con la recomendación.
- [ ] 7.3 Historial: tabla paginada con buscador, encabezados que alternan asc/desc, columnas fecha / medio / rango / modo / archivos / bytes / enlaces / estado, acción de revocar y abrir enlace en pestaña nueva.
- [ ] 7.4 Componente Alpine `puntualManager()` con estado `medios`, `search`, `kindFilter`, `selected`, `form`, `estimate`, `history`, `page`, `sort`, `dir` y eventos `openRequest`, `submitRequest`, `revoke`, `loadHistory`. Toasts para éxito y error (sin `alert()` nativo).
- [ ] 7.5 Entrada de sidebar "Solicitud Puntual" en `resources/views/layouts/app.blade.php`, en el grupo cliente inmediatamente después de "Compartidos", con `data-nav-path="/solicitud-puntual"`.
- [ ] 7.6 Regenerar el CSS compilado (`npm run build`) y pasar `php tests/tailwind_coverage_check.php`.

## 8. Interfaz de administración

- [ ] 8.1 Vista `resources/views/admin/medios.blade.php` — listado de medios declarados con buscador, filtro TV/Radio y edición de nombre, región y estado.
- [ ] 8.2 Bloque de contenedores con la acción "Remapear": previsualización del sub-canal detectado, nombre y región propuestos, marca de ya declarado, y confirmación explícita antes de escribir.
- [ ] 8.3 Guard de cliente en `Admin\MedioController`: las acciones de escritura exigen sesión de admin (`session('user_role') === 'admin'`).
- [ ] 8.4 Regenerar el CSS compilado (`npm run build`) y pasar `php tests/tailwind_coverage_check.php`.

## 9. Verificación

- [ ] 9.1 `tests/harness_solicitud_puntual.php` — contrato del módulo contra PostgreSQL y disco reales: catálogo complementario (regla de ancestro), cobertura completa y parcial, franja con N enlaces, día con 1 enlace de carpeta, snapshot del registro, idempotencia, revocación, vigencia de 3 días, recomendación por umbral, y **que el remapeo no altera el conteo de `files` ni crea storages**.
- [ ] 9.2 Extender `tests/harness_route_contract.php` con las rutas nuevas (cliente y admin) y verificar que ninguna queda huérfana.
- [ ] 9.3 Extender `tests/harness_shares_module.php` con el guard generalizado: `update`/`destroy` sobre enlaces de ambos flujos → `403`; `bulkDelete` excluye y reporta `protected_count`.
- [ ] 9.4 Test unitario del resolver (`tests/Unit/PuntualResolverTest.php`): mediana del paso con cadencias distintas (15/21/14 min), slots nominales, parsing de fecha y nombre, detección de homónimos por región.
- [ ] 9.5 Revisión independiente de seguridad (el riesgo alto del change): verificar que no hay ruta que sirva material de un medio no asignado fuera del flujo de solicitud, que la vigencia no puede extenderse ni volverse permanente, y que la revocación deja de servir el enlace.
- [ ] 9.6 Documentar el contrato del módulo en `AGENTS.md` (sección nueva) y cerrar el change en OpenSpec.
