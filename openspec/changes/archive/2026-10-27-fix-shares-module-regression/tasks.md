## 1. Fundaciones de esquema

- [x] 1.1 Crear la migration `add_share_availability_to_files` con `files.availability_state` (varchar, nullable), `files.last_verified_at` (timestamp, nullable) y `files.missing_since_at` (timestamp, nullable), más un índice parcial para el filtro de la spec `share-file-availability`
- [x] 1.2 Escribir el `down()` que elimina las tres columnas y el índice, y verificar que la reversión no borra datos ajenos
- [x] 1.3 Ejecutar la migration en el entorno y confirmar que `files` mantiene su volumen de filas (sin reescritura de tabla)

## 2. Servicio de disponibilidad

- [x] 2.1 Crear `app/app/Services/ShareAvailabilityService.php` con `verify(Collection $files): array` que devuelva `checked`, `available`, `missing`, `unknown` y `details`
- [x] 2.2 Implementar la resolución de la ruta física como `rtrim(storage.base_path) . '/' . ltrim(file.path)`, reutilizando el criterio de `PublicShareController` y de `StorageSyncService` en lugar de duplicar la concatenación en un cuarto sitio
- [x] 2.3 Clasificar como `unknown` (con razón legible) todo storage no local, inaccesible, con `base_path` no resoluble o con directorio padre no atravesable; NO usar `file_exists()` sobre una ruta local en esos casos
- [x] 2.4 Persistir el resultado con `DB::table('files')->where('id', ...)->update([...])` sobre las tres columnas únicamente, sin tocar otros atributos ni `updated_at`
- [x] 2.5 Verificar que `File` no tiene observers registrados y que el update acotado no dispara efectos colaterales

## 3. ShareController — contrato de consulta y payload

- [x] 3.1 Extraer `shareQuery(Request $request, User $user): Builder` como única fuente de alcance, reutilizada por listado, previsualización, depuración y verificación
- [x] 3.2 Implementar el vocabulario cerrado de filtros: `q`, `permission`, `status` (`never`/`active`/`expired`), `availability` (`available`/`missing`/`unknown`), `storage_id`, `created_from`, `created_to`, `expires_from`, `expires_to`, `accessed_from`, `accessed_to`; rechazar con `422` cualquier valor fuera del vocabulario
- [x] 3.3 Implementar `applyDateRange` con el criterio de día completo para fechas de longitud 10, en la zona horaria de la aplicación
- [x] 3.4 Implementar el ordenamiento permitido (`name`, `created_at`, `expires_at`, `accesses`, `size`, `permission`, `status`) y rechazar con `422` cualquier otro campo o una dirección distinta de `asc`/`desc`
- [x] 3.5 Ordenar `permission` por nivel (Lectura → Escritura/Subida → Completo) y `status` por grupo (Sin vencimiento → Activo → Expirado), con desempate estable por `shares.id`
- [x] 3.6 Implementar `sharePayload(Share $share): array` con la forma completa de la spec: `id`, `token`, `public_url`, `permissions`, `expires_at`, `created_at`, `is_expired`, `expiry_status`, `has_password`, `access_logs_count` y el bloque `file` con `availability_state`
- [x] 3.7 Declarar `protected $hidden = ['password_hash']` en `app/app/Models/Share.php` como segunda barrera
- [x] 3.8 Aplicar `sharePayload` en `index`, `show`, `store` y `update`, de modo que las cuatro respuestas tengan la misma forma y ninguna exponga el hash
- [x] 3.9 Autorizar `GET /shares/{id}` por propietario o administrador, respondiendo `403`/`404` sin revelar si el ID existe

## 4. ShareController — operaciones bulk

- [x] 4.1 Implementar `bulkPreview(Request $request)`: resolver el alcance con `shareQuery`, contar, y devolver `count` y `summary` con `expired`, `permanent`, `missing`, `unknown` y `permissions`; no muta datos
- [x] 4.2 Implementar `bulkDelete(Request $request)`: re-resolver el alcance, exigir que `confirm_count` coincida con el total actual (409 si no), eliminar en transacción por lotes, y devolver `deleted_count`, `omitted_count`, `failed_count` y `omitted_ids`
- [x] 4.3 Invalidar `share:meta:{token}` para cada token revocado, tanto en la revocación individual como en la bulk
- [x] 4.4 Implementar `verifyAvailability(Request $request)`: acotar por lote, avanzar por cursor estable por id, y devolver `checked`, `available`, `missing`, `unknown`, `has_more` y `next_cursor`
- [x] 4.5 Registrar la depuración bulk en el log con `user_id`, `deleted_count`, `omitted_count`, `all_matching` y `confirm_count`
- [x] 4.6 Nunca eliminar `File`, archivo físico ni transcripciones desde la depuración; verificar que la cascada de `share_access_log` sigue siendo la única que elimina logs

## 5. ShareController — listing y contadores

- [x] 5.1 Sustituir la hidratación completa `(clone $base)->get()` por conteos agregados en SQL para `total`, `expired`, `permanent`, `missing` y `unknown`
- [x] 5.2 Calcular `counters.missing` como recursos confirmados ausentes (`availability_state = 'missing'`), no como shares sin recurso asociado
- [x] 5.3 Calcular `counters.unknown` como recursos en estado `unknown` (incluye los nunca verificados, con `availability_state IS NULL`)
- [x] 5.4 Devolver `counters` reflecting el conjunto filtrado cuando el usuario aplique filtros, según la spec `share-management`

## 6. Ciclo de vida y expiración

- [x] 6.1 Implementar `resolveCreationExpiry`: si la solicitud omite la fecha y no marca la no expiración explícita, aplicar `SHARE_DEFAULT_EXPIRY_DAYS` (30 por defecto); si marca la no expiración, persistir `NULL`
- [x] 6.2 Interpretar la fecha recibida en la zona horaria de la aplicación antes de persistirla
- [x] 6.3 Mantener intactos los enlaces existentes con `expires_at IS NULL` (no hay backfill)
- [x] 6.4 Exponer `expiry_status` con el vocabulario `never`/`active`/`expired` de forma consistente en listado, previsualización y filtro

## 7. PublicShareController — corrección de la rotura

- [x] 7.1 Implementar `authenticate(Request $request, string $token)`: resolver el share, responder `404` si no existe, `410` si está expirado antes de evaluar la contraseña, redirigir al público si no tiene contraseña, validar la contraseña y establecer `share_auth_{token}` en sesión
- [x] 7.2 Ante contraseña incorrecta, devolver `401` JSON para clientes JSON o la vista `shares.public-password` con error, sin crear la sesión
- [x] 7.3 Verificar que `resources/views/shares/public-password.blade.php` apunta a un endpoint registrado y funcional
- [x] 7.4 Reducir la caché `share:meta:{token}` a escalares (`id`, `file_id`, `expires_at`, `permissions`, `password_hash`) y resolver la fila del recurso en cada respuesta, de modo que un renombrado se vea de inmediato
- [x] 7.5 Conservar la revalidación de existencia del share tras la caché (el caso "share revocado con caché previa" ya está cubierto; verificar que sigue tras el cambio)

## 8. Panel de compartidos (`resources/views/shares/index.blade.php`)

- [x] 8.1 Verificar que los selectores de estado envían `never` y que el backend lo acepta; eliminar el alias `permanent`
- [x] 8.2 Verificar que el selector de disponibilidad y los campos de rango de fechas están conectados al estado Alpine `filters` y llegan al backend
- [x] 8.3 Verificar que los encabezados de ordenamiento envían un `sort` dentro del vocabulario permitido y que el indicador visual coincide con el orden real aplicado por el servidor
- [x] 8.4 Mostrar el error de validación `422` de filtros y ordenamientos con un mensaje accionable, no con un fallo genérico
- [x] 8.5 Mostrar `expiry_status` y `availability_state` por fila, distinguiendo "no verificado" de "confirmado ausente"

## 9. Pestaña de enlaces del detalle de archivo (`resources/views/files/index.blade.php`)

- [x] 9.1 Corregir `loadFileShares()` para consumir la forma publicada (`data.data`) en lugar de `data.shares`
- [x] 9.2 Sustituir `bulkDeleteShares()` (N `DELETE /shares/{id}`) por una única llamada a `POST /shares/bulk-delete` con los IDs seleccionados, y mostrar eliminados/omitidos/fallidos en el toast
- [x] 9.3 Corregir `generateShareLink()` para no insertar la respuesta cruda en `fileShares`, y relistar los enlaces del recurso al terminar
- [x] 9.4 Verificar la sesión Alpine afectada: `fileShares`, `selectedShareIds`, `bulkDeleteLoading`, `shareFeedback`

## 10. Guardarraíles

- [x] 10.1 Crear `app/tests/harness_route_contract.php`: arrancar el router, recorrer todas las rutas y comprobar que cada una resuelve a un método de controller existente; salida `0`/`1`, tag único, mensaje con URI, verbos y método ausente
- [x] 10.2 Corregir `app/tests/harness_merged_into_rename_compat.php` para reportar `h_fail` en vez de propagar la excepción de la columna inexistente, y reescribir sus aserciones contra el esquema vigente
- [x] 10.3 Corregir `app/tests/harness_merged_into_create_edit_delete.php` con el mismo criterio
- [x] 10.4 Crear `app/tests/harness_shares_module.php`: previsualización que no muta, depuración que respeta el alcance del usuario y devuelve omitidos, verificación por cursor que cubre cada share exactamente una vez, ningún `File` eliminado, y `password_hash` ausente de toda respuesta
- [x] 10.5 Ejecutar la suite de harnesses de compartidos y el de contrato de rutas contra el entorno, con salida `0`

## 11. Ola 2 — listing cross-storage y retiro de la familia mirror

- [x] 11.1 Reimplementar la resolución de carpetas equivalentes contra el modelo vigente: storages cuyo `base_path` es prefijo de la ruta física del recurso, más la cadena `parent_storage_id`
- [x] 11.2 Deduplicar el listing por nombre, con criterio determinista de preferir el storage de `base_path` más largo
- [x] 11.3 Aplicar la misma resolución al listado de subcarpetas (`GET /s/{token}/folder/{folder_id}`) y a `isDescendantOf()`, para que las acciones sobre archivos cross-storage no respondan `403`
- [x] 11.4 Verificar contra datos reales que el listing no incluye nietos y que el conteo coincide con los hijos directos deduplicados
- [x] 11.5 Eliminar `app/app/Console/Commands/RepairShareMirrorTargetsCommand.php` y `app/app/Console/Commands/ShowCanonicalizationImpactCommand.php`, que fallan con error de SQL si se invocan
- [x] 11.6 Eliminar la tabla `share_notification_log` tras confirmar su conteo de filas; sus 2 registros históricos se archivaron en `backups/share_notification_log_20261027_093303.csv`
- [x] 11.7 Actualizar `AGENTS.md`: retirar las referencias a `File::isMirror()`, `File::canonical()`, `File::isFolderMirror()`, `File::canonicalFolder()`, `files.canonical_folder_id` y `file_mirror_audit_log`, documentar el harness nuevo en la tabla de inventario y añadir el runbook del módulo de compartidos
- [x] 11.8 Corregir el `Purpose` placeholder `TBD` de `openspec/specs/share-file-download/spec.md`
- [x] 11.9 Las rutas muertas de otros módulos quedan documentadas como **deuda conocida** en `harness_route_contract.php`, con razón y seguimiento; su retirada sigue planificada en un change aparte
- [x] 11.10 Al archivar, confirmar que la capability `share-write-notification-canonical` queda sin requisitos (patrón ya usado por `transcription-orchestrator-runtime`) y que `share-folder-identity-canonical` conserva únicamente los requisitos reescritos contra el modelo vigente

## 12. Verificación final y despliegue

- [x] 12.1 Verificar en vivo contra los 317 shares reales: previsualizar expirados (72), ejecutar la depuración, verificar disponibilidad de un lote, abrir un enlace con contraseña
- [x] 12.2 Confirmar que `GET /shares` con cada filtro y cada orden devuelve el subconjunto esperado
- [x] 12.3 Confirmar que ninguna respuesta de compartidos expone `password_hash` ni el token de un share ajeno
- [x] 12.4 `php artisan migrate:status` y `php artisan config:cache` si se tocó `config/shares.php`
- [x] 12.5 `systemctl reload php84-php-fpm` y limpieza de la caché pública de los tokens tocados
- [x] 12.6 Registrar el resultado en `AGENTS.md` con el runbook de operación y de reversión
