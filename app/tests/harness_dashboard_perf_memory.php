<?php
/**
 * Harness de rendimiento y memoria del dashboard.
 *
 * Change: `dashboard-perf-backup-guide` (S1, S2, S3).
 *
 * Mide, con números reales de este servidor:
 *   - tiempo del build completo (cold / warm) y por bloque;
 *   - cantidad de queries por request;
 *   - memoria por request y crecimiento entre renders consecutivos
 *     (la pregunta "¿hay fuga de memoria?" tiene que tener un guardarraíl);
 *   - que el log de Laravel no tenga muertes por `Allowed memory size`.
 *
 * No muta datos: solo lee y borra las claves del cache del dashboard para
 * poder medir en frío. Usa el usuario admin real (solo lectura).
 *
 * Uso:
 *   cd app && php tests/harness_dashboard_perf_memory.php
 *   exit 0 = OK, exit 1 = alguna aserción falló
 *
 * Umbrales: son de GUARDARRAÍL (regresión franca), no de rendimiento
 * objetivo. Cada tarea de optimización los endurece a medida que baja el
 * número medido; nunca al revés.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Dashboard\DashboardDataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$failures = 0;

function h_ok(string $m): void { echo "  \u{2713} {$m}\n"; }
function h_fail(string $m): void { echo "  \u{2717} {$m}\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $cond, string $ok, string $fail = ''): void
{
    $cond ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness dashboard-perf-memory\n";

$admin = User::where('role', 'admin')->orderBy('id')->first();
if (! $admin) {
    echo "No hay usuario admin; abort\n";
    exit(1);
}
echo "  admin=#{$admin->id} ({$admin->username})\n";

/** @var DashboardDataProvider $provider */
$provider = app(DashboardDataProvider::class);

// Los TTL salen del config: se imprimen para que el número medido quede
// interpretable en el log.
printf(
    "  cold_ttl=%ss cold_stale=%ss warm_ttl=%ss warm_stale=%ss\n",
    config('dashboard.cold_ttl'),
    config('dashboard.cold_stale_total'),
    config('dashboard.warm_ttl'),
    config('dashboard.warm_stale_total')
);

$coldKeys = ['dashboard:cold:stats', 'dashboard:cold:media-editor'];

// ─────────────────────────────────────────────────────────────────────
h_section('A. Tiempo de build: cold vs warm');
// ─────────────────────────────────────────────────────────────────────

foreach ($coldKeys as $k) {
    Cache::forget($k);
    Cache::forget('illuminate:cache:flexible:created:' . $k);
}
$warmKey = 'dashboard:warm:mis-avisos:e' . \App\Services\Ia\CacheEpoch::get();
Cache::forget($warmKey);
Cache::forget('illuminate:cache:flexible:created:' . $warmKey);

$t0 = microtime(true);
$blocks = $provider->buildAdmin($admin);
$msCold = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$blocksWarm = $provider->buildAdmin($admin);
$msWarm = (microtime(true) - $t0) * 1000;

printf("  cold=%.0f ms · warm=%.0f ms · ratio=%.1fx\n", $msCold, $msWarm, $msCold / max($msWarm, 0.001));

h_check($msCold > 0, "el build en frío se midió ({$msCold} ms)", 'la medición fría quedó en 0');
h_check(
    $msWarm < $msCold,
    "el build caliente es más barato que el frío ({$msWarm} ms < {$msCold} ms)",
    "el build caliente NO es más barato ({$msWarm} ms vs {$msCold} ms)"
);

$data = $provider->dataOf($blocksWarm);

// ─────────────────────────────────────────────────────────────────────
h_section('B. Desglose por bloque (cada bloque medido aislado, en caliente)');
// ─────────────────────────────────────────────────────────────────────

$renderArgs = [
    'stats' => $data['stats'],
    'ramdisk' => ['available' => true, 'total_gb' => 1, 'used_gb' => 0, 'free_gb' => 1, 'percent' => 0],
    'shm' => ['available' => true, 'total_gb' => 1, 'used_gb' => 0, 'free_gb' => 1, 'percent' => 0],
    'dashboardData' => $data,
    'dashboardFreshness' => $provider->freshnessOf($blocksWarm),
    'user' => $admin,
    'personalStorageId' => null,
    'instructivos' => [],
];

$t0 = microtime(true);
view('dashboard.admin', $renderArgs)->render();
$msRender = (microtime(true) - $t0) * 1000;
printf("  render(view dashboard.admin)=%.0f ms\n", $msRender);

h_check($msRender > 0, "el render de la vista se midió ({$msRender} ms)", 'el render quedó en 0');

// ─────────────────────────────────────────────────────────────────────
h_section('C. Queries por request del build en caliente');
// ─────────────────────────────────────────────────────────────────────

DB::flushQueryLog();
DB::enableQueryLog();
$provider->buildAdmin($admin);
$queryLog = DB::getQueryLog();
DB::disableQueryLog();

$tables = [];
foreach ($queryLog as $row) {
    if (preg_match('/from "([^"]+)"/', (string) ($row['query'] ?? ''), $m)) {
        $tables[$m[1]] = ($tables[$m[1]] ?? 0) + 1;
    }
}
arsort($tables);
printf("  queries=%d", count($queryLog));
if ($tables !== []) {
    $parts = [];
    foreach ($tables as $t => $c) {
        $parts[] = "{$t}×{$c}";
    }
    echo ' (' . implode(', ', $parts) . ')';
}
echo "\n";

// Guardarraíl: el dashboard no debe volverse un N+1. Tras T6 el build en
// caliente hace 5 queries (2 de system_settings: una del flag/audit y otra
// del CacheEpoch). 8 deja margen para un bloque nuevo sin permitir N+1.
h_check(
    count($queryLog) <= 8,
    'el build en caliente hace como máximo 8 queries (' . count($queryLog) . ')',
    'el build en caliente disparó ' . count($queryLog) . ' queries (¿N+1 nuevo?)'
);

// ─────────────────────────────────────────────────────────────────────
h_section('D. Memoria por request (¿hay fuga?)');
// ─────────────────────────────────────────────────────────────────────

$samples = [];
for ($i = 1; $i <= 6; $i++) {
    $before = memory_get_usage();
    $html = view('dashboard.admin', $renderArgs)->render();
    $samples[] = [
        'i' => $i,
        'used' => memory_get_usage(),
        'delta' => memory_get_usage() - $before,
        'peak' => memory_get_peak_usage(),
        'bytes' => strlen($html),
    ];
    unset($html);
}

foreach ($samples as $s) {
    printf(
        "  render %d: usage=%.2f MB delta=%+.3f MB peak=%.2f MB html=%dKB\n",
        $s['i'],
        $s['used'] / 1048576,
        $s['delta'] / 1048576,
        $s['peak'] / 1048576,
        (int) round($s['bytes'] / 1024)
    );
}

$usedFirst = $samples[0]['used'];
$usedLast = $samples[count($samples) - 1]['used'];
$driftBytes = $usedLast - $usedFirst;

// Una fuga real de PHP crece con cada render. Permitimos 2 MB de deriva por
// ruido de allocator/opcache entre 5 renders; por encima de eso hay algo que
// no se libera.
h_check(
    $driftBytes < 2 * 1048576,
    sprintf(
        'sin crecimiento entre renders consecutivos (deriva %+.2f MB en %d renders)',
        $driftBytes / 1048576,
        count($samples)
    ),
    sprintf(
        'LA MEMORIA CRECE entre renders: %+.2f MB tras %d renders',
        $driftBytes / 1048576,
        count($samples)
    )
);

$peakMb = memory_get_peak_usage() / 1048576;
h_check(
    $peakMb < 64,
    sprintf('el pico por request se mantiene bajo 64 MB (%.1f MB)', $peakMb),
    sprintf('el pico por request subió a %.1f MB', $peakMb)
);

// ─────────────────────────────────────────────────────────────────────
h_section('E. Evidencia externa de agotamiento de memoria');
// ─────────────────────────────────────────────────────────────────────

$logPath = storage_path('logs/laravel.log');
if (is_file($logPath)) {
    $hits = 0;
    $fh = fopen($logPath, 'r');
    if ($fh !== false) {
        while (($line = fgets($fh)) !== false) {
            if (str_contains($line, 'Allowed memory size')) {
                $hits++;
            }
        }
        fclose($fh);
    }
    h_check($hits === 0, 'laravel.log sin "Allowed memory size"', "laravel.log tiene {$hits} muertes por memoria");
} else {
    h_ok('laravel.log no existe todavía (nada que revisar)');
}

$memoryLimit = ini_get('memory_limit');
h_check($memoryLimit !== false && $memoryLimit !== '', "memory_limit legible ({$memoryLimit})", 'memory_limit ilegible');

// ─────────────────────────────────────────────────────────────────────
h_section('F. Invariantes del shape (no romper partials)');
// ─────────────────────────────────────────────────────────────────────

foreach (['media_editor', 'stats', 'mis_avisos', 'bg_jobs', 'active_sessions', 'storage_health'] as $k) {
    $b = $blocksWarm[$k] ?? null;
    h_check(
        is_array($b) && array_key_exists('data', $b) && array_key_exists('generated_at', $b) && array_key_exists('stale', $b),
        "bloque '{$k}' conserva el sobre {data,generated_at,stale}",
        "bloque '{$k}' perdió el sobre uniforme"
    );
}

echo "\n";
if ($failures === 0) {
    echo "\u{2713} Dashboard perf/memoria OK.\n";
    exit(0);
}
echo "\u{2717} {$failures} aserción(es) fallaron.\n";
exit(1);
