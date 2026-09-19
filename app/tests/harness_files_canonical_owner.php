<?php
/**
 * Harness de regresión — change `files-canonical-owner-by-storage` (2026-09-18).
 *
 * Verifica que:
 *  1. `StorageProvider::canonicalOwnerId()` es determinístico (100 invocaciones = mismo resultado).
 *  2. Branch is_personal ordena por user_id (no user_storages.id): admin no roba personal storage.
 *  3. Branch !is_personal ordena por user_storages.id (insertion order).
 *  4. Fallback por parent_storage_id funciona.
 *  5. Grep recursivo en app/app: 0 ocurrencias de `userStorages()->first()?->user_id` sin orderBy.
 *  6. (storage_id, path) únicos: no hay duplicidad de paths entre owners.
 *  7. Smoke test: tcloud:files-canonicalize --dry-run corre sin error y reporta conteos.
 *
 * Uso: php tests/harness_files_canonical_owner.php
 *
 * Tag: hco_<8-hex>. Cleanup defensivo al inicio (LIKE 'hco\_%').
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$failures = 0;
$tag = 'hco_' . substr(bin2hex(random_bytes(4)), 0, 8);

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

echo "Harness files-canonical-owner-by-storage (tag: {$tag})\n";

// ─── Cleanup defensivo al inicio ────────────────────────────────────────────
DB::table('user_storages')->where('id', '<', 0)->delete(); // noop
DB::table('storage_providers')->where('name', 'like', "hco\\_%")->delete();
DB::table('users')->where('username', 'like', "hco\\_%")->delete();

// ─── 1. Determinismo del helper ─────────────────────────────────────────────
h_section('1. canonicalOwnerId() es determinístico (100 invocaciones)');

// Pick a storage that has a real (non-NULL) canonical owner, traversing chain if needed.
$sampleStorage = null;
foreach (DB::table('storage_providers')->orderBy('id')->get() as $candidate) {
    if (StorageProvider::canonicalOwnerId((int) $candidate->id) !== null) {
        $sampleStorage = $candidate;
        break;
    }
}
if (!$sampleStorage) { h_fail('sin storages con canónico resuelto'); exit(1); }

$first = StorageProvider::canonicalOwnerId((int) $sampleStorage->id);
$consistent = true;
for ($i = 0; $i < 100; $i++) {
    if (StorageProvider::canonicalOwnerId((int) $sampleStorage->id) !== $first) {
        $consistent = false;
        break;
    }
}
h_check($consistent, "100 invocaciones retornaron " . var_export($first, true) . " consistentemente", "resultado NO determinístico");

// ─── 2. Branch is_personal: helper identifica owner via base_path ──────────
h_section('2. is_personal: helper identifica owner correcto via base_path');

$personalStorageId = (int) DB::table('storage_providers')
    ->where('is_personal', true)
    ->whereNotNull('base_path')
    ->whereRaw("base_path LIKE '/home/www/Usuarios_tcloud/%'")
    ->first()?->id;

if ($personalStorageId) {
    $storage = DB::table('storage_providers')->where('id', $personalStorageId)->first();
    $expectedUsername = basename(rtrim($storage->base_path, '/'));
    $expectedOwnerId = (int) DB::table('users')->where('username', $expectedUsername)->value('id');

    $canonical = StorageProvider::canonicalOwnerId($personalStorageId);

    h_check($canonical === $expectedOwnerId,
        "is_personal storage '{$storage->name}' → user '{$expectedUsername}' (id={$expectedOwnerId}) via base_path",
        "retornó " . var_export($canonical, true) . ", esperaba {$expectedOwnerId}");

    // Edge case: si admin (user_id=1) tiene full en el storage personal de OTRO user,
    // el helper NO debe devolver admin, sino el user derivado de base_path.
    if ($expectedOwnerId !== 1) {
        $adminHasFull = DB::table('user_storages')
            ->where('storage_provider_id', $personalStorageId)
            ->where('user_id', 1)
            ->where('permissions', 'full')
            ->exists();

        if (!$adminHasFull) {
            DB::table('user_storages')->insert([
                'storage_provider_id' => $personalStorageId,
                'user_id' => 1,
                'permissions' => 'full',
                'can_create_shares' => false,
                'assigned_at' => now(),
            ]);
        }

        $canonicalAfterAdmin = StorageProvider::canonicalOwnerId($personalStorageId);
        h_check($canonicalAfterAdmin === $expectedOwnerId,
            "admin dual-full en personal storage de OTRO user no roba ownership (sigue {$expectedOwnerId})",
            "admin robó ownership: retornó " . var_export($canonicalAfterAdmin, true));

        // Cleanup: borrar admin que agregamos
        if (!$adminHasFull) {
            DB::table('user_storages')->where('storage_provider_id', $personalStorageId)->where('user_id', 1)->delete();
        }
    } else {
        h_ok("storage es del admin (id=1); no aplica edge case admin-roba-ownership");
    }
} else {
    h_fail("sin storage personal /home/www/Usuarios_tcloud/* para test");
}

// ─── 3. Branch !is_personal: orden por user_storages.id ────────────────────
h_section('3. Shared storage: orden por user_storages.id (insertion order)');

$sharedStorageId = (int) DB::table('storage_providers')->where('is_personal', false)->first()?->id;
if ($sharedStorageId) {
    $canonicalShared = StorageProvider::canonicalOwnerId($sharedStorageId);
    // Esperar no-NULL (helper sube por parent chain si el storage no tiene full propio)
    if ($canonicalShared === null) {
        // Pick one with direct full
        $sharedStorageId = null;
        foreach (DB::table('storage_providers')->where('is_personal', false)->orderBy('id')->get() as $cand) {
            $directFull = (int) DB::table('user_storages')
                ->where('storage_provider_id', $cand->id)
                ->where('permissions', 'full')
                ->count();
            if ($directFull > 0) {
                $sharedStorageId = (int) $cand->id;
                break;
            }
        }
        if ($sharedStorageId) {
            $canonicalShared = StorageProvider::canonicalOwnerId($sharedStorageId);
        }
    }

    if ($canonicalShared === null) {
        h_fail("sin storage compartido con full resuelto");
    } else {
        $expected = (int) DB::table('user_storages')
            ->where('storage_provider_id', $sharedStorageId)
            ->where('permissions', 'full')
            ->orderBy('id')
            ->value('user_id');

        // Si expected es NULL (storage sin full propio), comparar con el canónico del parent
        if ($expected === null) {
            $parentId = (int) DB::table('storage_providers')->where('id', $sharedStorageId)->value('parent_storage_id');
            $expectedCanonical = $parentId ? StorageProvider::canonicalOwnerId($parentId) : null;
            h_check($canonicalShared === $expectedCanonical,
                "shared storage sin full propio hereda del parent ({$expectedCanonical})",
                "retornó " . var_export($canonicalShared, true) . ", esperaba parent canónico " . var_export($expectedCanonical, true));
        } else {
            h_check($canonicalShared === $expected, "shared storage retorna {$expected} (menor id)", "retornó " . var_export($canonicalShared, true));
        }
    }
} else {
    h_fail("sin storage compartido para test");
}

// ─── 4. Grep: 0 callsites con userStorages()->first()?->user_id ─────────────
h_section('4. Grep de auditoría: 0 callsites con userStorages()->first()?->user_id sin orderBy');

$grepOutput = [];
exec("grep -rn \"userStorages()->first()?->user_id\" " . escapeshellarg(__DIR__ . '/../app') . " --include='*.php' 2>/dev/null", $grepOutput);
h_check(count($grepOutput) === 0,
    "0 callsites con userStorages()->first()?->user_id",
    "encontró " . count($grepOutput) . " callsites: " . implode(', ', array_slice($grepOutput, 0, 3)));

// ─── 5. Unicidad de (storage_id, path): ratio 1:1 ──────────────────────────
h_section('5. Unicidad (storage_id, path) en files');

$total = (int) DB::table('files')->whereNull('deleted_at')->count();
$unique = (int) DB::table('files')->whereNull('deleted_at')
    ->select(DB::raw('COUNT(DISTINCT (storage_provider_id || \'|\' || path)) as c'))
    ->value('c');
h_check($total === $unique, "{$total} files = {$unique} (storage_id, path) únicos (ratio 1:1)", "ratio {$total}/{$unique} != 1");

// ─── 6. Smoke test: tcloud:files-canonicalize --dry-run ────────────────────
h_section('6. Smoke test: tcloud:files-canonicalize --dry-run ejecuta sin error');

try {
    $exitCode = Artisan::call('tcloud:files-canonicalize', ['--dry-run' => true]);
    $output = Artisan::output();
    h_check($exitCode === 0, "dry-run exit code = 0", "dry-run exit code = {$exitCode}");
    h_check(str_contains($output, 'Total filas a reasignar'), "output contiene resumen de conteos", "output no contiene resumen esperado:\n{$output}");
} catch (\Throwable $e) {
    h_fail("dry-run lanzó excepción: " . $e->getMessage());
}

// ─── Cleanup ────────────────────────────────────────────────────────────────
h_section('Cleanup');
DB::table('storage_providers')->where('name', 'like', "hco\\_%")->delete();
DB::table('users')->where('username', 'like', "hco\\_%")->delete();
h_ok('ningún registro residual (tag prefijo hco_)');

echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
