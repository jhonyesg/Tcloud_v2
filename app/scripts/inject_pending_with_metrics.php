<?php
/**
 * Inyecta pendientes al Redis consultando el endpoint /api/metrics/overview
 * del upstream para NO saturar. Decisión basada en 4 señales:
 *   1. queue.queued + queue.processing < uplink_pool_max * 2 (cabe en workers)
 *   2. ramdisk.pct < 80% (espacio para temp wavs)
 *   3. ram.pct < 85% (margen de RAM del sistema)
 *   4. Cola Redis local current < 140 (regulador de TCloud)
 *
 * USO:
 *   cd app && php scripts/inject_pending_with_metrics.php [max=200] [interval=30]
 *
 * No espera al tick. Despacha directo al Redis cola para acelerar.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\TimeFormat;
use App\Models\Transcription;
use App\Jobs\ConvertAndTranscribeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

$maxHardCap = (int) ($argv[1] ?? 200);
$intervalSec = (int) ($argv[2] ?? 30);
$rounds = (int) ($argv[3] ?? 3);

echo "Bogota start " . TimeFormat::bogota(now(), 'H:i:s') . " | max_cap={$maxHardCap} interval={$intervalSec}s rounds={$rounds}\n\n";

$upstreamBase = 'http://192.168.0.138:9000';

for ($round = 1; $round <= $rounds; $round++) {
    echo "─── Round {$round} ───\n";

    $resp = Http::timeout(5)->get($upstreamBase . '/api/metrics/overview');
    if (!$resp->ok()) {
        echo "  WARN upstream no respondio (HTTP " . $resp->status() . "). Saltando.\n";
        break;
    }
    $m = $resp->json();

    $queue       = $m['queue'] ?? [];
    $upstreamQueued      = (int) ($queue['queued'] ?? 0);
    $upstreamProcessing  = (int) ($queue['processing'] ?? 0);
    $upstreamInFlight    = $upstreamQueued + $upstreamProcessing;
    $workersActive       = (int) ($m['node']['workers'] ?? 0);
    $poolMax              = (int) ($m['pool']['max'] ?? 10);
    $capacity             = max(0, $poolMax * 2 - $upstreamInFlight); // margen de 2x

    $ramdisk       = $m['ramdisk'] ?? [];
    $ramdiskPct    = (float) ($ramdisk['pct'] ?? 0);
    $ramdiskOk     = (bool) ($ramdisk['ok'] ?? false);

    $ram           = $m['ram'] ?? [];
    $ramPct        = (float) ($ram['pct'] ?? 0);
    $swapUsedPct   = $ram['swap_total_gb'] > 0 ? (float) ($ram['swap_used_gb'] / $ram['swap_total_gb']) * 100 : 0;

    $redisQ = (int) Redis::llen('queues:transcription');
    $runway = max(0, 140 - $redisQ);

    $vramPct = (float) ($m['gpu']['vram_used_pct'] ?? 0);

    echo "  upstream.workers: {$workersActive}/{$poolMax}, in-flight: {$upstreamInFlight}, capacity: {$capacity}\n";
    echo "  ramdisk: " . number_format($ramdiskPct, 1) . "% used (" . ($ramdiskOk ? 'ok' : 'CRITICAL') . ")\n";
    echo "  ram:     " . number_format($ramPct, 1) . "% used, swap: " . number_format($swapUsedPct, 1) . "%\n";
    echo "  gpu:     " . number_format($vramPct, 1) . "% vram\n";
    echo "  redis_q: {$redisQ}/140 runway: {$runway}\n";

// Limites (el operador dijo: solo cola, RAM y ramdisk importan; swap se ignora)
    $reasons = [];
    if ($ramdiskPct > 80) { $reasons[] = "ramdisk {$ramdiskPct}% > 80%"; }
    if ($ramPct > 88) { $reasons[] = "ram {$ramPct}% > 88%"; }
    if ($vramPct > 85) { $reasons[] = "vram {$vramPct}% > 85%"; }
    if (!$ramdiskOk) { $reasons[] = "ramdisk ok=false"; }

    if ($reasons) {
        echo "  SKIP: " . implode(' | ', $reasons) . "\n";
        break;
    }

    $batchSize = min($maxHardCap, $capacity, $runway);
    if ($batchSize <= 0) {
        echo "  SKIP: capacity=" . ($capacity) . " runway=" . ($runway) . " (no hay hueco)\n";
        break;
    }

    // Drain
    $dispatched = 0;
    foreach (Transcription::where('created_at', '>=', '2026-09-14')
        ->where('state', 'pending')
        ->whereNull('job_id')
        ->whereNull('dispatched_at')
        ->orderBy('created_at', 'asc')
        ->limit($batchSize)
        ->get() as $tx) {
        $u = DB::table('transcriptions')->where('id', $tx->id)->whereNull('dispatched_at')
            ->update(['dispatched_at' => now()]);
        if (!$u) continue;
        ConvertAndTranscribeJob::dispatch($tx->file_id, true, 0);
        $dispatched++;
    }
    echo "  dispatched: {$dispatched} (meta era {$batchSize})\n";

    // Snapshot post-dispatch
    $postQ = (int) Redis::llen('queues:transcription');
    $stats = DB::table('transcriptions')
        ->selectRaw("
            COUNT(*) FILTER (WHERE state='pending' AND created_at >= '2026-09-14') AS pend_hoy,
            COUNT(*) FILTER (WHERE state='queued' AND created_at >= '2026-09-14') AS queued_hoy,
            COUNT(*) FILTER (WHERE state='processing' AND created_at >= '2026-09-14') AS proc_hoy,
            COUNT(*) FILTER (WHERE state='done' AND created_at >= '2026-09-14') AS done_hoy
        ")
        ->first();
    echo "  post: redis_q=" . $postQ . " | pend_hoy=" . ($stats->pend_hoy ?? 0)
       . " queued_hoy=" . ($stats->queued_hoy ?? 0) . " proc_hoy=" . ($stats->proc_hoy ?? 0)
       . " done_hoy=" . ($stats->done_hoy ?? 0) . "\n";

    if ($round < $rounds) {
        echo "  sleep {$intervalSec}s...\n\n";
        sleep($intervalSec);
    } else {
        echo "\n";
    }
}

echo "Bogota end " . TimeFormat::bogota(now(), 'H:i:s') . "\n";
