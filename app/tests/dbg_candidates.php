<?php
// Debug de selectCandidates
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Ia\AvisosScanService;

$tag = 'dbg_' . substr(bin2hex(random_bytes(4)), 0, 8);
$sid = DB::table('storage_providers')->insertGetId(['name' => "{$tag}_s", 'type' => 'local', 'base_path' => "/tmp/$tag", 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
$uid = DB::table('users')->insertGetId(['username' => "{$tag}_u", 'email' => "{$tag}@t.local", 'password_hash' => 'x', 'role' => 'user', 'created_at' => now(), 'updated_at' => now()]);
$f1 = DB::table('files')->insertGetId(['name' => "{$tag}.mp3", 'path' => "$tag/a.mp3", 'size' => 10, 'mime_type' => 'audio/mpeg', 'storage_provider_id' => $sid, 'owner_id' => $uid, 'is_folder' => false, 'created_at' => now(), 'updated_at' => now()]);
$t1 = DB::table('transcriptions')->insertGetId(['file_id' => $f1, 'state' => 'done', 'generate_alerts' => true, 'duration_seconds' => 10, 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
DB::table('transcription_segments')->insert(['transcription_id' => $t1, 'segment_index' => 0, 'start_seconds' => 0, 'end_seconds' => 2, 'text_raw' => 'x', 'text' => 'x', 'created_at' => now(), 'updated_at' => now()]);

$svc = new AvisosScanService();
$c = $svc->selectCandidates(['windowHours' => 24]);
echo "candidatos: " . $c->count() . "\n";
foreach ($c as $row) echo "  - t{$row->id} storage {$row->storage_provider_id}\n";

// limpieza
DB::table('transcription_segments')->where('transcription_id', $t1)->delete();
DB::table('transcriptions')->where('id', $t1)->delete();
DB::table('files')->where('id', $f1)->delete();
DB::table('storage_providers')->where('id', $sid)->delete();
DB::table('users')->where('id', $uid)->delete();
echo "limpio\n";