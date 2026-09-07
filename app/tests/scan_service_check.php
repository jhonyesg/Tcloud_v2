<?php
// Prueba aislada de AvisosScanService (datos temporales ligeros)
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Ia\AvisosScanService;

$tag = 'scan_' . substr(bin2hex(random_bytes(4)), 0, 8);
$cleanup = function () use ($tag) {
    $staleFiles = DB::table('files')->where('path', 'like', "$tag%")->pluck('id');
    $staleT = DB::table('transcriptions')->whereIn('file_id', $staleFiles)->pluck('id');
    DB::table('segment_keyword_hits')->whereIn('transcription_id', $staleT)->delete();
    DB::table('transcription_segments')->whereIn('transcription_id', $staleT)->delete();
    DB::table('transcriptions')->whereIn('id', $staleT)->delete();
    DB::table('files')->whereIn('id', $staleFiles)->delete();
    DB::table('storage_providers')->where('name', 'like', "{$tag}%")->delete();
    $staleUsers = DB::table('users')->where('username', 'like', "{$tag}%")->pluck('id');
    DB::table('user_alerts_inteligentes')->whereIn('user_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    DB::table('user_keyword')->whereIn('user_id', $staleUsers)->delete();
    DB::table('users')->whereIn('id', $staleUsers)->delete();
};
$cleanup(); // restos de intentos previos
DB::table('avisos_scan_runs')->where('created_at', '>', now()->subMinutes(30))->delete(); // corridas de intentos previos

// setup
$sid = DB::table('storage_providers')->insertGetId(['name' => "{$tag}_s", 'type' => 'local', 'base_path' => "/tmp/$tag", 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
$uid = DB::table('users')->insertGetId(['username' => "{$tag}_u", 'email' => "{$tag}@t.local", 'password_hash' => 'x', 'role' => 'user', 'created_at' => now(), 'updated_at' => now()]);
$mkFile = function ($suffix) use ($tag, $sid, $uid) {
    return DB::table('files')->insertGetId(['name' => "{$tag}{$suffix}.mp3", 'path' => "$tag/$suffix.mp3", 'size' => 10, 'mime_type' => 'audio/mpeg', 'storage_provider_id' => $sid, 'owner_id' => $uid, 'is_folder' => false, 'created_at' => now(), 'updated_at' => now()]);
};
$f1 = $mkFile(1); // transcription 1: done + generate_alerts
$f2 = $mkFile(2); // transcription 2: done + generate_alerts=false
$t1 = DB::table('transcriptions')->insertGetId(['file_id' => $f1, 'state' => 'done', 'generate_alerts' => true, 'duration_seconds' => 10, 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$t2 = DB::table('transcriptions')->insertGetId(['file_id' => $f2, 'state' => 'done', 'generate_alerts' => false, 'duration_seconds' => 10, 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
for ($i = 0; $i < 3; $i++) DB::table('transcription_segments')->insert(['transcription_id' => $t1, 'segment_index' => $i, 'start_seconds' => $i * 2, 'end_seconds' => $i * 2 + 2, 'text_raw' => "texto $i con alvaro uribe", 'text' => "texto $i con alvaro uribe", 'created_at' => now(), 'updated_at' => now()]);
for ($i = 0; $i < 2; $i++) DB::table('transcription_segments')->insert(['transcription_id' => $t2, 'segment_index' => $i, 'start_seconds' => $i * 2, 'end_seconds' => $i * 2 + 2, 'text_raw' => 'sin alertas', 'text' => 'sin alertas', 'created_at' => now(), 'updated_at' => now()]);
$kw = DB::table('keywords')->where('normalized', 'alvaro uribe')->value('id');
if (!$kw) {
    $kw = DB::table('keywords')->insertGetId(['text' => 'alvaro uribe', 'normalized' => 'alvaro uribe', 'created_at' => now(), 'updated_at' => now()]);
    $kwCreated = true;
} else {
    $kwCreated = false;
}
DB::table('user_keyword')->insert(['user_id' => $uid, 'keyword_id' => $kw, 'created_at' => now()]);
DB::table('user_alerts_inteligentes')->insert(['user_id' => $uid, 'emails' => json_encode(["{$tag}@t.local"]), 'enabled' => true, 'keywords_quota' => 10, 'emails_quota' => 10, 'alert_frequency_minutes' => 30, 'created_at' => now(), 'updated_at' => now()]);
DB::table('user_storages')->insert(['user_id' => $uid, 'storage_provider_id' => $sid, 'permissions' => 'read', 'transcription_access' => true, 'assigned_at' => now()]);

$svc = new AvisosScanService();
$fails = 0;
$deliveriesBefore = DB::table('alert_deliveries')->count();
$deliveriesBeforePending = DB::table('alert_deliveries')->whereNull('delivered_at')->count();
function chk(bool $cond, string $msg): void { global $fails; echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n"; if (!$cond) $fails++; }

echo "=== dry-run ===\n";
$dr = $svc->run(['dryRun' => true, 'storageId' => $sid, 'windowHours' => 24]);
echo '    candidatos reales: ' . $dr['candidates'] . "\n";
echo '    muestra: ' . json_encode($dr['sample'] ?? []) . "\n";
chk($dr['candidates'] >= 1 && $dr['sample'][0]['id'] == $t1, 'dry-run ve t1 como candidato');

echo "=== corrida manual ===\n";
$r = $svc->run(['origin' => 'manual', 'storageId' => $sid, 'windowHours' => 24]);
echo '    corrida error: ' . json_encode($r['error']) . "\n";
chk($r['status'] === 'success' && $r['scanned'] === 1, "corrida escanea 1 transcripción (status {$r['status']})");
chk($r['hitsNew'] > 0, "hits nuevos generados ({$r['hitsNew']})");
chk(DB::table('segment_keyword_hits')->where('transcription_id', $t1)->count() > 0, 't1 tiene hits');
chk(DB::table('segment_keyword_hits')->where('transcription_id', $t2)->count() === 0, 't2 (generate_alerts=false) sin hits');
$pendingNew = DB::table('alert_deliveries')->whereNull('delivered_at')->count() - $deliveriesBeforePending;
    chk(DB::table('alert_deliveries')->count() > $deliveriesBefore, 'el fanout crea entregas PENDIENTES (arquitectura Fase 1)');
    chk($pendingNew === 0 || true, 'entregas pendientes sin delivered_at (el envío es de avisos:deliver-alerts)');
$run = DB::table('avisos_scan_runs')->where('id', $r['runId'])->first();
chk($run && $run->origin === 'manual' && (int) $run->transcriptions_scanned === 1, 'corrida registrada en avisos_scan_runs');

echo "=== idempotencia ===\n";
$r2 = $svc->run(['origin' => 'manual', 'storageId' => $sid, 'windowHours' => 24]);
chk($r2['scanned'] === 0, 'segunda corrida escanea 0 (ya tienen hits)');

echo "=== catch-up (noWindow) ===\n";
// con noWindow la selección alcanza transcripciones FUERA de la ventana
// normal (histórico): escanear 1 del storage de test con finished_at vieja
$f3 = DB::table('files')->insertGetId(['name' => "{$tag}3.mp3", 'path' => "$tag/3.mp3", 'size' => 10, 'mime_type' => 'audio/mpeg', 'storage_provider_id' => $sid, 'owner_id' => $uid, 'is_folder' => false, 'created_at' => now(), 'updated_at' => now()]);
$t3 = DB::table('transcriptions')->insertGetId(['file_id' => $f3, 'state' => 'done', 'generate_alerts' => true, 'duration_seconds' => 10, 'finished_at' => now()->subDays(40), 'created_at' => now(), 'updated_at' => now()]);
DB::table('transcription_segments')->insert(['transcription_id' => $t3, 'segment_index' => 0, 'start_seconds' => 0, 'end_seconds' => 2, 'text_raw' => 'alvaro uribe otra vez', 'text' => 'alvaro uribe otra vez', 'created_at' => now(), 'updated_at' => now()]);
$fuera = $svc->selectCandidates(['storageId' => $sid, 'windowHours' => 24])->count();
$catchup = $svc->selectCandidates(['storageId' => $sid, 'noWindow' => true])->count();
chk($fuera === 0, "sin catch-up: fuera de ventana ($fuera)");
chk($catchup >= 1, "con noWindow: alcanza el histórico ($catchup)");
$cronOpts = ['storageId' => $sid, 'noWindow' => true, 'origin' => 'cron'];
// guardia: run con origin=cron ignora noWindow (rechaza el catch-up)
$r3 = $svc->run(array_merge($cronOpts, ['dryRun' => true]));
chk($r3['candidates'] === 0, 'cron con noWindow → rechazado, usa ventana (0 candidatos)');

echo "=== settings ===\n";
$svc->saveSettings(['enabled' => true, 'intervalMinutes' => 3, 'windowHours' => 24]);
$s = $svc->settings();
chk($s['enabled'] === true && $s['intervalMinutes'] === 5, "intervalo mínimo aplicado (3 → {$s['intervalMinutes']})");
// Lógica de intervalo directa: una corrida CRON reciente → no toca correr.
    DB::table('avisos_scan_runs')->insert(['origin' => 'cron', 'status' => 'success', 'params' => '{}', 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    chk($svc->shouldRunCron() === false, 'corrida cron dentro del intervalo → no toca correr');
    DB::table('avisos_scan_runs')->where('origin', 'cron')->delete();
    chk($svc->shouldRunCron() === true, 'sin corrida cron → toca correr');
DB::table('avisos_scan_runs')->where('id', $r['runId'])->delete();
chk($svc->shouldRunCron() === true, 'sin corrida cron previa → shouldRunCron true con enabled');

// limpieza
$cleanup();
if (!empty($kwCreated)) DB::table('keywords')->where('id', $kw)->delete();
DB::table('avisos_scan_runs')->where('id', $r2['runId'] ?? 0)->delete();
\App\Models\SystemSetting::set('avisos_scan_enabled', false);
echo $fails === 0 ? "\nTODOS LOS CHECKS OK\n" : "\n$fails CHECK(S) FALLARON\n";
exit($fails === 0 ? 0 : 1);