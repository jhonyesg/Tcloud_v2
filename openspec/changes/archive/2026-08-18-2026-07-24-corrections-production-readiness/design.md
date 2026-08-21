# Design: Hardening del módulo de correcciones

## Contexto actual

El módulo está implementado en `app/app/Models/Correction.php`, `app/app/Services/Ia/CorrectionService.php`, `app/app/Http/Controllers/Ia/CorreccionesController.php`, `app/app/Console/Commands/ApplyCorrectionsCommand.php` y `app/resources/views/ia/correcciones/index.blade.php`. Está conectado a `TranscriptionProcessor::processDoneWithSrt()` que se ejecuta al finalizar una transcripción. La tabla `corrections` está vacía en producción.

## D1: Mutación del array de segmentos

`CorrectionService::applyToSegments()` actualmente recibe un array, modifica una copia local y retorna `void`. `TranscriptionProcessor` no ve la mutación.

```php
// app/app/Services/Ia/CorrectionService.php
public function applyToSegments(array $segments): array
{
    $corrections = Correction::approved()
        ->orderByRaw('LENGTH(wrong_normalized) DESC')
        ->get(['wrong_normalized', 'correct_text']);
    if ($corrections->isEmpty()) {
        return $segments;
    }
    foreach ($segments as $i => $segment) {
        $raw = $segment['text_raw'] ?? $segment['text'] ?? '';
        $segments[$i]['text'] = $this->applyText($raw, $corrections);
    }
    return $segments;
}

private function applyText(string $text, Collection $corrections): string
{
    foreach ($corrections as $correction) {
        if ($correction->wrong_normalized === '') continue;
        $text = str_ireplace($correction->wrong_normalized, $correction->correct_text, $text);
    }
    return $text;
}
```

`TranscriptionProcessor::processDoneWithSrt()` consume el return:

```php
// app/app/Services/Ia/TranscriptionProcessor.php
$corrected = $this->corrections->applyToSegments($segmentsForCorrections);
foreach ($corrected as $seg) {
    $rows[] = [
        'transcription_id' => $transcription->id,
        'segment_index'    => $seg['index'],
        'start_seconds'    => $seg['start_seconds'],
        'end_seconds'      => $seg['end_seconds'],
        'text_raw'         => $seg['text_raw'] ?? $seg['text'],
        'text'             => $seg['text'],
        'created_at'       => $now,
        'updated_at'       => $now,
    ];
}
```

`Correction::applyToText(string $text): string` se mantiene como helper estático (usado por el seeder y por el endpoint de admin en previsualización).

## D2: CSRF selector syntax

```js
// app/resources/views/ia/correcciones/index.blade.php:213
// Antes
'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]).content,
// Después
'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
```

Falta `]` antes del `)` y la comilla de cierre del literal del selector. Es el único error sintáctico.

## D3: Retroactivo asíncrono

`applyRetroactively()` no debe correr en una petición HTTP. Los 5.3M segmentos tardarían minutos, PHP-FPM puede expirar y la UI queda colgada.

Patrón reutilizado de `ApiTranscriptorController::processBatch()` (líneas 780-850):

1. `POST /ia/correcciones/apply-retroactive` recibe `{dry_run: bool, chunk: int}`.
2. Controller genera `runId = 'correction_apply_' . time() . '_' . md5(mt_rand())`.
3. Inicializa `Cache::put("corrections_apply:{$runId}", ['status'=>'queued', 'progress'=>0, 'total'=>0, 'updated'=>0, 'started_at'=>now()], now()->addHours(4))`.
4. Lanza `execBackground("php artisan corrections:apply-run --run-id={$runId} --chunk={$chunk}" . ($dry ? ' --dry-run' : ''))`.
5. Endpoint polling `GET /ia/correcciones/apply-retroactive/{runId}` retorna el estado cache.
6. Frontend (Alpine) llama `setInterval(pollRun, 2000)` hasta `status in [done, error]`.

`execBackground()` se extrae a un trait `RunsBackgroundCommands` en `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php` para reutilizarlo entre `CorreccionesController` y `ApiTranscriptorController`.

`ApplyCorrectionsCommand` (firma actual `transcription:apply-corrections`) se reemplaza por `CorrectionsApplyRunCommand` (firma `corrections:apply-run`). El alias `transcription:apply-corrections` se conserva transitoriamente para no romper el flujo que el usuario podría estar usando.

`previewRetroactive()` se elimina del controller. La cola de admin ya no ofrece preview sintético; debe iniciar el run async y mostrar el conteo en vivo.

## D4: Conteos por delta dentro de chunk

`applies_count` se sobrecuenta porque `$appliedByCorrection` se acumula entre chunks y al final del chunk se ejecuta `DB::increment` con el acumulador. Si la corrección aparece en 3 chunks consecutivos, se incrementa 3 veces (incorrecto: la métrica es "cuántos segmentos afecta la corrección", no "cuántas veces la vio el worker").

```php
// app/app/Services/Ia/CorrectionService.php::applyRetroactively()
$appliedByCorrection = [];
$rows = [];

foreach ($chunk as $segment) {
    $raw = (string) $segment->text_raw;
    $corrected = $raw;
    $delta = [];
    foreach ($corrections as $c) {
        if ($c->wrong_normalized === '') continue;
        $new = str_ireplace($c->wrong_normalized, $c->correct_text, $corrected);
        if ($new !== $corrected) {
            $delta[$c->id] = ($delta[$c->id] ?? 0) + 1;
            $corrected = $new;
        }
    }
    if ($corrected !== $raw) {
        $rows[$segment->id] = $corrected;
        foreach ($delta as $cid => $n) {
            $appliedByCorrection[$cid] = ($appliedByCorrection[$cid] ?? 0) + $n;
        }
        $updated++;
    }
}

// Al final del chunk (commit por chunk):
if (!$dryRun && (!empty($rows) || !empty($appliedByCorrection))) {
    DB::transaction(function () use ($rows, $appliedByCorrection) {
        foreach ($rows as $id => $text) {
            DB::table('transcription_segments')->where('id', $id)->update(['text' => $text, 'updated_at' => now()]);
        }
        foreach ($appliedByCorrection as $cid => $n) {
            DB::table('corrections')->where('id', $cid)->increment('applies_count', $n);
        }
    });
}

$appliedByCorrection = []; // reset después del commit
```

Idempotencia: si la regla ya está aplicada, `str_ireplace` no produce cambio, `$new === $corrected`, no se cuenta. La métrica refleja cuántas veces una corrección ACABÓ produciendo un cambio.

## D5: Seed de correcciones reales

```php
// app/database/seeders/CorreccionesDictionarySeeder.php
class CorreccionesDictionarySeeder extends Seeder
{
    public function run(): void
    {
        $seeds = [
            ['wrong' => 'Active to',         'correct' => 'Activa tu',          'note' => 'Cuña radial Bogotá Modo Metro'],
            ['wrong' => 'active to',         'correct' => 'Activa tu',          'note' => 'Case-insensitive variant'],
            ['wrong' => 'valor the time',    'correct' => 'valorar el tiempo',  'note' => 'Spanglish en cuña radial'],
            ['wrong' => 'orgular',           'correct' => 'orgullo',            'note' => 'ASR misrecognition'],
            ['wrong' => 'with orgasm',       'correct' => 'with orgullo',       'note' => 'ASR misrecognition'],
            ['wrong' => 'applicate vacunes', 'correct' => 'aplicarse vacunas',  'note' => 'Spanglish en pauta de salud'],
        ];
        $admin = User::where('role', 'admin')->first();
        foreach ($seeds as $s) {
            app(CorrectionService::class)->upsertApproved($s['wrong'], $s['correct'], $admin);
        }
    }
}
```

El seeder es manual (`php artisan db:seed --class=CorreccionesDictionarySeeder`), no automático. Idempotente gracias a `upsertApproved()`.

## D6: Comportamiento del comando async

```text
POST /ia/correcciones/apply-retroactive {dry_run: false, chunk: 500}
  → generates runId
  → Cache::put("corrections_apply:{runId}", {
      status: 'queued',
      scope: {total: 5_307_060, completed: 0, updated: 0},
      published_at: null,
      finished_at: null,
      error_message: null,
    }, now()->addHours(4))
  → execBackground("php artisan corrections:apply-run --run-id={runId} --chunk=500")
  → retorna {runId}

GET /ia/correcciones/apply-retroactive/{runId}
  → retorna Cache::get("corrections_apply:{runId}")
```

`CorrectionsApplyRunCommand::handle()`:

```php
$state = Cache::get("corrections_apply:{$runId}");
if (!$state) { abort; }

$state['status'] = 'running';
$state['started_at'] = now();
Cache::put("corrections_apply:{$runId}", $state, now()->addHours(4));

$total = TranscriptionSegment::count();
$state['total'] = $total;
Cache::put("corrections_apply:{$runId}", $state, now()->addHours(4));

try {
    $this->service->applyRetroactively(function($lastId) use (&$state, $runId) {
        $state['progress'] = $lastId;
        $state['updated'] = $state['updated'] ?? 0;
        Cache::put("corrections_apply:{$runId}", $state, now()->addHours(4));
    }, $chunk, $dryRun);
    $state['status'] = 'done';
} catch (\Throwable $e) {
    $state['status'] = 'error';
    $state['error_message'] = $e->getMessage();
}
$state['finished_at'] = now();
Cache::put("corrections_apply:{$runId}", $state, now()->addHours(4));
```

## D7: Tests

- `tests/Unit/CorrectionApplyToTextTest.php`:
  - `test_applies_single_correction()` — una regla, una ocurrencia.
  - `test_multiple_corrections_ordered_by_length_desc()` — reglas largas antes que cortas.
  - `test_no_corrections_means_no_change()` — sin reglas, `text` queda igual.
  - `test_case_insensitive()` — `presedente` y `PRESEDENTE` matchean.
  - `test_empty_wrong_normalized_skipped()` — fila con `wrong_normalized=''` no rompe.

- `tests/Unit/CorrectionServiceTest.php`:
  - `test_apply_to_segments_returns_mutated_array()` — verifica D1.
  - `test_propose_creates_pending()` — wrong_normalized correcto.
  - `test_propose_with_existing_approved_returns_merged()`.
  - `test_propose_with_existing_pending_updates()`.
  - `test_approve_promotes_pending()`.
  - `test_approve_with_existing_approved_marks_merged()`.
  - `test_upsert_approved_creates_new()`.
  - `test_upsert_approved_updates_existing()` — no duplica.

- `tests/Feature/ApplyCorrectionsCommandTest.php`:
  - `test_dry_run_does_not_modify()`.
  - `test_real_apply_updates_text()`.
  - `test_applies_count_increments_correctly()` — solo cuenta cambios reales.
  - `test_idempotent_no_double_increment()` — segunda corrida no sobrecuenta.

Tests usan `RefreshDatabase` con transacciones para no tocar PG. Las pruebas con `corrections` usan filas mínimas; las de `applyRetroactively` usan SQLite o crean 5–10 segmentos de fixture.

## D8: Compatibilidad hacia atrás

- `POST /ia/correcciones/apply-retroactive` antes retornaba `{updated, elapsed, dry_run}`; ahora retorna `{runId}`. Cliente Alpine actualizado.
- `GET /ia/correcciones/preview-retroactive` retorna 404. Se elimina.
- `php artisan transcription:apply-corrections` se mantiene como alias de `corrections:apply-run` durante una release.
- `cache:clear` puede ser necesario tras el deploy para invalidar payloads viejos.

## D9: Riesgos y rollback

- Si la mutación del array falla, los SRT nuevos quedan sin corregir. Mitigación: test unitario confirma mutación.
- Si el retroactivo async se interrumpe, el progreso se pierde al expirar el TTL de Cache (4h). No hay soft-delete. Mitigación: el reintento desde el principio es seguro por idempotencia.
- El seed introduce 6 reglas aprobadas de una vez. Si una es incorrecta, el admin debe borrarla desde el cockpit (`DELETE /ia/correcciones/{id}`).

## Archivos

### Modificados
- `app/app/Services/Ia/CorrectionService.php`
- `app/app/Services/Ia/TranscriptionProcessor.php`
- `app/app/Http/Controllers/Ia/CorreccionesController.php`
- `app/app/Console/Commands/ApplyCorrectionsCommand.php`
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (extracción de `execBackground`)
- `app/resources/views/ia/correcciones/index.blade.php`
- `app/routes/web.php`
- `openspec/changes/2026-07-24-corrections-production-readiness/specs/transcription-corrections/spec.md`

### Nuevos
- `app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php`
- `app/app/Console/Commands/CorrectionsApplyRunCommand.php`
- `app/database/seeders/CorreccionesDictionarySeeder.php`
- `app/tests/Unit/CorrectionApplyToTextTest.php`
- `app/tests/Unit/CorrectionServiceTest.php`
- `app/tests/Feature/ApplyCorrectionsCommandTest.php`
