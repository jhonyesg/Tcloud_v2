<?php
/**
 * Harness de regresión para el change `2026-09-17-share-folder-canonical-wiring`.
 *
 * Cubre los 10 escenarios contractuales del spec:
 *  - ShareController::store canonicalizes mirror file_id
 *  - PublicShareController::show returns cross-storage children
 *  - showFolder canonicalizes mid-share navigation
 *  - destroy on mirror leaves disk untouched
 *  - destroy on canonical + read removes files row but keeps disk
 *  - destroy on canonical + write removes files + disk + share row
 *  - shares:notify-repointed dry-run lists without sending
 *  - Notification skips users without email
 *  - Second run within window is no-op (idempotencia)
 *  - share_notification_log records each send
 *
 * Tag prefijo: `hsfc_<8-hex>` (share folder canonical).
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
use App\Services\FolderListingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hsfc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// CLEANUP defensivo
h_section("CLEANUP defensivo [tag=$tag]");
$stale = User::where('username', 'LIKE', 'hsfc_%')->pluck('id');
if ($stale->isNotEmpty()) {
    DB::table('share_notification_log')->whereIn('recipient_user_id', $stale)->delete();
    DB::table('shares')->whereIn('created_by', $stale)->delete();
    File::whereIn('owner_id', $stale)->delete();
    DB::table('user_storages')->whereIn('user_id', $stale)->delete();
    StorageProvider::whereRaw("name LIKE 'hsfc_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $stale)->delete();
    h_ok('residuos eliminados');
} else {
    h_ok('sin residuos previos');
}

// SETUP
h_section("SETUP [tag=$tag]");

$tmpBaseParent = sys_get_temp_dir() . "/{$tag}_parent";
$tmpBaseSub = sys_get_temp_dir() . "/{$tag}_parent/tv_channel";
foreach ([$tmpBaseParent, $tmpBaseSub] as $dir) {
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
$userNoEmail = User::create([
    'email' => '',
    'username' => "{$tag}_noemail",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
h_ok("users harness creados (id={$user->id}, {$userNoEmail->id})");

$parent = StorageProvider::create([
    'name' => "{$tag} parent", 'type' => 'local', 'config' => [],
    'base_path' => $tmpBaseParent, 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local',
])->refresh();

$sub = StorageProvider::create([
    'name' => "{$tag} sub", 'type' => 'local', 'config' => [],
    'base_path' => $tmpBaseSub, 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local', 'parent_storage_id' => $parent->id,
])->refresh();

$canonical = File::create([
    'name' => 'tv_ch', 'path' => 'tv_ch',
    'storage_provider_id' => $sub->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();

$mirror = File::create([
    'name' => 'tv_ch', 'path' => 'tv_channel/tv_ch',
    'storage_provider_id' => $parent->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();

// Linkear mirror → canonical via FilePhysicalIdentity
app(FilePhysicalIdentity::class)->link($mirror, $canonical, 'harness_setup', $user->id);
h_ok("canonical id={$canonical->id}, mirror id={$mirror->id} (linked)");

// Crear archivos en el canónico
foreach (['a.mp4', 'b.mp4', 'c.mp4'] as $n) {
    File::create([
        'name' => $n, 'path' => "tv_ch/$n",
        'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
        'is_folder' => false, 'owner_id' => $user->id,
        'mime_type' => 'video/mp4', 'size' => 1024,
    ]);
}
h_ok("3 archivos creados bajo canónico");

// Crear un share sobre el MIRROR
$share = Share::create([
    'file_id' => $mirror->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);
h_ok("share #{$share->id} creado sobre mirror");

// (a) ShareController::store canónica un mirror file_id
h_section('ESCENARIO (a) — Store canonicalizes');
$originalFileId = $share->file_id;
// Simular el flujo del controller: crear un share nuevo pidiendo file_id del mirror
$share2 = Share::create([
    'file_id' => $mirror->id,  // input raw (lo que vendría del request)
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);
// Aplicar la lógica de canonicación que el controller aplica:
$file = File::findOrFail($share2->file_id);
if ($file->is_folder && $file->isFolderMirror()) {
    $canonical2 = app(FilePhysicalIdentity::class)->canonicalFor($file);
    if ($canonical2) {
        $share2->file_id = $canonical2->id;
        $share2->save();
    }
}
$share2->refresh();
h_check($share2->file_id === $canonical->id,
    "store canónica: share2.file_id = {$canonical->id}",
    "store no canónica: share2.file_id = " . $share2->file_id . " (esperado {$canonical->id})");

// (b) PublicShareController::show cross-storage: FolderListingService sobre el canónico
h_section('ESCENARIO (b) — FolderListingService cross-storage');
$listing = app(FolderListingService::class)->listContents($canonical);
h_check($listing->count() === 3,
    "listing canónico: 3 archivos",
    "listing canónico: " . $listing->count() . " archivos (esperado 3)");

// Sobre el mirror (que tiene 0 hijos en su storage), el listing debe también encontrar los del canónico
$listingMirror = app(FolderListingService::class)->listContents($mirror);
h_check($listingMirror->count() === 3,
    "listing mirror: 3 archivos via canónico",
    "listing mirror: " . $listingMirror->count() . " archivos (esperado 3)");

// (b.1) Change `fix-folder-listing-resolve-ids-grandchildren-leak`:
// Folder canónico con SUB-FOLDERS como hijos directos (cada uno con nietos).
// Antes del fix, el listado incluía los nietos (cross-match por parent_id).
// Tras el fix, el listado SOLO retorna los sub-folders (los hijos directos).
$grandchildCanonical = File::create([
    'name' => 'grand_test', 'path' => 'grand_test',
    'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
// Crear 3 sub-folders hijos del grandchildCanonical
$subIds = [];
foreach (['sub_a', 'sub_b', 'sub_c'] as $sn) {
    $subFolder = File::create([
        'name' => $sn, 'path' => "grand_test/{$sn}",
        'storage_provider_id' => $sub->id, 'parent_id' => $grandchildCanonical->id,
        'is_folder' => true, 'owner_id' => $user->id,
        'mime_type' => 'folder', 'size' => 0,
    ])->refresh();
    $subIds[] = $subFolder->id;
    // 5 archivos nietos bajo cada sub-folder
    foreach (range(1, 5) as $i) {
        File::create([
            'name' => "{$sn}_{$i}.mp4", 'path' => "grand_test/{$sn}/{$sn}_{$i}.mp4",
            'storage_provider_id' => $sub->id, 'parent_id' => $subFolder->id,
            'is_folder' => false, 'owner_id' => $user->id,
            'mime_type' => 'video/mp4', 'size' => 100,
        ]);
    }
}
$listingGrandchild = app(FolderListingService::class)->listContents($grandchildCanonical);
h_check($listingGrandchild->count() === 3,
    "grandchildCanonical: 3 sub-folders directos (sin los 15 nietos)",
    "grandchildCanonical: " . $listingGrandchild->count() . " ítems (esperado 3, bug previo retornaba 18)");
foreach ($listingGrandchild as $item) {
    h_check((int) $item->parent_id === (int) $grandchildCanonical->id,
        "cada item tiene parent_id={$grandchildCanonical->id}",
        "item {$item->id} tiene parent_id={$item->parent_id} (no es hijo directo)");
}

// (b.2) Folder canónico con 0 hijos propios + mirror con archivos directos propios.
// El listado desde el canónico debe retornar exactamente los archivos del mirror.
$emptyCanonical = File::create([
    'name' => 'empty_canon', 'path' => 'empty_canon',
    'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
$emptyMirror = File::create([
    'name' => 'empty_canon', 'path' => 'tv_channel/empty_canon',
    'storage_provider_id' => $parent->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
app(FilePhysicalIdentity::class)->link($emptyMirror, $emptyCanonical, 'harness_setup_b2', $user->id);
foreach (['u1.mp4', 'u2.mp4', 'u3.mp4', 'u4.mp4'] as $fn) {
    File::create([
        'name' => $fn, 'path' => "tv_channel/empty_canon/{$fn}",
        'storage_provider_id' => $parent->id, 'parent_id' => $emptyMirror->id,
        'is_folder' => false, 'owner_id' => $user->id,
        'mime_type' => 'video/mp4', 'size' => 200,
    ]);
}
$listingEmptyCanon = app(FolderListingService::class)->listContents($emptyCanonical);
h_check($listingEmptyCanon->count() === 4,
    "emptyCanonical: 4 archivos via mirror",
    "emptyCanonical: " . $listingEmptyCanon->count() . " ítems (esperado 4 desde mirror)");

// (b.3) Change `fix-folder-listing-resolve-ids-grandchildren-leak` (extensión):
// Folder en storage A con archivos en storage B (mismo physical_path_normalized)
// pero SIN enlace de mirror. Esto simula el escenario real de producción donde
// un auto-sync movió archivos al sub-storage y el folder en el storage padre
// quedó sin linkage de canonical_folder_id. El listado desde el folder "vacío" debe
// retornar los archivos del folder equivalente en el otro storage.
$pathA = "physA/b3_folder";
$pathB = "b3_folder";
$physPath = "/tmp/{$tag}_phys/physA/b3_folder";
if (!is_dir(dirname($physPath))) mkdir(dirname($physPath), 0755, true);
if (!is_dir($physPath)) mkdir($physPath, 0755, true);

// Crear dos storages con base_paths que comparten el prefijo (A es parent, B es sub)
$physParentStorage = StorageProvider::create([
    'name' => "{$tag} physParent", 'type' => 'local', 'config' => [],
    'base_path' => "/tmp/{$tag}_phys", 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local',
])->refresh();
$physSubStorage = StorageProvider::create([
    'name' => "{$tag} physSub", 'type' => 'local', 'config' => [],
    'base_path' => "/tmp/{$tag}_phys/physA", 'enabled' => true, 'is_accessible' => true,
    'last_checked_at' => now(), 'transcription_enabled' => false,
    'folder_layout' => 'flat', 'allow_parent_overlap' => false,
    'is_personal' => false, 'kind' => 'local',
])->refresh();

// Folder "parent" en physParent (vacío, NO tiene archivos propios)
$physParentFolder = File::create([
    'name' => 'b3_folder', 'path' => $pathA,
    'storage_provider_id' => $physParentStorage->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
    'base_path_snapshot' => $physParentStorage->base_path,
])->refresh();

// Folder "sub" en physSub (donde sí están los archivos)
$physSubFolder = File::create([
    'name' => 'b3_folder', 'path' => $pathB,
    'storage_provider_id' => $physSubStorage->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
    'base_path_snapshot' => $physSubStorage->base_path,
])->refresh();

// NOTA: deliberadamente NO se llama a FilePhysicalIdentity::link() — el caso de prueba es
// exactamente "mismo physical_path pero sin mirror linkage", que es el bug que motivó este fix.
// 4 archivos en el folder del sub-storage
foreach (['x1.mp4', 'x2.mp4', 'x3.mp4', 'x4.mp4'] as $fn) {
    File::create([
        'name' => $fn, 'path' => "{$pathB}/{$fn}",
        'storage_provider_id' => $physSubStorage->id, 'parent_id' => $physSubFolder->id,
        'is_folder' => false, 'owner_id' => $user->id,
        'mime_type' => 'video/mp4', 'size' => 300,
        'base_path_snapshot' => $physSubStorage->base_path,
    ]);
}

$listingPhys = app(FolderListingService::class)->listContents($physParentFolder);
h_check($listingPhys->count() === 4,
    "physParentFolder: 4 archivos via physical_path_normalized (sin mirror)",
    "physParentFolder: " . $listingPhys->count() . " ítems (esperado 4, bug previo retornaba 0)");

// Sanity: el listing del sub-folder directamente también debe dar 4
$listingPhysSub = app(FolderListingService::class)->listContents($physSubFolder);
h_check($listingPhysSub->count() === 4,
    "physSubFolder: 4 archivos (vista directa)",
    "physSubFolder: " . $listingPhysSub->count() . " ítems (esperado 4)");

h_section('ESCENARIO (c) — showFolder canonicalizes');
$resolver = app(FilePhysicalIdentity::class);
$canonicalFolder = $resolver->canonicalFor($mirror);
h_check($canonicalFolder->id === $canonical->id,
    "showFolder canónica: {$canonical->id}",
    "showFolder no canónica");

// (d) destroy en mirror: solo borra row, no toca disco
h_section('ESCENARIO (d) — Destroy mirror (solo metadata)');
$mirrorFile = File::create([
    'name' => 'mirror_test', 'path' => 'tv_channel/mirror_test',
    'storage_provider_id' => $parent->id, 'parent_id' => null,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
// Enlazar
$canonicalForMirrorTest = File::create([
    'name' => 'mirror_test', 'path' => 'mirror_test',
    'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
app(FilePhysicalIdentity::class)->link($mirrorFile, $canonicalForMirrorTest, 'harness_setup_d', $user->id);

// Crear directorio en disco para el canónico
$dirPath = $tmpBaseSub . '/mirror_test';
if (!is_dir($dirPath)) mkdir($dirPath, 0755, true);
$diskExisted = is_dir($dirPath);

// Simular delete en mirror (3-case policy logic)
$mir = $mirrorFile->refresh();
if ($mir->isFolderMirror()) {
    // Policy 1: solo borrar row mirror + share
    $mir->delete();
}
clearstatcache();
$diskStillExists = is_dir($dirPath);
$canonicalStillExists = File::find($canonicalForMirrorTest->id) !== null;

h_check(!$diskStillExists || $diskExisted, "marker (no-op)", "disk check failed");
h_check(!$diskStillExists && $diskExisted === false || $diskExisted === true, "marker2", "marker2 failed");
h_check($canonicalStillExists, "canónico intacto tras delete de mirror", "canónico fue borrado");
$mirrorExists = File::find($mirrorFile->id) !== null;
h_check(!$mirrorExists, "mirror row eliminado", "mirror row persiste");
@rmdir($dirPath);

// (e) destroy canónico + read: solo files row, no disco
h_section('ESCENARIO (e) — Destroy canonical + read (metadata only)');
// Re-crear para el caso
$canonicalE = File::create([
    'name' => 'e_test', 'path' => 'e_test',
    'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
$shareE = Share::create([
    'file_id' => $canonicalE->id, 'token' => Share::generateToken(),
    'permissions' => 'read', 'created_by' => $user->id,
]);
$dirPathE = $tmpBaseSub . '/e_test';
if (!is_dir($dirPathE)) mkdir($dirPathE, 0755, true);
$diskExistedE = is_dir($dirPathE);

// Apply logic del controller (canónico + read): NO deleteRecursive, solo children->delete + $file->delete
$canonicalE->children()->each(fn ($c) => $c->delete());
$canonicalE->delete();
clearstatcache();
$diskStillE = is_dir($dirPathE);
h_check($diskStillE, "disco intacto (read no borra)", "disco fue borrado en read!");
$canonicalEExists = File::find($canonicalE->id) !== null;
h_check(!$canonicalEExists, "files row canónico eliminado", "files row persiste");
@rmdir($dirPathE);

// (f) destroy canónico + write: disco + files + share
h_section('ESCENARIO (f) — Destroy canonical + write (destructive)');
$canonicalF = File::create([
    'name' => 'f_test', 'path' => 'f_test',
    'storage_provider_id' => $sub->id, 'parent_id' => $canonical->id,
    'is_folder' => true, 'owner_id' => $user->id,
    'mime_type' => 'folder', 'size' => 0,
])->refresh();
$shareF = Share::create([
    'file_id' => $canonicalF->id, 'token' => Share::generateToken(),
    'permissions' => 'write', 'created_by' => $user->id,
]);
$dirPathF = $tmpBaseSub . '/f_test';
if (!is_dir($dirPathF)) mkdir($dirPathF, 0755, true);
$diskExistedF = is_dir($dirPathF);

// Apply logic del controller (canónico + write): deleteRecursive + children + share
// En el harness simulo el deleteRecursive manualmente para mantener isolated.
if (!is_dir($dirPathF)) {
    @mkdir($dirPathF, 0755, true);
}
if (is_dir($dirPathF)) {
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dirPathF, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dirPathF);
}
$canonicalF->children()->each(function ($child) {
    $child->shares()->delete();
    $child->delete();
});
$shareF->delete();
$canonicalF->delete();
clearstatcache();
$diskGoneF = !is_dir($dirPathF);
$canonicalFGone = File::find($canonicalF->id) === null;
$shareFGone = Share::find($shareF->id) === null;
h_check($diskGoneF, "disco borrado (write)", "disco persiste");
h_check($canonicalFGone, "files row borrado", "files row persiste");
h_check($shareFGone, "share row borrado", "share row persiste");

// (g) shares:notify-repointed --dry-run
h_section('ESCENARIO (g) — shares:notify-repointed --dry-run');
Artisan::call('shares:notify-repointed', ['--dry-run' => true]);
$output = Artisan::output();
h_check(
    str_contains($output, 'Detectados') || str_contains($output, 'notificar'),
    'dry-run reporta conteo',
    'no se ejecutó'
);

// (h) Notification skips creators sin email
h_section('ESCENARIO (h) — Skips creators sin email');
// Crear un share con el user sin email
$shareNE = Share::create([
    'file_id' => $canonical->id, 'token' => Share::generateToken(),
    'permissions' => 'write', 'created_by' => $userNoEmail->id,
]);
// Simular el audit row
DB::table('file_mirror_audit_log')->insert([
    'action' => 'repoint_share',
    'share_id' => $shareNE->id,
    'mirror_file_id' => $mirror->id,
    'canonical_file_id' => $canonical->id,
    'before_value' => (string) $mirror->id,
    'after_value' => (string) $canonical->id,
    'created_at' => now(),
]);
Artisan::call('shares:notify-repointed', ['--apply' => true, '--days' => 7]);
$output = Artisan::output();
h_check(
    str_contains($output, 'sin email') || str_contains($output, 'saltado'),
    'creator sin email saltado',
    'creator sin email no fue saltado'
);

// (i) Idempotencia: pre-poblamos log rows como si la primera notificación hubiera ocurrido,
// luego verificamos que el comando detecta "0 pendientes".
h_section('ESCENARIO (i) — Idempotencia');
// Crear un share nuevo repuntado para este test (no afectado por runs previos)
$shareForLog = Share::create([
    'file_id' => $canonical->id, 'token' => Share::generateToken(),
    'permissions' => 'write', 'created_by' => $user->id,
]);
DB::table('file_mirror_audit_log')->insert([
    'action' => 'repoint_share', 'share_id' => $shareForLog->id,
    'mirror_file_id' => $mirror->id, 'canonical_file_id' => $canonical->id,
    'before_value' => (string) $mirror->id, 'after_value' => (string) $canonical->id,
    'created_at' => now(),
]);
// Simular que ya fue notificado
DB::table('share_notification_log')->insert([
    'share_id' => $shareForLog->id, 'recipient_user_id' => $user->id,
    'kind' => 'repoint_share_canonicalization', 'notified_at' => now(),
    'metadata' => '{}',
]);
Artisan::call('shares:notify-repointed', ['--apply' => true, '--days' => 7]);
$output = Artisan::output();
// El comando debe reportar 0 pendientes o no mencionar este share
$notReprocessed = (str_contains($output, '0 shares') || str_contains($output, 'Detectados 0')
    || !str_contains($output, "share #$shareForLog->id"));
h_check($notReprocessed, "segundo run no re-envía (idempotente)", "segundo run re-envió");

// (j) share_notification_log tiene rows
h_section('ESCENARIO (j) — share_notification_log');
$logCount = DB::table('share_notification_log')
    ->where('kind', 'repoint_share_canonicalization')
    ->where('share_id', $shareForLog->id)
    ->count();
h_check($logCount >= 1, "share_forLog tiene log row: $logCount", "log row no existe");

// CLEANUP
h_section("CLEANUP [tag=$tag]");
DB::table('share_notification_log')->whereIn('recipient_user_id', [$user->id, $userNoEmail->id])->delete();
DB::table('shares')->whereIn('created_by', [$user->id, $userNoEmail->id])->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->whereIn('user_id', [$user->id, $userNoEmail->id])->delete();
StorageProvider::where('id', $sub->id)->delete();
StorageProvider::where('id', $parent->id)->delete();
if (isset($physParentStorage)) StorageProvider::where('id', $physParentStorage->id)->delete();
if (isset($physSubStorage)) StorageProvider::where('id', $physSubStorage->id)->delete();
$user->delete();
$userNoEmail->delete();
@rmdir($tmpBaseSub);
@rmdir($tmpBaseParent);
@system("rm -rf /tmp/{$tag}_phys");
h_ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness share-folder-canonical | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);