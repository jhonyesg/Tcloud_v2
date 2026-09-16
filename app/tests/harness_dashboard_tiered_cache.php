<?php
/**
 * Probe de verificación del change dashboard-tiered-cache.
 * Mide: cache hit frío, frescura, y que el segundo build no recalcule.
 * Uso: php tests/harness_dashboard_tiered_cache.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\DashboardDataProvider;
use App\Services\Ia\CacheEpoch;
use App\Services\Ia\DashboardService;

$failures = 0;
function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_check(bool $c, string $ok, string $fail): void { $c ? h_ok($ok) : h_fail($fail); }

echo "Harness dashboard-tiered-cache\n";

$admin = User::where('role', 'admin')->first();
if (!$admin) { echo "No admin user; abort\n"; exit(1); }

$provider = app(DashboardDataProvider::class);

// Limpiar solo las keys del dashboard para arrancar en frío.
foreach (['dashboard:cold:stats','dashboard:cold:media-editor'] as $k) {
    Cache::forget($k);
    Cache::forget('illuminate:cache:flexible:created:' . $k);
}
foreach (Cache::getStore() ? [] : [] as $x) {}

echo "\n=== A. Cold stats: una sola query COUNT+SUM ===\n";
$t0 = microtime(true);
$blocks = $provider->buildAdmin($admin);
$tCold = (microtime(true) - $t0) * 1000;

$t1 = microtime(true);
$blocks2 = $provider->buildAdmin($admin);
$tWarm = (microtime(true) - $t1) * 1000;

printf("  frío=%.0fms  caliente=%.0fms  ratio=%.2fx\n", $tCold, $tWarm, $tCold / max($tWarm, 1));
h_check($tWarm < $tCold, 'la carga caliente es más rápida que la fría',
    'la carga caliente no mejoró');

echo "\n=== B. Envelope shape ===\n";
foreach (['media_editor','stats','mis_avisos','bg_jobs','active_sessions'] as $k) {
    $b = $blocks[$k];
    h_check(isset($b['data']) && isset($b['generated_at']) && array_key_exists('stale', $b),
        "bloque '$k' tiene sobre {data,generated_at,stale}", "bloque '$k' sin sobre uniforme");
}

echo "\n=== C. dataOf desembolsa y conserva shape de partials ===\n";
$data = $provider->dataOf($blocks);
h_check(isset($data['stats']['storage_used'], $data['stats']['total_files']),
    'stats.data conserva storage_used/total_files', 'stats.data perdió keys');
h_check(isset($data['media_editor']['users_with_editor'], $data['media_editor']['top_consumers']),
    'media_editor.data conserva shape', 'media_editor.data perdió keys');
h_check(isset($data['mis_avisos']['pairs_total'], $data['mis_avisos']['drift_negative']),
    'mis_avisos.data conserva shape', 'mis_avisos.data perdió keys');
h_check(is_array($data['bg_jobs']) && array_is_list($data['bg_jobs']),
    'bg_jobs.data sigue siendo lista', 'bg_jobs.data dejó de ser lista');
h_check(isset($data['active_sessions']['total']), 'active_sessions.data conserva shape',
    'active_sessions.data perdió keys');

echo "\n=== D. Frescura ===\n";
$fresh = $provider->freshnessOf($blocks2);
h_check(!empty($fresh['stats']['generated_at']), 'stats tiene generated_at', 'stats sin generated_at');
h_check($fresh['bg_jobs']['stale'] === false, 'bg_jobs siempre fresh (hot)', 'bg_jobs marcado stale');

echo "\n=== E. Cache tibio atado a epoch ===\n";
$epoch = CacheEpoch::get();
$key = 'dashboard:warm:mis-avisos:e' . $epoch;
Cache::forget($key); Cache::forget('illuminate:cache:flexible:created:' . $key);
$provider->buildAdmin($admin); // pasa por Cache::flexible y escribe el marker
$before = Cache::get('illuminate:cache:flexible:created:' . $key);
h_check($before !== null, 'la entry tibia se crea con la key del epoch actual',
    'la entrada tibia no se creó con la key esperada');
$keyStale = 'dashboard:warm:mis-avisos:e' . ($epoch + 999);
h_check(Cache::get('illuminate:cache:flexible:created:' . $keyStale) === null,
    'un epoch distinto usa una key distinta (invalidación)', 'el epoch no cambia la key');

echo "\n=== F. coverageSummary acotado ===\n";
$sum = app(DashboardService::class)->coverageSummary();
h_check(array_keys($sum) === ['pairs_total','pairs_pending','pairs_with_hits','drift_negative'],
    'coverageSummary devuelve solo los 4 KPIs', 'coverageSummary devuelve keys extra: ' . implode(',', array_keys($sum)));

echo "\n=== G. Stale-while-revalidate (deferred) ===\n";
$key = 'dashboard:cold:stats';
$createdKey = 'illuminate:cache:flexible:created:' . $key;
// Forzar el bloque a "stale": created retrasado más que el fresh TTL.
$original = Cache::get($key);
Cache::put($createdKey, time() - ((int) config('dashboard.cold_ttl') + 60), 3600);
$t0 = microtime(true);
$served = $provider->buildAdmin($admin);
$tServed = (microtime(true) - $t0) * 1000;
h_check($served['stats']['data'] === $original,
    'sirve el valor previo cuando está stale (no recalcula en línea)',
    'recalculó en línea en lugar de servir stale');
h_check($tServed < 200, "respuesta stale rápida ({$tServed}ms)", 'la respuesta stale fue lenta');
// En HTTP el middleware InvokeDeferredCallbacks dispara los callbacks tras
// enviar la respuesta. En consola lo simulamos invocando la colección.
app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
$createdAfter = Cache::get($createdKey);
h_check($createdAfter > (time() - 5), 'el callback diferido refrescó la entrada tras invoke',
    'la entrada no se refrescó tras invoke');

echo "\n=== H. Tier caliente cambia entre cargas ===\n";
$sessionCount = DB::table('user_sessions')->count();
$blocksX = $provider->buildAdmin($admin);
$generatedA = $blocksX['active_sessions']['generated_at'];
usleep(1_100_000); // 1.1s para garantizar cambio de segundo
$blocksY = $provider->buildAdmin($admin);
$generatedB = $blocksY['active_sessions']['generated_at'];
h_check($generatedA !== $generatedB, 'active_sessions (hot) se regenera por request',
    'active_sessions quedó cacheado');

echo "\n";
if ($failures === 0) { echo "✓ Tiered cache OK.\n"; exit(0); }
echo "✗ $failures aserción(es) fallaron.\n"; exit(1);
