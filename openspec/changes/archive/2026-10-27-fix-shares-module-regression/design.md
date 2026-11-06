## Context

El módulo de compartidos está repartido en tres superficies que comparten el modelo `Share` pero no su contrato:

| Superficie | Archivo | Consumidor |
|---|---|---|
| Panel dedicado | `resources/views/shares/index.blade.php` | `ShareController` (`GET /shares`) |
| Pestaña del detalle de archivo | `resources/views/files/index.blade.php` (líneas ~1300-1470) | mismo `ShareController` |
| Enlace público | `resources/views/shares/public.blade.php`, `public-password.blade.php` | `PublicShareController` |

Las specs archivadas (`share-management`, `share-security`, `share-file-availability`, `share-link-lifecycle`, `shares-tabs-bulk-delete`) describen el comportamiento correcto, pero la implementación de `ShareController` se reescribió de forma divergente el 2026-09-21 (`13645ca`) y `PublicShareController::authenticate` se eliminó el mismo día (`b07e434`). El sistema de specs quedó como contrato y el código quedó como otra cosa.

Restricciones del proyecto que condicionan el diseño:

- **Frontera de escritura de `files`**: Mis Archivos es el único módulo que crea y actualiza filas de `files` (AGENTS.md, "Frontera Mis Archivos ↔ API Transcriptor"). El módulo de compartidos solo debe **leer** esa tabla, salvo para el dato derivado de disponibilidad, que no es catálogo.
- **Sin duplicidad**: los enlaces se almacenan una vez en `shares`; la identidad física de un recurso se modela con `storage_providers.parent_storage_id` y la equivalencia por ruta normalizada. **No** existe un puntero mirror→canónico dentro de `files` (la columna `files.canonical_folder_id` fue eliminada en `13645ca`).
- **El guardarraíl documentado está roto**: `harness_merged_into_rename_compat.php` y `harness_merged_into_create_edit_delete.php` crashean porque consultan `files.canonical_folder_id` y `file_mirror_audit_log`, que no existen. Crashear no es fallar: un harness que aborta con excepción no señala el defecto en su salida.
- **Sesión**: la autenticación usa `session('user_id')` / `Session::get('user_id')`, nunca `auth()->user()`.

## Goals / Non-Goals

**Goals:**

- Que ninguna superficie de compartidos devuelva HTTP 500 ante una solicitud válida.
- Que el panel de compartidos y la pestaña del detalle de archivo consuman el **mismo** contrato de datos y de operaciones bulk.
- Que toda respuesta que devuelva un share use **una sola** forma serializada, sin secretos.
- Que el estado de disponibilidad sea filtrable en SQL y verificable en lotes acotados, sin que el módulo de compartidos pase a ser un escritor de catálogo.
- Que el contrato entre rutas y controllers vuelva a ser verificable de forma automática.

**Non-Goals:**

- No reintroducir la familia mirror/canónico (`files.canonical_folder_id`, `base_path_snapshot`, `physical_path_normalized`, `file_mirror_audit_log`, `shares:notify-repointed`): fue desmontada deliberadamente por el restore de agosto 2026 y las specs que la exigían se retiran.
- No tocar las rutas muertas de otros módulos (`admin/users/create`, `admin/storages/create`, `files/{file}/transcription`, `canales/{canal}`, `grabadores/create|edit`).
- No alterar los enlaces existentes con `expires_at IS NULL`.
- No cambiar el comportamiento público de descarga, previsualización o subida, que ya complies con `share-file-download` y `share-upload`.

## Decisions

### D1 — Reconstruir hacia adelante desde las specs, no revertir `13645ca`

**Elegido:** reimplementar el contrato de las specs sobre el estado actual del código.

**Alternativa descartada:** `git show 0a240d0:app/app/Http/Controllers/ShareController.php` y revertir. Se descartó por tres razones: (a) ese código filtra por `availability_state` sobre `files`, columna que ya no existe, así que el revert tampoco funcionaría sin la migración; (b) no cubre el cursor estable que la spec exige para la verificación por lotes; (c) no arregla `PublicShareController::authenticate`, que desapareció en un commit distinto (`b07e434`), ni la pestaña del detalle de archivo, cuyo cliente nunca cambió.

**Consecuencia:** el `ShareController` queda con un único constructor de consulta (`shareQuery`) reutilizado por el listado, la previsualización y la depuración, lo que garantiza que la previsualización y la ejecución resuelvan el mismo alcance.

### D2 — La disponibilidad se persiste en `files`, con actualización acotada

**Elegido:** tres columnas aditivas y nullables en `files` (`availability_state`, `last_verified_at`, `missing_since_at`) más un índice parcial, y un servicio de verificación que escribe **solo** esas columnas:

```php
DB::table('files')->where('id', $fileId)->update([
    'availability_state' => $state,
    'last_verified_at'   => $state === 'unknown' ? null : now(),
    'missing_since_at'   => $state === 'missing' ? ($current ?? now()) : null,
]);
```

**Alternativas descartadas:** (a) tabla paralela `share_availability` — evita tocar `files` pero duplica un estado que la spec `share-file-availability` asigna al recurso, y obliga a un JOIN en cada filtro; (b) cómputo al vuelo — el filtro de disponibilidad dejaría de ser un predicado SQL y cada request pagaría un sondeo de disco por share, lo que rompe el modelo de lote.

**Por qué el update acotado y no `save()` del modelo:** `save()` sobre un modelo `File` escribe todos los atributos modificados, bumpea `updated_at` y dispara los eventos del modelo. El módulo de compartidos no es dueño de esa fila y no debe modificar su marca de actualización. Verificado además que `File` no tiene observers registrados, así que el update acotado no produce efectos colaterales. La frontera de escritura de `files` de AGENTS.md se mantiene: compartidos no crea, no renombra y no borra recursos; solo anota un dato derivado de una sonda.

### D3 — Vocabulario cerrado de filtros, con 422 en valor no reconocido

**Elegido:** los filtros de estado de expiración usan `never` / `active` / `expired`, y la disponibilidad usa `available` / `missing` / `unknown`. Un valor fuera de ese vocabulario se rechaza con `422`.

**Por qué `never` y no `permanent`:** el panel (`resources/views/shares/index.blade.php`, línea 54) envía `never`; la spec de ciclo de vida usa "Sin vencimiento"; el código actual soporta `permanent`, un valor que nadie envía. Se unifica en `never` y se elimina el alias.

**Por qué 422 y no tolerancia:** el fallo actual es silencioso — un filtro no reconocido devuelve el conjunto completo, y el operador cree que filtró. Un error visible es preferible a un resultado falso. El riesgo de despliegue se acota porque el cliente y el servidor cambian en el mismo despliegue y el harness de contrato lo verifica.

**Regla de ordenamiento:** los campos permitidos son `name`, `created_at`, `expires_at`, `accesses`, `size`, `permission`, `status`. `permission` se ordena por nivel (Lectura → Escritura/Subida → Completo) y `status` por grupo (Sin vencimiento → Activo → Expirado), tal como exige la spec `share-management`. Un `sort` no permitido se rechaza con `422`.

### D4 — Un único serializador de share

**Elegido:** un método privado `sharePayload(Share $share): array` en `ShareController` que produce la forma completa, usado por `index`, `show`, `store` y `update`. Como segunda barrera, `Share` declara `protected $hidden = ['password_hash']`.

**Por qué no basta `$hidden`:** el defecto actual es que `store()` y `update()` devuelven el modelo Eloquent crudo, y `show()` también. Un `$hidden` corrige la fuga, pero no garantiza que las cuatro respuestas tengan la misma forma. El serializador único resuelve ambas cosas.

**Por qué no un API Resource de Laravel:** el proyecto no usa Resources en ningún otro módulo; un método privado mantiene la convención y evita introducir un patrón nuevo para un solo controlador.

### D5 — La caché pública guarda solo escalares

**Elegido:** `share:meta:{token}` almacena `['id', 'file_id', 'expires_at', 'permissions', 'password_hash']` y no el modelo con relaciones cargadas. La fila del recurso y su relación se resuelven en cada respuesta.

**Por qué:** el defecto actual es que la caché congela la relación `file`, de modo que un recurso renombrado se muestra con el nombre viejo hasta una hora después. Con la caché limitada a escalares, el nombre y la disponibilidad se leen siempre frescos. El coste es una consulta adicional por visita pública, que es el precio de la corrección.

### D6 — La pestaña del detalle de archivo consume la operación bulk del panel

**Elegido:** `bulkDeleteShares()` en `resources/views/files/index.blade.php` deja de emitir un `DELETE /shares/{id}` por enlace y llama a `POST /shares/bulk-delete` con los IDs seleccionados, igual que el panel. `loadFileShares()` deja de leer `data.shares` y lee `data.data`.

**Por qué:** la spec `shares-tabs-bulk-delete` dice explícitamente que la interfaz no debe depender de un `DELETE` por elemento. Además, la operación bulk revalida propiedad en el servidor y devuelve eliminados/omitidos/fallidos, que es la información que el toast actual no puede dar.

**Estado Alpine afectado:** `selectedShareIds`, `fileShares`, `bulkDeleteLoading` en el `x-data` de `resources/views/files/index.blade.php`. El flujo de alta (`generateShareLink`) deja de hacer `push` de la respuesta cruda y vuelve a pedir el listado del recurso, de modo que la lista quede consistente con el servidor sin duplicar la forma del payload en el cliente.

### D7 — Guardarraíl: contrato de rutas verificable, y harnesses que fallan en vez de abortar

**Elegido:** un harness nuevo `app/tests/harness_route_contract.php` que arranca el router y comprueba que cada ruta resuelve a un método existente, con el patrón de salida `0`/`1` del proyecto. Los dos harnesses rotos de mirror se corrigen para que reporten `h_fail` en vez de propagar la excepción de SQL.

**Por qué un harness y no un test de PHPUnit:** el proyecto no tiene suite PHPUnit en ejecución operativa; sus guardarraíles son scripts ejecutables con tag único, y AGENTS.md los documenta como runbook. Un test que nadie ejecuta no habría detectado esta regresión — de hecho, los dos harnesses que sí existían para este exacto dominio abortaron en silencio.

### D8 — Retirar la familia mirror y sus specs

**Elegido:** retirar los requisitos de `share-folder-identity-canonical` que dependen de la infraestructura desmontada, reescribir los que siguen siendo exigibles contra el modelo vigente, retirar `share-write-notification-canonical` por completo, eliminar `RepairShareMirrorTargetsCommand` (falla con error de SQL si se invoca) y eliminar la tabla `share_notification_log` (sin consumidor).

**Por qué no restaurarla:** el restore de agosto desactivó la feature de `physical_path_normalized` de forma deliberada, y `files` fue depurada y reindexada después. Reintroducir la infraestructura mirror/canónico implicaría una migración sobre la tabla más grande y más tocada del sistema, para sostener un modelo de identidad que el proyecto ya decidió abandonar. La spec de origen de esta decisión es `openspec/changes/archive/2026-09-21-restore-mis-archivos-august/`.

**Consecuencia de listing cross-storage:** el requisito "list children across storages" se conserva, pero se reexpresa contra el modelo vigente: un storage cuyo `base_path` es prefijo de la ruta física del recurso, más la cadena `parent_storage_id`. Esto es una funcionalidad a reconstruir, no un bug a corregir, y por eso queda en la **ola 2** del plan (ver abajo).

## Waves de despliegue

El cambio se divide en dos olas porque tienen perfiles de riesgo distintos:

```
  OLA 1 — P0 · "que nada dé 500 y los filtros sean ciertos"
  ─────────────────────────────────────────────────────────────────
  · restaurar bulkPreview / bulkDelete / verifyAvailability
  · restaurar PublicShareController::authenticate
  · filtros reales + 422 + orden por nivel/estado
  · serializador único (fin de la fuga de password_hash)
  · owner check en GET /shares/{id}
  · caché pública solo con escalares
  · pestaña del detalle de archivo sobre bulk + forma de respuesta
  · harness de contrato de rutas

  OLA 2 — P1 · "lo que el restore desmontó"
  ─────────────────────────────────────────────────────────────────
  · listing cross-storage de carpetas públicas
  · retiro de la familia mirror (comando, tabla, specs, docs)
```

La ola 1 es autocontenida y de riesgo bajo: no cambia el esquema salvo las tres columnas aditivas. La ola 2 toca el listing público y por eso va después, con el harness de compartidos ya verde como red.

## Risks / Trade-offs

**[El 422 de un filtro desconocido rompe la página si el cliente y el servidor se despliegan desfasados]** → Se despliegan juntos, y el harness de contrato de rutas verifica que las rutas y los métodos existen. Además, el único cliente de estos filtros es el Blade servido por la misma aplicación, así que no hay clientes externos que puedan desfasarse.

**[La migration sobre `files` compete con la reindexación de Mis Archivos]** → Las tres columnas son aditivas y nullables: `ADD COLUMN` sin `DEFAULT` no reescribe la tabla en PostgreSQL. La verificación escribe con `UPDATE` acotado sobre columnas concretas y no bloquea filas de forma masivo.

**[Un `UPDATE` acotado sobre `files` desde otro módulo erosiona la frontera de escritura de AGENTS.md]** → Se documenta la excepción explícitamente: la disponibilidad es un dato derivado de una sonda de filesystem, no catálogo, y la escritura no altera ningún atributo de la fila ni su marca de actualización. El harness de compartidos verifica que la verificación no modifica nombre, ruta, tamaño ni propietario.

**[El listing cross-storage puede duplicar entradas o perder archivos]** → Se resuelve por ruta normalizada y deduplicación por nombre, con el criterio determinista de preferir el storage de `base_path` más largo (sub-storage). La verificación se hace contra los datos reales antes de dar por buena la ola 2.

**[Retirar `share_notification_log` pierde histórico si tuviera filas]** → La tabla se consulta y se confirma el conteo antes de eliminarla. Si tuviera filas con valor histórico, se conserva como archivo en lugar de eliminarse.

**[Corregir los dos harnesses rotos puede revelar otros defectos del mismo origen]** → Es el objetivo. Los defectos que aparezcan se registran como tareas nuevas en este change, no se silencian.

## Migration Plan

1. `php artisan migrate` — añade `files.availability_state`, `files.last_verified_at`, `files.missing_since_at` y su índice parcial. Aditiva, nullable, reversible.
2. Desplegar el código de la ola 1 (controller, serializador, caché, cliente Alpine).
3. `php artisan config:cache` si se tocó `config/shares.php` (no se prevé tocarlo; los valores vigentes ya son los correctos: `max_bulk_items=200`, `hard_confirmation_threshold=25`).
4. Verificación en vivo contra los 317 shares reales: previsualizar expirados, ejecutar la depuración, verificar disponibilidad de un lote, abrir un enlace con contraseña.
5. Ola 2: listing cross-storage, retiro de la familia mirror, limpieza de `AGENTS.md`.

**Rollback:** `git revert` del commit y `php artisan migrate:rollback --step=1`. Las tres columnas son aditivas: la reversión no pierde datos que el módulo no pueda regenerar (el estado de disponibilidad se recalcula con una verificación). No hay estado persistente en Redis más allá de la caché pública, que expira sola. `systemctl reload php84-php-fpm` para liberar el opcode cache.

## Open Questions

Ninguna que bloquee la implementación. Queda un punto de confirmación operativa, sin efecto sobre las specs ni sobre el plan:

- El umbral `SHARE_MAX_BULK_ITEMS` está en 200 y el panel avisa al usuario por encima de 200 seleccionados. Con 317 shares y 72 expirados, la depuración de expirados entra sin problema. Si el volumen de enlaces por usuario creciera, el umbral debería pasar a ser configurable por la interfaz, pero eso no es necesario hoy.
