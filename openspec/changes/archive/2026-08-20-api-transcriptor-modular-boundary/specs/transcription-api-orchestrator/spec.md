# Spec: transcription-api-orchestrator (delta)

## ADDED Requirements

### Requirement: api-transcriptor es un módulo de frontera cerrada para ediciones cross-module

El sistema SHALL mantener el módulo `/ia/api-transcriptor` — vistas, rutas, controlador, servicios asociados, configuración y migraciones propias — como un **módulo de frontera cerrada para modificaciones cross-module**.

Un change cuyo nombre NO siga el patrón `YYYY-MM-DD-*-api-transcriptor-*` NO SHALL modificar archivos del módulo. La dependencia desde otros módulos hacia api-transcriptor SHALL ser solo de lectura (consumo de modelos Eloquent, redirección a su URL para acciones operativas). En particular, otro módulo NO SHALL importar el `ApiTranscriptorController` ni llamar a sus endpoints de escritura (`toggleStorage`, `retry`, `cancelJob`, `reprocess`, `bulkDispatch`, `scanStorage`, `processFolder`, `processDay`, `processBatch`, `syncStorage`) por ruta: si necesita el mismo efecto, abre su propio change con prefijo api-transcriptor o propone al operador usar la UI del módulo.

Fixes de bug y parches de seguridad SHALL abrir su propio change con el prefijo `YYYY-MM-DD-*-api-transcriptor-*` y seguir el flujo normal de OpenSpec (proposal + tasks + archive). Esta regla NO SHALL interpretarse como congelación total del módulo: ediciones internas bien documentadas siguen siendo válidas.

#### Scenario: Una propuesta cross-module intenta editar api-transcriptor

- **WHEN** un change cuyo nombre NO empieza por `YYYY-MM-DD-*-api-transcriptor-*` tiene un diff que toca uno o más de los siguientes paths:
  - `app/resources/views/ia/api-transcriptor/**`
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`
  - `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`
  - `app/app/Services/Ia/TranscriptorSettings.php`
  - `app/app/Services/Ia/TranscriptionCoherencePass.php`
  - `app/app/Services/Ia/TranscriptorApiClient.php`
  - `app/app/Services/Ia/TranscriptionProcessor.php`
  - `app/app/Services/Ia/TranscriptionPollingService.php`
  - `app/app/Services/Ia/DiskScannerService.php`
  - `app/app/Services/Ia/AudioConverter.php`
  - rutas con prefijo `/ia/api-transcriptor/` en `app/routes/web.php`
  - `app/config/transcriptor.php`
  - migraciones con `transcription` en el nombre del archivo
- **THEN** el PR se rechaza con referencia a este requisito, y la edición se traslada a un change dedicado con el prefijo correcto o se elimina del diff

#### Scenario: Consumo desde otro módulo

- **WHEN** el módulo Avisos Inteligentes o el módulo Correcciones necesita mostrar un enlace a `/ia/api-transcriptor`, leer una `Transcription` o un `TranscriptionSegment`
- **THEN** lo hace por URL (enlace en la vista) o por modelo Eloquent (lectura), nunca importando un controlador o servicio de api-transcriptor para escribir en él ni llamando a sus endpoints de escritura

#### Scenario: Fix interno del módulo

- **WHEN** se detecta un bug o vulnerabilidad dentro de api-transcriptor
- **THEN** se abre un nuevo change con nombre `YYYY-MM-DD-*-api-transcriptor-*` que sigue el flujo normal de OpenSpec, y ese change SÍ puede modificar libremente los archivos del módulo
