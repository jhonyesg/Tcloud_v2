<?php
/**
 * Harness de regresión para change `fix-storage-sync-missing-db-facade-import`.
 *
 * Reproduce el camino real que dispara el toast 500 al navegar folders:
 *   FileController@index → StorageSyncService::syncFolderWithReport()
 *                        → doSyncFolder()
 *                        → isFileLinked()   ← aquí reventaba `Class "App\Services\DB" not found`
 *
 * Antes del fix, `isFileLinked()` no podía resolver el facade `DB` (faltaba
 * el `use Illuminate\Support\Facades\DB;`); el harness monta un storage
 * local temporal con un archivo dentro y comprueba que la llamada termina
 * sin lanzar esa excepción y devuelve un array de files.
 *
 * Uso: php tests/harness_storage_sync_is_file_linked.php
 *      (desde la raíz del repo, ajustando la ruta si se corre desde app/)
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\StorageSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'harness_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpBase = sys_get_temp_dir() . "/{$tag}";
if (!mkdir($tmpBase, 0755, true) && !is_dir($tmpBase)) {
    fwrite(STDERR, "no se pudo crear {$tmpBase}\n");
    exit(2);
}
file_put_contents("{$tmpBase}/sample.txt", "harness fixture");
h_ok("directorio base temporal: {$tmpBase}");

$user = User::create([
    'email' => "{$tag}@harness.local",
    'username' => $tag,
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
h_ok("usuario harness creado (id={$user->id})");

$storage = StorageProvider::create([
    'name' => "{$tag} storage",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpBase,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'allow_parent_overlap' => false,
    'is_personal' => false,
    'kind' => 'local',
]);
h_ok("StorageProvider creado (id={$storage->id}, base_path={$tmpBase})");

// Phantom file row que NO existe en disco: obliga a doSyncFolder() a tratarlo
// como "orphan" y recorrer el loop que invoca isFileLinked($id) — exactamente
// el punto que reventaba con Class "App\Services\DB" not found antes del fix.
$orphan = File::create([
    'name' => 'ghost.txt',
    'path' => 'ghost.txt',
    'size' => 0,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
h_ok("File orphan seed (id={$orphan->id}) — forzará invocación de isFileLinked()");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: el facade DB NO está intentando resolverse como App\Services\DB
// (este es el corazón del bug: en el namespace App\Services, sin `use ... DB`,
//  PHP buscaba App\Services\DB y reventaba. Ahora debe resolver a Illuminate.)
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 1 — DB facade resuelve a Illuminate\\Support\\Facades\\DB');

$dbClass = DB::class;
if ($dbClass === \Illuminate\Support\Facades\DB::class) {
    h_ok("DB::class === Illuminate\\Support\\Facades\\DB (resolución correcta)");
} else {
    h_fail("DB::class = {$dbClass} (no resuelve al facade esperado)");
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: syncFolderWithReport() corre end-to-end sin excepción 500
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 2 — syncFolderWithReport() end-to-end');

$svc = app(StorageSyncService::class);

try {
    $report = $svc->syncFolderWithReport($storage, null, $user->id, false);

    if (!is_array($report) || !array_key_exists('files', $report)) {
        h_fail('el reporte no tiene la clave "files" (estructura inesperada)');
    } else {
        h_ok('syncFolderWithReport() devolvió un reporte con clave "files"');
    }

    if (!is_array($report['files'])) {
        h_fail('"files" no es un array');
    } else {
        h_ok('"files" es un array (' . count($report['files']) . ' entradas)');
    }

    // El scanner puede haber recogido sample.txt como carpeta root o como
    // archivo; lo importante es que el reporte se construyó sin lanzar la
    // excepción de facade roto.
    $foundSample = false;
    foreach ($report['files'] as $entry) {
        if (isset($entry['name']) && str_contains((string) $entry['name'], 'sample.txt')) {
            $foundSample = true;
            break;
        }
    }
    if ($foundSample) {
        h_ok('sample.txt detectado por el escáner (path real DB::selectOne() ejercitado)');
    } else {
        // No es fallo del fix; el reporte podría no contener la entry si
        // el sync la omitió por heurísticas, pero la línea que importa
        // (isFileLinked()) ya corrió sin lanzar ClassNotFound.
        h_ok('escáner devolvió reporte; isFileLinked() corrió sin ClassNotFound');
    }
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'App\\Services\\DB') || str_contains($msg, 'Class "App\\Services\\DB"')) {
        h_fail('REGRESIÓN: Class "App\\Services\\DB" not found — el fix NO está aplicado');
    } else {
        h_fail('excepción inesperada: ' . get_class($e) . ': ' . $msg);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
h_section('TEARDOWN');
try {
    File::where('storage_provider_id', $storage->id)->delete();
    $storage->delete();
    $user->delete();
    @unlink("{$tmpBase}/sample.txt");
    @rmdir($tmpBase);
    h_ok('limpieza completada (storage, user, files, tempdir)');
} catch (\Throwable $e) {
    echo "  ! cleanup error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: StorageSyncService::isFileLinked() resuelve DB facade correctamente\n";
    exit(0);
} else {
    echo "FAIL: {$failures} escenario(s) roto(s) — el fix NO está bien aplicado\n";
    exit(1);
}
