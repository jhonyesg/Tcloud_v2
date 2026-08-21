## 1. Database

- [x] 1.1 Crear migración `add_transcription_access_to_user_storages` en `app/database/migrations/` con `Schema::table('user_storages', fn (Blueprint $t) => $t->boolean('transcription_access')->default(false))`. Sin backfill; todas las filas arrancan en `false`.
- [x] 1.2 Confirmar con `php artisan migrate:status` que la migración aplica sin tocar `storage_providers`. Pretend OK: solo `alter table "user_storages" add column "transcription_access" boolean not null default '0'`. Pendiente de aplicación manual (`APP_ENV=production`).

## 2. Models

- [x] 2.1 Añadir `transcription_access` a `$fillable` y al cast `boolean` en `app/app/Models/UserStorage.php`.
- [x] 2.2 En `app/app/Models/User.php::storageProviders()`, reemplazar `withPivot('permissions', 'can_create_shares', 'transcription_enabled', 'assigned_at')` por `withPivot('permissions', 'can_create_shares', 'transcription_access', 'assigned_at')`.
- [x] 2.3 Grep por `pivot->transcription_enabled` y cualquier referencia huérfana en el repo; actualizar a `pivot->transcription_access` donde aparezca. Sin referencias vivas al pivote; las menciones restantes son docblocks históricos (ApiTranscriptorController, TranscriptionHealthCheckCommand, TranscriptionTuneCommand) que documentan la lección del 18-08.

## 3. Backend — Controller y rutas

- [x] 3.1 En `app/app/Http/Controllers/Ia/AvisosInteligentesController.php::index()`, añadir `withCount` para `storages_with_access` filtrando `user_storages.transcription_access = true`. Devolver también la cuenta en el JSON.
- [x] 3.2 En `show()`, modificar la consulta de storages para incluir `user_storages.transcription_access` en el `select` y mapearlo en la respuesta. Calcular `$globalStorages` y `$globalTranscribing` y pasarlos a la vista.
- [x] 3.3 Añadir método público `toggleStorageAccess(Request $request, int $userId, int $storageId)` que valida `{access: bool}`, verifica que la fila `user_storages(user, storage)` exista, escribe `transcription_access` y devuelve el estado nuevo en JSON.
- [x] 3.4 En `app/routes/web.php` añadir la ruta `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access` dentro del grupo middleware existente.

## 4. Backend — KeywordMatcher

- [x] 4.1 En `app/app/Services/Ia/KeywordMatcher.php::run()`, obtener `$storageId` desde `$transcription->file->storage_provider_id`. Si el file falta, retornar `0` sin tocar nada (fail-safe).
- [x] 4.2 Añadir filtro `whereHas('userStorages', fn ($q) => $q->where('storage_provider_id', $storageId)->where('transcription_access', true))` a la consulta de usuarios candidatos.
- [x] 4.3 Verificar con un usuario de prueba que: (a) con acceso, sigue recibiendo el match; (b) sin acceso, deja de recibirlo pero los matches históricos en BD siguen existiendo. Pendiente de validación manual en entorno de prueba.

## 5. Backend — MisAvisosController

- [x] 5.1 En `app/app/Http/Controllers/MisAvisosController.php`, en `index()` y `matches()`, encadenar `whereHas('transcription.file.storageProvider.userStorages', fn ($sq) => $sq->where('user_id', $user->id)->where('transcription_access', true))` sobre la consulta de matches. Solo `index()` por ahora (no existe `matches()` separado en el controlador; el listado viene de `index()`).
- [x] 5.2 Verificar que matches históricos siguen visibles (no se borran de BD) y que nuevos matches de storages sin acceso ya no aparecen en el listado. Pendiente de validación manual.

## 6. Frontend — Index

- [x] 6.1 En `app/resources/views/ia/avisos-inteligentes/index.blade.php`, sustituir la columna "Canales" (solo conteo) por "Acceso: X / Y" usando `u.storages_with_access` y `u.storages_count`.
- [x] 6.2 Ajustar el tooltip/header de la columna para que diga "Storages con acceso / storages asignados".

## 7. Frontend — Detalle del cliente

- [x] 7.1 En `app/resources/views/ia/avisos-inteligentes/user-detail.blade.php`, eliminar el badge read-only "Transcribe / Sin transcripción" por storage.
- [x] 7.2 Añadir banner global en el header con "Api-Transcriptor: {{ $globalTranscribing }} / {{ $globalStorages }} storages transcribiendo".
- [x] 7.3 Por cada storage, añadir toggle "Dar acceso a transcripciones" con estado inicial `s.transcription_access`. Atributo `data-tour="storage-access-toggle"` en el primer toggle.
- [x] 7.4 Hint contextual cuando `s.transcription_enabled = false`: badge ámbar "Sin producción".
- [x] 7.5 En el Alpine `userDetail(...)`, agregar `accessStates: { [id]: bool }`, `togglingAccess: Set`, y método `setAccess(storage)` con `fetch` a `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access`. Manejar `200` actualizando el estado, `4xx` con rollback + alerta.

## 8. Tour guiado

- [x] 8.1 Paso nuevo en `app/resources/views/ia/avisos-inteligentes/user-detail.blade.php` (botón "Guía" + 3 pasos: orquestación, banner global, toggle por canal), añadir paso nuevo al tour del módulo avisos-inteligentes anclado al selector `[data-tour="storage-access-toggle"]`. Texto: "Activa el acceso por storage para darle al cliente permiso de ver los resultados de las transcripciones de ese canal. No afecta si api-transcriptor transcribe o no."

## 9. Validación manual

- [ ] 9.1 Probar con un usuario que tenga 2 storages asignados: dar acceso a uno, dejar el otro en false. Forzar una transcripción nueva en cada storage.
- [ ] 9.2 Confirmar que el usuario ve matches/alertas solo del storage con acceso.
- [ ] 9.3 Confirmar que `storage_providers.transcription_enabled` no cambió para ninguno de los dos storages (consulta directa a BD).
- [ ] 9.4 Confirmar que el scanner sigue corriendo normalmente y que `transcription:tune` no se alteró.
- [ ] 9.5 Recargar `/mis-avisos` con el usuario de prueba y verificar que solo aparecen matches del storage con acceso.
