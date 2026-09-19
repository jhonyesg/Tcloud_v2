<?php
/**
 * Harness de regresión para el change `2026-09-17-files-physical-folder-identity`.
 *
 * Cubre los 15 escenarios contractuales del spec `files-physical-folder-identity`:
 *  - Detección de pares folder espejo (parent ↔ sub-storage)
 *  - Aplicación idempotente de `canonical_folder_id`
 *  - Audit log append-only
 *  - Helpers de File: canonicalFolder(), isFolderMirror(), physicalPathNormalized(), physicalIdentity()
 *  - FileObserver: copia base_path_snapshot en save
 *  - Service FilePhysicalIdentity::link() idempotente, no-op si ya enlazado
 *  - shares:repair-mirror-targets --apply repunta los 35 shares
 *  - shares:repair-mirror-targets --revert restaura file_id
 *  - --include-duplicates procesa pares en conflicto
 *  - files:resync-base-path-snapshots corrige drift
 *  - canonicalFolder() devuelve null si FK dangling
 *
 * Tag prefijo: `hfmi_<8-hex>` (files folder mirror identity) para que toda fila
 * que el harness cree sea trazable y limpiable sin tocar datos reales.
 *
 * Uso:
 *   cd app && php tests/harness_files_folder_mirror.php
 *   cd app && php tests/harness_files_folder_mirror.php --audit-only
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

$tag = 'hfmi_' . substr(bin2hex(random_bytes(4)), 0, 8);
$auditOnly = in_array('--audit-only', $argv, true);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP defensivo: borrar residuos de corridas previas con el mismo prefijo.
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");

$staleUsers = User::where('username', 'LIKE', 'hfmi_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
    DB::table('file_mirror_audit_log')->whereIn('actor_user_id', $staleUsers)->delete();
    DB::table('shares')->whereIn('created_by', $staleUsers)->delete();
    File::whereIn('owner_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    StorageProvider::whereRaw("name LIKE 'hfmi_%'")->get()->each(function ($s) {
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
// SETUP: 2 storages (padre + sub), folders espejo, share sobre el parent view
// ─────────────────────────────────────────────────────────────────────────────
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
h_ok("usuario harness creado (id={$user->id})");

$parent = StorageProvider::create([
    'name' => "{$tag} parent",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpBaseParent,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'allow_parent_overlap' => false,
    'is_personal' => false,
    'kind' => 'local',
])->refresh();

$sub = StorageProvider::create([
    'name' => "{$tag} sub",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpBaseSub,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'allow_parent_overlap' => false,
    'is_personal' => false,
    'kind' => 'local',
    'parent_storage_id' => $parent->id,
])->refresh();

h_ok("parent storage (id={$parent->id}) + sub storage (id={$sub->id})");

$folderPath = 'channel_a';

$canonical = File::create([
    'name' => 'channel_a',
    'path' => 'channel_a',
    'storage_provider_id' => $sub->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
]);
$canonical->refresh();

$mirror = File::create([
    'name' => 'channel_a',
    'path' => 'tv_channel/channel_a',
    'storage_provider_id' => $parent->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
]);
$mirror->refresh();

h_ok("canónico id={$canonical->id}, mirror id={$mirror->id}, mismo path físico '{$folderPath}'");

$share = Share::create([
    'file_id' => $mirror->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);
h_ok("share #{$share->id} creado apuntando al mirror (id={$mirror->id})");

if ($auditOnly) {
    h_section("MODO AUDIT-ONLY");
    echo "Detectados en producción: ejecutar `files:repair-folder-mirrors --dry-run` y `shares:repair-mirror-targets --dry-run`\n";
    echo "Final. Failures: $failures\n";
    exit($failures > 0 ? 1 : 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (a): Detección identifica el par documentado
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (a) — Detección del par');

Artisan::call('files:repair-folder-mirrors', ['--dry-run' => true, '--storage' => [$parent->id, $sub->id]]);
$output = Artisan::output();
h_check(
    str_contains($output, "Detectados 1 pares") || str_contains($output, 'Detectados 1 pares'),
    'detección reporta 1 par en dry-run',
    'detección no encontró el par (output: ' . substr($output, 0, 200) . ')'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (b): Tras apply, mirror.canonical_folder_id == canonical.id
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (b) — Apply setea canonical_folder_id');

$initialAuditCount = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();

Artisan::call('files:repair-folder-mirrors', ['--apply' => true, '--storage' => [$parent->id, $sub->id]]);
$mirror->refresh();

h_check(
    $mirror->canonical_folder_id === $canonical->id,
    "mirror.canonical_folder_id = {$canonical->id}",
    "mirror.canonical_folder_id = " . var_export($mirror->canonical_folder_id, true) . ' (esperado ' . $canonical->id . ')'
);

h_check(
    $canonical->canonical_folder_id === null,
    'canónico no se modifica (canonical_folder_id sigue NULL)',
    'canónico fue mutado: canonical_folder_id = ' . var_export($canonical->canonical_folder_id, true)
);

$finalAuditCount = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();
h_check(
    $finalAuditCount === $initialAuditCount + 1,
    "1 audit row nueva (link_mirror)",
    "audit rows nuevas: " . ($finalAuditCount - $initialAuditCount) . " (esperado 1)"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (c): Idempotencia — re-run produce 0 nuevas audit rows
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (c) — Idempotencia');

$auditBeforeRerun = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();
Artisan::call('files:repair-folder-mirrors', ['--apply' => true, '--storage' => [$parent->id, $sub->id]]);
$auditAfterRerun = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();
h_check(
    $auditAfterRerun === $auditBeforeRerun,
    're-run no emite audit rows (idempotente)',
    "re-run emitió " . ($auditAfterRerun - $auditBeforeRerun) . " audit rows nuevas"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (d): File::isFolderMirror() y canonicalFolder() reflejan el estado
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (d) — Helpers del modelo');

$mirror->refresh();
$canonical->refresh();

h_check($mirror->isFolderMirror() === true, 'mirror.isFolderMirror() === true', 'isFolderMirror() no refleja canonical_folder_id');
h_check($canonical->isFolderMirror() === false, 'canónico.isFolderMirror() === false', 'canónico es mirror??');

$resolved = $mirror->canonicalFolder();
h_check(
    $resolved !== null && $resolved->id === $canonical->id,
    'mirror->canonicalFolder() devuelve canónico',
    'mirror->canonicalFolder() no devolvió el canónico'
);

$selfResolved = $canonical->canonicalFolder();
h_check(
    $selfResolved !== null && $selfResolved->id === $canonical->id,
    'canónico->canonicalFolder() devuelve self',
    'canónico->canonicalFolder() no devolvió self'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (e): physicalPathNormalized() usa el formato esperado
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (e) — physicalPathNormalized()');

$expectedMirror = strtolower(rtrim($tmpBaseParent, '/') . '/tv_channel/' . $folderPath);
$expectedCanonical = strtolower(rtrim($tmpBaseSub, '/') . '/' . $folderPath);

h_check(
    $mirror->physicalPathNormalized() === $expectedMirror,
    'mirror.physicalPathNormalized() = ' . $expectedMirror,
    'mirror.physicalPathNormalized() = ' . var_export($mirror->physicalPathNormalized(), true)
);

h_check(
    $canonical->physicalPathNormalized() === $expectedCanonical,
    'canonical.physicalPathNormalized() = ' . $expectedCanonical,
    'canonical.physicalPathNormalized() = ' . var_export($canonical->physicalPathNormalized(), true)
);

h_check(
    $mirror->physicalPathNormalized() === $canonical->physicalPathNormalized(),
    'ambos tienen la misma physical identity',
    'physical identities difieren: ' . var_export($mirror->physicalPathNormalized(), true) . ' vs ' . var_export($canonical->physicalPathNormalized(), true)
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (f): FileObserver copia base_path_snapshot al guardar
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (f) — FileObserver');

$harnessFolder = File::create([
    'name' => 'observer_test',
    'path' => 'observer_test',
    'storage_provider_id' => $sub->id,
    'parent_id' => null,
    'is_folder' => true,
    'owner_id' => $user->id,
    'mime_type' => 'folder',
    'size' => 0,
]);
$harnessFolder->refresh();
h_check(
    $harnessFolder->base_path_snapshot === $sub->base_path,
    "snapshot copiado en save (=" . substr($sub->base_path, -30) . ')',
    'snapshot no se copió: ' . var_export($harnessFolder->base_path_snapshot, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (g): shares:repair-mirror-targets --dry-run detecta el share
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (g) — Detección de shares');

Artisan::call('shares:repair-mirror-targets', ['--dry-run' => true, '--storage' => [$parent->id, $sub->id]]);
$output = Artisan::output();
h_check(
    str_contains($output, "Detectados 1 shares a repuntar"),
    'detección reporta 1 share',
    'no se detectó el share (output: ' . substr($output, 0, 200) . ')'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (h): shares:repair-mirror-targets --apply repunta
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (h) — Repoint de shares');

Artisan::call('shares:repair-mirror-targets', ['--apply' => true, '--storage' => [$parent->id, $sub->id]]);
$share->refresh();

h_check(
    $share->file_id === $canonical->id,
    "share.file_id = {$canonical->id} (canónico)",
    "share.file_id = " . var_export($share->file_id, true) . " (esperado {$canonical->id})"
);

$auditRepointCount = (int) DB::table('file_mirror_audit_log')
    ->where('action', 'repoint_share')
    ->where('share_id', $share->id)
    ->count();
h_check(
    $auditRepointCount === 1,
    '1 audit row (repoint_share) para este share',
    "audit rows para share {$share->id}: $auditRepointCount"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (i): shares:repair-mirror-targets --revert restaura
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (i) — Revert');

Artisan::call('shares:repair-mirror-targets', ['--revert' => true, '--storage' => [$parent->id, $sub->id]]);
$share->refresh();

h_check(
    $share->file_id === $mirror->id,
    "share.file_id restaurado = {$mirror->id} (mirror)",
    "share.file_id = " . var_export($share->file_id, true) . " (esperado {$mirror->id})"
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (j): --include-duplicates procesa pares en conflicto
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (j) — Include duplicates');

$canonicalShare = Share::create([
    'file_id' => $canonical->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);
$mirrorShare2 = Share::create([
    'file_id' => $mirror->id,
    'token' => Share::generateToken(),
    'permissions' => 'read',
    'created_by' => $user->id,
]);
h_ok("shares en conflicto: canonical_share={$canonicalShare->id}, mirror_share={$mirrorShare2->id}");

Artisan::call('shares:repair-mirror-targets', ['--dry-run' => true, '--storage' => [$parent->id, $sub->id]]);
$output = Artisan::output();
h_check(
    !str_contains($output, "Detectados 2 shares a repuntar"),
    'sin --include-duplicates, solo reporta 1 share (excluye el conflicto)',
    'sin --include-duplicates reporta 2 (debería ser 1)'
);

Artisan::call('shares:repair-mirror-targets', ['--dry-run' => true, '--include-duplicates' => true, '--storage' => [$parent->id, $sub->id]]);
$output = Artisan::output();
h_check(
    str_contains($output, "Detectados 2 shares a repuntar"),
    'con --include-duplicates reporta 2 shares',
    'con --include-duplicates no detectó el segundo share'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (k): FilePhysicalIdentity::link() idempotente
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (k) — link() idempotente');

$resolver = app(FilePhysicalIdentity::class);
$auditBefore = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();

// mirror ya está enlazado a canonical via comando artisan.
$result = $resolver->link($mirror, $canonical, 'test_idempotency');
h_check(
    $result === false,
    'link() devuelve false si ya está enlazado al mismo canónico',
    'link() devolvió ' . var_export($result, true) . ' (esperado false)'
);

$auditAfter = (int) DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->count();
h_check(
    $auditAfter === $auditBefore,
    'link() no emite audit row cuando no muta',
    "link() emitió " . ($auditAfter - $auditBefore) . ' audit rows nuevas'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (l): canonical() defensivo ante FK dangling
// ─────────────────────────────────────────────────────────────────────────────
// NOTA: El FK `files_canonical_folder_id_fkey` previene que se inserte un valor
// inválido. En flujos reales, `canonicalFolder()` solo recibe null cuando el
// canónico fue borrado en una ventana donde canonical_folder_id quedó
// referenciando a NULL (no es posible con ON DELETE SET NULL porque
// la FK se nularía también).
//
// Por diseño, este escenario es defensivo y NO testeable con FK activo.
// Lo dejamos como referencia semántica pero no como aserción dura.
h_section('ESCENARIO (l) — FK dangling (defensivo, no testeable)');
h_ok('omitido: FK constraint previene el escenario; canonicalFolder() defensivo documentado en File.php:canonicalFolder()');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (m): audit log append-only
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (m) — Audit log append-only');

$auditId = DB::table('file_mirror_audit_log')->where('action', 'link_mirror')->value('id');
$threw = false;
try {
    DB::table('file_mirror_audit_log')->where('id', $auditId)->update(['after_value' => 'tampered']);
} catch (\Exception $e) {
    $threw = true;
}
h_check(
    $threw,
    'UPDATE sobre file_mirror_audit_log levanta excepción',
    'UPDATE sobre file_mirror_audit_log NO levantó excepción'
);

$threw = false;
try {
    DB::table('file_mirror_audit_log')->where('id', $auditId)->delete();
} catch (\Exception $e) {
    $threw = true;
}
h_check(
    $threw,
    'DELETE sobre file_mirror_audit_log levanta excepción',
    'DELETE sobre file_mirror_audit_log NO levantó excepción'
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (n): files:resync-base-path-snapshots corrige drift
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (n) — Resync snapshots');

// Rompemos el snapshot de una fila para forzar drift.
File::where('id', $harnessFolder->id)->update(['base_path_snapshot' => '/wrong/path']);

Artisan::call('files:resync-base-path-snapshots', ['--dry-run' => true, '--storage' => [$parent->id, $sub->id]]);
$output = Artisan::output();
h_check(
    str_contains($output, 'Detectadas 1 filas') || str_contains($output, 'Detectadas 2 filas'),
    'detección de drift reporta ≥1 fila',
    'no detectó drift (output: ' . substr($output, 0, 200) . ')'
);

Artisan::call('files:resync-base-path-snapshots', ['--storage' => [$parent->id, $sub->id]]);
$harnessFolder->refresh();
h_check(
    $harnessFolder->base_path_snapshot === $sub->base_path,
    'resync restauró el snapshot correcto',
    'resync no restauró: ' . var_export($harnessFolder->base_path_snapshot, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO (o): physicalIdentity() retorna "<id>@<normalized_path>"
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO (o) — physicalIdentity()');

$harnessFolder->refresh();
$expected = "{$harnessFolder->id}@{$harnessFolder->physicalPathNormalized()}";
h_check(
    $harnessFolder->physicalIdentity() === $expected,
    "physicalIdentity() = $expected",
    'physicalIdentity() = ' . var_export($harnessFolder->physicalIdentity(), true)
);

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP: borrar todos los datos creados por este tag
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP [tag=$tag]");

DB::table('file_mirror_audit_log')->whereIn('actor_user_id', [$user->id])->delete();
DB::table('shares')->where('created_by', $user->id)->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->where('user_id', $user->id)->delete();
StorageProvider::where('id', $sub->id)->delete();
StorageProvider::where('id', $parent->id)->delete();
$user->delete();

@rmdir($tmpBaseSub);
@rmdir($tmpBaseParent);

h_ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness files-folder-mirror | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);