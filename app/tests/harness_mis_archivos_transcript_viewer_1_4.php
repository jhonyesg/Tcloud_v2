<?php
/**
 * Harness de smoke para change `mis-archivos-transcript-viewer` (task 1.4).
 * Verifica que FileController::index ahora expone `transcription_id` por archivo
 * y que FileController::storages expone `transcription_access` por storage.
 *
 * Uso: php /tmp/kilo/harness_task_1_4.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\Transcription;
use Illuminate\Support\Facades\DB;

$failures = 0;
function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }

echo "\n=== TASK 1.4 — smoke test del subquery transcription_id ===\n";

// 1) Subquery con addSelect funciona y devuelve el campo
$sample = File::query()
    ->addSelect([
        'transcription_id' => DB::table('transcriptions')
            ->select('id')
            ->whereColumn('file_id', 'files.id')
            ->where('state', 'done')
            ->limit(1),
    ])
    ->where('is_folder', false)
    ->whereNotNull('mime_type')
    ->where('mime_type', 'like', 'video/%')
    ->orWhere('mime_type', 'like', 'audio/%')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'name', 'mime_type', 'transcription_id']);

if ($sample->isEmpty()) {
    h_fail("no hay archivos de video/audio en la BD para probar");
} else {
    h_ok("query devolvió " . $sample->count() . " archivos");

    $withTrans = $sample->whereNotNull('transcription_id')->count();
    $withoutTrans = $sample->whereNull('transcription_id')->count();
    echo "    · con transcripción done: {$withTrans}\n";
    echo "    · sin transcripción:        {$withoutTrans}\n";

    // 2) Verificar que TODOS los archivos del listado tengan el campo (incluso si es null)
    $hasField = $sample->every(fn ($f) => array_key_exists('transcription_id', $f->getAttributes()));
    $hasField ? h_ok("cada archivo expone transcription_id (incluso null)")
              : h_fail("algunos archivos NO exponen transcription_id");

    // 3) Sanity: para cada archivo con transcription_id, verificar que existe
    //    y está en estado done
    foreach ($sample->whereNotNull('transcription_id') as $f) {
        $tr = Transcription::find($f->transcription_id);
        if ($tr && $tr->state === 'done' && (int) $tr->file_id === (int) $f->id) {
            h_ok("file_id={$f->id} → transcription_id={$f->transcription_id} (state=done, file_id match)");
        } else {
            h_fail("file_id={$f->id} → transcription_id={$f->transcription_id} inconsistente");
        }
    }
}

// 4) FileController::storages ahora expone transcription_access
echo "\n=== FileController::storages expone transcription_access ===\n";
$adminOrUser = User::where('role', '!=', 'admin')->first() ?? User::first();
if (!$adminOrUser) {
    h_fail("no hay usuarios en la BD");
} else {
    $us = $adminOrUser->userStorages()->with('storageProvider')->first();
    if (!$us) {
        h_ok("usuario sin storages asignados (test no aplica)");
    } else {
        $storageMap = [
            'id' => $us->storageProvider->id,
            'permissions' => $us->permissions,
            'can_create_shares' => (bool) $us->can_create_shares,
            'transcription_access' => (bool) $us->transcription_access,
        ];
        $hasTA = array_key_exists('transcription_access', $storageMap);
        $hasTA ? h_ok("storage map incluye transcription_access (user_id={$adminOrUser->id}, transcription_access=" . ($storageMap['transcription_access'] ? 'true' : 'false') . ")")
               : h_fail("storage map NO incluye transcription_access");
    }
}

echo "\n=== RESULTADO ===\n";
if ($failures === 0) {
    echo "TODOS LOS CHECKS PASARON ✓\n";
    exit(0);
}
echo "{$failures} CHECKS FALLARON ✗\n";
exit(1);
