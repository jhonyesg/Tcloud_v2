# Tasks

Todas ejecutadas y verificadas en producción el 2026-08-20.

## 1. Diagnóstico

- [x] 1.1 Localizar el corte: última fila de `transcriptions` el 2026-08-18 22:50:17
- [x] 1.2 Confirmar el estado de las banderas: 0/310 en `user_storages`, 0/175 en `storage_providers`
- [x] 1.3 Rastrear la causa hasta la migración del pivote y el hueco del id 77 en `migrations`
- [x] 1.4 Reconstruir el conjunto habilitado previo desde el historial de `transcriptions` (36 storages)

## 2. Recuperación del servicio (requiere migración)

- [x] 2.1 **Migración** `2026_08_20_120000_reseed_user_storages_transcription`: reactiva los 36 storages, aborta si ninguno queda encendido
- [x] 2.2 Aplicar `php artisan migrate` y verificar 36 storages transcribiendo
- [x] 2.3 `php artisan transcription:tune --apply` → 12 workers systemd arriba
- [x] 2.4 Verificar flujo real: pending → queued → processing → done, cola Redis oscilando bajo el target 140

## 3. Backend — desacople

- [x] 3.1 Restaurar `ApiTranscriptorController::toggleStorage()` con `Log::info` del cambio (quién y cuándo)
- [x] 3.2 Ruta `POST /ia/api-transcriptor/storages/{id}/toggle`; eliminar la de Avisos
- [x] 3.3 Eliminar `App\Services\Ia\StorageTranscriptionSync` y `avisos:sync-storage-transcription`
- [x] 3.4 Quitar sus llamadas en `StorageProviderController`, `UserStorageController`, `UserController::destroy`, `AppServiceProvider`
- [x] 3.5 `AvisosInteligentesController`: eliminar `toggleStorageTranscription()`; `show()` lee la bandera del storage, `index()` deja de contar "contratados"
- [x] 3.6 Documentar como muerta la columna `user_storages.transcription_enabled` en la cabecera de su migración

## 4. Backend — detección

- [x] 4.1 Comando `transcription:health-check` (`--hours`, `--to`) con sonda por PK y cooldown de 6 h
- [x] 4.2 Agendarlo cada hora en `routes/console.php`
- [x] 4.3 `TranscriptionTuneCommand`: `Log::warning` antes de apagar el pool entero, con nº de workers activos
- [x] 4.4 `TranscriptionTickCommand`: distinguir "ocioso sano" de "0 storages habilitados"
- [x] 4.5 Plantilla `alerta-sistema` en `CorreoPlantillaSeeder` + `transcriptor.health_alert_email`

## 5. Frontend

- [x] 5.1 `ia/api-transcriptor/index`: badge → interruptor real, con `confirm()` al apagar y toast de resultado
- [x] 5.2 Sustituir "Gestionar en Avisos" por "Dejar de transcribir" en la tarjeta de medios sin archivos
- [x] 5.3 Actualizar el paso del tour guiado de la columna "Transcripción"
- [x] 5.4 `ia/avisos-inteligentes/user-detail`: badge de solo lectura + enlace a API Transcriptor; quitar el método `toggleStorage()`
- [x] 5.5 `ia/avisos-inteligentes/index`: columna "Canales" (asignados), sin conteo de "contratados"

## 6. Verificación

- [x] 6.1 Toggle probado contra la BD dentro de una transacción con rollback: apaga, enciende, 422 si falta el campo
- [x] 6.2 Guarda de apagado masivo probada y luego retirada junto con la derivación (ver design.md)
- [x] 6.3 `transcription:health-check --hours=0` dispara el aviso; con el pipeline vivo se queda callado
- [x] 6.4 `php artisan view:cache` compila todas las Blade; `route:list` muestra la ruta nueva y no la vieja
- [x] 6.5 Suite: sin regresiones (fallos previos ajenos: `min_shm_free_bytes` ausente en config, `AiSuggestCommandTest`, y 11 errores de entorno por contenedor sin `encrypter`)

## Pendiente para el operador

- [ ] Reactivar desde `/ia/api-transcriptor` los 3 storages que estaban habilitados sin producción en 90 días
- [ ] Definir `TRANSCRIPTOR_HEALTH_ALERT_EMAIL` y sembrar la plantilla `alerta-sistema` para recibir el aviso por correo
