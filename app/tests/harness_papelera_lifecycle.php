<?php
/**
 * Harness de regresión para change `papelera-reciclaje`.
 *
 * Recorre el ciclo de vida completo:
 *   1. softTrash: marca flags, no toca disco, oculta de listados.
 *   2. sync respeta trash: tras sync, la fila NO reaparece ni se duplica.
 *   3. restore a padre original.
 *   4. restore con colision de nombre -> sufijo -restored-<ts>.
 *   5. hardDelete: borra fila + archivo en disco.
 *   6. purgeExpired: purga filas con deleted_at > retention.
 *
 * Uso: php tests/harness_papelera_lifecycle.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use App\Modules\Papelera\Services\PapeleraService;
use App\Services\StorageSyncService;
use Illuminate\Support\Facades\Hash;

$tag = 'harness_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;
$createdStorageId = null;
$createdUserId = null;
$createdFileIds = [];

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
file_put_contents("{$tmpBase}/collision.txt", "harness fixture");
mkdir($tmpBase . "/subfolder");
file_put_contents($tmpBase . "/subfolder/nested.txt", "nested harness fixture");
h_ok("directorio temporal: {$tmpBase}");

$user = User::create([
    'email' => "{$tag}@harness.local",
    'username' => $tag,
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
$createdUserId = $user->id;
h_ok("user harness creado (id={$user->id})");

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
$createdStorageId = $storage->id;
h_ok("StorageProvider creado (id={$storage->id})");

// Crea las filas File equivalentes a lo que el sync habria creado
$sampleRow = File::create([
    'name' => 'sample.txt',
    'path' => 'sample.txt',
    'size' => 15,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
$createdFileIds[] = $sampleRow->id;

$collisionRow = File::create([
    'name' => 'collision.txt',
    'path' => 'collision.txt',
    'size' => 15,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
$createdFileIds[] = $collisionRow->id;

$subfolderRow = File::create([
    'name' => 'subfolder',
    'path' => 'subfolder',
    'size' => 0,
    'mime_type' => 'folder',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => true,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
$createdFileIds[] = $subfolderRow->id;

$nestedRow = File::create([
    'name' => 'nested.txt',
    'path' => 'subfolder/nested.txt',
    'size' => 24,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => $subfolderRow->id,
    'is_folder' => false,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
$createdFileIds[] = $nestedRow->id;
h_ok('4 filas File creadas (sample, collision, subfolder, nested)');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1 — softTrash: marca flags, no toca disco, oculta de listados
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 1 — softTrash en folder con archivos');

$service = app(PapeleraService::class);
$service->softTrash($subfolderRow, $user->id);

$subfolderFresh = File::find($subfolderRow->id);
$nestedFresh = File::find($nestedRow->id);

if ($subfolderFresh->is_trashed === true) h_ok('subfolder.is_trashed=true');
else h_fail('subfolder.is_trashed no se marcó');

if ($subfolderFresh->deleted_at !== null) h_ok('subfolder.deleted_at seteado');
else h_fail('subfolder.deleted_at sigue NULL');

if ($subfolderFresh->parent_id === null) h_ok('subfolder.parent_id=null (oculto de listados)');
else h_fail('subfolder.parent_id no se nulificó');

if ($subfolderFresh->original_parent_id === null) h_ok('subfolder.original_parent_id=null (era root)');
else h_fail('subfolder.original_parent_id esperado null');

if ($nestedFresh->is_trashed === true) h_ok('nested (hijo) también soft-trashed recursivamente');
else h_fail('nested no se soft-trashó en la recursión');

if (file_exists($tmpBase . '/subfolder/nested.txt')) h_ok('archivo en disco INTACTO (no se movió)');
else h_fail('archivo en disco fue movido (no debería)');

if (is_dir($tmpBase . '/subfolder')) h_ok('directorio en disco INTACTO');
else h_fail('directorio en disco desapareció');

// Listado del padre (root) NO debe mostrar la fila trashada
$listing = File::where('storage_provider_id', $storage->id)
    ->whereNull('parent_id')
    ->where('is_trashed', false)
    ->get();
if (!$listing->contains('id', $subfolderRow->id)) h_ok('subfolder NO aparece en listado del padre');
else h_fail('subfolder SI aparece en listado del padre (filtro is_trashed no aplicado)');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2 — sync respeta is_trashed: NO recrea filas trashadas
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 2 — StorageSyncService respeta is_trashed');

try {
    $report = app(StorageSyncService::class)->syncFolderWithReport($storage, null, $user->id, false);
    h_ok('syncFolderWithReport corrió sin excepción (regresion del fix 2026-09-06)');

    $reportIds = collect($report['files'] ?? [])->pluck('id')->all();
    if (!in_array($subfolderRow->id, $reportIds)) {
        h_ok('subfolder trashado NO reaparece en el reporte del sync');
    } else {
        h_fail('subfolder trashado reapareció en el reporte del sync (regresión del bug original)');
    }

    // Aseguramos tambien que NO se creo una fila duplicada para el mismo path
    $dupCount = File::where('storage_provider_id', $storage->id)
        ->where('path', 'subfolder')
        ->count();
    if ($dupCount === 1) h_ok('no se duplicó la fila para el path "subfolder"');
    else h_fail("se duplicó la fila para el path 'subfolder' ({$dupCount} filas)");
} catch (\Throwable $e) {
    h_fail('sync lanzó excepción: ' . get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3 — restore a padre original
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 3 — restore al padre original');

$restored = $service->restore(File::find($subfolderRow->id), $user->id);
$restoredFresh = File::find($subfolderRow->id);

if ($restoredFresh->is_trashed === false) h_ok('subfolder.is_trashed=false tras restore');
else h_fail('subfolder sigue is_trashed=true tras restore');

if ($restoredFresh->deleted_at === null) h_ok('subfolder.deleted_at=null');
else h_fail('subfolder.deleted_at no se limpió');

if ($restoredFresh->parent_id === null) h_ok('subfolder.parent_id=null (volvió al root, original era null)');
else h_fail('subfolder.parent_id=' . $restoredFresh->parent_id . ' (esperado null)');

if ($restoredFresh->original_parent_id === null) h_ok('subfolder.original_parent_id=null (limpio)');
else h_fail('subfolder.original_parent_id no se limpió');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4 — restore con colisión de nombre -> sufijo -restored-<ts>
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 4 — restore con colisión');

// Creamos un archivo NO-trashed con el mismo nombre que vamos a restaurar
// (collisionRow). Como en el destino YA existe un archivo visible con ese
// nombre, el restore debe sufijar el nombre para evitar pisar el original.
$blockingRow = File::create([
    'name' => 'collision.txt',
    'path' => 'blocking_collision.txt',
    'size' => 99,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subDay(),
    'availability_state' => 'available',
]);
$createdFileIds[] = $blockingRow->id;

// Ahora trashamos el collisionRow original y lo restauramos. El restore debe
// detectar que "collision.txt" ya existe en root (el blockingRow) y aplicar
// el sufijo -restored-<ts>.
$collisionRow->update([
    'is_trashed' => true,
    'deleted_at' => now(),
    'original_parent_id' => null,
    'parent_id' => null,
]);
$restoredCollision = $service->restore($collisionRow, $user->id);

if ($restoredCollision === null) {
    h_fail('restore devolvió null');
} elseif (str_contains($restoredCollision->name, '-restored-')) {
    h_ok('restore con colisión aplicó sufijo: ' . $restoredCollision->name);
} else {
    h_fail('restore con colisión NO aplicó sufijo: ' . $restoredCollision->name);
}

// El archivo original blocking collision.txt SIGUE existiendo, y la fila restaurada
// está en root (parent_id=null) con sufijo — ambos visibles en el listado
$collisionsInRoot = File::where('storage_provider_id', $storage->id)
    ->whereNull('parent_id')
    ->where('name', 'like', 'collision%')
    ->count();
if ($collisionsInRoot >= 2) h_ok("listado del root muestra {$collisionsInRoot} archivos 'collision*' (original + restaurado)");
else h_fail("esperaba >=2 archivos 'collision*' en root, encontré {$collisionsInRoot}");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 5 — hardDelete: borra fila + archivo en disco
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 5 — hardDelete (purga)');

$sampleBefore = file_exists($tmpBase . '/sample.txt');
if (!$sampleBefore) h_fail('precondición rota: sample.txt no está en disco');

// Trash + hardDelete
$sampleRow->update(['is_trashed' => true, 'deleted_at' => now(), 'parent_id' => null]);
$sampleRowFresh = File::find($sampleRow->id);
$ok = $service->hardDelete($sampleRowFresh, $user->id);
if ($ok) h_ok('hardDelete devolvió true');
else h_fail('hardDelete devolvió false');

$after = File::find($sampleRow->id);
if ($after === null) h_ok('fila sample eliminada de BD');
else h_fail('fila sample SIGUE en BD');

if (!file_exists($tmpBase . '/sample.txt')) h_ok('archivo sample.txt borrado de disco');
else h_fail('archivo sample.txt SIGUE en disco');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 6 — purgeExpired: purga filas con deleted_at > retention
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 6 — trash:purge cron');

// Crear fila trash artificialmente vieja
$oldTrash = File::create([
    'name' => 'old_trash.txt',
    'path' => 'old_trash.txt',
    'size' => 0,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subDays(30),
    'availability_state' => 'available',
    'is_trashed' => true,
    'deleted_at' => now()->subDays(30),
]);
file_put_contents($tmpBase . '/old_trash.txt', 'old fixture');
$createdFileIds[] = $oldTrash->id;

// Crear fila trash RECIENTE (NO debe purgarse)
$recentTrash = File::create([
    'name' => 'recent_trash.txt',
    'path' => 'recent_trash.txt',
    'size' => 0,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $storage->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'file_modified_at' => now()->subHour(),
    'availability_state' => 'available',
    'is_trashed' => true,
    'deleted_at' => now()->subHour(),
]);
file_put_contents($tmpBase . '/recent_trash.txt', 'recent fixture');
$createdFileIds[] = $recentTrash->id;

// Correr purga
$deleted = $service->purgeExpired(500, 0.5);
h_ok("purgeExpired devolvió {$deleted}");

if (File::find($oldTrash->id) === null) h_ok('old_trash (30 días) fue purgado');
else h_fail('old_trash SIGUE en BD tras purga');

if (File::find($recentTrash->id) !== null) h_ok('recent_trash (1 hora) NO fue purgado');
else h_fail('recent_trash fue purgado prematuramente');

if (!file_exists($tmpBase . '/old_trash.txt')) h_ok('archivo old_trash.txt borrado de disco');
else h_fail('archivo old_trash.txt SIGUE en disco');

if (file_exists($tmpBase . '/recent_trash.txt')) h_ok('archivo recent_trash.txt NO se borró');
else h_fail('archivo recent_trash.txt se borró (no debía)');

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
h_section('TEARDOWN');
try {
    File::where('storage_provider_id', $storage->id)->delete();
    if ($createdStorageId !== null) {
        StorageProvider::where('id', $createdStorageId)->delete();
    }
    if ($createdUserId !== null) {
        User::where('id', $createdUserId)->delete();
    }
    @unlink($tmpBase . '/sample.txt');
    @unlink($tmpBase . '/collision.txt');
    @unlink($tmpBase . '/subfolder/nested.txt');
    @rmdir($tmpBase . '/subfolder');
    @unlink($tmpBase . '/old_trash.txt');
    @unlink($tmpBase . '/recent_trash.txt');
    @unlink($tmpBase . '/collision.txt-restored-' . explode('-', pathinfo($restoredCollision->name, PATHINFO_FILENAME))[0] ?? 'x');
    @rmdir($tmpBase);
    h_ok('limpieza completada');
} catch (\Throwable $e) {
    echo "  ! cleanup error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: Papelera de reciclaje - ciclo de vida completo validado\n";
    exit(0);
} else {
    echo "FAIL: {$failures} escenario(s) roto(s) — la papelera NO está bien implementada\n";
    exit(1);
}
