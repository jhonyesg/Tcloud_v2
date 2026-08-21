# Change: Avisos Inteligentes como orquestador de transcripción por cliente

## Why

El módulo "Avisos Inteligentes" (change archivado `2026-07-06-ia-transcription-module`) está construido pero **nunca se ha usado**: sus cinco tablas (`user_alerts_inteligentes`, `keywords`, `user_keyword`, `keyword_matches`, `alert_logs`) tienen cero filas y las tareas de validación 12.1–12.11 quedaron sin marcar.

La causa de fondo es un error de modelo. Hoy `transcription_enabled` es una columna de `storage_providers`: una bandera **global del storage**. Pero la transcripción es un **servicio que cada cliente contrata**. El cliente A puede pagarla sobre un canal y el cliente B tener ese mismo canal asignado sin haberla pagado — y B no debe recibir avisos ni enterarse. Con una bandera única por storage eso es inexpresable.

**Evidencia en producción (2026-08-18):**

```
175 storages activos → 39 transcribiendo, 136 no
13 clientes · 310 filas en user_storages
43 de los 175 storages los comparten entre 2 y 8 clientes

Punto:        24 con tx,  99 sin   (99 emisoras de radio ya asignadas, dormidas)
sigloprensa:   0 con tx,  12 sin   ← hoy no puede recibir ningún aviso
soluziona / StakeholdersPrensa / santiago: 0 con tx
```

Además, `KeywordMatcher::run()` itera **todos** los usuarios con el módulo activo sin filtrar por storage, así que enviaría a un cliente el snippet del texto de canales que no tiene asignados. El modelo por cliente cierra esa fuga por construcción.

## What Changes

### 1. `user_storages.transcription_enabled` (migración)

Nueva columna booleana en el pivote, que pasa a ser la fuente de verdad comercial: qué cliente contrató transcripción sobre qué storage. El pivote ya tiene esta forma (`permissions`, `can_create_shares`), así que no hace falta tabla nueva.

### 2. `storage_providers.transcription_enabled` pasa a derivarse

Queda como `EXISTS(user_storages WHERE storage_provider_id = sp.id AND transcription_enabled)`. Nuevo servicio `StorageTranscriptionSync` recalcula, invocado desde todos los puntos que escriben `user_storages`, más un comando de reconciliación.

Consecuencia deliberada: una sola transcripción física sirve a todos los clientes que contrataron ese storage — no se duplica trabajo de ASR.

### 3. Pantalla admin reorientada al cliente

`/ia/avisos-inteligentes` lista clientes con `storages con tx / totales`; la ficha `{user}` gana la lista de storages del cliente con toggle por cliente. El switch de `/ia/api-transcriptor` pasa a solo lectura para no dejar dos fuentes de verdad.

## Non-goals

- **No** cambia el matching (sigue por substring); ni el aviso in-app, ni el digest de correo, ni la plantilla `ia-alert-match`, ni el retroactivo del admin, ni el deep-link al editor. Todo eso es fase posterior.
- **No** asigna storages a clientes: eso se sigue haciendo en `/admin/storages`. Esta pantalla solo añade la capa de transcripción sobre lo ya asignado.
- **No** reparte el costo de ASR entre los clientes que comparten un storage.
- **No** aborda la duplicación activa de `transcription_segments` (queda del lado del módulo transcriptor).

## Impact

- **Rutas:** una nueva `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription`; se retira `POST /ia/api-transcriptor/storages/{id}/toggle` (`routes/web.php:173`).
- **Controllers:** `Ia/AvisosInteligentesController` (index, show, nuevo toggle), `Ia/ApiTranscriptorController` (retirar `toggleStorage`), `StorageProviderController`, `UserStorageController`, `UserController` (invocar el sync).
- **Models:** `User` (relación `belongsToMany` a `StorageProvider` con pivote), `UserStorage`.
- **Nuevo:** `app/app/Services/Ia/StorageTranscriptionSync.php`, comando `avisos:sync-storage-transcription`.
- **Views:** `ia/avisos-inteligentes/index.blade.php`, `ia/avisos-inteligentes/user-detail.blade.php`, `ia/api-transcriptor/index.blade.php`.
- **Migración:** SÍ — añade la columna y **siembra 155 de 310 filas**. Sin la siembra, la derivación apagaría los 39 storages que transcriben hoy y detendría el pipeline en producción.
- **Riesgos:** medio-alto por la migración con siembra sobre datos vivos. Mitigado por: siembra conservadora en el mismo `up()`, comando `--dry-run` de reconciliación, y `down()` que no apaga nada.
