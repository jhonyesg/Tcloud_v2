<?php
require '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/vendor/autoload.php';
$app = require '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\StorageProvider;

$substorages = StorageProvider::whereNull('duplicate_of_storage_id')->whereNotNull('base_path')->get();
$totalLeaks = 0;
$perStorage = [];
foreach ($substorages as $sub) {
    $clean = strtolower(rtrim($sub->base_path, '/'));
    // Inline the path (cannot use ? in position() with PG)
    $sql = "SELECT count(*) AS n FROM files f JOIN storage_providers s ON s.id = f.storage_provider_id WHERE f.storage_provider_id != " . (int)$sub->id . " AND NOT f.is_folder AND NOT f.is_trashed AND f.path IS NOT NULL AND f.path <> '' AND position('" . addslashes($clean) . "' in lower(s.base_path || '/' || f.path)) > 0";
    $count = (int) DB::selectOne($sql)->n;
    if ($count > 0) {
        echo sprintf("#%d %s: %d leaks\n", $sub->id, $sub->name, $count);
        $totalLeaks += $count;
        $perStorage[] = $sub->id;
    }
}
echo "\nTOTAL: $totalLeaks leaks remaining in " . count($perStorage) . " storages\n";
echo "Storage IDs: " . implode(', ', $perStorage) . "\n";
