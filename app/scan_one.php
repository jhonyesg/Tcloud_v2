<?php
// Scan directo de un storage especifico con batch grande.
// Uso: php scan_one.php <storage_id> <batch>
// Bypassea la limitacion de --storages del CLI.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$storageId = (int) ($argv[1] ?? 49);
$batch = (int) ($argv[2] ?? 500);

$storage = App\Models\StorageProvider::find($storageId);
if (!$storage) {
    fwrite(STDERR, "Storage $storageId no existe\n");
    exit(1);
}
if (!$storage->transcription_enabled) {
    fwrite(STDERR, "Storage $storageId ({$storage->name}) NO tiene transcripcion habilitada\n");
    exit(1);
}

echo "[" . date('H:i:s') . "] Scanning storage {$storage->id} ({$storage->name}) batch={$batch}\n";

$scanner = app(App\Services\Ia\DiskScannerService::class);

$t0 = microtime(true);
$stats = $scanner->scanStorage(
    $storage,
    0,        // daysBack
    false,    // all
    $batch,   // batchOverride
    true,     // generateAlerts
    ['mode' => 'today', 'folders' => []]  // scope
);
$dt = round(microtime(true) - $t0, 2);

echo "[" . date('H:i:s') . "] OK in {$dt}s\n";
echo "  scanned:              {$stats['scanned']}\n";
echo "  candidates:           {$stats['candidates']}\n";
echo "  files_created:        {$stats['files_created']}\n";
echo "  transcriptions_created: {$stats['transcriptions_created']}\n";
