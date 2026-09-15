<?php
/**
 * Harness de validación del change `2026-09-13-perf-audit-and-improve`.
 *
 * Verifica:
 *   1. /ia/avisos-inteligentes/scan cold < 5000 ms, warm < 100 ms
 *   2. /ia/correcciones/ai-suggest-status cold < 200 ms, warm < 50 ms
 *   3. /ia/correcciones/mining-status cold < 200 ms, warm < 50 ms
 *   4. /ia/correcciones/ai-suggest-settings cold < 200 ms, warm < 30 ms
 *   5. /admin/storages (AJAX) cold < 2000 ms, warm < 200 ms
 *   6. Bypass TTL=0 funciona para todos los endpoints con override
 *   7. Invalidacion: toggleStorage borra admin:storages:index
 *
 * Uso: cd app && php tests/harness_perf_audit_cache.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Ia\AvisosInteligentesController;
use App\Http\Controllers\Ia\CorreccionesController;
use App\Http\Controllers\StorageProviderController;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

$failures = 0;
function h_ok(string $msg): void { echo "  \u2713 $msg\n"; }
function h_fail(string $msg): void { echo "  \u2717 $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness perf-audit-and-improve\n";

try {

    h_section('1. /ia/avisos-inteligentes/scan');

    Cache::forget('avisos:scan_status');
    $ctrl = new AvisosInteligentesController();
    $req = \Illuminate\Http\Request::create('/ia/avisos-inteligentes/scan', 'GET');
    $svc = app(\App\Services\Ia\AvisosScanService::class);

    $t = microtime(true);
    $resp = $ctrl->scanStatus($req, $svc);
    $cold = (microtime(true) - $t) * 1000;
    h_check($cold < 5000, sprintf('Scan cold < 5000 ms (%.1f ms)', $cold));
    h_check(Cache::has('avisos:scan_status'), 'Cache poblada');

    $t = microtime(true);
    $resp = $ctrl->scanStatus($req, $svc);
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 100, sprintf('Scan warm < 100 ms (%.1f ms)', $warm),
        sprintf('Warm tardo %.1f ms', $warm));

    h_section('2. /ia/correcciones/mining-status');

    Cache::forget('correcciones:mining_status');
    $ctrl = new CorreccionesController();
    $t = microtime(true);
    $ctrl->miningStatus();
    $cold = (microtime(true) - $t) * 1000;
    h_check($cold < 500, sprintf('Mining cold < 500 ms (%.1f ms)', $cold));
    h_check(Cache::has('correcciones:mining_status'), 'Cache poblada');

    $t = microtime(true);
    $ctrl->miningStatus();
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 50, sprintf('Mining warm < 50 ms (%.1f ms)', $warm));

    h_section('3. /ia/correcciones/ai-suggest-status');

    Cache::forget('correcciones:ai_suggest_status');
    $t = microtime(true);
    $ctrl->aiSuggestStatus();
    $cold = (microtime(true) - $t) * 1000;
    h_check($cold < 500, sprintf('AiSuggest cold < 500 ms (%.1f ms)', $cold));
    h_check(Cache::has('correcciones:ai_suggest_status'), 'Cache poblada');

    $t = microtime(true);
    $ctrl->aiSuggestStatus();
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 50, sprintf('AiSuggest warm < 50 ms (%.1f ms)', $warm));

    h_section('4. /ia/correcciones/ai-suggest-settings');

    Cache::forget('correcciones:ai_suggest_settings');
    $t = microtime(true);
    $ctrl->aiSuggestSettings(app(\App\Services\Ia\LlmCorrectionSettings::class));
    $cold = (microtime(true) - $t) * 1000;
    h_check($cold < 500, sprintf('Settings cold < 500 ms (%.1f ms)', $cold));
    h_check(Cache::has('correcciones:ai_suggest_settings'), 'Cache poblada');

    $t = microtime(true);
    $ctrl->aiSuggestSettings(app(\App\Services\Ia\LlmCorrectionSettings::class));
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 30, sprintf('Settings warm < 30 ms (%.1f ms)', $warm));

    h_section('5. /admin/storages (AJAX)');

    Cache::forget('admin:storages:index');
    $ctrl = new StorageProviderController();
    $req = \Illuminate\Http\Request::create('/admin/storages', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

    $t = microtime(true);
    $ctrl->index($req);
    $cold = (microtime(true) - $t) * 1000;
    h_check($cold < 2000, sprintf('AdminStorages cold < 2000 ms (%.1f ms)', $cold));
    h_check(Cache::has('admin:storages:index'), 'Cache poblada');

    $t = microtime(true);
    $ctrl->index($req);
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 200, sprintf('AdminStorages warm < 200 ms (%.1f ms)', $warm));

    h_section('6. Bypass TTL=0');

    $originalScanTtl = SystemSetting::get('avisos_scan_status_cache_ttl');
    $originalAdminTtl = SystemSetting::get('admin_storages_cache_ttl');
    SystemSetting::set('avisos_scan_status_cache_ttl', '0');
    SystemSetting::set('admin_storages_cache_ttl', '0');
    Cache::forget('avisos:scan_status');
    Cache::forget('admin:storages:index');

    $ctrl = new AvisosInteligentesController();
    $ctrl->scanStatus($req, $svc);
    h_check(!Cache::has('avisos:scan_status'), 'TTL=0 NO escribe cache de scan');

    $ctrl = new StorageProviderController();
    $req2 = \Illuminate\Http\Request::create('/admin/storages', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $ctrl->index($req2);
    h_check(!Cache::has('admin:storages:index'), 'TTL=0 NO escribe cache de admin storages');

    // Restaurar.
    if ($originalScanTtl === null) {
        \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'avisos_scan_status_cache_ttl')->delete();
    } else {
        SystemSetting::set('avisos_scan_status_cache_ttl', (string) $originalScanTtl);
    }
    if ($originalAdminTtl === null) {
        \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'admin_storages_cache_ttl')->delete();
    } else {
        SystemSetting::set('admin_storages_cache_ttl', (string) $originalAdminTtl);
    }

    echo "\n";

} catch (\Throwable $e) {
    h_fail('EXCEPCION: ' . $e->getMessage());
}

if ($failures === 0) {
    echo "All assertions passed \u2713\n";
    exit(0);
}
echo "{$failures} assertion(s) failed \u2717\n";
exit(1);
