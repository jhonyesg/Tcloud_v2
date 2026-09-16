# Change: api-transcriptor como módulo de frontera cerrada para ediciones cross-module

## Why

El módulo `/ia/api-transcriptor` llegó a un cierre operativo el 2026-08-20:

- **`2026-08-20-transcription-recovery-and-api-transcriptor-decoupling`** dejó la bandera de habilitación como **autoritativa y de un solo escritor** (`ApiTranscriptorController::toggleStorage()`). Avisos y Correcciones solo consumen el contenido producido.
- **`2026-08-20-api-transcriptor-config-sin-palancas-muertas`** audita las 43 claves del `SCHEMA` de `TranscriptorSettings`: cada una tiene consumidor real a través de la capa de settings. Las dos que no lo tenían (`ai_coherence_threshold`, `ai_coherence_model`) están fuera; los topes de interfaz (`ui_batch_max`, `ui_max_parallel_sends`, `scan_batch`) ya viajan desde la capa de settings a la vista en vez de leerse de `config()` al renderizar.
- El **tour interactivo** cubre las tres pestañas (config / storages / jobs) en 36 pasos totales, disparado por el botón morado "Guía" del header.

Los dos incidentes que motivaron las dos propuestas archivadas hoy comparten el mismo patrón de fondo: módulos externos asumieron que podían mover archivos de api-transcriptor para ajustarse a su lógica (la ficha del cliente de Avisos tomó el control de la bandera; la UI leyó `config()` en vez de la capa de settings). Esas suposiciones causaron 44 horas de pipeline apagado y palancas muertas en la pantalla de configuración.

No añadir más funcionalidades a este módulo es la mejor protección contra el siguiente incidente del mismo tipo.

## What Changes

- Se declara **api-transcriptor como módulo de frontera cerrada** para modificaciones cross-module: un change cuyo nombre NO siga el patrón `YYYY-MM-DD-*-api-transcriptor-*` no modifica archivos del módulo. La dependencia desde otros módulos hacia api-transcriptor es solo de lectura (consumo de datos, redirección a su URL).
- Se añade el requisito `api-transcriptor es un módulo de frontera cerrada` a la capability `transcription-api-orchestrator`, con escenarios que distinguen edición prohibida vs. consumo permitido vs. fix interno.
- **No** se añade funcionalidad nueva, no se reescribe el tour, no se borran rutas ni servicios, no se tocan tests.

## Non-goals

- **No** se congela el módulo en sentido absoluto: fixes de bug y parches de seguridad siguen siendo válidos, abriendo su propio change con prefijo `2026-MM-DD-*-api-transcriptor-*` y siguiendo el flujo normal de OpenSpec.
- **No** se prohíbe el consumo: otros módulos pueden leer `Transcription`, `TranscriptionSegment`, enlaces a `/ia/api-transcriptor`, etc.
- **No** se mueven archivos, no se refactoriza nada, no se auditan otros módulos: la declaración aplica solo a api-transcriptor.
- **No** se mete un script de CI que falle builds por tocar el módulo: el verificador es humano, en revisión de PR.

## Capabilities

### Modified Capabilities
- `transcription-api-orchestrator`: nuevo requisito `api-transcriptor es un módulo de frontera cerrada` con dos escenarios. El delta se aplica al spec existente, sin modificar los otros requisitos.

## Impact

- `openspec/specs/transcription-api-orchestrator/spec.md` — un `### ADDED Requirements` con su `Requirement` y `Scenarios`.
- Sin cambios en código de aplicación. Sin migración. Sin tests nuevos.
- El verificador (tarea 1.x) vive como lista de inspección en `tasks.md` para aplicarla en revisión de PR futuros.
