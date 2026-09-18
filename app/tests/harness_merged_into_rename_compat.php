<?php
/**
 * Harness de regresión para el change `schema-clarity-rename-merged-into`.
 *
 * Verifica que el rename de columnas dejó el esquema en estado consistente:
 *  - Columna `files.canonical_folder_id` existe con FK + índice renombrados.
 *  - Columna `storage_providers.duplicate_of_storage_id` existe con FK + índice renombrados.
 *  - Las filas existentes siguen resolviendo al mismo dato que antes del rename.
 *  - `File::isFolderMirror()` retorna true/false según `canonical_folder_id`.
 *  - `StorageProvider::isDuplicate()` retorna true/false según `duplicate_of_storage_id`.
 *  - Aliases deprecados (`isMirror()`, `isMerged()`, `canonical()`) siguen funcionando.
 *
 * Tag prefijo: `hmrc_<8-hex>` (merged-into rename compat).
 *
 * Uso:
 *   cd app && php tests/harness_merged_into_rename_compat.php
 *   exit 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

$tag = 'hmrc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP defensivo
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");

$staleUsers = User::where('username', 'LIKE', 'hmrc_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
    DB::table('file_mirror_audit_log')->whereIn('actor_user_id', $staleUsers)->delete();
    DB::table('shares')->whereIn('created_by', $staleUsers)->delete();
    File::whereIn('owner_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    StorageProvider::whereRaw("name LIKE 'hmrc_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $staleUsers)->delete();
    h_ok('residuos eliminados (' . $staleUsers->count() . ' users)');
} else {
    h_ok('sin residuos previos');
}

// ─────────────────────────────────────────────────────────────────────────────
// (1) Schema: columnas renombradas, FK constraints renombrados, índices renombrados
// ─────────────────────────────────────────────────────────────────────────────
h_section('(1) Schema: columnas / FK / índices');

// files.canonical_folder_id
$filesCol = DB::selectOne("
    SELECT column_name FROM information_schema.columns
    WHERE table_name = 'files' AND column_name = 'canonical_folder_id'
");
h_check($filesCol !== null, 'files.canonical_folder_id existe', 'files.canonical_folder_id NO existe');

$oldFilesCol = DB::selectOne("
    SELECT column_name FROM information_schema.columns
    WHERE table_name = 'files' AND column_name = 'merged_into_id'
");
h_check($oldFilesCol === null, 'files.merged_into_id NO existe (rename completo)', 'files.merged_into_id SIGUE existiendo — rename incompleto');

$filesFk = DB::selectOne("
    SELECT conname FROM pg_constraint
    WHERE conrelid = 'files'::regclass AND conname = 'files_canonical_folder_id_fkey'
");
h_check($filesFk !== null, 'FK files_canonical_folder_id_fkey existe', 'FK files_canonical_folder_id_fkey NO existe');

$filesIdx = DB::selectOne("
    SELECT indexname FROM pg_indexes
    WHERE tablename = 'files' AND indexname = 'files_canonical_folder_id_idx'
");
h_check($filesIdx !== null, 'index files_canonical_folder_id_idx existe', 'index files_canonical_folder_id_idx NO existe');

// storage_providers.duplicate_of_storage_id
$spCol = DB::selectOne("
    SELECT column_name FROM information_schema.columns
    WHERE table_name = 'storage_providers' AND column_name = 'duplicate_of_storage_id'
");
h_check($spCol !== null, 'storage_providers.duplicate_of_storage_id existe', 'storage_providers.duplicate_of_storage_id NO existe');

$oldSpCol = DB::selectOne("
    SELECT column_name FROM information_schema.columns
    WHERE table_name = 'storage_providers' AND column_name = 'merged_into_id'
");
h_check($oldSpCol === null, 'storage_providers.merged_into_id NO existe (rename completo)', 'storage_providers.merged_into_id SIGUE existiendo — rename incompleto');

$spFk = DB::selectOne("
    SELECT conname FROM pg_constraint
    WHERE conrelid = 'storage_providers'::regclass AND conname = 'storage_providers_duplicate_of_storage_id_fkey'
");
h_check($spFk !== null, 'FK storage_providers_duplicate_of_storage_id_fkey existe', 'FK NO existe');

$spIdx = DB::selectOne("
    SELECT indexname FROM pg_indexes
    WHERE tablename = 'storage_providers' AND indexname = 'storage_providers_duplicate_of_storage_id_idx'
");
h_check($spIdx !== null, 'index storage_providers_duplicate_of_storage_id_idx existe', 'index NO existe');

// ─────────────────────────────────────────────────────────────────────────────
// (2) SETUP: 1 storage dup pair, 1 folder mirror pair
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpDir1 = sys_get_temp_dir() . "/{$tag}_a";
$tmpDir2 = sys_get_temp_dir() . "/{$tag}_a/sub";
foreach ([$tmpDir1, $tmpDir2] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
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
h_ok("user harness creado (id={$user->id})");

$spCanonical = StorageProvider::create([
    'name' => "{$tag} canonical",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpDir1,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
])->refresh();

$spDuplicate = StorageProvider::create([
    'name' => "{$tag} duplicate",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpDir2,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
])->refresh();

$canonicalFolder = File::create([
    'name' => 'shared_folder',
    'path' => 'shared_folder',
    'storage_provider_id' => $spDuplicate->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
])->refresh();

$mirrorFolder = File::create([
    'name' => 'shared_folder',
    'path' => 'sub/shared_folder',
    'storage_provider_id' => $spCanonical->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
])->refresh();

h_ok("sp: canonical={$spCanonical->id}, duplicate={$spDuplicate->id}");
h_ok("folders: canonical={$canonicalFolder->id}, mirror={$mirrorFolder->id}");

// Marcar el spDuplicate como mergeado hacia spCanonical
DB::table('storage_providers')->where('id', $spDuplicate->id)->update([
    'duplicate_of_storage_id' => $spCanonical->id,
    'merged_at' => now(),
    'merged_reason' => 'harness_test',
    'enabled' => false,
    'base_path' => null,
]);
$spDuplicate->refresh();

// Marcar el mirrorFolder como mirror del canonicalFolder
DB::table('files')->where('id', $mirrorFolder->id)->update([
    'canonical_folder_id' => $canonicalFolder->id,
    'merged_at' => now(),
    'merged_reason' => 'harness_test',
]);
$mirrorFolder->refresh();

// ─────────────────────────────────────────────────────────────────────────────
// (3) Conteo: WHERE canonical_folder_id IS NOT NULL == <previo merged_into_id IS NOT NULL>
// ─────────────────────────────────────────────────────────────────────────────
h_section('(3) Conteo equivalente pre/post rename');

$countNew = (int) DB::table('files')
    ->where('canonical_folder_id', $canonicalFolder->id)
    ->whereNull('deleted_at')
    ->count();
h_check(
    $countNew === 1,
    "files WHERE canonical_folder_id = {$canonicalFolder->id} = 1",
    "files WHERE canonical_folder_id = {$canonicalFolder->id} = {$countNew} (esperado 1)"
);

$countSp = (int) DB::table('storage_providers')
    ->where('duplicate_of_storage_id', $spCanonical->id)
    ->count();
h_check(
    $countSp === 1,
    "storage_providers WHERE duplicate_of_storage_id = {$spCanonical->id} = 1",
    "storage_providers WHERE duplicate_of_storage_id = {$spCanonical->id} = {$countSp} (esperado 1)"
);

// ─────────────────────────────────────────────────────────────────────────────
// (4) Helpers del modelo
// ─────────────────────────────────────────────────────────────────────────────
h_section('(4) Helpers del modelo');

h_check(
    $mirrorFolder->isFolderMirror() === true,
    'mirror.isFolderMirror() === true',
    'mirror.isFolderMirror() !== true'
);
h_check(
    $mirrorFolder->isMirror() === true,
    'mirror.isMirror() (alias deprecado) === true',
    'mirror.isMirror() !== true — alias deprecado roto'
);
h_check(
    $canonicalFolder->isFolderMirror() === false,
    'canonical.isFolderMirror() === false',
    'canonical.isFolderMirror() !== false'
);
h_check(
    $canonicalFolder->isMirror() === false,
    'canonical.isMirror() (alias deprecado) === false',
    'canonical.isMirror() !== false — alias deprecado roto'
);

$resolvedMirror = $mirrorFolder->canonicalFolder();
h_check(
    $resolvedMirror !== null && $resolvedMirror->id === $canonicalFolder->id,
    'mirror.canonicalFolder() devuelve canonical',
    'mirror.canonicalFolder() no devolvió el canónico'
);
$resolvedMirrorAlias = $mirrorFolder->canonical();
h_check(
    $resolvedMirrorAlias !== null && $resolvedMirrorAlias->id === $canonicalFolder->id,
    'mirror.canonical() (alias deprecado) devuelve canonical',
    'mirror.canonical() no devolvió el canónico — alias deprecado roto'
);

h_check(
    $spDuplicate->isDuplicate() === true,
    'spDuplicate.isDuplicate() === true',
    'spDuplicate.isDuplicate() !== true'
);
h_check(
    $spDuplicate->isMerged() === true,
    'spDuplicate.isMerged() (alias deprecado) === true',
    'spDuplicate.isMerged() !== true — alias deprecado roto'
);
h_check(
    $spCanonical->isDuplicate() === false,
    'spCanonical.isDuplicate() === false',
    'spCanonical.isDuplicate() !== false'
);
h_check(
    $spCanonical->isMerged() === false,
    'spCanonical.isMerged() (alias deprecado) === false',
    'spCanonical.isMerged() !== false — alias deprecado roto'
);

// ─────────────────────────────────────────────────────────────────────────────
// (5) Query directo via Eloquent con nombre nuevo
// ─────────────────────────────────────────────────────────────────────────────
h_section('(5) Query Eloquent directo');

$found = File::where('canonical_folder_id', $canonicalFolder->id)->first();
h_check(
    $found !== null && $found->id === $mirrorFolder->id,
    'File::where(canonical_folder_id) encuentra el mirror',
    'File::where(canonical_folder_id) no encontró el mirror'
);

$foundSp = StorageProvider::where('duplicate_of_storage_id', $spCanonical->id)->first();
h_check(
    $foundSp !== null && $foundSp->id === $spDuplicate->id,
    'StorageProvider::where(duplicate_of_storage_id) encuentra el duplicate',
    'StorageProvider::where(duplicate_of_storage_id) no encontró el duplicate'
);

$excluded = StorageProvider::whereNull('duplicate_of_storage_id')
    ->where('id', $spDuplicate->id)
    ->exists();
h_check(
    $excluded === false,
    'StorageProvider::whereNull(duplicate_of_storage_id) excluye el duplicate',
    'StorageProvider::whereNull(duplicate_of_storage_id) NO excluyó el duplicate'
);

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP [tag=$tag]");

DB::table('file_mirror_audit_log')->whereIn('actor_user_id', [$user->id])->delete();
DB::table('shares')->where('created_by', $user->id)->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->where('user_id', $user->id)->delete();
StorageProvider::where('id', $spDuplicate->id)->delete();
StorageProvider::where('id', $spCanonical->id)->delete();
$user->delete();

@rmdir($tmpDir2);
@rmdir($tmpDir1);

h_ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness merged-into rename compat | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);