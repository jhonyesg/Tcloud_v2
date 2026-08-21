# Tasks: Avisos Inteligentes como orquestador de transcripción por cliente

## 0. Baseline previo a migrar

- [x] 0.1 Guardar el listado de storages con `transcription_enabled = true` (hoy 39) para comparar después:
  `SELECT id, name FROM storage_providers WHERE transcription_enabled ORDER BY id;`
- [x] 0.2 Anotar los conteos por cliente actuales (Punto 24/123, siglohallon 30/38, Stakeholders 29/36, jsuarez 12/24, Multiarchivo 20/23, Massmedios 14/17, ACR 15/16, sigloprensa 0/12, Monitoreoalpunto 9/11, prueba 2/4, soluziona 0/2, StakeholdersPrensa 0/2, santiago 0/2).

## 1. MIGRACIÓN — `user_storages.transcription_enabled`

- [x] 1.1 Crear migración `add_transcription_enabled_to_user_storages`:
  - `boolean('transcription_enabled')->default(false)->notNullable()` en `user_storages`.
  - Índice parcial sobre `(storage_provider_id)` donde `transcription_enabled` es `true`, para abaratar el `EXISTS` de la derivación.
- [x] 1.2 **Siembra en el mismo `up()`** (crítica): marcar en `true` las filas de `user_storages` cuyo storage tenga `transcription_enabled = true` — deben ser 155 de 310. Sin esto se apagan los 39 storages vivos y se detiene el pipeline.
- [x] 1.3 `down()`: eliminar solo la columna, sin tocar `storage_providers.transcription_enabled`.
- [x] 1.4 Ejecutar y verificar que los storages en `true` son **exactamente los mismos 39** de la tarea 0.1.

## 2. Backend: servicio de derivación

- [x] 2.1 Crear `app/app/Services/Ia/StorageTranscriptionSync.php`:
  - `recalculate(int $storageId): bool` — calcula el `EXISTS` sobre `user_storages`, persiste en `storage_providers` solo si cambió, devuelve el valor final.
  - `recalculateAll(): int` — reconcilia todos los storages, devuelve cuántos cambiaron.
- [x] 2.2 Registrar el servicio como singleton en `AppServiceProvider` (mismo patrón que `KeywordMatcher`, línea 34).
- [x] 2.3 Crear comando `avisos:sync-storage-transcription [--dry-run]` que llame a `recalculateAll()` y reporte los storages desalineados.

## 3. Backend: invocar el sync en los puntos de escritura

Un Observer no sirve: hay escrituras por query builder masivo que no disparan eventos de modelo.

- [x] 3.1 `StorageProviderController.php` — asignar/desasignar/bulk (líneas 255, 263, 292, 313, 340, 350).
- [x] 3.2 `UserStorageController.php` — líneas 39, 47, 61, 77.
- [x] 3.3 `UserController.php` — líneas 144 (borrado masivo por usuario) y 269 (creación).

## 4. Backend: modelo y controlador de avisos

- [x] 4.1 `app/app/Models/User.php`: añadir `belongsToMany(StorageProvider::class, 'user_storages')->withPivot('transcription_enabled')`. Conservar `userStorages()` que ya existe.
- [x] 4.2 `Ia/AvisosInteligentesController::index()`: añadir por cliente el conteo de storages totales y con transcripción vía `withCount` con constraint sobre el pivote (una sola consulta, sin N+1). Conservar búsqueda y `moduleFilter`.
- [x] 4.3 `Ia/AvisosInteligentesController::show()`: añadir los storages del cliente (`id`, `name`, `transcription_enabled` **del pivote**).
- [x] 4.4 Nuevo `toggleStorageTranscription(Request, int $userId, int $storageId)`: validar que exista la fila en `user_storages` (rechazar si no), actualizar el pivote, llamar a `StorageTranscriptionSync::recalculate()` y devolver el estado nuevo.
- [x] 4.5 Ruta `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription` junto al bloque existente (`routes/web.php:204-214`), mismo middleware admin.

## 5. Backend: retirar la segunda fuente de verdad

- [x] 5.1 Retirar `ApiTranscriptorController::toggleStorage()` (`:575`) y su ruta (`routes/web.php:173`).
- [x] 5.2 Revisar el uso de `transcription_enabled: false` en `ia/api-transcriptor/index.blade.php:2141` y adaptarlo.

## 6. Frontend

- [x] 6.1 `ia/avisos-inteligentes/index.blade.php`: la columna "Alertas 24h" (hoy siempre 0) pasa a "Storages con transcripción" mostrando `N / M`. El `x-for` sobre `users` ya está montado.
- [x] 6.2 `ia/avisos-inteligentes/user-detail.blade.php`: sección de storages del cliente con toggle por fila (`fetch()` a la ruta nueva, actualización de estado local), siguiendo el estilo de tarjetas y badges existente.
- [x] 6.3 `ia/api-transcriptor/index.blade.php`: el switch (líneas 272-290) y su handler (línea 3034) pasan a indicador de solo lectura con enlace a Avisos Inteligentes.

## 7. Verificación

- [x] 7.1 Tras migrar, `php artisan avisos:sync-storage-transcription --dry-run` reporta **cero** cambios.
- [x] 7.2 `/ia/avisos-inteligentes` reproduce exactamente los conteos de la tarea 0.2.
- [x] 7.3 **Caso central**: en un storage compartido por 6-8 clientes (`11 Telepacifico`, `14 Teleantioquia`), desactivar la transcripción de **un solo cliente** → los demás siguen en `true` y el storage sigue transcribiendo. Desactivarla en todos → el storage pasa a `false`.
- [x] 7.4 Activar `01 Radio FM Bogota` para `Punto` (hoy en `off`) → el storage pasa a `true` y entra en el pipeline.
- [x] 7.5 POST del toggle sobre un storage NO asignado al cliente → rechazado, sin escrituras.
- [x] 7.6 Desasignar un cliente desde `/admin/storages` → la bandera del storage se recalcula.
- [x] 7.7 `/ia/api-transcriptor` muestra el estado derivado y ya no permite escribirlo. Verificado en código: la ruta `storages/{id}/toggle` no aparece en `route:list`, no queda ningún handler (`toggleEnabled`/`skipStorage` solo sobreviven como comentarios) y la única escritura a `storage_providers.transcription_enabled` es `StorageTranscriptionSync`. Falta la confirmación visual en navegador.
- [x] 7.8 Rollback limpio: `migrate:rollback --step=1` y comprobar que ningún storage se apaga.
