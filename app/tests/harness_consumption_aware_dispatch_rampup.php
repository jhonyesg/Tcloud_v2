<?php
/**
 * Harness de regresion para change `api-transcriptor-consumption-aware-dispatch`.
 *
 * Verifica que applyStagger() divide correctamente el batch en chunks
 * y respeta dispatch_stagger_ms entre cada uno.
 *
 * Estrategia:
 *   - Crea un TranscriptionTickCommand real
 *   - Invoca applyStagger() via Reflection con batch=30, chunk=5, stagger=50ms
 *   - Verifica:
 *     - 6 chunks de 5 IDs cada uno
 *     - timestamps entre chunks separados por >= 40ms (margen para jitter)
 *     - con stagger=0 todo va en un solo chunk (back-compat)
 *
 * Uso:
 *   cd app && php tests/harness_consumption_aware_dispatch_rampup.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Console\Commands\TranscriptionTickCommand;
use App\Models\SystemSetting;
use App\Services\Ia\TranscriptorSettings;

$tag = 'hcar_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness consumption-aware-dispatch-rampup (tag: {$tag})\n";

SystemSetting::set('transcriptor.stagger_chunk_size', '5');
SystemSetting::set('transcriptor.dispatch_stagger_ms', '50');
app(TranscriptorSettings::class)->flush();

$cmd = app(TranscriptionTickCommand::class);
$settings = app(TranscriptorSettings::class);

$reflMethod = new ReflectionMethod($cmd, 'applyStagger');
$reflMethod->setAccessible(true);

// ─── Test 1: batch=30, chunk=5, stagger=50ms → 6 chunks con pausa entre cada uno
h_section("test 1: 30 IDs, chunk=5, stagger=50ms");

$ids = range(1, 30);
$start = microtime(true);
$chunks = iterator_to_array($reflMethod->invoke($cmd, $ids, $settings), false);
$elapsed = microtime(true) - $start;

h_check(count($chunks) === 6, '6 chunks generados', 'chunks=' . count($chunks));
$totalIds = array_sum(array_map('count', $chunks));
h_check($totalIds === 30, '30 IDs en total', "totalIds=$totalIds");
foreach ($chunks as $i => $chunk) {
    h_check(count($chunk) === 5, "chunk #$i tiene 5 IDs", "chunk #$i tiene " . count($chunk));
}
// 5 pausas entre 6 chunks, cada una >= 40ms (margen para jitter)
$expectedMin = 5 * 0.040;
h_check($elapsed >= $expectedMin, "tiempo total >= {$expectedMin}s (medido: {$elapsed}s)");

// ─── Test 2: stagger=0 → 6 chunks pero sin pausa entre ellos
h_section("test 2: stagger=0 → 6 chunks sin pausa");

SystemSetting::set('transcriptor.dispatch_stagger_ms', '0');
app(TranscriptorSettings::class)->flush();

$start2 = microtime(true);
$chunks2 = iterator_to_array($reflMethod->invoke($cmd, range(1, 30), $settings), false);
$elapsed2 = microtime(true) - $start2;

h_check(count($chunks2) === 6, '6 chunks generados');
h_check($elapsed2 < 0.05, "tiempo total < 0.05s (sin pausas, medido: {$elapsed2}s)");

// ─── Test 3: batch vacio
h_section("test 3: batch vacio → 0 chunks");

$chunks3 = iterator_to_array($reflMethod->invoke($cmd, [], $settings), false);
h_check(count($chunks3) === 0, '0 chunks para batch vacio');

// ─── Test 4: chunk_size=1 → 1 chunk por ID (estilo "goteo total")
h_section("test 4: chunk_size=1 → 30 chunks de 1 ID");

SystemSetting::set('transcriptor.stagger_chunk_size', '1');
SystemSetting::set('transcriptor.dispatch_stagger_ms', '1');
app(TranscriptorSettings::class)->flush();

$chunks4 = iterator_to_array($reflMethod->invoke($cmd, range(1, 30), $settings), false);
h_check(count($chunks4) === 30, '30 chunks de 1 ID', 'chunks=' . count($chunks4));

// ─── Test 5: chunk_size > batch → 1 solo chunk con todos los IDs
h_section("test 5: chunk_size=10, batch=3 → 1 chunk con 3 IDs");

SystemSetting::set('transcriptor.stagger_chunk_size', '10');
SystemSetting::set('transcriptor.dispatch_stagger_ms', '0');
app(TranscriptorSettings::class)->flush();

$chunks5 = iterator_to_array($reflMethod->invoke($cmd, [1, 2, 3], $settings), false);
h_check(count($chunks5) === 1, '1 chunk', 'chunks=' . count($chunks5));
h_check(count($chunks5[0]) === 3, 'chunk tiene 3 IDs');

// cleanup
SystemSetting::set('transcriptor.dispatch_stagger_ms', '250');
SystemSetting::set('transcriptor.stagger_chunk_size', '5');
app(TranscriptorSettings::class)->flush();

echo "\n" . ($failures === 0 ? "✓ ALL PASSED" : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
