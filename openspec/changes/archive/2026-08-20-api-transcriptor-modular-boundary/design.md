# Design

## Por qué un requisito en spec y no solo memoria Kilo

La regla de "no tocar api-transcriptor desde otros módulos" se podría capturar de tres formas:

1. **Memoria Kilo** (`project.md` / `corrections.md`): se consulta en cada sesión y se respeta por consistencia. Pero un revisor de PR o un dev nuevo no la lee de forma sistemática; solo aparece cuando alguien la busca.
2. **Comentario inline** en `app/resources/views/ia/api-transcriptor/index.blade.php` y en el controlador: vive pegado al código, pero se pierde cuando el archivo se mueve o se copia.
3. **Requisito en `openspec/specs/transcription-api-orchestrator/spec.md`**: queda donde están las demás reglas del módulo, lo lee el validador de OpenSpec cuando se propone un change que toca ese spec, y aparece en cualquier diff de una propuesta cross-module que sin querer edite el archivo.

Esta propuesta usa (3). La memoria Kilo recibe una entrada complementaria para que el modelo la respete automáticamente cuando arranca una sesión, pero la fuente de verdad es el spec.

## Anatomía del módulo cerrado

```
/ia/api-transcriptor (módulo cerrado para ESCRITURA cross-module)
════════════════════════════════════════════════════════════════

  app/resources/views/ia/api-transcriptor/
      index.blade.php          ← vista principal (tour, tabla, config)
      job-detail.blade.php     ← detalle de un job

  app/app/Http/Controllers/Ia/
      ApiTranscriptorController.php       ← toggleStorage autoritativo
      TranscriptorSettingsController.php  ← CRUD overrides

  app/app/Services/Ia/
      TranscriptorSettings.php            ← SCHEMA + capa de settings
      TranscriptionCoherencePass.php      ← constantes de coherencia
      TranscriptorApiClient.php           ← POST/GET a la API externa
      TranscriptionProcessor.php          ← ffmpeg + dispatch
      TranscriptionPollingService.php     ← recogida por GET /v1/jobs
      DiskScannerService.php              ← scan-stale
      AudioConverter.php                  ← ffmpeg → Opus

  app/app/Console/Commands/
      TranscriptionTickCommand.php
      TranscriptionTuneCommand.php
      TranscriptionHealthCheckCommand.php
      TranscriptionCheckShmHealthCommand.php
      TranscriptionConfigCommand.php
      TranscriptionTuneCommand.php
      PollResultsCommand.php

  app/routes/web.php (rutas con prefijo /ia/api-transcriptor/**)

  app/config/transcriptor.php
  app/database/migrations/*_transcription*.php
```

Cualquier path dentro de este árbol, tocado desde un diff cuyo nombre de change **no** empieza por `YYYY-MM-DD-*-api-transcriptor-*`, viola la regla. El verificador (sección siguiente) la aplica a mano en revisión de PR.

## Qué cuenta como edición cross-module (prohibida)

- Modificar `app/resources/views/ia/api-transcriptor/**` desde un change que no es de api-transcriptor.
- Modificar `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` o `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` desde fuera.
- Modificar cualquier servicio de `app/app/Services/Ia/*` que el módulo usa como interno (`TranscriptorSettings`, `TranscriptionCoherencePass`, `TranscriptorApiClient`, `TranscriptionProcessor`, `TranscriptionPollingService`, `DiskScannerService`, `AudioConverter`).
- Modificar las rutas de `/ia/api-transcriptor/**` en `app/routes/web.php`.
- Modificar `app/config/transcriptor.php`.
- Añadir o modificar una migración cuyo nombre contenga `transcription` y que afecte tablas del módulo (`transcriptions`, `transcription_segments`, `transcription_reviews`, `transcription_settings`, …).
- Añadir una llamada nueva desde un servicio de otro módulo a un método de `ApiTranscriptorController` que no sea GET. (Los GET de consumo se permiten; ver abajo.)

## Qué cuenta como consumo permitido

- Leer modelos `Transcription`, `TranscriptionSegment`, `TranscriptionReview` desde otro módulo (Eloquent).
- Renderizar un enlace `<a href="/ia/api-transcriptor/jobs/{id}">` desde otra vista.
- El endpoint público `POST /ia/api-transcriptor/storages/{id}/toggle` solo lo invoca el botón del propio módulo; si otro módulo necesita el mismo efecto, **no debe llamar a la ruta directamente**: debe pedir al admin que vaya a api-transcriptor o debe proponer su propio change con prefijo api-transcriptor.
- `POST /ia/api-transcriptor/jobs/{id}/retry` y similares siguen la misma lógica.

## Qué cuenta como fix interno permitido

Un change bien formado con prefijo `2026-MM-DD-*-api-transcriptor-*` puede:

- Corregir un bug operativo dentro del módulo.
- Aplicar un parche de seguridad.
- Refactorizar una pieza interna sin cambiar comportamiento observable.
- Añadir instrumentación (logs, métricas) sin cambiar el contrato.

Cada uno de esos cambios sigue el flujo normal: `proposal.md` + `tasks.md` + `specs/...` (si toca requisitos) + archive al cerrar.

## Verificador manual (en PR)

Cualquier propuesta futura que no sea de api-transcriptor y cuyo diff toque uno o más archivos listados arriba se rechaza con la siguiente plantilla:

> El diff de este change toca archivos del módulo cerrado `/ia/api-transcriptor`. La regla vive en `openspec/changes/2026-08-20-api-transcriptor-modular-boundary/specs/transcription-api-orchestrator/spec.md`. Opciones:
>
> 1. Si la edición es un fix de bug o parche de seguridad del módulo: abrir un nuevo change con prefijo `YYYY-MM-DD-*-api-transcriptor-*` y enlazarlo aquí.
> 2. Si la edición solo es consumo (lectura, redirección a URL): confirmar que ninguno de los archivos modificados cae en la lista de paths cerrados.
> 3. Si la edición cruza la frontera por fuerza: reabrir el debate en un change `2026-MM-DD-api-transcriptor-modular-boundary-revisit`.

## Alternativas descartadas

- **Script CI dedicado** que falle el build cuando un diff cross-module toque el módulo. Descartado por coste de mantenimiento: cualquier excepción (un fix urgente que no puede esperar al prefijo) requiere bypass; el bypass anula la protección. La revisión humana con PR es más honesta sobre sus excepciones.
- **Congelar el módulo totalmente** (ni bugs). Descartado por rigidez: una vulnerabilidad crítica en `TranscriptorApiClient` no puede esperar al debate sobre si cuenta como freeze.
- **Mover la regla a `corrections.md` o `project.md`**. Descartado: la memoria Kilo es contexto, no fuente de verdad verificable. Un PR que rompe la regla no la descubre el validador de OpenSpec hasta que alguien edita el spec, que es justo lo que la regla prohíbe.
