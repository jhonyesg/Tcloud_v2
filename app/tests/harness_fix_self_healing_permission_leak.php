<?php
/**
 * Harness de regresión para el change `2026-09-17-fix-self-healing-permission-leak`.
 *
 * Valida que `FileController::checkFilePermission` ya no bypassa permisos
 * via el "descendant walk" que permitía a un usuario con acceso al
 * sub-storage (hijo) descargar archivos del storage general (padre).
 *
 * Escenarios:
 *  (a) User sin acceso al storage del file → checkFilePermission = FALSE
 *  (b) Admin → checkFilePermission = TRUE
 *  (c) Owner del file → checkFilePermission = TRUE
 *  (d) User con acceso al sub-storage, file en parent → checkFilePermission = FALSE (FIX)
 *  (e) User con acceso directo al storage del file → checkFilePermission = TRUE
 *
 * Tag prefijo: `fpl_<8-hex>` (fix permission leak).
 *
 * Uso:
 *   cd app && php tests/harness_fix_self_healing_permission_leak.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'fpl_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function ok(string $m): void { echo "  ✓ $m\n"; }
function bad(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function section(string $m): void { echo "\n=== $m ===\n"; }
function check(bool $c, string $o, string $f): void
{
    if ($c) ok($o); else bad($f);
}

// ─── Limpieza defensiva ───
section("CLEANUP defensivo [tag=$tag]");
$stale = User::where('username', 'LIKE', 'fpl_%')->pluck('id');
if ($stale->isNotEmpty()) {
    DB::table('file_mirror_audit_log')->whereIn('actor_user_id', $stale)->delete();
    File::whereIn('owner_id', $stale)->delete();
    UserStorage::whereIn('user_id', $stale)->delete();
    StorageProvider::whereRaw("name LIKE 'fpl_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        UserStorage::where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $stale)->delete();
    ok('residuos eliminados');
} else {
    ok('sin residuos previos');
}

// ─── SETUP: crear storage padre + sub-storage + user regular ───
section("SETUP [tag=$tag]");

$tmpBase = sys_get_temp_dir() . "/{$tag}";
if (!is_dir($tmpBase)) mkdir($tmpBase, 0755, true);
$tmpBaseSub = "{$tmpBase}/tv";
if (!is_dir($tmpBaseSub)) mkdir($tmpBaseSub, 0755, true);

$parent = StorageProvider::create([
    'name' => "{$tag}_parent", 'type' => 'local', 'config' => [],
    'base_path' => $tmpBase, 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local',
])->refresh();

$sub = StorageProvider::create([
    'name' => "{$tag}_sub", 'type' => 'local', 'config' => [],
    'base_path' => $tmpBaseSub, 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local', 'parent_storage_id' => $parent->id,
])->refresh();

$user = User::create([
    'email' => "{$tag}@harness.local",
    'username' => $tag,
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
ok("user regular creado (id={$user->id})");

// User solo tiene acceso al SUB-storage (no al parent).
UserStorage::create([
    'user_id' => $user->id,
    'storage_provider_id' => $sub->id,
    'permissions' => 'read',
    'can_create_shares' => false,
    'assigned_at' => now(),
]);
ok("user solo tiene acceso al SUB-storage id={$sub->id}");

// Crear file en el PARENT (parent view row) con owner DIFERENTE del user test.
// owner_id=1 (jsuarez, admin) para que el owner check NO conceda acceso.
// Eso fuerza a que checkFilePermission solo pueda conceder via admin o via
// storage_id directo — y ambos fallan para el user regular.
$fileInParent = File::create([
    'name' => 'test.pdf',
    'path' => 'tv/test.pdf',
    'storage_provider_id' => $parent->id,
    'parent_id' => null,
    'is_folder' => false,
    'owner_id' => 1,  // jsuarez (no es el user regular)
    'mime_type' => 'application/pdf',
    'size' => 1024,
])->refresh();
ok("file creado en PARENT (id={$fileInParent->id}, storage={$fileInParent->storage_provider_id}, owner=1)");

// Crear file en el SUB (user debería tener acceso directo).
$fileInSub = File::create([
    'name' => 'subfile.txt',
    'path' => 'subfile.txt',
    'storage_provider_id' => $sub->id,
    'parent_id' => null,
    'is_folder' => false,
    'owner_id' => $user->id,
    'mime_type' => 'text/plain',
    'size' => 100,
])->refresh();
ok("file creado en SUB (id={$fileInSub->id}, storage={$fileInSub->storage_provider_id})");

// Crear file en el PARENT pero con owner_id DIFERENTE al user (jsuarez-style).
$fileParentOtherOwner = File::create([
    'name' => 'other.pdf',
    'path' => 'tv/other.pdf',
    'storage_provider_id' => $parent->id,
    'parent_id' => null,
    'is_folder' => false,
    'owner_id' => 1,  // jsuarez (admin), no el user regular
    'mime_type' => 'application/pdf',
    'size' => 2048,
])->refresh();
ok("file creado en PARENT con owner_id=1 (admin)");

// ─── Obtener el controller ───
$controller = app(\App\Http\Controllers\FileController::class);
$method = new ReflectionMethod($controller, 'checkFilePermission');
$method->setAccessible(true);

// ─── ESCENARIO (a): User sin acceso al storage → FALSE ───
section('ESCENARIO (a) — User sin acceso al storage → DENEGADO');
$result = $method->invoke($controller, $fileInParent, 'read');
check(
    $result === false,
    "checkFilePermission(fileInParent, read) = FALSE (esperado)",
    "BUG: user sin acceso al parent recibió acceso: " . var_export($result, true)
);

// ─── ESCENARIO (b): Admin → TRUE ───
section('ESCENARIO (b) — Admin → permitido');
$admin = User::find(1); // jsuarez
session()->put('user_id', $admin->id);
$result = $method->invoke($controller, $fileInParent, 'read');
check(
    $result === true,
    "checkFilePermission(fileInParent, admin) = TRUE",
    "BUG: admin fue denegado: " . var_export($result, true)
);

// ─── ESCENARIO (c): Owner del file → TRUE ───
section('ESCENARIO (c) — Owner del file → permitido');
$fileOwnedByUser = File::create([
    'name' => 'owned.txt',
    'path' => 'tv/owned.txt',
    'storage_provider_id' => $parent->id,
    'parent_id' => null,
    'is_folder' => false,
    'owner_id' => $user->id,
    'mime_type' => 'text/plain',
    'size' => 100,
])->refresh();
$result = $method->invoke($controller, $fileOwnedByUser, 'read');
check(
    $result === true,
    "checkFilePermission(fileOwnedByUser, owner) = TRUE",
    "BUG: owner fue denegado: " . var_export($result, true)
);

// ─── ESCENARIO (d): User regular con acceso solo al SUB, file en PARENT → FALSE (FIX) ───
section('ESCENARIO (d) — FIX: user con sub-storage no debe acceder al parent');
session()->put('user_id', $user->id);
$result = $method->invoke($controller, $fileInParent, 'read');
check(
    $result === false,
    "checkFilePermission(fileInParent={$fileInParent->id}, user sub-storage) = FALSE",
    "BUG CRÍTICO: descendant walk concedió acceso (file en parent, user solo tiene sub): " . var_export($result, true)
);

// ─── ESCENARIO (e): User con acceso al SUB, file en SUB → TRUE ───
section('ESCENARIO (e) — User regular con acceso directo al SUB, file en SUB → permitido');
session()->put('user_id', $user->id);
$result = $method->invoke($controller, $fileInSub, 'read');
check(
    $result === true,
    "checkFilePermission(fileInSub, user sub-storage) = TRUE",
    "BUG: user con acceso directo al sub fue denegado: " . var_export($result, true)
);

// ─── ESCENARIO (f): User regular sin acceso NI al parent NI al sub → FALSE ───
section('ESCENARIO (f) — User sin ningún acceso');
$other = User::create([
    'email' => "{$tag}_other@harness.local",
    'username' => "{$tag}_other",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
session()->put('user_id', $other->id);
$result = $method->invoke($controller, $fileInParent, 'read');
check(
    $result === false,
    "checkFilePermission(fileInParent, user sin acceso) = FALSE",
    "BUG: user sin acceso fue admitido: " . var_export($result, true)
);

// ─── Limpieza ───
section("CLEANUP [tag=$tag]");
File::where('owner_id', $user->id)->delete();
File::where('owner_id', $other->id)->delete();
File::whereIn('id', [$fileInParent->id, $fileParentOtherOwner->id, $fileOwnedByUser->id])->delete();
UserStorage::where('user_id', $user->id)->delete();
UserStorage::where('user_id', $other->id)->delete();
StorageProvider::where('id', $sub->id)->delete();
StorageProvider::where('id', $parent->id)->delete();
$user->delete();
$other->delete();
@rmdir($tmpBaseSub);
@rmdir($tmpBase);
ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness fix-self-healing-permission-leak | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);