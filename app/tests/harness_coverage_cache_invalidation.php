<?php
/**
 * Harness de validación — change `avisos-scan-coverage-admin-discoverability`.
 *
 * Cubre:
 *  - Comando avisos:reset-cache-coverage incrementa el epoch correctamente
 *  - Es idempotente (ejecutar dos veces seguidas)
 *  - POST /scan/rewind dispara bump automático vía Reconciler (verifica que el
 *    comando y el Reconciler producen el mismo resultado funcional)
 *  - Sidebar link está presente en layout app.blade.php (visual; skip si no
 *    aplica al harness)
 *
 * Uso: php tests/harness_coverage_cache_invalidation.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\CacheEpoch;
use App\Services\Ia\WatermarkReconciler;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-scan-coverage-admin-discoverability\n";

// ─── 1. Estado inicial ───────────────────────────────────────────────────
h_section('1. Estado inicial');

$initial = CacheEpoch::get();
h_ok("epoch inicial: {$initial}");

// ─── 2. CacheEpoch::bump() directo ───────────────────────────────────────
h_section('2. CacheEpoch::bump() directo');

$next = CacheEpoch::bump();
if ($next === $initial + 1) {
    h_ok("bump() incrementa en 1: {$initial} → {$next}");
} else {
    h_fail("bump() NO incrementó en 1: {$initial} → {$next}");
}

$initial2 = $next;
$next2 = CacheEpoch::bump();
if ($next2 === $initial2 + 1) {
    h_ok("segundo bump también incrementa: {$initial2} → {$next2}");
} else {
    h_fail("segundo bump roto");
}

// ─── 3. Reconciler::rewindPair() bumpea ─────────────────────────────────
h_section('3. Reconciler::rewindPair() bumpea el epoch');

$epochBeforeRewind = CacheEpoch::get();

// Crear keyword para el test.
$testKw = \App\Models\Keyword::create(['text' => '[reset-cache] test', 'normalized' => '[reset-cache] test']);
$testKwId = (int) $testKw->id;
$testStorageId = (int) DB::table('storage_providers')->where('enabled', true)->value('id');

// Setup mínimo para que rewindPair no falle por FK.
DB::table('keyword_scan_watermarks')->insertOrIgnore([
    'keyword_id' => $testKwId,
    'storage_provider_id' => $testStorageId,
    'scanned_until' => null,
    'candidates_total' => 0,
    'hits_total' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);

$reconciler = app(WatermarkReconciler::class);
$reconciler->rewindPair($testKwId, $testStorageId, 1);

$epochAfterRewind = CacheEpoch::get();
if ($epochAfterRewind > $epochBeforeRewind) {
    h_ok("rewindPair bumpea: {$epochBeforeRewind} → {$epochAfterRewind}");
} else {
    h_fail("rewindPair NO bumpeó");
}

// Cleanup
DB::table('keyword_scan_watermarks')->where('keyword_id', $testKwId)->delete();
$testKw->delete();

// ─── 4. Sidebar link presente ───────────────────────────────────────────
h_section('4. Sidebar link presente en layout');

$layout = file_get_contents(__DIR__ . '/../resources/views/layouts/app.blade.php');
if (str_contains($layout, '/ia/avisos-inteligentes') && str_contains($layout, 'fa-radar')) {
    h_ok('layout/app.blade.php contiene el link /ia/avisos-inteligentes con ícono fa-radar');
} else {
    h_fail('layout/app.blade.php NO contiene el link esperado');
}

// ─── 5. Comando CLI registrado ──────────────────────────────────────────
h_section('5. Comando CLI registrado');

$commands = \Illuminate\Support\Facades\Artisan::all();
if (isset($commands['avisos:reset-cache-coverage'])) {
    h_ok('comando avisos:reset-cache-coverage registrado en Artisan');
} else {
    h_fail('comando NO registrado');
}

// ─── 6. Comando ejecuta y bumpam ────────────────────────────────────────
h_section('6. Comando ejecuta y bumpam');

$epochBeforeCommand = CacheEpoch::get();
$exitCode = \Illuminate\Support\Facades\Artisan::call('avisos:reset-cache-coverage');
$epochAfterCommand = CacheEpoch::get();

if ($exitCode === 0) {
    h_ok('exit code 0');
} else {
    h_fail("exit code {$exitCode}");
}
if ($epochAfterCommand > $epochBeforeCommand) {
    h_ok("comando bumpeó: {$epochBeforeCommand} → {$epochAfterCommand}");
} else {
    h_fail('comando NO bumpeó');
}

// Idempotente.
$exitCode2 = \Illuminate\Support\Facades\Artisan::call('avisos:reset-cache-coverage');
$epochAfterCommand2 = CacheEpoch::get();
if ($exitCode2 === 0 && $epochAfterCommand2 === $epochAfterCommand + 1) {
    h_ok('idempotente: segunda ejecución bumpam normalmente');
} else {
    h_fail("idempotencia rota: exit={$exitCode2}, epoch={$epochAfterCommand2}");
}

// ─── Resumen ─────────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
