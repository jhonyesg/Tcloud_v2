# Change: Hardening del módulo de correcciones para producción

## Why

El módulo `transcription-corrections` está en producción desde el 2026-07-06 (`f57e4a0`) pero la tabla `corrections` está vacía: cero reglas, cero segmentos con `text != text_raw`. La causa no es la ausencia de uso, sino cinco bloqueadores que deben resolverse antes de poblar el diccionario con realizaciones reales:

1. `CorrectionService::applyToSegments()` modifica una copia del array por valor; `TranscriptionProcessor` no recibe la mutación, por lo que las correcciones approved nunca llegan al `text` de los segmentos nuevos.
2. `app/resources/views/ia/correcciones/index.blade.php:213` tiene un selector CSRF sin cerrar; el `<script>` de Alpine no parsea y todas las acciones del admin (aprobar, rechazar, nueva, re-aplicar) están inutilizadas.
3. `applyRetroactively()` recorre los 5.3M segmentos en una sola petición HTTP sin progreso persistente; no puede ejecutarse en producción.
4. `applies_count` no se incrementa al parsear SRT nuevo y se sobrecuenta entre chunks retroactivos.
5. Cero tests para `CorrectionService`, `Correction`, ni `ApplyCorrectionsCommand`.

## What Changes

- **Backend bugfix**: `CorrectionService::applyToSegments()` retorna el array mutado; `TranscriptionProcessor` consume el resultado.
- **Backend bugfix**: `applies_count` se calcula como delta dentro del chunk, no como acumulador entre chunks.
- **JS bugfix**: cerrar el selector CSRF en `ia/correcciones/index.blade.php`.
- **Retroactivo async**: `apply-retroactive` se transforma en `POST .../apply-retroactive` (lanzar runId) + `GET .../apply-retroactive/{runId}` (polling). Mismo patrón que `processBatch()` ya usa.
- **Comando nuevo**: `php artisan corrections:apply-run --run-id=<id> [--dry-run] [--chunk=500]` desacoplado de la petición HTTP.
- **Seed real**: insertar 5–6 correcciones detectadas en el corpus de hoy (`Active to → Activa tu`, etc.).
- **Tests**: `CorrectionServiceTest`, `CorrectionApplyToTextTest`, `ApplyCorrectionsCommandTest`.

## Non-goals

- Detector automático de candidatos (`correction_candidates`). Cambio separado.
- Capa de overrides puntuales (`segment_overrides`). Cambio separado.
- Cambios al módulo de alerts / Mis Avisos / email. Tiene su propia cadena rota.
- Cambios de UI mayores: el admin de correcciones sigue siendo lista de pendientes + aprobadas.
- Cambios al visor de transcripciones (`/ia/api-transcriptor/jobs/{id}`). Solo se modifica el cockpit de correcciones.

## Impact

- **Specs affected**: `transcription-corrections` (modificado).
- **Code affected**:
  - `app/app/Services/Ia/CorrectionService.php`
  - `app/app/Services/Ia/TranscriptionProcessor.php`
  - `app/app/Http/Controllers/Ia/CorreccionesController.php`
  - `app/app/Console/Commands/ApplyCorrectionsCommand.php` (refactor)
  - `app/app/Console/Commands/CorrectionsApplyRunCommand.php` (nuevo)
  - `app/resources/views/ia/correcciones/index.blade.php`
  - `app/routes/web.php`
  - `app/database/seeders/CorreccionesDictionarySeeder.php` (nuevo)
  - `app/tests/Unit/CorrectionApplyToTextTest.php` (nuevo)
  - `app/tests/Unit/CorrectionServiceTest.php` (nuevo)
  - `app/tests/Feature/ApplyCorrectionsCommandTest.php` (nuevo)
- **Migrations**: ninguna. Esquema actual compatible.
- **Compatibilidad**: la respuesta de `POST /ia/correcciones/apply-retroactive` cambia de `{updated, elapsed}` a `{runId}`. Cliente debe actualizarse a polling.
- **OpenSpec**: `openspec/changes/2026-07-24-corrections-production-readiness/specs/transcription-corrections/spec.md` actualizado.
