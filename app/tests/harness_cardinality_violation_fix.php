<?php
/**
 * Harness de validación — change `fix-avisos-watermarks-cardinality-violation`.
 *
 * Cubre:
 *  1. dedupeBumpSet() consolida filas duplicadas por (kid, sid) correctamente.
 *  2. SUM(candidates) y SUM(hits) sobre filas duplicadas.
 *  3. MAX(scanned_until vía finished_at) — gana la fila más reciente.
 *  4. Backward compat: bumpSet sin duplicados → 1:1 (sin overhead).
 *  5. Reproducción del bug original del incidente 2026-09-09:
 *     bumpSet con 8+ filas del mismo par → INSERT con 1 fila → NO cardinality violation.
 *  6. End-to-end real: bumpWatermarks() ejecuta sin error con dataset duplicado.
 *
 * Uso: php tests/harness_cardinality_violation_fix.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\AvisosScanService;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness fix-avisos-watermarks-cardinality-violation\n";

$tag = 'cv_' . substr(bin2hex(random_bytes(4)), 0, 8);

// ─── 1. Test unitario: dedupe básico ──────────────────────────────────
h_section('1. Dedupe básico (8 filas → 1)');

$dedupeMethod = new ReflectionMethod(AvisosScanService::class, 'dedupeBumpSet');
$dedupeMethod->setAccessible(true);

$bumpSet = [];
for ($i = 0; $i < 8; $i++) {
    $bumpSet[] = [
        'keyword_id' => 94,
        'storage_provider_id' => 12,
        'finished_at' => '2026-07-11 ' . str_pad((string) (5 + $i), 2, '0', STR_PAD_LEFT) . ':00:00',
        'candidates' => 1,
        'hits' => $i > 0 ? 1 : 0,
    ];
}

$deduped = $dedupeMethod->invoke(null, $bumpSet);
if (count($deduped) === 1) {
    h_ok("8 filas con (94, 12) → 1 fila");
} else {
    h_fail("esperaba 1 fila, got " . count($deduped));
}

if ((int) $deduped[0]['candidates'] === 8) {
    h_ok("SUM(candidates) = 8");
} else {
    h_fail("candidates=" . $deduped[0]['candidates'] . ", esperaba 8");
}

if ((int) $deduped[0]['hits'] === 7) {
    h_ok("SUM(hits) = 7 (0+1+1+1+1+1+1+1)");
} else {
    h_fail("hits=" . $deduped[0]['hits'] . ", esperaba 7");
}

if (str_starts_with((string) $deduped[0]['finished_at'], '2026-07-11 12:00:00')) {
    h_ok("MAX(finished_at) = 2026-07-11 12:00:00 (la última)");
} else {
    h_fail("finished_at=" . $deduped[0]['finished_at'] . ", esperaba 2026-07-11 12:00:00");
}

// ─── 2. Test: hits variables por duplicado ─────────────────────────────
h_section('2. SUM(hits) con valores variables');

$bumpSet = [];
foreach ([2, 0, 5] as $h) {
    $bumpSet[] = ['keyword_id' => 100, 'storage_provider_id' => 5, 'finished_at' => '2026-07-11 01:00:00', 'candidates' => 1, 'hits' => $h];
}
$deduped = $dedupeMethod->invoke(null, $bumpSet);
if (count($deduped) === 1 && (int) $deduped[0]['hits'] === 7) {
    h_ok("hits [2,0,5] sumados = 7");
} else {
    h_fail("esperaba 1 fila con hits=7, got count=" . count($deduped));
}

// ─── 3. Backward compat: sin duplicados → 1:1 ──────────────────────────
h_section('3. Backward compat (sin duplicados)');

$bumpSet = [];
for ($i = 0; $i < 50; $i++) {
    $bumpSet[] = [
        'keyword_id' => $i + 1,
        'storage_provider_id' => 1,
        'finished_at' => '2026-07-11 00:00:00',
        'candidates' => 1,
        'hits' => 0,
    ];
}
$deduped = $dedupeMethod->invoke(null, $bumpSet);
if (count($deduped) === 50) {
    h_ok("50 pares únicos → 50 filas (sin overhead)");
} else {
    h_fail("esperaba 50, got " . count($deduped));
}

// ─── 4. Reproducción del incidente 2026-09-09 ──────────────────────────
h_section('4. Reproducción del incidente 2026-09-09');

$bugInput = [
    ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'candidates' => 1, 'hits' => 0],
    ['keyword_id' => 95, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'candidates' => 1, 'hits' => 0],
    ['keyword_id' => 117, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'candidates' => 1, 'hits' => 0],
    ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:05:11', 'candidates' => 1, 'hits' => 1],  // dup
    ['keyword_id' => 95, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:05:11', 'candidates' => 1, 'hits' => 2],  // dup
    ['keyword_id' => 117, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:05:11', 'candidates' => 1, 'hits' => 0],  // dup
    ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 07:00:00', 'candidates' => 1, 'hits' => 0],  // dup
    ['keyword_id' => 95, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 07:00:00', 'candidates' => 1, 'hits' => 1],  // dup
    ['keyword_id' => 117, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 07:00:00', 'candidates' => 1, 'hits' => 0],  // dup
];
$deduped = $dedupeMethod->invoke(null, $bugInput);
if (count($deduped) === 3) {
    h_ok("9 filas (3 pares × 3 transc) → 3 filas únicas");
} else {
    h_fail("esperaba 3 filas, got " . count($deduped));
}

// ─── 5. End-to-end real con bumpWatermarks ─────────────────────────────
h_section('5. End-to-end con bumpWatermarks()');

// Setup: crear keyword + storage + run reales para evitar FK violations.
$kw = \App\Models\Keyword::create(['text' => "[{$tag}] cv test", 'normalized' => "[{$tag}] cv"]);
$kwId = (int) $kw->id;
$storageId = (int) DB::table('storage_providers')->where('enabled', true)->value('id');

$runId = (int) DB::table('avisos_scan_runs')->insertGetId([
    'origin' => 'manual', 'status' => 'success', 'params' => '{}',
    'transcriptions_scanned' => 0, 'hits_new' => 0, 'failed_count' => 0, 'duration_ms' => 0,
    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

// Limpiar watermarks previos para empezar limpio.
DB::table('keyword_scan_watermarks')->where('keyword_id', $kwId)->delete();

$realBumpSet = [];
for ($i = 0; $i < 5; $i++) {
    $realBumpSet[] = [
        'keyword_id' => $kwId,
        'storage_provider_id' => $storageId,
        'finished_at' => '2026-09-09 10:00:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'candidates' => 1,
        'hits' => $i,
    ];
}

try {
    $service = app(AvisosScanService::class);
    $service->bumpWatermarks($realBumpSet, $runId);
    h_ok('bumpWatermarks() ejecutó sin error con dataset duplicado');
} catch (\Throwable $e) {
    h_fail('bumpWatermarks() lanzó: ' . $e->getMessage());
}

$wmRows = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->where('storage_provider_id', $storageId)
    ->get();

if ($wmRows->count() === 1) {
    h_ok("BD tiene exactamente 1 fila (dedupe correcto)");
} else {
    h_fail("BD tiene " . $wmRows->count() . " filas, esperaba 1");
}

$row = $wmRows->first();
$expectedHits = 0 + 1 + 2 + 3 + 4; // = 10
if ((int) $row->hits_total === $expectedHits) {
    h_ok("hits_total = {$expectedHits} (suma de 0+1+2+3+4)");
} else {
    h_fail("hits_total = {$row->hits_total}, esperaba {$expectedHits}");
}

if ((int) $row->candidates_total === 5) {
    h_ok("candidates_total = 5 (suma)");
} else {
    h_fail("candidates_total = {$row->candidates_total}, esperaba 5");
}

if (str_starts_with((string) $row->scanned_until, '2026-09-09 10:00:04')) {
    h_ok("scanned_until = 2026-09-09 10:00:04 (MAX de las 5 filas)");
} else {
    h_fail("scanned_until = {$row->scanned_until}, esperaba 2026-09-09 10:00:04");
}

// ─── Cleanup ─────────────────────────────────────────────────────────────
h_section('Cleanup');
DB::table('keyword_scan_watermarks')->where('keyword_id', $kwId)->delete();
DB::table('keywords')->where('id', $kwId)->delete();
DB::table('avisos_scan_runs')->where('id', $runId)->delete();
h_ok('Cleanup completo');

// ─── Resumen ─────────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron. Cardinality violation corregido.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
