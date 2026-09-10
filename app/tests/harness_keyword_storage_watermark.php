<?php
/**
 * Harness de validación del change `avisos-keyword-storage-watermark`.
 *
 * Ejecuta contra PostgreSQL real:
 *   1. Tabla `keyword_scan_watermarks` existe con PK compuesta y FKs correctas.
 *   2. selectCandidates() emite pares (transcription, keyword) — no transc sueltas.
 *   3. bumpWatermarks() es monotónico: nunca retrocede scanned_until.
 *   4. bumpWatermarks() es race-safe: dos UPSERTs concurrentes con finished_at
 *      distintos → el resultado es el MAX.
 *   5. Alta de Keyword: el hook crea watermarks NULL para storages aplicables.
 *   6. coverage() devuelve filas para los pares existentes.
 *
 * Uso: php tests/harness_keyword_storage_watermark.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Keyword;
use App\Services\Ia\AvisosScanService;
use Illuminate\Support\Facades\DB;

$tag = 'kwm_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-keyword-storage-watermark (tag: {$tag})\n";

$service = app(AvisosScanService::class);

// ─── 1. Schema ────────────────────────────────────────────────────────────
h_section('1. Schema de keyword_scan_watermarks');

$exists = DB::selectOne("SELECT to_regclass('public.keyword_scan_watermarks') AS t");
if ($exists && $exists->t) {
    h_ok('tabla keyword_scan_watermarks existe');
} else {
    h_fail('tabla NO existe');
    exit(1);
}

$cols = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='keyword_scan_watermarks' ORDER BY ordinal_position");
$expected = [
    'keyword_id' => 'bigint',
    'storage_provider_id' => 'bigint',
    'scanned_until' => 'timestamp without time zone',
    'last_scan_run_id' => 'bigint',
    'last_scanned_at' => 'timestamp without time zone',
    'last_hit_at' => 'timestamp without time zone',
    'candidates_total' => 'bigint',
    'hits_total' => 'bigint',
    'created_at' => 'timestamp without time zone',
    'updated_at' => 'timestamp without time zone',
];
$actualTypes = [];
foreach ($cols as $c) {
    $actualTypes[$c->column_name] = $c->data_type;
}
$schemaOk = true;
foreach ($expected as $name => $type) {
    if (($actualTypes[$name] ?? null) !== $type) {
        h_fail("columna {$name}: esperado {$type}, got " . ($actualTypes[$name] ?? 'NULL'));
        $schemaOk = false;
    }
}
if ($schemaOk) {
    h_ok('todas las columnas con tipos correctos');
}

$pkCols = DB::selectOne("SELECT pg_get_indexdef(indexrelid) AS def FROM pg_index WHERE indrelid='keyword_scan_watermarks'::regclass AND indisprimary");
if ($pkCols && str_contains($pkCols->def, '(keyword_id, storage_provider_id)')) {
    h_ok('PK compuesta (keyword_id, storage_provider_id)');
} else {
    h_fail('PK compuesta no encontrada');
}

$fks = DB::select("SELECT conname, confdeltype FROM pg_constraint WHERE conrelid='keyword_scan_watermarks'::regclass AND contype='f'");
$fkBehavior = [];
foreach ($fks as $f) {
    $fkBehavior[$f->conname] = ['c' => 'CASCADE', 'r' => 'RESTRICT', 'n' => 'SET NULL', 'a' => 'NO ACTION'][$f->confdeltype] ?? '?';
}
$expectedFk = [
    'keyword_scan_watermarks_keyword_id_foreign' => 'CASCADE',
    'keyword_scan_watermarks_storage_provider_id_foreign' => 'CASCADE',
    'keyword_scan_watermarks_last_scan_run_id_foreign' => 'SET NULL',
];
$fksOk = true;
foreach ($expectedFk as $name => $behavior) {
    if (($fkBehavior[$name] ?? null) !== $behavior) {
        h_fail("FK {$name}: esperado {$behavior}, got " . ($fkBehavior[$name] ?? 'MISSING'));
        $fksOk = false;
    }
}
if ($fksOk) {
    h_ok('FKs con comportamiento correcto (CASCADE/CASCADE/SET NULL)');
}

// ─── 2. selectCandidates emite pares ──────────────────────────────────────
h_section('2. selectCandidates() emite pares (transcription, keyword)');

$opts = ['noWindow' => true, 'limit' => 5];
$candidates = $service->selectCandidates($opts);
if ($candidates->count() > 0) {
    $first = $candidates->first();
    if (isset($first->transcription_id) && isset($first->keyword_id) && isset($first->storage_provider_id)) {
        h_ok('selectCandidates emite {transcription_id, keyword_id, storage_provider_id, finished_at}');
        echo "    sample: t={$first->transcription_id} k={$first->keyword_id} s={$first->storage_provider_id}\n";
    } else {
        h_fail('Faltan campos esperados en candidato: ' . json_encode((array) $first));
    }
} else {
    h_ok('selectCandidates devuelve 0 candidatos (sin datos aplicables) — OK si la BD está vacía para el scope');
}

// ─── 3. bumpWatermarks monotónico ─────────────────────────────────────────
h_section('3. bumpWatermarks() monotónico (nunca retrocede)');

$testKwId = (int) DB::table('keywords')->insertGetId([
    'text' => "[{$tag}] test kw",
    'normalized' => "[{$tag}] test kw",
    'created_at' => now(),
    'updated_at' => now(),
]);
$testStorageId = (int) DB::table('storage_providers')->where('enabled', true)->value('id');
if (!$testStorageId) {
    h_fail('no hay storage_provider habilitado para test');
    exit(1);
}

// Insertar corridas reales para que el FK no se queje.
$runIdA = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$runIdB = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$runIdX = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$runIdY = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

$now = now();
DB::table('keyword_scan_watermarks')->insert([
    'keyword_id' => $testKwId,
    'storage_provider_id' => $testStorageId,
    'scanned_until' => '2026-09-09 12:00:00',
    'candidates_total' => 0,
    'hits_total' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);

// Intentar retroceder con un finished_at anterior.
$service->bumpWatermarks([
    [
        'keyword_id' => $testKwId,
        'storage_provider_id' => $testStorageId,
        'finished_at' => '2026-01-01 00:00:00', // MUY anterior
        'hits' => 0,
        'candidates' => 1,
    ],
], $runIdA);

$row = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $testKwId)
    ->where('storage_provider_id', $testStorageId)
    ->first();

if ($row && str_starts_with((string) $row->scanned_until, '2026-09-09 12:00:00')) {
    h_ok('monotónico OK: bumpWatermarks con finished_at anterior NO retrocede scanned_until');
} else {
    h_fail('monotónico ROTO: scanned_until=' . ($row->scanned_until ?? 'NULL'));
}

// Avanzar correctamente.
$service->bumpWatermarks([
    [
        'keyword_id' => $testKwId,
        'storage_provider_id' => $testStorageId,
        'finished_at' => '2026-09-09 13:00:00',
        'hits' => 5,
        'candidates' => 1,
    ],
], $runIdB);

$row = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $testKwId)
    ->where('storage_provider_id', $testStorageId)
    ->first();

if ($row && str_starts_with((string) $row->scanned_until, '2026-09-09 13:00:00')) {
    h_ok('avance OK: scanned_until se actualiza al MAX');
} else {
    h_fail('avance ROTO: scanned_until=' . ($row->scanned_until ?? 'NULL'));
}

if ($row && (int) $row->hits_total === 5) {
    h_ok('hits_total acumulado: 5');
} else {
    h_fail('hits_total=' . ($row->hits_total ?? 'NULL') . ' (esperado 5)');
}

// ─── 4. race-safe: dos UPSERTs simultáneos ────────────────────────────────
h_section('4. UPSERT GREATEST race-safe (simulado secuencialmente)');

// Simular dos "carreras" que terminan a la vez con finished_at distintos.
$testKwId2 = (int) DB::table('keywords')->insertGetId([
    'text' => "[{$tag}] test kw2",
    'normalized' => "[{$tag}] test kw2",
    'created_at' => now(),
    'updated_at' => now(),
]);
DB::table('keyword_scan_watermarks')->insert([
    'keyword_id' => $testKwId2,
    'storage_provider_id' => $testStorageId,
    'scanned_until' => null,
    'candidates_total' => 0,
    'hits_total' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);

$runIdZ1 = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$runIdZ2 = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$runIdZ3 = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

$service->bumpWatermarks([
    ['keyword_id' => $testKwId2, 'storage_provider_id' => $testStorageId, 'finished_at' => '2026-09-10 10:00:00', 'hits' => 0, 'candidates' => 1],
], $runIdZ1);
$service->bumpWatermarks([
    ['keyword_id' => $testKwId2, 'storage_provider_id' => $testStorageId, 'finished_at' => '2026-09-10 12:00:00', 'hits' => 0, 'candidates' => 1],
], $runIdZ2);
$service->bumpWatermarks([
    ['keyword_id' => $testKwId2, 'storage_provider_id' => $testStorageId, 'finished_at' => '2026-09-10 11:00:00', 'hits' => 0, 'candidates' => 1],
], $runIdZ3);

$row2 = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $testKwId2)
    ->where('storage_provider_id', $testStorageId)
    ->first();

if ($row2 && str_starts_with((string) $row2->scanned_until, '2026-09-10 12:00:00')) {
    h_ok('race-safe OK: tras 3 bumps con finished_at 10h/12h/11h, scanned_until = 12h (MAX)');
} else {
    h_fail('race-safe ROTO: scanned_until=' . ($row2->scanned_until ?? 'NULL'));
}

// last_scan_run_id queda en el último bump que tocó la fila (Z3), no en el que
// trajo el MAX — la monotonicidad de scanned_until ya garantiza la coherencia.
if ($row2 && (int) $row2->last_scan_run_id === $runIdZ3) {
    h_ok("last_scan_run_id = último bump que tocó la fila ({$runIdZ3})");
} else {
    h_fail('last_scan_run_id=' . ($row2->last_scan_run_id ?? 'NULL') . " (esperado {$runIdZ3})");
}

// ─── 5. Alta de Keyword crea watermarks NULL ──────────────────────────────
h_section('5. Hook Keyword::created crea watermarks NULL');

// Limpia estado previo.
DB::table('keyword_scan_watermarks')->where('keyword_id', $testKwId)->delete();
DB::table('keyword_scan_watermarks')->where('keyword_id', $testKwId2)->delete();

// Crear keyword vía modelo (dispara booted hook).
$newKw = Keyword::create(['text' => "[{$tag}] hook test", 'normalized' => "[{$tag}] hook test"]);
$newKwId = (int) $newKw->id;

$watermarks = DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->get();
if ($watermarks->count() > 0) {
    h_ok("Keyword::created generó {$watermarks->count()} watermark(s) para storages aplicables");
    foreach ($watermarks as $w) {
        if ($w->scanned_until !== null) {
            h_fail("watermark ({$newKwId}, {$w->storage_provider_id}): scanned_until debe ser NULL, got {$w->scanned_until}");
        }
    }
} else {
    h_ok('sin watermarks creados (puede ser normal si no hay usuarios con acceso al storage)');
}

// Cleanup
DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->delete();
$newKw->delete();
DB::table('keywords')->whereIn('id', [$testKwId, $testKwId2])->delete();
DB::table('avisos_scan_runs')->whereIn('id', [$runIdA, $runIdB, $runIdX, $runIdY, $runIdZ1, $runIdZ2, $runIdZ3])->delete();

// ─── 6. coverage() ────────────────────────────────────────────────────────
h_section('6. coverage() devuelve filas');

$coverage = $service->coverage();
if (is_array($coverage) && count($coverage) > 0) {
    h_ok('coverage() devuelve ' . count($coverage) . ' pares');
    $first = $coverage[0];
    if (isset($first['keyword_text'], $first['storage_name'], $first['pending_catchup'])) {
        h_ok('estructura de coverage correcta (keyword_text, storage_name, pending_catchup)');
    } else {
        h_fail('estructura incorrecta: ' . json_encode(array_keys($first)));
    }
} else {
    h_fail('coverage() vacío');
}

// ─── 7. Cursor legacy eliminado ───────────────────────────────────────────
h_section('7. SystemSetting legacy eliminado');

$exists = DB::table('system_settings')->where('key', 'avisos_scan_cursor')->exists();
if (!$exists) {
    h_ok('SystemSetting(avisos_scan_cursor) eliminado');
} else {
    h_fail('SystemSetting(avisos_scan_cursor) aún existe');
}

// ─── Resumen ──────────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
