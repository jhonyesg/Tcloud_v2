# Tasks: Hardening del módulo de correcciones

## 1. Backend bugfix: mutación del array

- [x] Actualizar `app/app/Services/Ia/CorrectionService.php::applyToSegments()`:
  - Cambiar firma `public function applyToSegments(array $segments): void` → `public function applyToSegments(array $segments): array`.
  - Iterar `$segments as $i => $segment` y mutar `$segments[$i]['text']` directamente.
  - Retornar `$segments` al final.
  - Extraer `applyText(string $text, Collection $corrections): string` como helper privado.
- [x] En `app/app/Services/Ia/TranscriptionProcessor.php:55-56`, reemplazar el uso actual:
  - Antes: `$corrected = $raw; ... $this->corrections->applyToSegments($segmentsForCorrections);` (sin usar return).
  - Después: `$segmentsForCorrections = $this->corrections->applyToSegments($segmentsForCorrections);`.
- [x] Ejecutar `php -l app/app/Services/Ia/CorrectionService.php` y `php -l app/app/Services/Ia/TranscriptionProcessor.php` para validar sintaxis.

## 2. Backend bugfix: conteos por delta

- [x] En `app/app/Services/Ia/CorrectionService.php::applyRetroactively()`:
  - Mantener `$appliedByCorrection` dentro del chunk como deltas internos.
  - Mover el `DB::increment` al final del chunk, con el delta del chunk.
  - Resetear `$appliedByCorrection` después del commit por chunk.
  - Confirmar idempotencia: si el segmento ya tenía `text` corregido, `str_ireplace` no produce cambio de texto, no se cuenta.
- [x] Actualizar el docblock de `applyRetroactively()` para documentar el cálculo.

## 3. Frontend bugfix: CSRF selector

- [x] En `app/resources/views/ia/correcciones/index.blade.php:213`, reemplazar:
  - Antes: `'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]).content,`
  - Después: `'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,`
- [x] Verificar visualmente en consola del navegador que no haya errores de sintaxis en el script.
- [x] Probar manualmente las acciones "Aprobar", "Rechazar", "Nueva", "Eliminar", "Re-aplicar" en el admin de correcciones.

## 4. Retroactivo asíncrono

### 4.1. Extracción de `execBackground`

- [x] Crear `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php` con la lógica de `execBackground()` extraída de `ApiTranscriptorController.php:855+`.
- [x] Aplicar el trait en `ApiTranscriptorController` y `CorreccionesController`.
- [x] Verificar que `processBatch()` y `batchStatus()` siguen funcionando.

### 4.2. Comando async

- [x] Crear `app/app/Console/Commands/CorrectionsApplyRunCommand.php`:
  - Firma: `corrections:apply-run {--run-id=required} {--dry-run : Solo reporta, no escribe} {--chunk=500}`
  - Lee cache key `corrections_apply:{runId}`; aborta si no existe.
  - Marca `status=running`.
  - Cuenta `TranscriptionSegment::count()` y lo persiste en cache.
  - Llama `CorrectionService::applyRetroactively($callback, $chunk, $dryRun)`.
  - El callback persiste `progress` y `updated` en cache.
  - Al final, `status=done` o `status=error`.
  - `php -l` valida sintaxis.
- [x] Alias deprecado: `app/app/Console/Commands/ApplyCorrectionsCommand.php` queda como fachada que delega a `CorrectionsApplyRunCommand` con `--run-id=cli`. Marcar `@deprecated` con migración próxima.

### 4.3. Endpoints controller

- [x] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Refactorizar `applyRetroactive(Request $request)`:
    - Recibe `{dry_run: bool, chunk: int}`.
    - Genera `runId = 'correction_apply_' . time() . '_' . md5(mt_rand())`.
    - Cache::put inicial con `status='queued'`, TTL 4h.
    - Lanza `execBackground("php artisan corrections:apply-run --run-id={runId} --chunk={chunk}" . ($dry ? ' --dry-run' : ''))`.
    - Retorna `{runId}`.
  - Añadir `runStatus($runId)`:
    - Lee `Cache::get("corrections_apply:{$runId}")`.
    - Si no existe, 404.
    - Retorna el estado.
  - Eliminar `previewRetroactive()`.
- [x] Registrar rutas en `app/routes/web.php` dentro del bloque admin IA:
  ```php
  Route::post('/correcciones/apply-retroactive', [...]);
  Route::get('/correcciones/apply-retroactive/{runId}', [...]);
  ```
  Borrar la ruta `GET /correcciones/preview-retroactive`.

### 4.4. Frontend Alpine

- [x] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Reemplazar `openApply()` y `runApply()` para que operen con `runId` + polling.
  - Crear `pollRun(runId)` que llama `GET /ia/correcciones/apply-retroactive/{runId}` cada 2s.
  - Mostrar barra de progreso con `progress` / `total` y `updated` segmentos.
  - Detener polling cuando `status in [done, error]`.
  - Mantener el `setTimeout(window.location.reload, 2500)` solo en `status='done'`.
- [x] Verificar que el modal Re-aplicar muestra mensaje claro cuando el run está corriendo.

## 5. Tests

### 5.1. `tests/Unit/CorrectionApplyToTextTest.php`

- [x] `test_applies_single_correction()` — crea una corrección approved y verifica que `applyToText()` la aplica.
- [x] `test_multiple_corrections_ordered_by_length_desc()` — dos reglas; la larga se aplica primero.
- [x] `test_no_corrections_means_no_change()` — sin reglas, texto idéntico.
- [x] `test_case_insensitive()` — mayúsculas y minúsculas matchean.
- [x] `test_empty_wrong_normalized_skipped()` — fila con `wrong_normalized=''` no rompe el loop.

### 5.2. `tests/Unit/CorrectionServiceTest.php`

- [x] `test_apply_to_segments_returns_mutated_array()` — verifica D1.
- [x] `test_propose_creates_pending()`.
- [x] `test_propose_with_existing_approved_returns_merged()`.
- [x] `test_propose_with_existing_pending_updates()`.
- [x] `test_approve_promotes_pending()`.
- [x] `test_approve_with_existing_approved_marks_merged()`.
- [x] `test_upsert_approved_creates_new()`.
- [x] `test_upsert_approved_updates_existing()`.

### 5.3. `tests/Feature/ApplyCorrectionsCommandTest.php`

- [x] `test_dry_run_does_not_modify()`.
- [x] `test_real_apply_updates_text()`.
- [x] `test_applies_count_increments_only_for_real_changes()`.
- [x] `test_idempotent_no_double_increment_on_second_run()`.

### 5.4. Verificación de tests

- [x] `php artisan test --filter=Correction` debe pasar verde.
- [x] `php artisan test` completo no debe romper otros tests.

## 6. Seed de correcciones

- [x] Crear `app/database/seeders/CorreccionesDictionarySeeder.php`:
  - 6 reglas con `Wrong → Correct` y nota de origen.
  - Llama `app(CorrectionService::class)->upsertApproved()` para cada una.
  - Encuentra al admin via `User::where('role', 'admin')->first()`.
- [x] `php -l` valida sintaxis.
- [x] `php artisan db:seed --class=CorreccionesDictionarySeeder --pretend` muestra la SQL esperada.
- [x] `php artisan db:seed --class=CorreccionesDictionarySeeder` real.
- [x] Verificar con `php artisan tinker --execute='echo App\Models\Correction::where("status","approved")->count();'` que retorna 6.

## 7. Spec delta

- [x] Editar `openspec/changes/2026-07-24-corrections-production-readiness/specs/transcription-corrections/spec.md`:
  - `## MODIFIED Requirements`:
    - `Requirement: Correcciones activas se aplican a segmentos nuevos en el parseo del SRT` — agregar texto sobre `applyToSegments` retornando array.
    - `Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones` — reemplazar por el flujo async con `runId`.
    - `Requirement: Métricas de aplicación por corrección` — agregar idempotencia.
  - `## ADDED Requirements`:
    - `Requirement: Bugfix de mutación del array`
    - `Requirement: Bugfix de CSRF selector en UI admin`
    - `Requirement: Seed inicial con realizaciones reales`
    - `Requirement: Comando async con runId`
  - `## REMOVED Requirements`:
    - `Requirement: Preview sintético de retroactivo`.

## 8. Verificación manual en producción

- [x] Despachar un nuevo SRT de prueba con la frase "Active to Bogotá Modo Metro" en el texto y verificar que `transcription_segments.text` lo trae como "Activa tu Bogotá Modo Metro".
- [x] Correr `php artisan corrections:apply-run --run-id=test_<timestamp> --dry-run` y verificar que la cache key existe con `status='done'` y `updated>0`.
- [x] Correr sin `--dry-run` y verificar que `applies_count` de las 6 reglas se incrementa.
- [x] Limpiar la cache key de prueba al finalizar.

## 9. Artefactos OpenSpec

- [x] `openspec/changes/2026-07-24-corrections-production-readiness/proposal.md` ✓ (este archivo)
- [x] `openspec/changes/2026-07-24-corrections-production-readiness/design.md` ✓
- [x] `openspec/changes/2026-07-24-corrections-production-readiness/tasks.md` ✓
- [x] `openspec/changes/2026-07-24-corrections-production-readiness/specs/transcription-corrections/spec.md` ✓
- [x] `openspec/changes/2026-07-24-corrections-production-readiness/.openspec.yaml` ✓

## 10. Resumen de archivos

### Modificados
- `app/app/Services/Ia/CorrectionService.php`
- `app/app/Services/Ia/TranscriptionProcessor.php`
- `app/app/Http/Controllers/Ia/CorreccionesController.php`
- `app/app/Console/Commands/ApplyCorrectionsCommand.php` (compatibilidad)
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (extracción `execBackground`)
- `app/resources/views/ia/correcciones/index.blade.php`
- `app/routes/web.php`

### Nuevos
- `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php`
- `app/app/Console/Commands/CorrectionsApplyRunCommand.php`
- `app/app/Database/Seeders/CorreccionesDictionarySeeder.php` (ubicado en `app/app/Database/Seeders/` por PSR-4 `App\\: app/app/`)
- `app/tests/Unit/CorrectionApplyToTextTest.php`
- `app/tests/Unit/CorrectionServiceTest.php`
- `app/tests/Feature/ApplyCorrectionsCommandTest.php`
- `openspec/changes/2026-07-24-corrections-production-readiness/specs/transcription-corrections/spec.md`
