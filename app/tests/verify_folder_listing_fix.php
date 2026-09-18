<?php
/**
 * Verificación post-fix del change `fix-folder-listing-resolve-ids-grandchildren-leak`.
 *
 * Itera sobre 5 folders reales de producción y compara el conteo de
 * `FolderListingService::listContents()` contra los conteos esperados.
 * Exit code 0 si todos pasan; 1 si alguno falla.
 *
 * Run: php tests/verify_folder_listing_fix.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\File;
use App\Services\FolderListingService;

$svc = app(FolderListingService::class);

$cases = [
    ['fid' => 7631760, 'expected' => 35, 'label' => '18092026 storage 5 (0 directos + 35 via folder equivalente en storage 34 via physical_path_normalized)'],
    ['fid' => 7244379, 'expected' => 97, 'label' => '17092026 canónico storage 7 (0 directos + 97 via mirror sin overlap)'],
    ['fid' => 7244491, 'expected' => 97, 'label' => 'Mirror 17092026 storage 5 (97 files directos)'],
    ['fid' => 1524,    'expected' => 121, 'label' => 'Sol storage 47 (121 directos; mirror con 59 duplicados por nombre, quedan 121 post-dedup)'],
    ['fid' => 7346139, 'expected' => 2,   'label' => 'Cache storage 36 (2 sub-f; mirror con 2 duplicados por nombre, quedan 2 post-dedup)'],
];

$failures = 0;
echo "=== Verificación post-fix FolderListingService ===\n\n";

foreach ($cases as $c) {
    $file = File::find($c['fid']);
    if (!$file) {
        echo "  ✗ [MISSING] Folder {$c['fid']} no existe\n";
        $failures++;
        continue;
    }

    $actual = $svc->listContents($file)->count();
    $ok = $actual === $c['expected'];
    $marker = $ok ? '✓' : '✗';
    echo "  {$marker} {$c['label']}\n";
    echo "      folder_id={$c['fid']} expected={$c['expected']} actual={$actual}\n";
    if (!$ok) {
        $failures++;
    }
}

echo "\n";
echo $failures === 0
    ? "TODOS PASARON — Fix verificado contra producción.\n"
    : "FALLARON $failures caso(s) — revisar FolderListingService.\n";

exit($failures === 0 ? 0 : 1);
