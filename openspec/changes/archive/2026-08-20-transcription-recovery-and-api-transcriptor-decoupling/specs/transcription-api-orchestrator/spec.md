# Spec: transcription-api-orchestrator (delta)

## MODIFIED Requirements

### Requirement: Admin puede habilitar transcripción por StorageProvider

El sistema SHALL permitir al admin marcar cada `StorageProvider` con la bandera `transcription_enabled = true` para indicar que sus archivos deben enviarse a la API de transcripción.

Esa bandera SHALL ser **autoritativa y de un solo escritor**: se cambia únicamente desde API Transcriptor, vía `POST /ia/api-transcriptor/storages/{id}/toggle` (`ApiTranscriptorController::toggleStorage()`). El sistema NO SHALL derivarla de ninguna otra tabla ni permitir que otro módulo la escriba.

API Transcriptor SHALL ser independiente de Avisos Inteligentes y de Correcciones: esos módulos **consumen** el contenido que la transcripción produce, y por tanto no deciden qué se transcribe. La pantalla de un módulo consumidor NO SHALL ofrecer el control de habilitación ni redirigir a él para cambiarlo.

Contexto histórico (no repetir): entre el 2026-08-18 y el 2026-08-20 esta bandera fue un valor derivado de `user_storages.transcription_enabled` y el control se mudó a la ficha del cliente en Avisos Inteligentes. La indirección dejó el pipeline apagado 44 horas cuando el pivote quedó vacío, y obligaba a salir del módulo para encender un canal.

Todo cambio de la bandera SHALL registrarse en `laravel.log` con el storage, el valor anterior, el nuevo y el usuario que lo hizo: apagar un storage detiene su descubrimiento por completo, y ese silencio es indistinguible de un fallo.

#### Scenario: Admin habilita un storage para transcripción
- **WHEN** el admin pulsa el interruptor de la columna "Transcripción" en `/ia/api-transcriptor` sobre un storage inactivo
- **THEN** el sistema persiste `transcription_enabled = true`, deja constancia en el log, y el scanner comienza a considerar archivos de ese storage en el siguiente ciclo del tick

#### Scenario: Admin deshabilita un storage
- **WHEN** el admin confirma el apagado desde el mismo interruptor
- **THEN** el scanner deja de crear nuevos `ConvertAndTranscribeJob`s para ese storage, los jobs ya encolados o en proceso continúan, y lo ya transcrito se conserva

#### Scenario: Petición sin el campo esperado
- **WHEN** llega un `POST` al endpoint de toggle sin `transcription_enabled`
- **THEN** el sistema responde 422 y no modifica ninguna fila

#### Scenario: El pool de workers se reajusta solo
- **WHEN** cambia el número de storages habilitados
- **THEN** `transcription:tune --apply` recalcula los medios equivalentes en su siguiente corrida (cada 5 min) y ajusta las units systemd sin intervención manual
