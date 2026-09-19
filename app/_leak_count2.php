<?php
require '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/vendor/autoload.php';
$app = require '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\StorageProvider;

$pairs = DB::select("
    SELECT parent.id AS parent_id,
           child.id AS child_id,
           child.base_path AS child_base,
           length(child.base_path) - length(parent.base_path) AS depth_diff
    FROM storage_providers parent
    JOIN storage_providers child ON child.base_path LIKE parent.base_path || '/%'
    WHERE parent.duplicate_of_storage_id IS NULL AND child.duplicate_of_storage_id IS NULL
      AND parent.base_path IS NOT NULL AND child.base_path IS NOT NULL
    ORDER BY depth_diff ASC
");

$results = [];
foreach ($pairs as $p) {
    $clean = strtolower(rtrim($p->child_base, '/'));
    $sql = "SELECT count(*) AS n FROM files f JOIN storage_providers s ON s.id = f.storage_provider_id WHERE f.storage_provider_id != " . (int)$p->child_id . " AND NOT f.is_folder AND NOT f.is_trashed AND lower(s.base_path || '/' || f.path) LIKE '" . addslashes($clean) . "/%'";
    $count = (int) DB::selectOne($sql)->n;
    if ($count > 100) {
        $results[$p->child_id] = $count;
    }
}

foreach ($results as $cid => $count) {
    $name = StorageProvider::find($cid)?->name ?? '?';
    echo sprintf("#%d %s: %d leaks\n", $cid, $name, $count);
}
echo "TOTAL storages with >100 leaks: " . count($results) . "\n";
echo "Total leaked files: " . array_sum($results) . "\n";
