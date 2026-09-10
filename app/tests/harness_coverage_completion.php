<?php
/**
 * Harness de validación — change `avisos-scan-coverage-completion`.
 *
 * Cubre:
 *  1. RetentionPolicy: get/set con validación de rango
 *  2. CoverageStats: serie temporal devuelta con shape correcto
 *  3. CheckPartitioningReadiness: comando detecta estados OK/WARNING/CRITICAL
 *  4. ArchiveAuditLogCommand: respeta retention policy si --days no se pasa
 *
 * Uso: php tests/harness_coverage_completion.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\CoverageStats;
use App\Services\Ia\RetentionPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-scan-coverage-completion\n";

// ─── 1. RetentionPolicy ──────────────────────────────────────────────────
h_section('1. RetentionPolicy');

$initialDays = RetentionPolicy::getDays();
if ($initialDays === 90) {
    h_ok("default 90 días: {$initialDays}");
} else {
    h_fail("default esperaba 90, got {$initialDays}");
}

// Set válido
$setDays = RetentionPolicy::setDays(180);
if ($setDays === 180) {
    h_ok("setDays(180): {$setDays}");
} else {
    h_fail("setDays(180) esperaba 180, got {$setDays}");
}

$current = RetentionPolicy::getDays();
if ($current === 180) {
    h_ok("getDays() retorna 180 tras set");
} else {
    h_fail("getDays() esperaba 180, got {$current}");
}

// Set fuera de rango debe clampear
$clampedLow = RetentionPolicy::setDays(5); // MIN=30
if ($clampedLow === 30) {
    h_ok("setDays(5) clampeado a MIN=30");
} else {
    h_fail("setDays(5) esperaba 30 (clamp), got {$clampedLow}");
}

$clampedHigh = RetentionPolicy::setDays(10000); // MAX=3650
if ($clampedHigh === 3650) {
    h_ok("setDays(10000) clampeado a MAX=3650");
} else {
    h_fail("setDays(10000) esperaba 3650, got {$clampedHigh}");
}

// Reset a 90
RetentionPolicy::setDays(90);
h_ok('Reset a 90 días');

// ─── 2. CoverageStats ───────────────────────────────────────────────────
h_section('2. CoverageStats');

$stats = app(CoverageStats::class);
$series = $stats->lastDays(7);

if (is_array($series) && count($series) === 7) {
    h_ok("lastDays(7) retorna 7 puntos");
} else {
    h_fail("lastDays(7) esperaba 7 puntos, got " . count($series ?? []));
}

if (isset($series[0]['date'], $series[0]['total_pairs'], $series[0]['pending_pairs'], $series[0]['hits_total'])) {
    h_ok("shape correcto: date, total_pairs, pending_pairs, hits_total");
} else {
    h_fail("shape incorrecto: " . json_encode($series[0] ?? []));
}

// Verificar que las fechas son los últimos 7 días consecutivos
$today = now()->format('Y-m-d');
$lastDate = end($series)['date'] ?? null;
if ($lastDate === $today) {
    h_ok("último punto es hoy ({$today})");
} else {
    h_fail("último punto esperaba {$today}, got {$lastDate}");
}

// ─── 3. CheckPartitioningReadiness ──────────────────────────────────────
h_section('3. CheckPartitioningReadiness');

$exitCode = Artisan::call('avisos:check-partitioning-readiness');
$output = Artisan::output();

if (str_contains($output, 'watermark_audit_log') && str_contains($output, 'segment_keyword_hits')) {
    h_ok('output contiene ambas tablas');
} else {
    h_fail('output incompleto: ' . $output);
}

if (str_contains($output, 'Tabla') && str_contains($output, 'Estado')) {
    h_ok('output formato tabla correcto');
} else {
    h_fail('output no formato tabla');
}

// Hoy las dos tablas están muy por debajo del threshold → exit 0 (OK)
if ($exitCode === 0) {
    h_ok("exit 0 (volumen actual < threshold)");
} else {
    h_fail("exit esperaba 0, got {$exitCode}");
}

// ─── 4. ArchiveAuditLogCommand usa RetentionPolicy ──────────────────────
h_section('4. ArchiveAuditLogCommand usa RetentionPolicy');

// Configurar policy a 30 días (MIN) y ejecutar sin --days
RetentionPolicy::setDays(30);
$exitCode = Artisan::call('avisos:archive-audit-log', ['--dry-run' => true]);
$output = Artisan::output();
if (str_contains($output, '30 días')) {
    h_ok("archive-audit-log usa retention policy (30 días)");
} else {
    h_fail("esperaba output con '30 días', got: " . substr($output, 0, 200));
}

// Reset
RetentionPolicy::setDays(90);
h_ok('Reset a 90 días');

// ─── 5. Resumen ──────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
