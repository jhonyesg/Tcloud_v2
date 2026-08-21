# Change: Recuperación del pipeline de transcripción y desacople de API Transcriptor

## Why

El pipeline estuvo **44 horas parado** (2026-08-18 22:50 → 2026-08-20 18:07) sin que nada avisara.

Causa: la migración `2026_08_18_210000_add_transcription_enabled_to_user_storages` convirtió `storage_providers.transcription_enabled` en un valor **derivado** de un pivote nuevo por cliente. Su siembra sembró **0 filas** — corrió tras un rollback (hueco en el id 77 de `migrations`) cuando la bandera origen ya estaba en `false` — y las 310 filas del pivote quedaron vacías. Con eso los 175 storages derivaron a `false`: `DiskScannerService` se quedó sin nada que recorrer, `transcription:tune` apagó los 12 workers systemd y la UI de envío quedó vacía. Cada pieza reportaba su estado como normal ("no hay pending", "workers objetivo 0"), así que el corte fue invisible.

Además el cambio acopló dos módulos independientes: encender un canal pasó a hacerse en Avisos Inteligentes. API Transcriptor decide **qué se transcribe** (operativo); Avisos y Correcciones **consumen** el contenido que produce.

## What Changes

- **Recuperación**: migración que reactiva los 36 storages que transcribían antes del corte, reconstruidos desde el historial de `transcriptions` (90 días). Excluye el id 5 "00 Discos", raíz del árbol de datos.
- **Desacople**: se restaura `ApiTranscriptorController::toggleStorage()` y la ruta `POST /ia/api-transcriptor/storages/{id}/toggle`. `storage_providers.transcription_enabled` vuelve a ser autoritativo. Se eliminan `StorageTranscriptionSync`, el comando `avisos:sync-storage-transcription`, la ruta de toggle por cliente en Avisos y sus llamadas en `StorageProviderController`, `UserStorageController`, `UserController::destroy` y `AppServiceProvider`.
- **Avisos en solo lectura**: la ficha del cliente muestra sus canales y si se transcriben, con enlace a API Transcriptor.
- **Centinela**: `transcription:health-check` cada hora (WARNING + correo opcional vía plantilla `alerta-sistema`), y WARNING explícito en `tick`/`tune` cuando no queda ningún storage habilitado.

Requiere migración: sí (`2026_08_20_120000_reseed_user_storages_transcription`).

## Non-goals

- **No** se recupera el atraso del 19 y 20 (~46.000 archivos): decisión del operador. El pipeline ya cubría ~8.500 de ~22.000 archivos/día antes del corte.
- **No** se toca `min_file_size_bytes` (override en BD a 1 MB), solo queda vigilado.
- **No** se borra la columna `user_storages.transcription_enabled`: queda en BD sin uso y documentada como muerta.

## Capabilities

### New Capabilities
- `transcription-flow-watchdog`: centinela horario que avisa si el pipeline deja de producir transcripciones, más el ruido explícito de `tick`/`tune` cuando no hay storages habilitados.

### Modified Capabilities
- `transcription-api-orchestrator`: la bandera del storage es autoritativa y su interruptor vive en API Transcriptor, nunca derivada de otro módulo.
- `keyword-alerts`: la ficha del cliente informa qué canales se transcriben, en solo lectura.

## Impact

- Controllers: `Ia/ApiTranscriptorController`, `Ia/AvisosInteligentesController`, `StorageProviderController`, `UserStorageController`, `UserController`.
- Rutas: `+POST /ia/api-transcriptor/storages/{id}/toggle`, `−POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription`.
- Comandos: `+transcription:health-check`, `−avisos:sync-storage-transcription`; `TranscriptionTickCommand` y `TranscriptionTuneCommand` modificados.
- Servicios: `−App\Services\Ia\StorageTranscriptionSync`.
- Vistas: `ia/api-transcriptor/index`, `ia/avisos-inteligentes/index`, `ia/avisos-inteligentes/user-detail`.
- Config: `transcriptor.health_alert_email`; seeder `CorreoPlantillaSeeder` (+`alerta-sistema`).
