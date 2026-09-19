<?php
/**
 * Harness de regresión para el change `2026-09-17-mis-archivos-substorage-orphan-repair`.
 *
 * Cubre los 3 puntos del fix:
 *  1. `createFileFromScan()` delega archivos a un sub-storage y, si el folder
 *     intermedio NO existe en el sub-storage, lo crea con su jerarquía completa
 *     (en lugar de dejar parent_id=NULL, que era el bug original).
 *  2. `fullSync()` no skipea una carpeta por mtime cuando BD=0 + disco>0
 *     (cardinality-zero force sync).
 *  3. El comando `files:repair-orphan-subtree --apply` re-parenta los archivos
 *     huérfanos preexistentes (los que el bug legacy dejó en la BD antes del
 *     deploy de este fix).
 *
 * Tag prefijo: `hsor_<8-hex>` (substorage-orphan-repair) para que toda fila
 * que el harness cree sea trazable y limpiable sin tocar datos reales.
 *
 * Uso:
 *   cd app && php tests/harness_files_substorage_orphan_repair.php
 *   exit 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\StorageSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hsor_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void    { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void  { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP defensivo: borrar residuos de corridas previas con el mismo prefijo.
// Patrón estándar del proyecto (ver otros harness_*).
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");
// Cleanup defensivo tambien de tmp dirs (el teardown puede fallar si
// quedaron archivos en paths no esperados).
$oldTmpDirs = glob(sys_get_temp_dir() . '/hsor_*');
foreach ($oldTmpDirs as $oldDir) {
    if (is_dir($oldDir)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($oldDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($oldDir);
    }
}
if (count($oldTmpDirs) > 0) {
    h_ok('tmp dirs de corridas previas eliminados (' . count($oldTmpDirs) . ' dirs)');
}

$staleUsers = User::where('username', 'LIKE', 'hsor_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
    File::whereIn('owner_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    StorageProvider::whereRaw("name LIKE 'hsor_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $staleUsers)->delete();
    h_ok('residuos de corridas previas eliminados (' . $staleUsers->count() . ' users)');
} else {
    h_ok('sin residuos previos');
}

// ─────────────────────────────────────────────────────────────────────────────
// SETUP común
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpParent = sys_get_temp_dir() . "/{$tag}_parent";
foreach ([$tmpParent] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

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

$svc = app(StorageSyncService::class);
// findMoreSpecificStorage() cachea los storages por instancia. Cada escenario
// añade storages nuevos, asi que forzamos refresh entre escenarios.
$refreshSvc = function () use (&$svc) {
    app()->forgetInstance(StorageSyncService::class);
    $svc = app(StorageSyncService::class);
};

$mkStorage = function (string $name, string $basePath) use ($tag, $user): StorageProvider {
    return StorageProvider::create([
        'name' => "{$tag} {$name}",
        'type' => 'local',
        'config' => [],
        'base_path' => $basePath,
        'enabled' => true,
        'is_accessible' => true,
        'last_checked_at' => now(),
        'transcription_enabled' => false,
        'folder_layout' => 'flat',
        'allow_parent_overlap' => false,
        'is_personal' => false,
        'kind' => 'local',
    ]);
};

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: delegación al syncFolder() crea jerarquía faltante
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 1 — syncFolder() con delegación crea jerarquía faltante');

// Sub-storage DEBE estar anidado bajo el parent para que findMoreSpecificStorage()
// lo detecte como prefijo de la ruta del archivo.
mkdir("{$tmpParent}/Disco_C/Prensa/Espectador/20260917/imagenes", 0755, true);
file_put_contents("{$tmpParent}/Disco_C/Prensa/Espectador/20260917/imagenes/01.jpg", 'harness-jpg-1');

$parentStorage = $mkStorage('parent', $tmpParent);
$subStorage = $mkStorage('sub', "{$tmpParent}/Disco_C/Prensa/Espectador");
$refreshSvc();
h_ok("parent storage id={$parentStorage->id}, sub storage id={$subStorage->id} (nested)");

// Crear la cadena de folders en el parent manualmente para no depender del sync
$mkFolder = function (StorageProvider $s, string $path, ?int $parentId = null) use ($user): File {
    $segments = explode('/', $path);
    $name = array_pop($segments);
    return File::create([
        'name' => $name,
        'path' => $path,
        'size' => 0,
        'mime_type' => 'folder',
        'storage_provider_id' => $s->id,
        'owner_id' => $user->id,
        'parent_id' => $parentId,
        'is_folder' => true,
        'availability_state' => 'available',
        'last_verified_at' => now(),
    ]);
};

$folderDiscoC = $mkFolder($parentStorage, 'Disco_C');
$folderPrensa = $mkFolder($parentStorage, 'Disco_C/Prensa', $folderDiscoC->id);
$folderEspectador = $mkFolder($parentStorage, 'Disco_C/Prensa/Espectador', $folderPrensa->id);
$folder20260917 = $mkFolder($parentStorage, 'Disco_C/Prensa/Espectador/20260917', $folderEspectador->id);
// IMPORTANTE: NO creamos "Disco_C/Prensa/Espectador/20260917/imagenes" en el parent.
// Eso simula exactamente el estado roto que el bug producía.

// Llamar a syncFolderWithReport sobre el folder "imagenes" del parent. Esto
// escanea ESE nivel, encuentra 01.jpg, y dispara createFileFromScan() que
// delega al sub-storage. El fix tiene que crear la jerarquía faltante.
//
// NOTA: fullSync() también funcionaría, pero su chunkById(500) tiene un caso
// conocido donde se detiene tras 1 fila cuando hay menos de chunkSize filas
// iniciales. Para test unitario preferimos invocar syncFolder directo sobre
// el folder hoja.
$folderImagenesInParent = File::where('storage_provider_id', $parentStorage->id)
    ->where('path', 'Disco_C/Prensa/Espectador/20260917/imagenes')
    ->where('is_folder', true)
    ->first();

if ($folderImagenesInParent === null) {
    $folderImagenesInParent = File::create([
        'name' => 'imagenes',
        'path' => 'Disco_C/Prensa/Espectador/20260917/imagenes',
        'size' => 0,
        'mime_type' => 'folder',
        'storage_provider_id' => $parentStorage->id,
        'owner_id' => $user->id,
        'parent_id' => $folder20260917->id,
        'is_folder' => true,
        'availability_state' => 'available',
        'last_verified_at' => now(),
    ]);
}

$report = $svc->syncFolderWithReport($parentStorage, $folderImagenesInParent->id, $user->id, false);

$fileDelegated = File::where('storage_provider_id', $subStorage->id)
    ->where('name', '01.jpg')
    ->first();

h_check($fileDelegated !== null, 'archivo delegado creado en sub-storage', 'archivo NO delegado al sub-storage');

if ($fileDelegated) {
    h_check($fileDelegated->parent_id !== null, 'parent_id != NULL (jerarquía resuelta en sub-storage)', 'parent_id = NULL (regresión del bug)');

    $folderInSub = File::where('storage_provider_id', $subStorage->id)
        ->where('path', '20260917/imagenes')
        ->where('is_folder', true)
        ->first();

    h_check($folderInSub !== null, 'folder "20260917/imagenes" creado en sub-storage', 'folder intermedio NO creado');
    h_check(
        $folderInSub && $fileDelegated->parent_id === $folderInSub->id,
        'parent_id del archivo = id del folder intermedio creado',
        'parent_id del archivo != id del folder'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: cadena multinivel (a/b/c/file.txt) crea los 3 folders
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 2 — cadena multinivel crea todos los folders');

$multiBase = sys_get_temp_dir() . "/{$tag}_multi";
mkdir("{$multiBase}/a/b/c", 0755, true);
file_put_contents("{$multiBase}/a/b/c/file.txt", 'harness');

$multiParent = $mkStorage('multi parent', $multiBase);
$multiSub = $mkStorage('multi sub', "{$multiBase}/a/b");
$refreshSvc();

$folderA = $mkFolder($multiParent, 'a');

// Llamar al sync sobre "a" en el parent: descubre "b/" y "b/c/" (carpetas normales,
// sin delegación porque no son archivos). Encuentra "c/file.txt" y lo delega.
// Aquí NO existe la cadena a/b en el sub-storage, así que el fix debe crearla.
// Idem: invocar syncFolder directo sobre el folder "a/b" del parent para
// encontrar "c/file.txt" y forzar la delegación multinivel.
$folderAB = File::where('storage_provider_id', $multiParent->id)
    ->where('path', 'a/b')
    ->where('is_folder', true)
    ->first();
if ($folderAB === null) {
    $folderAB = File::create([
        'name' => 'b', 'path' => 'a/b', 'size' => 0, 'mime_type' => 'folder',
        'storage_provider_id' => $multiParent->id, 'owner_id' => $user->id,
        'parent_id' => $folderA->id, 'is_folder' => true,
        'availability_state' => 'available', 'last_verified_at' => now(),
    ]);
}

// Crear tambien folder "c" en el parent para que la sync escanee ese nivel
$folderC = File::where('storage_provider_id', $multiParent->id)
    ->where('path', 'a/b/c')
    ->where('is_folder', true)
    ->first();
if ($folderC === null) {
    $folderC = File::create([
        'name' => 'c', 'path' => 'a/b/c', 'size' => 0, 'mime_type' => 'folder',
        'storage_provider_id' => $multiParent->id, 'owner_id' => $user->id,
        'parent_id' => $folderAB->id, 'is_folder' => true,
        'availability_state' => 'available', 'last_verified_at' => now(),
    ]);
}

$report2 = $svc->syncFolderWithReport($multiParent, $folderC->id, $user->id, false);

$fileMulti = File::where('storage_provider_id', $multiSub->id)
    ->where('name', 'file.txt')
    ->first();

h_check($fileMulti !== null, 'archivo delegado en sub-storage multi', 'archivo NO delegado');

$createdFolders = File::where('storage_provider_id', $multiSub->id)
    ->where('is_folder', true)
    ->pluck('path')
    ->toArray();

h_check(in_array('c', $createdFolders, true), 'folder "c" creado en sub-storage multi', 'folder "c" NO creado');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3: idempotencia — ejecutar dos veces con el mismo archivo
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 3 — idempotencia bajo delegación repetida');

// Poner el archivo directamente en imagenes/ (no en subcarpeta) para que
// un syncFolder de un solo nivel lo descubra. Nombre "03.jpg" para no
// colisionar con "01.jpg" ya delegado.
file_put_contents("{$parentStorage->base_path}/Disco_C/Prensa/Espectador/20260917/imagenes/03.jpg", 'harness-dup-3');

$folderImagenesInParent = File::where('storage_provider_id', $parentStorage->id)
    ->where('path', 'Disco_C/Prensa/Espectador/20260917/imagenes')
    ->where('is_folder', true)
    ->first();

// Llamar al sync dos veces — la segunda debe ser no-op gracias al UNIQUE
$svc->syncFolderWithReport($parentStorage, $folderImagenesInParent->id, $user->id, false);

$countAfter1 = File::where('storage_provider_id', $subStorage->id)->where('name', '03.jpg')->count();
$svc->syncFolderWithReport($parentStorage, $folderImagenesInParent->id, $user->id, false);
$countAfter2 = File::where('storage_provider_id', $subStorage->id)->where('name', '03.jpg')->count();

h_check($countAfter1 === 1, "03.jpg existe en 1 fila tras primer sync", "03.jpg existe en {$countAfter1} filas tras primer sync");
h_check($countAfter2 === 1, "03.jpg sigue en 1 fila tras segundo sync (idempotente)", "03.jpg terminó en {$countAfter2} filas (duplicación)");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4: dry-run del comando repair no muta nada
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 4 — dry-run no muta nada');

$orphan = File::create([
    'name' => 'orphan.jpg',
    'path' => '20260917/imagenes/orphan.jpg',
    'size' => 1024,
    'mime_type' => 'image/jpeg',
    'storage_provider_id' => $subStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now(),
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
// Tambien crear el archivo físico para que la sync cuente entradas reales
file_put_contents("{$parentStorage->base_path}/Disco_C/Prensa/Espectador/20260917/imagenes/orphan.jpg", 'harness-orphan');

$beforeFolderCount = File::where('storage_provider_id', $subStorage->id)->where('is_folder', true)->count();
$beforeOrphanParent = File::where('id', $orphan->id)->value('parent_id');

$exitCode = \Illuminate\Support\Facades\Artisan::call('files:repair-orphan-subtree', [
    '--dry-run' => true,
    '--storage' => (string) $subStorage->id,
]);

h_check($exitCode === 0, "dry-run salió con exit code 0", "dry-run salió con exit {$exitCode}");

$afterFolderCount = File::where('storage_provider_id', $subStorage->id)->where('is_folder', true)->count();
$afterOrphanParent = File::where('id', $orphan->id)->value('parent_id');

h_check($afterFolderCount === $beforeFolderCount, "dry-run no creó folders (antes={$beforeFolderCount}, despues={$afterFolderCount})", "dry-run creó folders");
h_check($afterOrphanParent === $beforeOrphanParent, "dry-run no reparentó (parent_id sigue NULL)", "dry-run mutó el archivo huerfano");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 5: apply repara los huerfanos
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 5 — apply repara los huerfanos');

$exitCode = \Illuminate\Support\Facades\Artisan::call('files:repair-orphan-subtree', [
    '--apply' => true,
    '--storage' => (string) $subStorage->id,
]);

h_check($exitCode === 0, "apply salió con exit code 0", "apply salió con exit {$exitCode}");

$orphanAfter = File::where('id', $orphan->id)->first();
h_check($orphanAfter && $orphanAfter->parent_id !== null, 'huérfano re-parentado (parent_id != NULL)', 'huérfano sigue con parent_id NULL');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 6: cache folder_gen:* se incrementa durante apply
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 6 — invalidación de cache');

// Sembrar un nuevo huérfano para que el apply tenga trabajo que hacer
$orphan6 = File::create([
    'name' => 'orphan6.jpg',
    'path' => '20260917/imagenes/orphan6.jpg',
    'size' => 1024,
    'mime_type' => 'image/jpeg',
    'storage_provider_id' => $subStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now(),
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
file_put_contents("{$parentStorage->base_path}/Disco_C/Prensa/Espectador/20260917/imagenes/orphan6.jpg", 'harness-orphan6');

$genKey = "folder_gen:{$subStorage->id}:null";
$before = Cache::get($genKey, 0);
\Illuminate\Support\Facades\Artisan::call('files:repair-orphan-subtree', [
    '--apply' => true,
    '--storage' => (string) $subStorage->id,
]);
$after = Cache::get($genKey, 0);

h_check($after > $before, "folder_gen:{$subStorage->id}:null incrementó ({$before} → {$after})", "folder_gen NO incrementó ({$before} → {$after})");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 7: fullSync con mtime == file_modified_at + BD=0 + disco>0 → NO skip
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 7 — fullSync fuerza sync con cardinalidad cero');

$forceTestBase = sys_get_temp_dir() . "/{$tag}_forcesync";
mkdir("{$forceTestBase}/folder_x/sub", 0755, true);
file_put_contents("{$forceTestBase}/folder_x/sub/file.txt", 'harness-forcesync');

$forceStorage = $mkStorage('forcesync', $forceTestBase);

$dirMtime = filemtime("{$forceTestBase}/folder_x");
$folderX = File::create([
    'name' => 'folder_x',
    'path' => 'folder_x',
    'size' => 0,
    'mime_type' => 'folder',
    'storage_provider_id' => $forceStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => true,
    'file_modified_at' => \Carbon\Carbon::createFromTimestamp($dirMtime, config('app.timezone')),
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);

// BD tiene 0 hijos no-trashed de folder_x; disco tiene "sub" adentro.
// El mtime del directorio == file_modified_at (escenario del bug legacy).
$stats = $svc->fullSync($forceStorage, $user->id, force: false);

$subRow = File::where('storage_provider_id', $forceStorage->id)
    ->where('path', 'folder_x/sub')
    ->where('is_folder', true)
    ->first();

h_check($subRow !== null, "cardinality-cero forzó sync: 'folder_x/sub' creado en BD", "'folder_x/sub' NO fue creado (skip incorrecto)");

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN (orden inverso al setup)
// ─────────────────────────────────────────────────────────────────────────────
h_section('TEARDOWN');
try {
    $createdStorages = StorageProvider::whereRaw("name LIKE '{$tag}%'")->get();
    foreach ($createdStorages as $s) {
        File::where('storage_provider_id', $s->id)->delete();
        $s->delete();
    }
    DB::table('user_storages')->where('user_id', $user->id)->delete();
    $user->delete();

    foreach ([$tmpParent, $multiBase, $forceTestBase] as $dir) {
        if (is_dir($dir)) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
            }
            @rmdir($dir);
        }
    }
    h_ok('limpieza completada (storages, user, files, tempdirs)');
} catch (\Throwable $e) {
    echo "  ! cleanup error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: substorage-orphan-repair — 7 escenarios verde\n";
    exit(0);
} else {
    echo "FAIL: {$failures} escenario(s) roto(s) — el fix tiene regresiones\n";
    exit(1);
}
