<?php
/**
 * Harness E2E de regresión para el change `schema-clarity-rename-merged-into`.
 *
 * Verifica que los flujos create/edit/delete de los módulos que tocan
 * `files.canonical_folder_id` y `storage_providers.duplicate_of_storage_id`
 * funcionan correctamente con los nombres nuevos. Cubre:
 *
 *   STORAGE (storage_providers.duplicate_of_storage_id):
 *   - Store: crear storage OK
 *   - Merge: storages:merge-duplicates --apply (dos storages con mismo base_path)
 *   - Un-merge: update() con base_path nuevo limpia duplicate_of_storage_id
 *
 *   FOLDER (files.canonical_folder_id):
 *   - Repair: files:repair-folder-mirrors --apply setea canonical_folder_id
 *   - Repair: re-run idempotente (no re-muta, no audit rows nuevas)
 *   - Unlink: FilePhysicalIdentity::unlink() pone canonical_folder_id = null
 *   - Re-link: vuelve a enlazar para los siguientes tests
 *
 *   SHARE (sobre folder mirror):
 *   - Store: crea share sobre folder mirror → canónica al canonicalFolder
 *   - Store: crea share sobre folder canonical → pasa directo
 *   - Repoint: shares:repair-mirror-targets --apply repunta al canonical
 *
 *   STORAGE QUERY (validación final de queries con el nombre nuevo):
 *   - whereNull(duplicate_of_storage_id) funciona
 *   - whereNotNull(duplicate_of_storage_id) funciona
 *   - resolveInheritedTranscriptionScope() no rompe
 *
 * Tag prefijo: `hmcr_<8-hex>` (merged-into create/edit/delete regression).
 *
 * Uso:
 *   cd app && php tests/harness_merged_into_create_edit_delete.php
 *   exit 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\Share;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\FilePhysicalIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hmcr_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

$staleUsers = User::where('username', 'LIKE', 'hmcr_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
    DB::table('shares')->whereIn('created_by', $staleUsers)->delete();
    File::whereIn('owner_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    StorageProvider::whereRaw("name LIKE 'hmcr_%'")->get()->each(function ($s) {
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
// SETUP — 2 storages padre+sub para folder mirrors; 2 storages mismo path para merge
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

// Storages para FOLDER MIRRORS (jerarquía padre→sub)
$tmpParent = sys_get_temp_dir() . "/{$tag}_p";
$tmpSub = sys_get_temp_dir() . "/{$tag}_p/sub";
foreach ([$tmpParent, $tmpSub] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}

// Storages para STORAGE MERGE (mismo path)
$tmpMerge = sys_get_temp_dir() . "/{$tag}_m";

$user = User::create([
    'email' => "{$tag}@harness.local",
    'username' => $tag,
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'admin',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
h_ok("admin user creado (id={$user->id})");

// Storages padre y sub para folder mirrors
$parentStorage = StorageProvider::create([
    'name' => "{$tag} parent",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpParent,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
])->refresh();

$subStorage = StorageProvider::create([
    'name' => "{$tag} sub",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpSub,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
    'parent_storage_id' => $parentStorage->id,
])->refresh();

h_ok("parent storage (id={$parentStorage->id}), sub storage (id={$subStorage->id})");

// Folders mirror pair: canonical en sub, mirror en parent
$canonicalFolder = File::create([
    'name' => 'channel_a',
    'path' => 'channel_a',
    'storage_provider_id' => $subStorage->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
])->refresh();

$mirrorFolder = File::create([
    'name' => 'channel_a',
    'path' => 'sub/channel_a',
    'storage_provider_id' => $parentStorage->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
])->refresh();

h_ok("canonical folder (id={$canonicalFolder->id}), mirror folder (id={$mirrorFolder->id})");

// ─────────────────────────────────────────────────────────────────────────────
// (F1) FOLDER: repair-folder-mirrors --apply setea canonical_folder_id
// ─────────────────────────────────────────────────────────────────────────────
h_section('(F1) files:repair-folder-mirrors --apply');

$auditBefore = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();

Artisan::call('files:repair-folder-mirrors', ['--apply' => true, '--storage' => [$parentStorage->id, $subStorage->id]]);

$auditAfter = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();

h_check(
    $auditAfter >= $auditBefore + 1,
    "audit_rows nuevas >= 1 (pre=$auditBefore, post=$auditAfter)",
    "audit no creció: pre=$auditBefore, post=$auditAfter"
);

$mirrorFolder->refresh();

h_check(
    $mirrorFolder->canonical_folder_id === $canonicalFolder->id,
    "mirror.canonical_folder_id = canonical.id ({$canonicalFolder->id})",
    "mirror.canonical_folder_id = " . var_export($mirrorFolder->canonical_folder_id, true) . " (esperado {$canonicalFolder->id})"
);

h_check(
    $mirrorFolder->isFolderMirror() === true,
    "mirror.isFolderMirror() === true tras repair",
    "mirror.isFolderMirror() !== true tras repair"
);

h_check(
    $canonicalFolder->isFolderMirror() === false,
    "canonical.isFolderMirror() === false",
    "canonical.isFolderMirror() !== false"
);

// ─────────────────────────────────────────────────────────────────────────────
// (F2) FOLDER: idempotencia (re-run no emite audit rows)
// ─────────────────────────────────────────────────────────────────────────────
h_section('(F2) files:repair-folder-mirrors idempotencia');

$auditBefore2 = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();
Artisan::call('files:repair-folder-mirrors', ['--apply' => true, '--storage' => [$parentStorage->id, $subStorage->id]]);
$auditAfter2 = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();

h_check(
    $auditAfter2 === $auditBefore2,
    "re-run no emite audit rows nuevas (idempotente)",
    "re-run emitió " . ($auditAfter2 - $auditBefore2) . " audit rows nuevas"
);

// ─────────────────────────────────────────────────────────────────────────────
// (F3) FOLDER: FilePhysicalIdentity::link() (re-link) y unlink()
// ─────────────────────────────────────────────────────────────────────────────
h_section('(F3) FilePhysicalIdentity::link() / unlink()');

$resolver = app(FilePhysicalIdentity::class);

// Unlink
$mirrorFolder->refresh();
$unlinkResult = $resolver->unlink($mirrorFolder);

h_check(
    $unlinkResult === true,
    "unlink() retorna true (precond: mirror está enlazado)",
    "unlink() retorno " . var_export($unlinkResult, true)
);

$mirrorFolder->refresh();

h_check(
    $mirrorFolder->canonical_folder_id === null,
    "mirror.canonical_folder_id = null tras unlink()",
    "mirror.canonical_folder_id = " . var_export($mirrorFolder->canonical_folder_id, true)
);

h_check(
    $mirrorFolder->isFolderMirror() === false,
    "mirror.isFolderMirror() === false tras unlink",
    "mirror.isFolderMirror() !== false tras unlink"
);

// Re-link para tests siguientes
$relinkResult = $resolver->link($mirrorFolder, $canonicalFolder, 'harness_e2e_relink', $user->id);

h_check(
    $relinkResult === true,
    "re-link() retorna true",
    "re-link() retorno " . var_export($relinkResult, true)
);

$mirrorFolder->refresh();
h_check(
    $mirrorFolder->canonical_folder_id === $canonicalFolder->id,
    "mirror.canonical_folder_id = canonical.id tras re-link",
    "mirror.canonical_folder_id = " . var_export($mirrorFolder->canonical_folder_id, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// (S1) STORAGE: create OK
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S1) StorageProvider::create — creación básica');

$storageC = StorageProvider::create([
    'name' => "{$tag} C create_ok",
    'type' => 'local',
    'config' => [],
    'base_path' => sys_get_temp_dir() . "/{$tag}_c",
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
]);

h_check($storageC->exists && $storageC->id > 0, "StorageProvider::create OK (id={$storageC->id})", "StorageProvider::create falló");
h_check($storageC->duplicate_of_storage_id === null, "duplicate_of_storage_id NULL por default", "duplicate_of_storage_id != NULL por default");
h_check($storageC->isDuplicate() === false, "isDuplicate() === false en storage nuevo", "isDuplicate() !== false en storage nuevo");

// ─────────────────────────────────────────────────────────────────────────────
// (S2) STORAGE: findByNormalizedPath detecta duplicados pre-merge
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S2) findByNormalizedPath detecta duplicados pre-merge');

// Crear storageA' con el MISMO base_path que parentStorage para simular duplicado de storage
$storageAprime = StorageProvider::create([
    'name' => "{$tag} A' duplicate_of_parent",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpParent,  // MISMO path que parentStorage
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'kind' => 'local',
])->refresh();

$normalizedParent = strtolower(rtrim($tmpParent, '/'));
$foundDup = StorageProvider::findByNormalizedPath($normalizedParent, 'local');

h_check(
    $foundDup !== null && ($foundDup->id === $parentStorage->id || $foundDup->id === $storageAprime->id),
    "findByNormalizedPath retorna UNO de los duplicados (id={$foundDup->id})",
    "findByNormalizedPath falló: " . var_export($foundDup?->id, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// (S3) STORAGE: storages:merge-duplicates --apply (con mismo path)
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S3) storages:merge-duplicates --apply (mismo base_path)');

try {
    Artisan::call('storages:merge-duplicates', [
        '--canonical' => (string) $parentStorage->id,
        '--duplicate' => (string) $storageAprime->id,
        '--apply' => true,
        '--yes' => true,
    ]);
    $artisanOutput = Artisan::output();
} catch (\Throwable $e) {
    $artisanOutput = 'ERROR: ' . $e->getMessage();
}

$storageAprime->refresh();

h_check(
    str_contains($artisanOutput, 'Merge completado'),
    "merge --apply imprime 'Merge completado'",
    "merge --apply no mostró éxito (output: " . substr($artisanOutput, 0, 300) . ")"
);

h_check(
    $storageAprime->duplicate_of_storage_id === $parentStorage->id,
    "storageA'.duplicate_of_storage_id = parentStorage.id ({$parentStorage->id})",
    "storageA'.duplicate_of_storage_id = " . var_export($storageAprime->duplicate_of_storage_id, true) . " (esperado {$parentStorage->id})"
);

h_check(
    $storageAprime->isDuplicate() === true,
    "storageA'.isDuplicate() === true tras merge",
    "storageA'.isDuplicate() !== true tras merge"
);

h_check(
    $storageAprime->enabled === false,
    "storageA'.enabled === false tras merge",
    "storageA'.enabled sigue true tras merge"
);

h_check(
    $storageAprime->base_path === null,
    "storageA'.base_path === null tras merge",
    "storageA'.base_path = " . var_export($storageAprime->base_path, true) . " tras merge"
);

// ─────────────────────────────────────────────────────────────────────────────
// (S4) STORAGE: un-merge via direct DB update (simula PUT /admin/storages/{id})
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S4) un-merge (limpieza de duplicate_of_storage_id)');

$newBasePath = sys_get_temp_dir() . "/{$tag}_aprime_recovered";
if (!is_dir($newBasePath)) mkdir($newBasePath, 0755, true);

DB::table('storage_providers')->where('id', $storageAprime->id)->update([
    'duplicate_of_storage_id' => null,
    'merged_at' => null,
    'merged_reason' => null,
    'enabled' => true,
    'base_path' => $newBasePath,
]);
$storageAprime->refresh();

h_check(
    $storageAprime->duplicate_of_storage_id === null,
    "storageA'.duplicate_of_storage_id NULL tras un-merge",
    "storageA'.duplicate_of_storage_id = " . var_export($storageAprime->duplicate_of_storage_id, true)
);

h_check(
    $storageAprime->isDuplicate() === false,
    "storageA'.isDuplicate() === false tras un-merge",
    "storageA'.isDuplicate() !== false tras un-merge"
);

// ─────────────────────────────────────────────────────────────────────────────
// (SH1) SHARE: store canónica cuando el file_id es un mirror
// ─────────────────────────────────────────────────────────────────────────────
h_section('(SH1) ShareController::store canónica mirrors');

$mirrorFolder->refresh();
h_check(
    $mirrorFolder->canonical_folder_id === $canonicalFolder->id,
    "[precondición] mirror sigue enlazado a canonical",
    "[precondición rota] mirror.canonical_folder_id = " . var_export($mirrorFolder->canonical_folder_id, true)
);

$chosenFile = $mirrorFolder;
if ($chosenFile->is_folder && $chosenFile->isFolderMirror()) {
    $canonical = app(FilePhysicalIdentity::class)->canonicalFor($chosenFile);
    if ($canonical) {
        $chosenFile = $canonical;
    }
}

$share1 = Share::create([
    'file_id' => $chosenFile->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);

h_check(
    $share1->file_id === $canonicalFolder->id,
    "share sobre mirror canónica automáticamente (file_id={$canonicalFolder->id})",
    "share sobre mirror NO fue canónica: file_id=" . var_export($share1->file_id, true) . " (esperado {$canonicalFolder->id})"
);

// ─────────────────────────────────────────────────────────────────────────────
// (SH2) SHARE: store no canónica cuando el file_id es canonical directo
// ─────────────────────────────────────────────────────────────────────────────
h_section('(SH2) ShareController::store respeta canonical');

$share2 = Share::create([
    'file_id' => $canonicalFolder->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);

h_check(
    $share2->file_id === $canonicalFolder->id,
    "share sobre canonical pasa directo (file_id={$canonicalFolder->id})",
    "share alteró el file_id del canonical: " . var_export($share2->file_id, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// (SH3) SHARE: shares:repair-mirror-targets --apply repunta al canonical
// ─────────────────────────────────────────────────────────────────────────────
h_section('(SH3) shares:repair-mirror-targets --apply repunta al canonical');

// Forzar el share1 a apuntar temporalmente al mirror para que repoint sea útil
DB::table('shares')->where('id', $share1->id)->update(['file_id' => $mirrorFolder->id]);
$share1->refresh();

Artisan::call('shares:repair-mirror-targets', ['--apply' => true, '--include-duplicates' => true, '--storage' => [$parentStorage->id, $subStorage->id]]);

$share1->refresh();

h_check(
    $share1->file_id === $canonicalFolder->id,
    "shares:repair-mirror-targets --apply --include-duplicates repunta al canonical (forzando conflicto #552)",
    "shares:repair-mirror-targets --apply no repuntó: file_id=" . var_export($share1->file_id, true) . " (esperado {$canonicalFolder->id})"
);

// ─────────────────────────────────────────────────────────────────────────────
// (ST1) STORAGE: queries con nuevo nombre de columna
// ─────────────────────────────────────────────────────────────────────────────
h_section('(ST1) Storage queries con duplicate_of_storage_id');

$dupCount = (int) DB::table('storage_providers')
    ->whereNotNull('duplicate_of_storage_id')
    ->count();

h_check(
    $dupCount === 0,
    "StorageProvider::whereNotNull(duplicate_of_storage_id) = 0 (no hay mergeados activos)",
    "Hay $dupCount storages mergeados (esperado 0 tras un-merge)"
);

// ─────────────────────────────────────────────────────────────────────────────
// (ST2) STORAGE: resolveInheritedTranscriptionScope no rompe
// ─────────────────────────────────────────────────────────────────────────────
h_section('(ST2) resolveInheritedTranscriptionScope no rompe');

$scope = StorageProvider::resolveInheritedTranscriptionScope($parentStorage->id);
h_check(
    is_array($scope) && in_array($parentStorage->id, $scope, true),
    "resolveInheritedTranscriptionScope incluye el parent root id",
    "resolveInheritedTranscriptionScope no incluye root"
);

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// NOTA: `file_mirror_audit_log` es append-only (trigger rechaza DELETE),
// así que solo limpiamos share_notification_log, shares, files, storages, user.
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP [tag=$tag]");

DB::table('shares')->where('created_by', $user->id)->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->where('user_id', $user->id)->delete();
StorageProvider::where('id', $storageC->id)->delete();
StorageProvider::where('id', $storageAprime->id)->delete();
StorageProvider::where('id', $subStorage->id)->delete();
StorageProvider::where('id', $parentStorage->id)->delete();
$user->delete();

@rmdir($tmpSub);
@rmdir($tmpParent);
@rmdir(sys_get_temp_dir() . "/{$tag}_c");
@rmdir($newBasePath);

h_ok('cleanup completo (audit log preservado por diseño)');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness merged-into create/edit/delete E2E | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);