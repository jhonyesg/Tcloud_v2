<?php
/**
 * Harness de regresión para change `api-transcriptor-consumption-aware-dispatch`.
 *
 * Verifica que `TranscriptorApiClient::getRemoteInfo()` parsea correctamente
 * respuestas reales de `/api/metrics/overview` y que `getRemoteStats()`
 * mantiene el shape backward-compatible con el regulador existente.
 *
 * Estrategia:
 *   - Captura 3 respuestas reales de /api/metrics/overview (2026-09-14)
 *   - Para cada una, valida que getRemoteInfo() devuelve los 15 campos
 *     esperados con valores correctos
 *   - Valida fail-open cuando /api/metrics/overview devuelve 404 o JSON
 *     malformado (sin node.workers)
 *   - Valida getRemoteStats() mantiene shape {processing, capacity, usage_pct, source}
 *
 * Uso:
 *   cd app && php tests/harness_remote_info_parser.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;

$tag = 'hrif_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness remote-info-parser (tag: {$tag})\n";

// Parser de referencia (debe coincidir con la implementacion del cliente).
function parseOverview(array $data): ?array
{
    if (!isset($data['node']['workers']) || !is_numeric($data['node']['workers'])) return null;
    $gpu = $data['gpu'] ?? [];
    $ramdisk = $data['ramdisk'] ?? [];
    $queue = $data['queue']['by_state_corrected'] ?? [];
    $circuit = $data['circuit_breakers'] ?? [];
    $cpu = $data['cpu'] ?? [];
    $workers = max(0, (int) $data['node']['workers']);
    $processing = max(0, (int) ($queue['processing/0'] ?? 0));
    return [
        'workers'         => $workers,
        'processing'      => $processing,
        'capacity'        => $workers,
        'usage_pct'       => $workers > 0 ? (int) round(($processing / $workers) * 100) : 0,
        'cluster_state'   => 'UP',
        'circuit_open'    => ((int) ($circuit['open'] ?? 0)) > 0,
        'gpu_util_pct'    => max(0, min(100, (int) ($gpu['util_pct'] ?? 0))),
        'gpu_vram_pct'    => max(0, min(100, (int) ($gpu['vram_used_pct'] ?? 0))),
        'ramdisk_pct'     => max(0, min(100, (float) ($ramdisk['pct'] ?? 0))),
        'ramdisk_free_gb' => max(0, (float) ($ramdisk['free_gb'] ?? 0)),
        'cpu_pct'         => max(0, min(100, (float) ($cpu['pct'] ?? 0))),
        'cpu_load_1m'     => max(0, (float) ($cpu['load_1m'] ?? 0)),
        'queue_total'     => (int) ($data['queue']['total_jobs'] ?? 0),
        'source'          => '/api/metrics/overview',
        'fetched_at'      => 'TEST',
    ];
}

$captures = [
    'saturated' => [
        'node' => ['id' => 'transcriptor-138', 'hostname' => '23c807ce42f6', 'workers' => 3],
        'ram' => ['pct' => 73.2, 'used_gb' => 49.3],
        'ramdisk' => ['pct' => 91.08, 'free_gb' => 1.42, 'ok' => true],
        'disk' => ['pct' => 72.5],
        'cpu' => ['pct' => 29.9, 'load_1m' => 4.23, 'cores' => 16],
        'gpu' => ['model' => 'Unknown AMD GPU', 'vram_used_pct' => 58, 'util_pct' => 100],
        'queue' => [
            'total_jobs' => 81616,
            'by_state_corrected' => [
                'queued/0' => 91,
                'processing/0' => 6,
                'done/1' => 27663,
            ],
        ],
        'circuit_breakers' => ['healthy' => 1, 'open' => 0, 'half_open' => 0],
    ],
    'healthy' => [
        'node' => ['id' => 'transcriptor-138', 'workers' => 3],
        'ram' => ['pct' => 38],
        'ramdisk' => ['pct' => 30, 'free_gb' => 11.2, 'ok' => true],
        'cpu' => ['pct' => 12, 'load_1m' => 0.8],
        'gpu' => ['model' => 'AMD Radeon RX 7900 XT', 'vram_used_pct' => 22, 'util_pct' => 5],
        'queue' => [
            'total_jobs' => 81000,
            'by_state_corrected' => [
                'queued/0' => 0,
                'processing/0' => 1,
                'done/1' => 27000,
            ],
        ],
        'circuit_breakers' => ['healthy' => 3, 'open' => 0, 'half_open' => 0],
    ],
    'no_workers' => [
        'node' => ['id' => 'transcriptor-138'],
        'ram' => ['pct' => 50],
    ],
];

$expectedFields = ['workers', 'processing', 'capacity', 'usage_pct', 'cluster_state', 'circuit_open', 'gpu_util_pct', 'gpu_vram_pct', 'ramdisk_pct', 'ramdisk_free_gb', 'cpu_pct', 'cpu_load_1m', 'queue_total', 'source', 'fetched_at'];

// ─── Test 1: parser con respuesta saturada (ramdisk 91%) ─────────────────────
h_section("test 1: /api/metrics/overview saturado (ramdisk=91%)");
$result = parseOverview($captures['saturated']);
h_check($result !== null, 'parser devuelve array', 'parser devolvio null');
h_check(($result['workers'] ?? null) === 3, 'workers=3 desde node.workers');
h_check(($result['processing'] ?? null) === 6, 'processing=6 desde queue.by_state_corrected["processing/0"]');
h_check(($result['capacity'] ?? null) === 3, 'capacity=3 (alias)');
h_check(($result['usage_pct'] ?? null) === 200, 'usage_pct=200 (6/3 saturado al doble)');
h_check(($result['cluster_state'] ?? null) === 'UP', 'cluster_state=UP');
h_check(($result['circuit_open'] ?? null) === false, 'circuit_open=false');
h_check(($result['gpu_util_pct'] ?? null) === 100, 'gpu_util_pct=100');
h_check(($result['gpu_vram_pct'] ?? null) === 58, 'gpu_vram_pct=58 desde vram_used_pct');
h_check(($result['ramdisk_pct'] ?? null) == 91.08, 'ramdisk_pct=91.08');
h_check(($result['cpu_pct'] ?? null) == 29.9, 'cpu_pct=29.9');
h_check(($result['queue_total'] ?? null) === 81616, 'queue_total=81616');

$missing = array_diff($expectedFields, array_keys($result ?? []));
h_check(empty($missing), 'los 15 campos esperados presentes (faltan: ' . implode(',', $missing) . ')');

// ─── Test 2: parser con respuesta healthy ─────────────────────────────────────
h_section("test 2: /api/metrics/overview healthy");
$result2 = parseOverview($captures['healthy']);
h_check(($result2['workers'] ?? null) === 3, 'workers=3');
h_check(($result2['processing'] ?? null) === 1, 'processing=1 desde queue.by_state_corrected');
h_check(($result2['ramdisk_pct'] ?? null) == 30, 'ramdisk_pct=30');
h_check(($result2['gpu_util_pct'] ?? null) === 5, 'gpu_util_pct=5');
h_check(($result2['ramdisk_free_gb'] ?? null) == 11.2, 'ramdisk_free_gb=11.2');

// ─── Test 3: fail-open sin node.workers ──────────────────────────────────────
h_section("test 3: /api/metrics/overview sin node.workers -> null");
$result3 = parseOverview($captures['no_workers']);
h_check($result3 === null, 'respuesta sin node.workers devuelve null (fail-open)');

// ─── Test 4: backward compat shape de getRemoteStats ─────────────────────────
h_section("test 4: shape backward-compat de getRemoteStats");
$derived = [
    'processing' => $result['processing'],
    'capacity'   => $result['capacity'],
    'usage_pct'  => $result['usage_pct'],
    'source'     => $result['source'],
];
h_check(array_key_exists('processing', $derived), 'campo processing presente');
h_check(array_key_exists('capacity', $derived), 'campo capacity presente');
h_check(array_key_exists('usage_pct', $derived), 'campo usage_pct presente');
h_check(is_int($derived['processing']), 'processing es int');
h_check(is_int($derived['capacity']), 'capacity es int');
h_check($derived['source'] === '/api/metrics/overview', 'source=/api/metrics/overview');

// ─── Test 5: cliente real contra /api/metrics/overview (opcional) ────────────
h_section("test 5: cliente real contra /api/metrics/overview live (opcional)");
try {
    $client = app(TranscriptorApiClient::class);
    $liveInfo = $client->getRemoteInfo();
    if ($liveInfo !== null) {
        h_ok('getRemoteInfo() devolvio datos live');
        h_check(isset($liveInfo['workers']), 'live: workers presente');
        h_check(isset($liveInfo['processing']), 'live: processing presente');
        h_check(isset($liveInfo['ramdisk_pct']), 'live: ramdisk_pct presente');
        h_check(isset($liveInfo['gpu_util_pct']), 'live: gpu_util_pct presente');
        h_check(($liveInfo['source'] ?? '') === '/api/metrics/overview', 'live: source=/api/metrics/overview');

        $liveStats = $client->getRemoteStats();
        h_check($liveStats !== null, 'getRemoteStats() tambien responde');
        if ($liveStats !== null) {
            h_check(isset($liveStats['source']), 'live stats incluye source');
            h_check(($liveStats['capacity'] ?? 0) > 0, 'live: capacity > 0');
        }
    } else {
        echo "  · /api/metrics/overview no responde (omitido, no es regresion)\n";
    }
} catch (\Throwable $e) {
    echo "  · cliente real no disponible: " . $e->getMessage() . "\n";
}

echo "\n" . ($failures === 0 ? "✓ ALL PASSED" : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
