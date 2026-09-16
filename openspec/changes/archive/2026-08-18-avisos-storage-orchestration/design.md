# Design: Avisos Inteligentes como orquestador de transcripción por cliente

## Decisión 1: la habilitación vive en el pivote, no en el storage

La transcripción es un servicio contratado por cliente. Modelarla como columna de
`storage_providers` hace imposible el caso central: dos clientes comparten un canal
y solo uno paga.

```
  user_storages                              ← fuente de verdad COMERCIAL
    user_id, storage_provider_id
    permissions, can_create_shares
    transcription_enabled  (NUEVO)           ← este cliente contrató aquí

  storage_providers.transcription_enabled    ← DERIVADO
    = EXISTS(user_storages us
             WHERE us.storage_provider_id = sp.id
               AND us.transcription_enabled)
```

Se descartó una tabla nueva (`user_storage_transcription`): el pivote ya modela
atributos de la relación (`permissions`, `can_create_shares`), así que una columna
más es consistente con lo que hay y evita un tercer join en cada consulta.

La bandera del storage se conserva porque es la que consume el pipeline
(`StorageProvider::scopeTranscriptionEnabled()`, `DiskScannerService`,
`ApiTranscriptorController`). Convertirla en derivada mantiene intacto todo ese
código: sigue leyendo la misma columna, solo cambia quién la escribe.

Consecuencia buscada: **una sola transcripción física sirve a los N clientes que
contrataron** ese storage. No se transcribe dos veces el mismo archivo. El reparto
de costo entre clientes es un problema posterior.

## Decisión 2: el eje de la pantalla es el cliente

```
  13 clientes   contra   175 storages
```

Listar por storage daría 175 filas con el cliente repetido; listar por cliente da
13. Además todo lo que el módulo administra —techo de keywords, correos, historial—
ya es por cliente, y la ficha `user-detail.blade.php` ya existe: se extiende en vez
de reescribirse. El storage es detalle dentro de la ficha.

## Decisión 3: la derivación se invoca explícitamente, no por Observer

Un Observer de Eloquent sobre `UserStorage` parecería lo natural, pero **no se
dispara con query builder masivo**, y `user_storages` se escribe así en varios
sitios:

- `StorageProviderController.php:340` — `UserStorage::insert()` (bulk)
- `StorageProviderController.php:350` — `->delete()` masivo por storage
- `UserController.php:144` — `->delete()` masivo por usuario

Con un Observer, desasignar clientes en bloque dejaría storages transcribiendo sin
ningún cliente detrás, en silencio. Por eso:

- `StorageTranscriptionSync::recalculate(int $storageId): bool`
- `StorageTranscriptionSync::recalculateAll(): int`

invocados explícitamente desde cada punto de escritura, más
`php artisan avisos:sync-storage-transcription [--dry-run]` como red de seguridad y
detector de deriva.

## Decisión 4: una sola fuente de verdad para el switch

`ApiTranscriptorController::toggleStorage()` (`:575`) escribe hoy la columna que
pasa a ser derivada. Si se deja viva, cualquier uso la desincroniza hasta la
siguiente reconciliación. Se retira la escritura y su ruta (`routes/web.php:173`),
y el switch de la vista (`ia/api-transcriptor/index.blade.php`, líneas 272-290 y
handler en 3034) pasa a indicador de solo lectura con enlace a avisos.

## Migración: la siembra no es opcional

Estado de partida: 39 storages con `transcription_enabled = true`, y **155 de las
310 filas** de `user_storages` les corresponden. Si la columna nace en `false` y la
derivación entra en vigor, esos 39 storages se apagan y **el pipeline de
transcripción se detiene en producción**.

El `up()` siembra en la misma migración: para cada storage con
`transcription_enabled = true`, marcar en `true` sus filas de `user_storages`.

La siembra es conservadora a propósito — asume que todos los clientes de un storage
activo lo tenían contratado. Es la única opción que no interrumpe el servicio; el
admin depura después desde la ficha, que es justo lo que la pantalla habilita.

Índice parcial sobre las filas en `true` para que el `EXISTS` de la derivación sea
barato.

`down()` elimina solo la columna. `storage_providers.transcription_enabled` conserva
su último valor, así que el rollback no apaga nada.

## Alpine / Blade

Sigue el patrón de las vistas existentes (sin build step, Alpine por CDN):

- `ia/avisos-inteligentes/index.blade.php`: el componente `avisosInteligentes()` y su
  `x-for` sobre `users` ya están montados. La columna "Alertas 24h" (hoy siempre 0,
  no hay matches) pasa a "Storages con transcripción" con `N / M`.
- `ia/avisos-inteligentes/user-detail.blade.php`: sección nueva de storages con un
  toggle por fila que hace `fetch()` a la ruta nueva y actualiza el estado local;
  mismo estilo de tarjetas y badges de la vista.

Auth por `session('user')` según convención del proyecto — nunca `auth()->user()`.
Nota: `EnsureMisAvisosEnabled` usa `Session::get('user_id')`; las rutas admin de
avisos van bajo el middleware `admin` ya existente.

## Rendimiento

`index()` necesita, por cliente, storages totales y con transcripción. Con la
relación `belongsToMany` sobre `user_storages` se resuelve con `withCount` y un
constraint sobre el pivote, en una sola consulta — evita el N+1 que daría recorrer
`userStorages.storageProvider` en PHP.
