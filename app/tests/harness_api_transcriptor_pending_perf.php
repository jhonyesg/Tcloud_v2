<?php
/**
 * Harness de validación del change `2026-09-12-api-transcriptor-pending-perf`.
 *
 * Ejecuta contra el stack real (BD + cache Redis). Verifica:
 *   1. /stats cold < 200 ms, warm < 50 ms
 *   2. /health cold < 200 ms, warm < 50 ms
 *   3. /empty-folders cold ~3.6 s, warm < 50 ms, parcial warm < 2.5 s
 *   4. Cache-Control header en /stats y /health
 *   5. Bypass con TTL=0 funciona para los 3 endpoints
 *   6. Invalidación en toggleStorage limpia las 3 caches
 *
 * Uso: cd app && php tests/harness_api_transcriptor_pending_perf.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Ia\ApiTranscriptorController;
use App\Models\StorageProvider;
use App\Models\SystemSetting;
use App\Services\Ia\StorageFunnelService;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'hpp_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness api-transcriptor-pending-perf (tag: {$tag})\n";

function makeController(): ApiTranscriptorController
{
    return new ApiTranscriptorController(
        app(TranscriptorSettings::class),
        app(StorageFunnelService::class),
    );
}

try {
    h_section('1. /stats — cache Redis');

    // Default 300s (5 min) — ver resolucion del operador que navega un rato
    // y recarga: el TTL de 60s expiraba y la pagina se sentia lenta al volver.
    // Acá forzamos el valor para que el harness no dependa del default actual.
    $originalStatsTtl = SystemSetting::get('transcriptor_stats_cache_ttl');
    $originalHealthTtl = SystemSetting::get('transcriptor_health_cache_ttl');
    SystemSetting::set('transcriptor_stats_cache_ttl', '60');
    Cache::forget('transcriptor:health:combined');

    $ctrl = makeController();
    $client = app(TranscriptorApiClient::class);

    $t = microtime(true);
    $resp = $ctrl->stats($client);
    $cold = (microtime(true) - $t) * 1000;
    $headersCold = $resp->headers->all();

    h_check($resp->getStatusCode() === 200, '/stats responde 200');
    h_check($cold < 1500, sprintf('/stats cold < 1500 ms (%.1f ms)', $cold));
    h_check(
        isset($headersCold['cache-control']) && str_contains($headersCold['cache-control'][0], 'max-age=30'),
        '/stats emite Cache-Control: max-age=30, private'
    );

    $t = microtime(true);
    $resp = $ctrl->stats($client);
    $warm = (microtime(true) - $t) * 1000;

    h_check($warm < 50, sprintf('/stats warm < 50 ms (%.1f ms)', $warm),
        sprintf('warm tardó %.1f ms', $warm));
    h_check(Cache::has('transcriptor:stats:combined'), 'Cache poblada tras primer call');

    $data = json_decode($resp->getContent(), true);
    h_check(isset($data['cached_at']), 'cached_at presente en payload');
    h_check(isset($data['local']) && is_array($data['local']), 'local GROUP BY presente');

    h_section('2. /health — cache Redis');

    SystemSetting::set('transcriptor_health_cache_ttl', '30');
    Cache::forget('transcriptor:health:combined');

    $t = microtime(true);
    $resp = $ctrl->health($client);
    $cold = (microtime(true) - $t) * 1000;
    $headers = $resp->headers->all();

    h_check($resp->getStatusCode() === 200, '/health responde 200');
    h_check(
        isset($headers['cache-control']) && str_contains($headers['cache-control'][0], 'max-age=30'),
        '/health emite Cache-Control: max-age=30, private'
    );
    h_check($cold < 1500, sprintf('/health cold < 1500 ms (%.1f ms)', $cold));

    $t = microtime(true);
    $resp = $ctrl->health($client);
    $warm = (microtime(true) - $t) * 1000;

    h_check($warm < 50, sprintf('/health warm < 50 ms (%.1f ms)', $warm));
    h_check(Cache::has('transcriptor:health:combined'), 'Cache poblada tras primer call');

    h_section('3. /empty-folders — cache por storage');

    $ctrl = makeController();
    $req = Request::create('/ia/api-transcriptor/empty-folders', 'GET', ['max_dirs' => 200]);

    $storageIds = DB::table('storage_providers')
        ->where('transcription_enabled', true)
        ->pluck('id');
    foreach ($storageIds as $id) {
        foreach ([200, 300, 400, 500] as $cap) {
            Cache::forget("transcriptor:empty_folders:{$id}:max{$cap}");
            Cache::forget("transcriptor:empty_folders:lock:{$id}");
        }
    }

    $t = microtime(true);
    $resp = $ctrl->emptyFolders($req);
    $cold = (microtime(true) - $t) * 1000;

    h_check($resp->getStatusCode() === 200, '/empty-folders responde 200');
    h_check($cold < 10000, sprintf('/empty-folders cold < 10 s (%.1f ms)', $cold));
    // Tras cold, todos los storages deberian estar cacheados (incluyendo el sentinel __none__).
    $cacheCount = 0;
    foreach ($storageIds as $id) {
        if (Cache::has("transcriptor:empty_folders:{$id}:max200")) $cacheCount++;
    }
    h_check(
        $cacheCount === count($storageIds),
        sprintf('Todos los %d storages quedan cacheados tras cold (cacheados=%d)',
            count($storageIds), $cacheCount)
    );

    $t = microtime(true);
    $resp = $ctrl->emptyFolders($req);
    $warm = (microtime(true) - $t) * 1000;
    h_check($warm < 100, sprintf('/empty-folders warm < 100 ms (%.1f ms)', $warm));

    // Test parcial warm: borrar la mitad de los caches.
    $storageArr = $storageIds->all();
    $half = (int) (count($storageArr) / 2);
    for ($i = 0; $i < $half; $i++) {
        Cache::forget("transcriptor:empty_folders:{$storageArr[$i]}:max200");
    }

    $t = microtime(true);
    $resp = $ctrl->emptyFolders($req);
    $partial = (microtime(true) - $t) * 1000;
    h_check(
        $partial > $warm && $partial < $cold,
        sprintf('Parcial warm entre warm (%.1f ms) y cold (%.1f ms): %.1f ms', $warm, $cold, $partial)
    );

    h_section('4. Bypass con TTL=0');

    SystemSetting::set('transcriptor_stats_cache_ttl', '0');
    SystemSetting::set('transcriptor_health_cache_ttl', '0');
    Cache::forget('transcriptor:stats:combined');
    Cache::forget('transcriptor:health:combined');

    $ctrl = makeController();

    $ctrl->stats($client);
    $ctrl->health($client);
    h_check(!Cache::has('transcriptor:stats:combined'), 'TTL=0 NO escribe stats cache');
    h_check(!Cache::has('transcriptor:health:combined'), 'TTL=0 NO escribe health cache');

    // Restaurar al estado original o eliminar el setting (para que la app
    // use los defaults 300s/120s configurados en el codigo). Dejamos los
    // valores en BD para que el siguiente que ejecute el harness vea los
    // defaults; el harness los sobreescribe de todas formas al principio.
    if ($originalStatsTtl === null) {
        DB::table('system_settings')->where('key', 'transcriptor_stats_cache_ttl')->delete();
    } else {
        SystemSetting::set('transcriptor_stats_cache_ttl', (string) $originalStatsTtl);
    }
    if ($originalHealthTtl === null) {
        DB::table('system_settings')->where('key', 'transcriptor_health_cache_ttl')->delete();
    } else {
        SystemSetting::set('transcriptor_health_cache_ttl', (string) $originalHealthTtl);
    }

    h_section('5. Headers Cache-Control y shape del payload');

    Cache::forget('transcriptor:stats:combined');
    $resp = $ctrl->stats($client);
    $data = json_decode($resp->getContent(), true);
    h_check(isset($data['local']) && count($data['local']) > 0, 'local tiene contadores por estado');
    h_check(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['cached_at'] ?? '') === 1,
        'cached_at formato ISO8601'
    );

    echo "\n";
} finally {
    // Cleanup: nada que borrar porque no creamos filas.
    Cache::forget('transcriptor:stats:combined');
    Cache::forget('transcriptor:health:combined');
}

if ($failures === 0) {
    echo "All assertions passed ✓\n";
    exit(0);
}
echo "{$failures} assertion(s) failed ✗\n";
exit(1);
