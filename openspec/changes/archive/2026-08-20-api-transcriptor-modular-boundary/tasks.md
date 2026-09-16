# Tasks

Este change no escribe código. La regla queda vigente en cuanto se archiva.

## 1. Verificador del estado del módulo al cierre

- [x] 1.1 El botón morado "Guía" sigue presente en `app/resources/views/ia/api-transcriptor/index.blade.php` y dispara `startApiTranscriptorTour()`.
- [x] 1.2 El tour cubre las tres pestañas: `config` (≥10 pasos), `storages` (≥10 pasos), `jobs` (≥5 pasos). Verificable contando `TcloudTour.start({` en el mismo archivo.
- [x] 1.3 No hay selectores del tour huérfanos (selector que no apunta a ningún nodo del DOM renderizado).
- [x] 1.4 No hay diff de código de aplicación fuera de `openspec/`.

## 2. Verificador de archivado

- [x] 2.1 `openspec list --json` muestra el change como archivable (sin tareas pendientes propias del change).
- [x] 2.2 `openspec validate 2026-08-20-api-transcriptor-modular-boundary --strict` queda en verde.

## Nota sobre verificación futura

La inspección de PRs futuros contra la regla documentada en `design.md` § Verificador manual NO es una tarea de este change: se aplica por el revisor de cada PR cross-module cuando aparezca. El grep canónico vive en `design.md` para no duplicarlo aquí.
