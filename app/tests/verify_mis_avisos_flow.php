<?php
/**
 * 4.1 simulado — flujo crítico completo: scan → deliveries → scheduler → job en cola.
 * Uso: php -d xdebug.mode=off tests/verify_mis_avisos_flow.php
 */
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "A: bootstrap OK\n";
$fileId = DB::table('files as f')
    ->leftJoin('transcriptions as t', 't.file_id', '=', 'f.id')
    ->where('f.storage_provider_id', 5)
    ->whereNull('t.id')
    ->orderBy('f.id')
    ->value('f.id');
echo "B: file={$fileId}\n";

$kwNorm = 'verify41_' . substr(bin2hex(random_bytes(3)), 0, 5) . 'palabra';
$keywordId = DB::table('keywords')->where('normalized', $kwNorm)->value('id');
if (!$keywordId) {
    $keywordId = DB::table('keywords')->insertGetId(['text' => str_replace('_', ' ', $kwNorm), 'normalized' => $kwNorm, 'created_at' => now(), 'updated_at' => now()]);
}
echo "C: keyword={$keywordId}\n";
DB::table('user_keyword')->insertOrIgnore(['user_id' => 11, 'keyword_id' => $keywordId, 'created_at' => now()]);
DB::table('user_alerts_inteligentes')->where('user_id', 11)->update([
    'emails' => json_encode(['test-digest@tcloud.local']),
    'enabled' => true, 'keywords_quota' => 200, 'emails_quota' => 20, 'alert_frequency_minutes' => 1,
]);

// Transcripción previa del test SOLO por hits de ESTA keyword (tabla chica),
// jamás LIKE sobre transcription_segments (49.8M filas, seq scan de 13GB).
$prevHits = DB::table('segment_keyword_hits')->where('keyword_id', $keywordId)->pluck('transcription_id')->unique()->values();
$transId = $prevHits->first();
if (!$transId) {
    $transId = DB::table('transcriptions')->insertGetId(['file_id' => $fileId, 'state' => 'processing', 'generate_alerts' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2] as $idx) {
        DB::table('transcription_segments')->insert([
            'transcription_id' => $transId, 'segment_index' => $idx,
            'start_seconds' => $idx * 20, 'end_seconds' => $idx * 20 + 6,
            'text_raw' => 'seg', 'text' => $idx === 2 ? "mencion de {$kwNorm} en vivo" : 'otro tema',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
echo "D: trans={$transId}\n";

$hits = app(App\Services\Ia\KeywordMatcher::class)->run(App\Models\Transcription::find($transId));
echo "E: scan hits={$hits} deliveries=" . DB::table('alert_deliveries')->count() . "\n";

DB::table('alert_deliveries')->whereNull('delivered_at')->update(['due_at' => now()->subSeconds(10)]);
echo "F: due_at forzado a vencido\n";

\Artisan::call('avisos:deliver-alerts');
echo "G: scheduler [" . trim(\Artisan::output()) . "]\n";

$batched = DB::table('alert_deliveries')->whereNotNull('batch_id')->whereNotNull('delivered_at')->count();
echo "H: entregas con batch={$batched}\n";

foreach (DB::table('alert_logs')->whereDate('sent_at', today())->orderByDesc('id')->limit(2)->get(['email_to', 'status', 'subject', 'error_message']) as $l) {
    echo "I: log " . json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
}

// Limpieza total del test
DB::table('alert_deliveries')->whereIn('hit_id', DB::table('segment_keyword_hits')->where('transcription_id', $transId)->pluck('id'))->delete();
DB::table('segment_keyword_hits')->where('transcription_id', $transId)->delete();
DB::table('transcription_segments')->where('transcription_id', $transId)->delete();
DB::table('transcriptions')->where('id', $transId)->delete();
DB::table('user_keyword')->where('keyword_id', $keywordId)->where('user_id', 11)->delete();
DB::table('keywords')->where('id', $keywordId)->delete();
DB::table('alert_logs')->where('email_to', 'test-digest@tcloud.local')->delete();
echo "J: cleanup OK\n";