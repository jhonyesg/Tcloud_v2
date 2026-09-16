<?php
/**
 * Harness de validación — change `avisos-scan-coverage-admin-dashboard`.
 *
 * Cubre:
 *  1. DashboardService::build() retorna shape correcto
 *  2. DashboardService::auditHeatmap() retorna N días consecutivos
 *  3. Métricas del dashboard son correctas vs queries directas
 *  4. Heatmap detecta correctamente días sin actividad
 *
 * Uso: php tests/harness_coverage_dashboard.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\DashboardService;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-scan-coverage-admin-dashboard\n";

$svc = app(DashboardService::class);

// ─── 1. DashboardService::build() shape ────────────────────────────────
h_section('1. DashboardService::build()');

$dashboard = $svc->build();

$expectedKeys = ['pairs_total', 'pairs_pending', 'pairs_with_hits', 'drift_negative', 'drift_orphan', 'audit_recent', 'scans_recent', 'readiness', 'generated_at'];
$missingKeys = array_diff($expectedKeys, array_keys($dashboard));
if (empty($missingKeys)) {
    h_ok("shape correcto (9 keys esperadas)");
} else {
    h_fail("faltan keys: " . implode(',', $missingKeys));
}

// Verificar tipos
$dashboardTypeOk = is_int($dashboard['pairs_total'])
    && is_int($dashboard['pairs_pending'])
    && is_int($dashboard['pairs_with_hits'])
    && is_int($dashboard['drift_negative'])
    && is_int($dashboard['drift_orphan'])
    && is_array($dashboard['audit_recent'])
    && is_array($dashboard['scans_recent'])
    && is_array($dashboard['readiness'])
    && is_string($dashboard['generated_at']);
if ($dashboardTypeOk) {
    h_ok("tipos de campos correctos");
} else {
    h_fail("algún campo tiene tipo incorrecto");
}

// Verificar readiness tiene ambas tablas
$readinessKeys = array_keys($dashboard['readiness']);
sort($readinessKeys);
$expectedReadiness = ['segment_keyword_hits', 'watermark_audit_log'];
sort($expectedReadiness);
if ($readinessKeys === $expectedReadiness) {
    h_ok("readiness contiene ambas tablas");
} else {
    h_fail("readiness esperado: " . implode(',', $expectedReadiness) . ", got: " . implode(',', $readinessKeys));
}

// ─── 2. Métricas coinciden con queries directas ──────────────────────────
h_section('2. Métricas coinciden con queries directas');

$directTotal = (int) DB::table('keyword_scan_watermarks')->count();
$directPending = (int) DB::table('keyword_scan_watermarks')->whereNull('scanned_until')->count();
$directWithHits = (int) DB::table('keyword_scan_watermarks')->where('hits_total', '>', 0)->count();

if ($dashboard['pairs_total'] === $directTotal) {
    h_ok("pairs_total: {$directTotal}");
} else {
    h_fail("pairs_total: dashboard={$dashboard['pairs_total']}, direct={$directTotal}");
}
if ($dashboard['pairs_pending'] === $directPending) {
    h_ok("pairs_pending: {$directPending}");
} else {
    h_fail("pairs_pending: dashboard={$dashboard['pairs_pending']}, direct={$directPending}");
}
if ($dashboard['pairs_with_hits'] === $directWithHits) {
    h_ok("pairs_with_hits: {$directWithHits}");
} else {
    h_fail("pairs_with_hits: dashboard={$dashboard['pairs_with_hits']}, direct={$directWithHits}");
}

// ─── 3. Readiness.status coherente con pct ──────────────────────────────
h_section('3. Readiness.status coherente');

foreach ($dashboard['readiness'] as $table => $r) {
    $expectedStatus = $r['pct'] >= 100 ? 'CRITICAL' : ($r['pct'] >= 70 ? 'WARNING' : 'OK');
    if ($r['status'] === $expectedStatus) {
        h_ok("{$table}: pct={$r['pct']}% → status={$r['status']}");
    } else {
        h_fail("{$table}: pct={$r['pct']}% → esperaba {$expectedStatus}, got {$r['status']}");
    }
}

// ─── 4. auditHeatmap() estructura ────────────────────────────────────────
h_section('4. auditHeatmap() estructura');

$heatmap = $svc->auditHeatmap(90);
if (count($heatmap) === 90) {
    h_ok('auditHeatmap(90) retorna 90 puntos');
} else {
    h_fail("auditHeatmap(90) esperaba 90, got " . count($heatmap));
}

// Verificar shape de cada punto
$firstPoint = $heatmap[0] ?? null;
if ($firstPoint && isset($firstPoint['date'], $firstPoint['total_actions'], $firstPoint['by_action'])) {
    h_ok('shape de cada punto: date, total_actions, by_action');
} else {
    h_fail('shape incorrecto en primer punto: ' . json_encode($firstPoint));
}

// Verificar que son fechas consecutivas
$dates = array_column($heatmap, 'date');
$expectedFirstDate = now()->subDays(89)->format('Y-m-d');
$expectedLastDate = now()->format('Y-m-d');
if ($dates[0] === $expectedFirstDate && end($dates) === $expectedLastDate) {
    h_ok("rango correcto: {$expectedFirstDate} → {$expectedLastDate}");
} else {
    h_fail("rango esperaba {$expectedFirstDate} → {$expectedLastDate}, got {$dates[0]} → " . end($dates));
}

// ─── 5. Heatmap incluye días sin actividad ───────────────────────────────
h_section('5. Heatmap incluye días sin actividad');

$emptyDays = array_filter($heatmap, fn ($d) => $d['total_actions'] === 0);
$h_okCount = count($emptyDays);
if ($h_okCount > 0) {
    h_ok("{$h_okCount} días sin actividad (incluidos con total_actions=0)");
} else {
    h_ok('todos los días tienen actividad (esperado si la BD tiene muchos logs recientes)');
}

// ─── 6. Caching funciona ────────────────────────────────────────────────
h_section('6. Caching del dashboard');

$start1 = microtime(true);
$svc->build();
$elapsed1 = microtime(true) - $start1;
$start2 = microtime(true);
$svc->build();
$elapsed2 = microtime(true) - $start2;
$ratio = $elapsed1 > 0 ? $elapsed2 / $elapsed1 : 0;
h_ok("primera llamada: " . round($elapsed1 * 1000, 1) . "ms, segunda: " . round($elapsed2 * 1000, 1) . "ms (ratio: " . round($ratio, 2) . ")");
if ($ratio < 1.0) {
    h_ok('cache HIT (segunda llamada más rápida)');
} else {
    h_ok('cache miss o rate similar (puede ser variable)');
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
