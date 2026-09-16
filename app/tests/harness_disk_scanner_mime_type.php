<?php
/**
 * Harness de regresión para change `fix-disk-scanner-mime-type-from-extension`.
 *
 * Verifica contra PostgreSQL real que:
 *   (a) `DiskScannerService::scanStorage()` persiste `files.mime_type` desde
 *       la extensión real del nombre (no hardcoded `'video/mp4'`).
 *   (b) `MentionsSearchService::classifyMediaKind($mime)` produce el kind
 *       correcto (`radio` para audio, `tv` para video).
 *   (c) `avisos:reconcile-file-mime-types` (sin `--apply`) cuenta sin mutar.
 *   (d) `avisos:reconcile-file-mime-types --apply` reescribe `mime_type` para
 *       filas candidatas y deja `.mp4` legítimos intactos.
 *
 * Cleanup: todo se crea con tag `hdms_<8-hex>` y se borra en `finally` por
 * ese tag. Cleanup defensivo al inicio borra residuos de corridas previas.
 *
 * Uso:
 *   cd app && php tests/harness_disk_scanner_mime_type.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Models\User;
use App\Models\UserStorage;
use App\Services\FileScannerService;
use App\Services\Ia\DiskScannerService;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hdms_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness disk-scanner-mime-type (tag: {$tag})\n";

// ─── Cleanup defensivo: borrar residuos hdms_* de corridas previas ────────────
h_section('cleanup defensivo pre-run');
DB::table('transcription_segments')->where('text', 'like', 'hdms_%')->delete();
$residuosTrans = DB::table('transcriptions')->where('original_name', 'like', 'hdms_%')->delete();
$residuosFiles = DB::table('files')->where('name', 'like', 'hdms_%')->delete();
$residuosStorages = DB::table('storage_providers')->where('name', 'like', 'hdms_%')->delete();
$residuosUsers = DB::table('users')->where('username', 'like', 'hdms_%')->delete();
$totalResiduos = $residuosFiles + $residuosTrans + $residuosStorages + $residuosUsers;
h_ok("residuos borrados: files={$residuosFiles} trans={$residuosTrans} storages={$residuosStorages} users={$residuosUsers} (total {$totalResiduos})");

// ─── Bootstrap: 1 cliente + 1 storage + archivos físicos ─────────────────────
h_section('bootstrap: cliente + storage temporal + archivos físicos');

$cliente = User::create([
    'email' => "{$tag}@test.local",
    'username' => $tag,
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'media_editor_enabled' => false,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
h_ok("cliente creado id={$cliente->id}");

// Crear carpeta temporal con un subdirectorio 'dmY' (hoy) para que el scanner
// encuentre los archivos como candidatos reales.
$tmpBase = sys_get_temp_dir() . "/{$tag}";
$todayDmY = date('dmY');
$todayFolder = "{$tmpBase}/{$todayDmY}";
if (!mkdir($todayFolder, 0755, true) && !is_dir($todayFolder)) {
    fwrite(STDERR, "no se pudo crear {$todayFolder}\n");
    exit(2);
}

// Crear archivos físicos .mp3, .opus, .wav, .mp4 (basura, solo basta con que existan).
$filesCreated = [];
$samples = [
    ['name' => "{$tag}_audio1.mp3",  'expected_mime' => 'audio/mpeg',  'expected_kind' => 'radio'],
    ['name' => "{$tag}_audio2.opus", 'expected_mime' => 'audio/opus',  'expected_kind' => 'radio'],
    ['name' => "{$tag}_audio3.wav",  'expected_mime' => 'audio/wav',   'expected_kind' => 'radio'],
    ['name' => "{$tag}_video.mp4",   'expected_mime' => 'video/mp4',   'expected_kind' => 'tv'],
];
foreach ($samples as $s) {
    $abs = "{$todayFolder}/{$s['name']}";
    file_put_contents($abs, 'fixture');
    // mtime 2 minutos en el pasado para pasar el cutoff de scan_min_age_seconds (60s).
    $pastMtime = time() - 120;
    touch($abs, $pastMtime);
    $filesCreated[] = ['abs' => $abs, 'name' => $s['name'], 'expected_mime' => $s['expected_mime'], 'expected_kind' => $s['expected_kind']];
}
h_ok("4 archivos físicos creados en {$todayFolder} (mtime -120s)");
h_ok("2 user_storages se crearán vía storage de usuario");

$storage = StorageProvider::create([
    'name' => $tag,
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpBase,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => true,
    'folder_layout' => 'flat',
    'allow_parent_overlap' => false,
]);
UserStorage::create([
    'user_id' => $cliente->id,
    'storage_provider_id' => $storage->id,
    'permissions' => 'full',
    'transcription_access' => true,
]);
h_ok("storage id={$storage->id} base_path={$tmpBase} folder_layout=flat");

$verify = DB::table('storage_providers')->where('id', $storage->id)->select('id', 'base_path', 'folder_layout')->first();
h_check($verify && $verify->folder_layout === 'flat', 'storage fila verificada en BD con folder_layout=flat');

// ─── Aserción (a): scanner persiste mime_type correcto ─────────────────────
try {
    h_section('Aserción (a): DiskScannerService persiste mime_type por extensión');

    $scanner = app(DiskScannerService::class);
    $stats = $scanner->scanStorage($storage, 0);

    h_check($stats['files_created'] >= 4, "scanner creó >=4 files (creados={$stats['files_created']})");

    foreach ($filesCreated as $f) {
        $row = DB::table('files')->where('storage_provider_id', $storage->id)->where('name', $f['name'])->first();
        h_check($row !== null, "file '{$f['name']}' existe en BD id=" . ($row->id ?? 'null'));
        if ($row) {
            h_check($row->mime_type === $f['expected_mime'],
                "mime_type correcto: {$f['name']} → '{$f['expected_mime']}'",
                "mime_type MAL: {$f['name']} → '{$row->mime_type}' (esperaba '{$f['expected_mime']}')");
        }
    }

    // ─── Aserción (b): classifyMediaKind produce el kind correcto ───────────
    h_section('Aserción (b): classifyMediaKind produce radio/tv desde el mime_type');

    $fileScanner = app(FileScannerService::class);
    foreach ($filesCreated as $f) {
        $classified = MentionsSearchService::classifyMediaKind($fileScanner->getMimeType($f['name']));
        h_check($classified === $f['expected_kind'],
            "classifyMediaKind('{$f['expected_mime']}') = '{$f['expected_kind']}' para {$f['name']}",
            "classifyMediaKind MAL: para {$f['name']} obtuvo '{$classified}' (esperaba '{$f['expected_kind']}')");
    }

    // ─── Aserción (c): dry-run del comando no muta ─────────────────────────
    h_section('Aserción (c): --apply omitido (dry-run) no muta');

    // Preparar 3 filas para el reconciliador: deben ser mp3 con mime erróneo.
    // Las filas del scanner YA tienen mime_type correcto, así que el comando
    // NO las tocará. Para validar el reconciliador creamos filas dedicadas
    // con mime='video/mp4' hardcoded que NO salieron del scanner.
    $preRows = [];
    for ($i = 0; $i < 3; $i++) {
        $name = "{$tag}_legacy_{$i}.mp3";
        $id = DB::table('files')->insertGetId([
            'name' => $name,
            'path' => "legacy/{$name}",
            'size' => 100,
            'mime_type' => 'video/mp4',
            'storage_provider_id' => $storage->id,
            'owner_id' => $cliente->id,
            'parent_id' => null,
            'is_folder' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'file_modified_at' => now(),
            'availability_state' => 'available',
            'is_trashed' => false,
        ]);
        $preRows[] = $id;
    }
    h_ok("3 filas de prueba creadas con mime='video/mp4' hardcoded (ids=" . implode(',', $preRows) . ")");

    // Dry-run
    \Illuminate\Support\Facades\Artisan::call('avisos:reconcile-file-mime-types', ['--chunk' => 100]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    $postRows = DB::table('files')->whereIn('id', $preRows)->pluck('mime_type', 'id');
    $allStillVideo = $postRows->every(fn($m) => $m === 'video/mp4');
    h_check($allStillVideo, 'dry-run NO mutó las 3 filas (mime sigue siendo video/mp4)');
    h_check(str_contains($output, 'DRY-RUN'), 'salida del comando incluye modo DRY-RUN');

    // ─── Aserción (d): --apply reescribe los archivos de audio ─────────────
    h_section('Aserción (d): --apply reescribe mime_type a audio/mpeg para .mp3');

    \Illuminate\Support\Facades\Artisan::call('avisos:reconcile-file-mime-types', ['--apply' => true, '--chunk' => 100]);
    $outputApply = \Illuminate\Support\Facades\Artisan::output();

    $postApply = DB::table('files')->whereIn('id', $preRows)->pluck('mime_type', 'id');
    $allNowAudio = $postApply->every(fn($m) => $m === 'audio/mpeg');
    h_check($allNowAudio, '--apply mutó las 3 filas: mime=audio/mpeg');

    $logCheck = DB::table('files')->whereIn('id', $preRows)->where('mime_type', 'audio/mpeg')->count();
    h_check($logCheck === 3, "3 filas con mime='audio/mpeg' tras --apply (real={$logCheck})");

    // Verificar log
    $logPath = storage_path('logs/laravel.log');
    $logHasFinished = false;
    $logHasUpdated = false;
    if (file_exists($logPath)) {
        $tailSize = 256 * 1024;
        $fp = fopen($logPath, 'r');
        fseek($fp, max(0, filesize($logPath) - $tailSize));
        $tail = fread($fp, $tailSize);
        fclose($fp);
        $logHasFinished = str_contains($tail, 'files.mime_reconcile.finished');
    }
    h_check($logHasFinished, 'laravel.log contiene files.mime_reconcile.finished reciente');

    // ─── Aserción (e): archivos .mp4 legítimos NO se tocan ────────────────
    h_section('Aserción (e): --apply deja intactos archivos .mp4 legítimos');

    $realMp4Ids = [];
    for ($i = 0; $i < 2; $i++) {
        $name = "{$tag}_real_video_{$i}.mp4";
        $id = DB::table('files')->insertGetId([
            'name' => $name,
            'path' => "videos/{$name}",
            'size' => 200,
            'mime_type' => 'video/mp4',
            'storage_provider_id' => $storage->id,
            'owner_id' => $cliente->id,
            'parent_id' => null,
            'is_folder' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'file_modified_at' => now(),
            'availability_state' => 'available',
            'is_trashed' => false,
        ]);
        $realMp4Ids[] = $id;
    }
    h_ok("2 filas .mp4 legítimas creadas (ids=" . implode(',', $realMp4Ids) . ")");

    \Illuminate\Support\Facades\Artisan::call('avisos:reconcile-file-mime-types', ['--apply' => true, '--chunk' => 100]);

    $mp4StillVideo = DB::table('files')->whereIn('id', $realMp4Ids)->where('mime_type', 'video/mp4')->count();
    h_check($mp4StillVideo === 2, "archivos .mp4 legítimos NO mutados (mantiuvieron video/mp4, real={$mp4StillVideo}/2)");

    // ─── Aserción (f): idempotencia ───────────────────────────────────────
    h_section('Aserción (f): re-ejecutar --apply es idempotente');

    $countBefore = DB::table('files')->where('name', 'like', "{$tag}%")->where('mime_type', 'video/mp4')->count();

    \Illuminate\Support\Facades\Artisan::call('avisos:reconcile-file-mime-types', ['--apply' => true, '--chunk' => 100]);

    $countAfter = DB::table('files')->where('name', 'like', "{$tag}%")->where('mime_type', 'video/mp4')->count();

    // Solo deberían quedar los .mp4 legítimos (2) — los 3 .mp3 + 4 del scanner
    // ahora son audio/.mp4 legítimos = 2. El scanner creó 4 files (3 audio +
    // 1 mp4). Los 3 .mp3 de preRows + 3 del scanner (.mp3/.opus/.wav) = 6 audio.
    // Total esperado de video/mp4 tras --apply: solo los 2 .mp4 legítimos + el
    // 1 .mp4 del scanner = 3 (no se tocan).
    h_check($countAfter <= 3, "tras idempotencia quedan <=3 video/mp4 (los legítimos); antes={$countBefore} después={$countAfter}");

} finally {
    // ─── Cleanup en orden inverso ───────────────────────────────────────────
    h_section('cleanup [finally]');

    DB::table('transcription_segments')->where('text', 'like', "{$tag}%")->delete();
    DB::table('transcriptions')->where('original_name', 'like', "{$tag}%")->delete();
    DB::table('files')->where('name', 'like', "{$tag}%")->delete();
    DB::table('user_storages')->whereIn('user_id', function ($q) use ($tag) {
        $q->select('id')->from('users')->where('username', 'like', "{$tag}%");
    })->delete();
    DB::table('storage_providers')->where('name', 'like', "{$tag}%")->delete();
    DB::table('users')->where('username', 'like', "{$tag}%")->delete();

    foreach ($filesCreated ?? [] as $f) {
        @unlink($f['abs']);
    }
    @rmdir($todayFolder ?? '');
    @rmdir($tmpBase ?? '');

    $postUsers = DB::table('users')->where('username', 'like', "{$tag}%")->count();
    $postStorages = DB::table('storage_providers')->where('name', 'like', "{$tag}%")->count();
    $postFiles = DB::table('files')->where('name', 'like', "{$tag}%")->count();
    $postSegs = DB::table('transcription_segments')->where('text', 'like', "{$tag}%")->count();
    $postTrans = DB::table('transcriptions')->where('original_name', 'like', "{$tag}%")->count();

    h_check($postUsers === 0, "post users=0 (real={$postUsers})");
    h_check($postStorages === 0, "post storages=0 (real={$postStorages})");
    h_check($postFiles === 0, "post files=0 (real={$postFiles})");
    h_check($postSegs === 0, "post segs=0 (real={$postSegs})");
    h_check($postTrans === 0, "post trans=0 (real={$postTrans})");
    h_check(!is_dir($tmpBase ?? ''), "directorio temporal borrado");
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: DiskScannerService persiste mime_type real; classifyMediaKind correcto; reconciliador funciona y es idempotente.\n";
    exit(0);
} else {
    echo "FAIL: {$failures} check(s) fallaron\n";
    exit(1);
}
