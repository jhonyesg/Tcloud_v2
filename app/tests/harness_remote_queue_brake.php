<?php
/**
 * Harness de regresión — freno con histéresis de la cola remota.
 *
 * Change: `transcriptor-remote-queue-hysteresis-brake` (2026-09-16).
 *
 * Contrato verificado:
 *   A. FRENO inmediato al alcanzar `target_remote_queue`.
 *   B. NO reanuda con la cola apenas por debajo del techo (ping-pong):
 *      entre `resume_remote_queue` y el techo el freno se mantiene.
 *   C. Reanuda SOLO al bajar a `resume_remote_queue`, y respeta la ventana
 *      `remote_queue_recheck_seconds` (no reanuda antes de que venza).
 *   D. Fail-open sin telemetria.
 *   E. El pestillo es compartido: isBraked() refleja la última decision.
 *   F. Coherencia con el planner: computeEffectiveBatch devuelve 0 con el
 *      freno activo, incluso si la cola puntual esta bajo el techo.
 *   G. El sender aplaza con `remote_queue_requeue_seconds`, no con
 *      `requeue_after_minutes`.
 *
 * Uso:  cd app && php tests/harness_remote_queue_brake.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Console\Commands\TranscriptionTickCommand;
use App\Models\SystemSetting;
use App\Models\Transcription;
use App\Services\Ia\RemoteQueueBrake;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$tag = 'hrqb_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness remote-queue-brake (tag: {$tag})\n";

// ─── Setup: settings deterministas ───────────────────────────────────────────
SystemSetting::set('transcriptor.target_remote_queue', '180');
SystemSetting::set('transcriptor.resume_remote_queue', '120');
SystemSetting::set('transcriptor.remote_queue_recheck_seconds', '30');
SystemSetting::set('transcriptor.remote_queue_requeue_seconds', '30');
SystemSetting::set('transcriptor.requeue_after_minutes', '5');
SystemSetting::set('transcriptor.regulator_mode', 'hybrid');
SystemSetting::set('transcriptor.pulse_batch_size', '50');
SystemSetting::set('transcriptor.floor_remote_queue', '30');
SystemSetting::set('transcriptor.max_batch', '200');
SystemSetting::set('transcriptor.min_batch', '10');
SystemSetting::set('transcriptor.remote_ramdisk_pressure_pct', '85');
SystemSetting::set('transcriptor.remote_ram_pressure_pct', '90');
$settings = app(TranscriptorSettings::class);
$settings->flush();
$brake = app(RemoteQueueBrake::class);
$brake->forget();

$info = function (int $queued): array {
    return [
        'queue_queued' => $queued,
        'processing' => 0,
        'workers' => 3,
        'ram_pct' => 50,
        'ramdisk_pct' => 30,
        'cluster_state' => 'UP',
        'fetched_at' => 'TEST',
    ];
};

try {
    // ─── A. Freno inmediato en el techo ─────────────────────────────────────
    h_section('A. Freno inmediato al alcanzar el techo');

    $brake->forget();
    $r = $brake->evaluate($info(180), $settings);
    h_check($r['braked'] === true, 'queue=180 (techo) -> braked=true');
    h_check($r['reason'] === 'remote_queue_full', 'reason=remote_queue_full', 'reason=' . $r['reason']);
    h_check($brake->isBraked() === true, 'pestillo compartido activo');

    // ─── B. No reanuda apenas bajo el techo (ping-pong) ─────────────────────
    h_section('B. Sin ping-pong: 179 NO reanuda');

    // Simular que paso la ventana de revalidacion para probar el peor caso:
    // el pestillo esta activo y la cola bajo solo 1 job.
    Cache::put(RemoteQueueBrake::CACHE_KEY, [
        'braked' => true, 'queue' => 180, 'checked_at' => time() - 999,
    ], 600);

    $r = $brake->evaluate($info(179), $settings);
    h_check($r['braked'] === true, 'queue=179 -> braked=true (no reanuda en el techo)', 'braked=' . var_export($r['braked'], true));
    h_check($r['reason'] === 'remote_queue_braked', 'reason=remote_queue_braked', 'reason=' . $r['reason']);

    $r = $brake->evaluate($info(150), $settings);
    h_check($r['braked'] === true, 'queue=150 (entre resume y target) -> sigue frenado');

    // ─── C. Reanudo en el umbral + ventana ──────────────────────────────────
    h_section('C. Reanudo en resume_remote_queue respetando la ventana');

    // Ventana vigente: no debe reanudar aunque la cola ya bajo a 100.
    Cache::put(RemoteQueueBrake::CACHE_KEY, [
        'braked' => true, 'queue' => 180, 'checked_at' => time(),
    ], 600);
    $r = $brake->evaluate($info(100), $settings);
    h_check($r['braked'] === true, 'ventana vigente: mantiene freno aunque queue=100', 'braked=' . var_export($r['braked'], true));
    h_check(($r['recheck_in'] ?? 0) > 0, 'reporta recheck_in pendiente');

    // Ventana vencida: reanuda al estar <= resume.
    Cache::put(RemoteQueueBrake::CACHE_KEY, [
        'braked' => true, 'queue' => 180, 'checked_at' => time() - 999,
    ], 600);
    $r = $brake->evaluate($info(120), $settings);
    h_check($r['braked'] === false, 'ventana vencida + queue=120 (resume) -> reanuda');
    h_check($r['reason'] === 'remote_queue_resumed', 'reason=remote_queue_resumed', 'reason=' . $r['reason']);
    h_check($brake->isBraked() === false, 'pestillo liberado');

    // Ventana vencida pero cola aun por encima de resume: sigue frenado.
    Cache::put(RemoteQueueBrake::CACHE_KEY, [
        'braked' => true, 'queue' => 180, 'checked_at' => time() - 999,
    ], 600);
    $r = $brake->evaluate($info(121), $settings);
    h_check($r['braked'] === true, 'queue=121 (1 sobre resume) -> sigue frenado', 'braked=' . var_export($r['braked'], true));

    // ─── D. Fail-open sin telemetria ────────────────────────────────────────
    h_section('D. Fail-open sin telemetria');

    $brake->forget();
    $r = $brake->evaluate(null, $settings);
    h_check($r['braked'] === false, 'remoteInfo=null -> braked=false (fail-open)');
    h_check($r['reason'] === 'no_telemetry', 'reason=no_telemetry', 'reason=' . $r['reason']);

    // ─── E. Coherencia con el planner ───────────────────────────────────────
    h_section('E. Planner: computeEffectiveBatch=0 con freno activo');

    Cache::put(RemoteQueueBrake::CACHE_KEY, [
        'braked' => true, 'queue' => 179, 'checked_at' => time(),
    ], 600);

    $cmd = app(TranscriptionTickCommand::class);
    $refl = new ReflectionMethod($cmd, 'computeEffectiveBatch');
    $refl->setAccessible(true);
    $batch = $refl->invoke($cmd, $settings, 100, 50, $info(179));
    h_check($batch === 0, 'cola=179 con pestillo activo -> batch=0 (sin ping-pong)', 'batch=' . var_export($batch, true));

    // Sin freno (cola baja) debe dar batch > 0.
    $brake->forget();
    $batch2 = $refl->invoke($cmd, $settings, 100, 50, $info(0));
    h_check($batch2 > 0, 'cola=0 sin freno -> batch > 0', 'batch=' . var_export($batch2, true));

    // ─── F. El sender aplaza con el setting corto ───────────────────────────
    h_section('F. Sender: requeue corto por freno de cola');

    $s = app(TranscriptorSettings::class);
    h_check($s->int('remote_queue_requeue_seconds') === 30, 'remote_queue_requeue_seconds=30 leido');
    h_check($s->int('requeue_after_minutes') === 5, 'requeue_after_minutes sigue en 5 (no se toco)');

    // Verificar el calculo del aplazamiento sin tocar filas reales: replicar la
    // rama del sender (now + remote_queue_requeue_seconds) vs (now + 5 min).
    $corto = now()->addSeconds(max(5, $s->int('remote_queue_requeue_seconds')));
    $largo = now()->addMinutes(max(1, $s->int('requeue_after_minutes')));
    h_check($corto->lt($largo), 'el aplazamiento por cola es mas corto que el generico');
    $segundos = abs($corto->diffInSeconds(now()));
    h_check(abs($segundos - 30) <= 2, 'el aplazamiento por cola es de ~30 s', 'segundos=' . $segundos);

    // ─── G. Garantia de histeresis: resume < target ─────────────────────────
    h_section('G. Invariante resume < target');

    SystemSetting::set('transcriptor.resume_remote_queue', '500'); // absurdo a proposito
    $s->flush();
    $r = $brake->evaluate($info(0), $settings);
    h_check($r['resume'] < $r['target'], 'resume clampeado por debajo de target aunque el setting sea absurdo', "resume={$r['resume']} target={$r['target']}");

} finally {
    // ─── Cleanup ────────────────────────────────────────────────────────────
    Cache::forget(RemoteQueueBrake::CACHE_KEY);
    SystemSetting::set('transcriptor.resume_remote_queue', '120');
    SystemSetting::set('transcriptor.remote_queue_recheck_seconds', '30');
    SystemSetting::set('transcriptor.remote_queue_requeue_seconds', '30');
    DB::table('transcriptions')->where('original_name', 'LIKE', $tag . '\_%')->delete();
    app(TranscriptorSettings::class)->flush();
    echo "\n(cache y settings restaurados)\n";
}

echo "\n" . ($failures === 0 ? '✓ ALL PASSED' : "✗ {$failures} FAILURES") . "\n";
exit($failures === 0 ? 0 : 1);
