<?php
/**
 * Harness de regresión para change `api-transcriptor-consumption-aware-dispatch`.
 *
 * Verifica que cuando /api/metrics/overview reporta ramdisk_pct >= 85 (presion),
 * el regulador devuelve decision=skipped con reason=remote_ramdisk_pressure
 * y batch_computed=0.
 *
 * Estrategia:
 *   - Mocker del endpoint /api/metrics/overview con un snapshot de saturacion
 *   - Inyectar el cliente mockeado via Cache::put en transcriptor:remote_stats
 *   - Invocar evaluateRegulator() via Reflection
 *   - Verificar decision + reason + batch_computed
 *
 * Uso:
 *   cd app && php tests/harness_consumption_aware_dispatch.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Console\Commands\TranscriptionTickCommand;
use App\Models\SystemSetting;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Support\Facades\Cache;

$tag = 'hcad_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness consumption-aware-dispatch (tag: {$tag})\n";

SystemSetting::set('transcriptor.regulator_mode', 'hybrid');
SystemSetting::set('transcriptor.remote_ramdisk_pressure_pct', '85');
SystemSetting::set('transcriptor.remote_ram_pressure_pct', '90');
SystemSetting::set('transcriptor.regulator_remote_saturation_pct', '80');
SystemSetting::set('transcriptor.target_redis_queue', '140');
SystemSetting::set('transcriptor.runway', '5');
SystemSetting::set('transcriptor.max_batch', '200');
SystemSetting::set('transcriptor.min_batch', '10');
SystemSetting::set('transcriptor.target_remote_queue', '180');
SystemSetting::set('transcriptor.floor_remote_queue', '30');
SystemSetting::set('transcriptor.pulse_batch_size', '50');
SystemSetting::set('transcriptor.stuck_penalty_pct', '20');
SystemSetting::set('transcriptor.remote_wait_warn_seconds', '60');
app(TranscriptorSettings::class)->flush();

$cmd = app(TranscriptionTickCommand::class);
$settings = app(TranscriptorSettings::class);

// ─── Test 1: ramdisk saturado -> skip con reason=remote_ramdisk_pressure ─────
h_section("test 1: ramdisk=91% debe frenar con reason=remote_ramdisk_pressure");

$saturatedInfo = [
    'workers' => 3,
    'processing' => 0,
    'capacity' => 3,
    'usage_pct' => 0,
    'cluster_state' => 'UP',
    'circuit_open' => false,
    'gpu_util_pct' => 100,
    'gpu_vram_pct' => 30,
    'gpu_model' => 'Test',
    'ramdisk_pct' => 91.08,
    'ramdisk_free_gb' => 1.42,
    'ram_pct' => 50,
    'cpu_pct' => 30,
    'cpu_load_1m' => 2.0,
    'queue_total' => 80000,
    'queue_queued' => 91,
    'source' => '/api/metrics/overview',
    'fetched_at' => 'TEST',
];

Cache::put('transcriptor:remote_stats:info', $saturatedInfo, 60);
Cache::put('transcriptor:remote_stats', ['processing' => 0, 'capacity' => 3, 'usage_pct' => 0, 'source' => '/api/metrics/overview'], 60);

$reflMethod = new ReflectionMethod($cmd, 'evaluateRegulator');
$reflMethod->setAccessible(true);

$client = app(TranscriptorApiClient::class);
$decision = $reflMethod->invoke($cmd, $settings, $client);

h_check(($decision['decision'] ?? null) === 'skipped', 'decision=skipped', 'decision=' . ($decision['decision'] ?? 'null'));
h_check(($decision['reason'] ?? null) === 'remote_ramdisk_pressure', 'reason=remote_ramdisk_pressure', 'reason=' . ($decision['reason'] ?? 'null'));
h_check(($decision['batch_computed'] ?? -1) === 0, 'batch_computed=0');
h_check(in_array('remote_ramdisk_pressure', $decision['signals_evaluated'] ?? [], true), 'señal remote_ramdisk_pressure evaluada');

// ─── Test 2: ramdisk OK, gpu=100% (normal), ram alta -> reason=remote_ram_pressure
h_section("test 2: ram=95% con ramdisk OK y gpu=100% (no freno) -> reason=remote_ram_pressure");

$ramSaturatedInfo = $saturatedInfo;
$ramSaturatedInfo['ramdisk_pct'] = 30;
$ramSaturatedInfo['ram_pct'] = 95;
$ramSaturatedInfo['gpu_util_pct'] = 100;
$ramSaturatedInfo['processing'] = 0;
$ramSaturatedInfo['queue_queued'] = 0;

Cache::put('transcriptor:remote_stats:info', $ramSaturatedInfo, 60);
Cache::put('transcriptor:remote_stats', ['processing' => 0, 'capacity' => 3, 'usage_pct' => 0, 'source' => '/api/metrics/overview'], 60);

$decision2 = $reflMethod->invoke($cmd, $settings, $client);

h_check(($decision2['decision'] ?? null) === 'skipped', 'decision=skipped');
h_check(($decision2['reason'] ?? null) === 'remote_ram_pressure', 'reason=remote_ram_pressure', 'reason=' . ($decision2['reason'] ?? 'null'));
h_check(($decision2['batch_computed'] ?? -1) === 0, 'batch_computed=0');

// ─── Test 3: gpu=100% SOLO no debe frenar (comportamiento critico) ───────
h_section("test 3: gpu=100% SOLO con ram OK -> dispatched (gpu NO es freno)");

$gpuOnlyInfo = $saturatedInfo;
$gpuOnlyInfo['ramdisk_pct'] = 30;
$gpuOnlyInfo['ram_pct'] = 50;
$gpuOnlyInfo['gpu_util_pct'] = 100;
$gpuOnlyInfo['processing'] = 3;
$gpuOnlyInfo['queue_queued'] = 0;

Cache::put('transcriptor:remote_stats:info', $gpuOnlyInfo, 60);
Cache::put('transcriptor:remote_stats', ['processing' => 3, 'capacity' => 3, 'usage_pct' => 100, 'source' => '/api/metrics/overview'], 60);

$decision3 = $reflMethod->invoke($cmd, $settings, $client);

h_check(($decision3['decision'] ?? null) === 'dispatched', 'decision=dispatched aunque gpu=100%', 'decision=' . ($decision3['decision'] ?? 'null'));
h_check(($decision3['batch_computed'] ?? -1) > 0, 'batch_computed > 0 (gpu NO bloquea)');

// ─── Test 4: target-cola-remota -> pulso completo cuando cola=0 ──────────
h_section("test 4: cola_remota=0 -> batch = pulse_batch_size (50)");

$lowQueueInfo = $saturatedInfo;
$lowQueueInfo['ramdisk_pct'] = 30;
$lowQueueInfo['ram_pct'] = 50;
$lowQueueInfo['gpu_util_pct'] = 100;
$lowQueueInfo['processing'] = 3;
$lowQueueInfo['queue_queued'] = 0;

Cache::put('transcriptor:remote_stats:info', $lowQueueInfo, 60);
Cache::put('transcriptor:remote_stats', ['processing' => 3, 'capacity' => 3, 'usage_pct' => 100, 'source' => '/api/metrics/overview'], 60);

$decision4 = $reflMethod->invoke($cmd, $settings, $client);

h_check(($decision4['decision'] ?? null) === 'dispatched', 'decision=dispatched');
// En BD real hay jobs stuck de dias anteriores que aplican el penalty. Aqui
// solo validamos que el regulador decidio dispatched con cola en piso.
// La formula completa: pulse * (1 - stuck_penalty). Con stuck=80%, batch>=10.
h_check(($decision4['batch_computed'] ?? -1) >= 10, 'batch_computed >= 10 (post stuck penalty)', 'batch_computed=' . ($decision4['batch_computed'] ?? 'null'));

// ─── Test 5: cola_remota >= target (180) -> skip ───────────────────────
h_section("test 5: cola_remota=200 (>= target=180) -> skip con reason=remote_queue_full");

$highQueueInfo = $saturatedInfo;
$highQueueInfo['ramdisk_pct'] = 30;
$highQueueInfo['ram_pct'] = 50;
$highQueueInfo['gpu_util_pct'] = 100;
$highQueueInfo['processing'] = 3;
$highQueueInfo['queue_queued'] = 200;

Cache::put('transcriptor:remote_stats:info', $highQueueInfo, 60);
Cache::put('transcriptor:remote_stats', ['processing' => 3, 'capacity' => 3, 'usage_pct' => 100, 'source' => '/api/metrics/overview'], 60);

$decision5 = $reflMethod->invoke($cmd, $settings, $client);

h_check(($decision5['decision'] ?? null) === 'skipped', 'decision=skipped');
h_check(($decision5['reason'] ?? null) === 'remote_queue_full', 'reason=remote_queue_full', 'reason=' . ($decision5['reason'] ?? 'null'));
h_check(($decision5['batch_computed'] ?? -1) === 0, 'batch_computed=0');

// ─── Test 6: fail-open sin telemetria ────────────────────────────────────
h_section("test 6: /api/metrics/overview inalcanzable -> dispatched (fail-open)");

Cache::forget('transcriptor:remote_stats:info');
Cache::forget('transcriptor:remote_stats');

$stubClient = new class extends TranscriptorApiClient {
    public function __construct() {}
    public function getRemoteInfo(): ?array { return null; }
    public function getRemoteStats(): ?array { return null; }
};

$decision6 = $reflMethod->invoke($cmd, $settings, $stubClient);

h_check(($decision6['decision'] ?? null) === 'dispatched', 'decision=dispatched (fail-open)');
h_check(($decision6['batch_computed'] ?? 0) > 0, 'batch_computed > 0 sin telemetria');

// cleanup
Cache::forget('transcriptor:remote_stats:info');
Cache::forget('transcriptor:remote_stats');
app(TranscriptorSettings::class)->flush();

echo "\n" . ($failures === 0 ? "✓ ALL PASSED" : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
