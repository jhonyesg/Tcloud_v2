<?php
/**
 * Harness de regresión — change `optimize-watermark-drift-report`.
 *
 * Garantiza que el refactor O(n+m) de WatermarkReconciler::driftReport()
 * preserva el contrato y mejora la latencia:
 *   1. Shapes intactos (summary + missing[]/orphan[] con keyword_id/storage_provider_id)
 *   2. Paridad de missing/orphan contra un set-difference de referencia (SQL)
 *   3. Scope por usuario produce un subconjunto coherente
 *   4. Latencia < umbral (sin cache, cálculo en vivo)
 *
 * Uso: php tests/harness_drift_report_parity.php
 * Exit: 0 = OK, 1 = falla
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Ia\WatermarkReconciler;

$failures = 0;
function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_check(bool $c, string $ok, string $fail): void { $c ? h_ok($ok) : h_fail($fail); }

echo "Harness optimize-watermark-drift-report\n";
$reconciler = app(WatermarkReconciler::class);

// ─── Referencia SQL: set-difference por EXCEPT ──────────────────────────────
$missingRef = DB::select("
    SELECT keyword_id, storage_provider_id FROM (
        SELECT DISTINCT uk.keyword_id, us.storage_provider_id
        FROM user_keyword uk
        JOIN user_storages us ON us.user_id = uk.user_id
        JOIN user_alerts_inteligentes uai ON uai.user_id = us.user_id AND uai.enabled = true
        WHERE us.transcription_access = true
        EXCEPT
        SELECT keyword_id, storage_provider_id FROM keyword_scan_watermarks
    ) m
");
$orphanRef = DB::select("
    SELECT keyword_id, storage_provider_id FROM (
        SELECT keyword_id, storage_provider_id FROM keyword_scan_watermarks
        EXCEPT
        SELECT DISTINCT uk.keyword_id, us.storage_provider_id
        FROM user_keyword uk
        JOIN user_storages us ON us.user_id = uk.user_id
        JOIN user_alerts_inteligentes uai ON uai.user_id = us.user_id AND uai.enabled = true
        WHERE us.transcription_access = true
    ) o
");

$t = microtime(true);
$rep = $reconciler->driftReport();
$ms = round((microtime(true) - $t) * 1000);

echo "\n=== 1. Shape ===\n";
h_check(array_key_exists('summary', $rep) && array_key_exists('missing', $rep) && array_key_exists('orphan', $rep),
    'retorna summary/missing/orphan', 'falta alguna key del contrato');
h_check(array_keys($rep['summary']) === ['applicable_pairs','existing_pairs','missing','orphan','scope_user_id'],
    'summary conserva sus 5 keys en orden', 'summary cambió de shape: ' . implode(',', array_keys($rep['summary'])));

echo "\n=== 2. Paridad de conteos vs SQL ===\n";
h_check($rep['summary']['missing'] === count($missingRef),
    "missing coincide con SQL ({$rep['summary']['missing']})",
    "missing difiere: php={$rep['summary']['missing']} sql=" . count($missingRef));
h_check($rep['summary']['orphan'] === count($orphanRef),
    "orphan coincide con SQL ({$rep['summary']['orphan']})",
    "orphan difiere: php={$rep['summary']['orphan']} sql=" . count($orphanRef));

echo "\n=== 3. Objetos con propiedades (consumidos por el comando) ===\n";
$firstMissing = $rep['missing'][0] ?? null;
$firstOrphan = $rep['orphan'][0] ?? null;
if ($firstMissing) {
    h_check(isset($firstMissing->keyword_id) && isset($firstMissing->storage_provider_id),
        'missing[] expone ->keyword_id / ->storage_provider_id', 'missing[] no expone las propiedades');
}
if ($firstOrphan) {
    h_check(isset($firstOrphan->keyword_id) && isset($firstOrphan->storage_provider_id),
        'orphan[] expone ->keyword_id / ->storage_provider_id', 'orphan[] no expone las propiedades');
}

echo "\n=== 4. Scope por usuario ===\n";
$anyUser = (int) DB::table('user_keyword')->value('user_id');
if ($anyUser) {
    $userRep = $reconciler->driftReport($anyUser);
    h_check($userRep['summary']['scope_user_id'] === $anyUser, "scope_user_id={$anyUser}", 'scope_user_id no coincide');
    h_check($userRep['summary']['missing'] <= $rep['summary']['missing'],
        "drift acotado <= global ({$userRep['summary']['missing']} <= {$rep['summary']['missing']})",
        'el drift acotado supera al global');
}

echo "\n=== 5. Latencia ===\n";
echo "  driftReport (sistema completo): {$ms} ms\n";
h_check($ms < 500, "driftReport < 500 ms (obtenido {$ms} ms)", "driftReport lento: {$ms} ms");

echo "\n";
if ($failures === 0) { echo "✓ Contrato y paridad OK.\n"; exit(0); }
echo "✗ $failures aserción(es) fallaron.\n"; exit(1);
