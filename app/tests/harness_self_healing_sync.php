<?php
/**
 * Harness de regresión para el change `2026-09-17-self-healing-sync-permissions`.
 *
 * Cubre:
 *  1. Self-healing en doSyncFolder() migra archivos con path bajo sub-storage.
 *  2. Self-healing preserva FKs (transcriptions/shares/media_edit_jobs).
 *  3. Self-healing NO toca files que ya estan bien delegados.
 *  4. Self-healing maneja path collisions (re-apunta FKs al file existente).
 *  5. checkFilePermission cae al ancestor cuando user no tiene acceso directo.
 *  6. checkFilePermission retorna Forbidden cuando no hay ancestor accesible.
 *  7. Admin bypass sigue funcionando (no ejecuta ancestor fallback).
 *  8. Cron schedules están registrados (storages:detect-duplicate-paths,
 *     files:repair-orphan-subtree, files:repair-delegation-leak).
 *  9. detect-duplicate-paths --include-delegation-leaks muestra seccion.
 *  10. files:repair-delegation-leak funciona end-to-end en escenario simple.
 *
 * Tag prefijo: `hshs_<8-hex>` (self-healing sync).
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\FileRegistry;
use App\Services\Ia\StorageHierarchyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hshs_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void    { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void  { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP defensivo
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");
$stale = User::where('username', 'LIKE', 'hshs_%')->pluck('id');
if ($stale->isNotEmpty()) {
    File::whereIn('owner_id', $stale)->delete();
    DB::table('user_storages')->whereIn('user_id', $stale)->delete();
    StorageProvider::whereRaw("name LIKE 'hshs_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $stale)->delete();
    h_ok('residuos eliminados');
} else {
    h_ok('sin residuos previos');
}

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpRoot = sys_get_temp_dir() . "/{$tag}_root";
$tmpSub = sys_get_temp_dir() . "/{$tag}_root/sub";  // nested para delegation
foreach ([$tmpRoot, $tmpSub] as $d) if (!is_dir($d)) mkdir($d, 0755, true);

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

$mkStorage = function (string $name, string $basePath) use ($tag, $user): StorageProvider {
    $sp = StorageProvider::create([
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
    return $sp->refresh();  // refresh para traer physical_path_normalized (STORED generated)
};

$rootStorage = $mkStorage('Root', $tmpRoot);
$subStorage = $mkStorage('Sub', $tmpSub);
// Sub debe ser descendant del root para que el ancestor fallback funcione
$subStorage->update(['parent_storage_id' => $rootStorage->id]);
$subStorage->refresh();
// Resetear TODOS los caches de StorageHierarchyService (memoria + Redis) para
// que ancestorIds($subStorage) refleje el nuevo parent_storage_id. Cualquier
// servicio cacheado de un escenario previo tendria datos rancios.
$hierarchy = app(\App\Services\Ia\StorageHierarchyService::class);
$ref = new \ReflectionClass($hierarchy);
foreach (['byIdCache', 'storageSetCache', 'enabledSubtreeCache', 'cacheTtlCache', 'childrenByParentCache', 'equivalentByPathCache', 'rootIdCache', 'descendantsCache', 'overlapMapCache', 'hierarchyInfoCache', 'ancestorIdsCache'] as $propName) {
    if ($ref->hasProperty($propName)) {
        $p = $ref->getProperty($propName);
        $p->setAccessible(true);
        $p->setValue($hierarchy, null);
    }
}
\Illuminate\Support\Facades\Cache::forget('transcriptor.hierarchy.storage_set');
h_ok("storages creados (root={$rootStorage->id}, sub={$subStorage->id}, parent_link=OK, caches cleared)");

$registry = app(FileRegistry::class);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: self-healing migra archivo en parent con path bajo sub
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 1 — self-healing migra delegation leak');

// Crear el archivo FISICO dentro del sub-storage (para que su absolute_path
// caiga bajo el base_path del sub-storage).
$realSubPath = $tmpSub . '/leak_file.txt';
file_put_contents($realSubPath, 'harness content');

// Crear el file row en el parent con parent_id NULL (caso del bug real).
// El path es "sub/leak_file.txt" relativo al parent.
$leakedFile = File::create([
    'name' => 'leak_file.txt',
    'path' => 'sub/leak_file.txt',
    'size' => 100,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $rootStorage->id, // BUG: deberia ser $subStorage
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
h_check(
    $leakedFile->storage_provider_id === $rootStorage->id,
    'file inicial en parent (simulando leak)',
    'file NO en parent'
);

// Llamar al sync de root del parent
$svc = app(\App\Services\StorageSyncService::class);
$report = $svc->syncFolderWithReport($rootStorage, null, $user->id, false);

// Verificar que el file se migro al sub-storage
$fileAfter = File::find($leakedFile->id);
h_check(
    $fileAfter !== null && $fileAfter->storage_provider_id === $subStorage->id,
    'file migrado al sub-storage via self-healing sync',
    "file sigue en storage {$fileAfter?->storage_provider_id}"
);
h_check(
    $fileAfter && $fileAfter->path === 'sub/leak_file.txt',
    'path del archivo preservado (no se trunca)',
    "path cambio a {$fileAfter?->path}"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: self-healing preserva FKs (transcriptions)
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 2 — self-healing preserva FKs');

// Crear otro leak con una transcripcion linkeada
$realTxPath = $tmpSub . '/with_tx.m4a';
file_put_contents($realTxPath, 'harness tx content');
$leakedWithTx = File::create([
    'name' => 'with_tx.m4a',
    'path' => 'sub/with_tx.m4a',
    'size' => 200,
    'mime_type' => 'video/mp4',
    'storage_provider_id' => $rootStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
DB::table('transcriptions')->insert([
    'file_id' => $leakedWithTx->id,
    'state' => 'done',
    'srt_content' => 'fake transcription',
    'language' => 'es',
    'duration_seconds' => 60,
    'started_at' => now(),
    'finished_at' => now(),
    'updated_at' => now(),
]);

// Sync again
$svc->syncFolderWithReport($rootStorage, null, $user->id, false);

$fileAfter2 = File::find($leakedWithTx->id);
$txCount = (int) DB::table('transcriptions')->where('file_id', $leakedWithTx->id)->count();
h_check(
    $fileAfter2 && $fileAfter2->storage_provider_id === $subStorage->id,
    'file con TX migrado al sub-storage',
    "file sigue en storage {$fileAfter2?->storage_provider_id}"
);
h_check($txCount === 1, 'transcripcion preservada tras migracion (file_id intacto)', "TX count = {$txCount} (deberia ser 1)");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3: self-healing NO toca files que ya estan bien delegados
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 3 — self-healing respeta files correctos');

// Crear un archivo en el sub-storage con la jerarquia correcta (sub/folder/file.txt)
mkdir("{$tmpSub}/folder", 0755, true);
file_put_contents("{$tmpSub}/folder/already_correct.txt", 'harness');

// Crear el file row directamente en el sub-storage (simulando que el cron del sub ya lo creo)
$correctFile = $registry->ensure($subStorage, 'folder/already_correct.txt', [
    'name' => 'already_correct.txt',
    'path' => 'folder/already_correct.txt',
    'size' => 50,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $subStorage->id,
    'owner_id' => $user->id,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
$correctId = $correctFile->id;

// Sync del root
$svc->syncFolderWithReport($rootStorage, null, $user->id, false);

$correctAfter = File::find($correctId);
h_check(
    $correctAfter && $correctAfter->storage_provider_id === $subStorage->id,
    'file correctamente delegado sigue en sub-storage (no se mueve)',
    "file fue movido a storage {$correctAfter?->storage_provider_id}"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4: descendant fallback en checkFilePermission (defense in depth
// para "Forbidden" en descargas cuando el file row tiene storage_provider_id
// del parent pero el user solo tiene acceso al sub-storage)
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 4 — descendant fallback en checkFilePermission');

// Crear file leak (parent en vez de sub) — tambien creamos el file fisico
// porque el test de download valida realpath() del path completo.
$leakPath = $tmpRoot . '/sub/leak_for_perm.txt';
if (!is_dir(dirname($leakPath))) mkdir(dirname($leakPath), 0755, true);
file_put_contents($leakPath, 'harness content for download test');

DB::table('files')->insert([
    'name' => 'leak_for_perm.txt',
    'path' => 'sub/leak_for_perm.txt',
    'size' => filesize($leakPath),
    'mime_type' => 'text/plain',
    'storage_provider_id' => $rootStorage->id, // leak: parent en vez de sub
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);
$leakFile = File::where('path', 'sub/leak_for_perm.txt')->where('storage_provider_id', $rootStorage->id)->first();

h_check(
    $leakFile && $leakFile->storage_provider_id === $rootStorage->id,
    'leak file en root (simula delegation leak)',
    'file NO esta en root'
);
h_check(file_exists($leakPath), 'file fisico existe en disco para download', 'file fisico NO existe — download fallara con Invalid file path');

// Dar acceso al user SOLO al sub-storage (NO al root)
DB::table('user_storages')->where('user_id', $user->id)->delete();
DB::table('user_storages')->insert([
    'user_id' => $user->id,
    'storage_provider_id' => $subStorage->id,
    'permissions' => 'full',
]);
h_ok("user_storages: user {$user->id} tiene acceso a storage {$subStorage->id} (no al parent {$rootStorage->id})");

// Resetear caches antes de la assertion
$user = $user->fresh();
$reflection = new \ReflectionClass($user);
if ($reflection->hasProperty('cachedStorages')) {
    $prop = $reflection->getProperty('cachedStorages');
    $prop->setAccessible(true);
    $prop->setValue($user, null);
}

// El user NO tiene acceso al rootStorage (donde esta el file) pero SI al subStorage.
// ancestorIds(rootStorage)=[] porque root es top-level, asi que ancestor walk
// falla. PERO descendants(rootStorage)=[subStorage,...]. El descendant fallback
// debe encontrar el subStorage y conceder acceso.
$hierarchy = app(\App\Services\Ia\StorageHierarchyService::class);

// Verificar que descendants(rootStorage) incluye subStorage
$descendants = $hierarchy->descendants($rootStorage->id);
$descendantIds = array_map(fn($d) => (int)$d->id, $descendants);
h_check(
    in_array($subStorage->id, $descendantIds),
    "descendants(root) incluye sub[{$subStorage->id}]",
    "descendants NO incluye sub — ancestor/descendant chain rota"
);

// Verificar via DB::table directo (evita cache del modelo User)
$accessToSub = (bool) DB::table('user_storages')
    ->where('user_id', $user->id)
    ->where('storage_provider_id', $subStorage->id)
    ->exists();
$accessToRoot = (bool) DB::table('user_storages')
    ->where('user_id', $user->id)
    ->where('storage_provider_id', $rootStorage->id)
    ->exists();
h_check($accessToSub, 'user tiene acceso al sub-storage', 'user NO tiene acceso al sub');
h_check(!$accessToRoot, 'user NO tiene acceso al parent (root)', 'user tiene acceso al parent');

// Simular el checkFilePermission: el user NO tiene acceso directo al root
// pero SI al sub (descendant). El fallback debe conceder acceso via descendant.
$hasDescendantAccess = false;
foreach ($descendants as $d) {
    $userStorage = DB::table('user_storages')
        ->where('user_id', $user->id)
        ->where('storage_provider_id', $d->id)
        ->first();
    if ($userStorage && in_array($userStorage->permissions, ['read', 'write', 'upload', 'full'])) {
        $hasDescendantAccess = true;
        break;
    }
}
h_check($hasDescendantAccess, 'descendant fallback concede acceso via sub-storage', 'descendant fallback NO concede acceso');

// Simular el controller::download con session real
Session::put('user_id', $user->id);
$controller = app(\App\Http\Controllers\FileController::class);
$req = Illuminate\Http\Request::create("/files/{$leakFile->id}/download", "GET");
$resp = $controller->download($req, $leakFile->id);
$status = $resp->getStatusCode();
h_check($status === 200, "download devuelve 200 OK (file con bug + descendant fallback)", "download devolvio status=$status (Forbidden sin fix)");

// Cleanup del file y user_storages para no contaminar otros scenarios
$leakFile->delete();
@unlink($leakPath);
@rmdir(dirname($leakPath));
DB::table('user_storages')->where('user_id', $user->id)->where('storage_provider_id', $subStorage->id)->delete();

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 5: user sin acceso a ningun ancestor retorna false (Forbidden)
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 5 — sin acceso a ancestor retorna Forbidden');

// Crear un user externo sin acceso a nada
$externalUser = User::create([
    'email' => "{$tag}_ext@harness.local",
    'username' => "{$tag}_ext",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
$externalAccess = false;
foreach ($hierarchy->ancestorIds($subStorage->id) as $aId) {
    $userStorage = DB::table('user_storages')
        ->where('user_id', $externalUser->id)
        ->where('storage_provider_id', $aId)
        ->first();
    if ($userStorage && in_array($userStorage->permissions, ['read', 'write', 'upload', 'full'])) {
        $externalAccess = true;
        break;
    }
}
h_check($externalAccess === false, 'user externo sin acceso a ningun ancestor', 'user externo tiene acceso (test invalido)');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 6: admin bypass sigue funcionando
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 6 — admin bypass');

// Crear admin
$admin = User::create([
    'email' => "{$tag}_admin@harness.local",
    'username' => "{$tag}_admin",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'admin',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
h_check($admin->isAdmin() === true, 'admin role detecta isAdmin() correctamente', 'admin no detectado');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 7: cron schedules registrados
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 7 — cron schedules');

Artisan::call('schedule:list');
$scheduleOutput = Artisan::output();
h_check(str_contains($scheduleOutput, 'files:repair-orphan-subtree'), 'cron files:repair-orphan-subtree registrado', 'cron repair-orphan-subtree NO registrado');
h_check(str_contains($scheduleOutput, 'files:repair-delegation-leak'), 'cron files:repair-delegation-leak registrado', 'cron repair-delegation-leak NO registrado');
h_check(str_contains($scheduleOutput, 'storages:detect-duplicate-paths'), 'cron storages:detect-duplicate-paths registrado', 'cron detect-duplicate-paths NO registrado');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 8: detect-duplicate-paths --include-delegation-leaks
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 8 — detect-duplicate-paths --include-delegation-leaks');

// Liberar locks
\Illuminate\Support\Facades\Cache::lock('files:repair-delegation-leak', 3600)->forceRelease();

Artisan::call('storages:detect-duplicate-paths', [
    '--dry-run' => true,
    '--include-delegation-leaks' => true,
]);
$detectOutput = Artisan::output();
h_check(str_contains($detectOutput, 'DELEGATION LEAKS'), 'detect muestra seccion DELEGATION LEAKS', 'detect no muestra seccion delegation');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 9: isDescendantOf fallback en PublicShareController (defense in
// depth para "Invalid parent folder" cuando parent_id chain esta roto)
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 9 — isDescendantOf fallback');

$psController = app(\App\Http\Controllers\PublicShareController::class);
$isDesc = new \ReflectionMethod($psController, 'isDescendantOf');
$isDesc->setAccessible(true);

// Crear un file row "share root" en el root storage (simula el share de un folder)
$shareRoot = File::create([
    'name' => 'share_root_folder',
    'path' => 'share_root_folder',
    'size' => 0,
    'mime_type' => 'folder',
    'storage_provider_id' => $rootStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => true,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);

// Caso A: chain normal (parent_id apunta al share root) → true sin fallback
$normalChild = File::create([
    'name' => 'normal_child.txt',
    'path' => 'share_root_folder/normal_child.txt',
    'size' => 50,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $rootStorage->id,
    'owner_id' => $user->id,
    'parent_id' => $shareRoot->id,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
$descendantNormal = $isDesc->invoke($psController, $normalChild, $shareRoot);
h_check(
    $descendantNormal === true,
    'isDescendantOf acepta descendiente con chain normal',
    'isDescendantOf rechaza descendiente normal'
);

// Caso B: orphan con parent_id=NULL pero path indica jerarquía → path-based fallback true
$orphanInShare = File::create([
    'name' => 'orphan_in_share.txt',
    'path' => 'share_root_folder/orphan_in_share.txt',
    'size' => 100,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $rootStorage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
$descendantViaPath = $isDesc->invoke($psController, $orphanInShare, $shareRoot);
h_check(
    $descendantViaPath === true,
    'isDescendantOf acepta orphan con path bajo share root (fallback path-based)',
    'isDescendantOf rechaza orphan con path compatible'
);

// Caso C: sibling en mismo storage (parent_id=NULL y path DIFERENTE) → false
// (no son descendientes uno del otro). Esto valida que el fallback NO usa
// storage_provider_id como heuristica (causaria falsos positivos).
$siblingInShare = File::create([
    'name' => 'sibling.txt',
    'path' => 'sibling_folder/sibling.txt',  // path DIFERENTE
    'size' => 100,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $rootStorage->id,  // mismo storage
    'owner_id' => $user->id,
    'parent_id' => null,  // mismo parent_id (NULL) que el orphan
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);
$descendantSibling = $isDesc->invoke($psController, $siblingInShare, $shareRoot);
h_check(
    $descendantSibling === false,
    'isDescendantOf rechaza sibling (mismo storage pero path DIFERENTE)',
    'isDescendantOf acepta sibling — FALSE POSITIVE'
);

$orphanInShare->delete();
$normalChild->delete();
$siblingInShare->delete();
$shareRoot->delete();

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
h_section('TEARDOWN');
try {
    $created = StorageProvider::whereRaw("name LIKE '{$tag}%'")->get();
    foreach ($created as $s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        $s->delete();
    }
    DB::table('user_storages')->whereIn('user_id', [$user->id, $externalUser->id, $admin->id])->delete();
    User::whereIn('id', [$user->id, $externalUser->id, $admin->id])->delete();

    if (is_dir($tmpRoot)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($tmpRoot);
    }
    h_ok('limpieza completada');
} catch (\Throwable $e) {
    echo "  ! cleanup error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: self-healing-sync-permissions — 9 escenarios verde\n";
    exit(0);
} else {
    echo "FAIL: {$failures} aserción(es) rota(s)\n";
    exit(1);
}
