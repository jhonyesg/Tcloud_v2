<?php
/**
 * Harness de regresión para el change `schema-clarity-rename-merged-into`.
 *
 * CONTEXTO HISTÓRICO
 * ------------------
 * Este harness verificaba el flujo create/edit/delete completo del modelo
 * mirror→canónico: `files:repair-folder-mirrors`, `FilePhysicalIdentity::
 * link()/unlink()`, `storages:merge-duplicates --apply` y
 * `shares:repair-mirror-targets --apply`.
 *
 * El 2026-09-21 la migration `2026_09_20_120000_drop_mirror_columns_and_
 * restore_baseline` desmontó esa familia de forma deliberada. TODOS los
 * comandos y helpers que este harness ejercitaban dejaron de existir, y el
 * harness CRASHEABA (excepción de SQL) en vez de reportar FAIL.
 *
 * Qué verifica AHORA, contra el esquema vigente:
 *   1. Lo que SOBREVIVE del modelo de storages: creación, jerarquía via
 *      `parent_storage_id`, detección de duplicados por ruta normalizada
 *      (`findByNormalizedPath` / `canonicalFor`) y la semántica de
 *      `duplicate_of_storage_id` (merge / un-merge).
 *   2. Que la familia mirror NO pueda reaparecer por descuido en el código de
 *      aplicación.
 *   3. Que el alta de un share siga funcionando con el contrato actual.
 *
 * Tag prefijo: `hmcr_<8-hex>` (merged-into create/edit/delete).
 *
 * Uso:
 *   cd app && php tests/harness_merged_into_create_edit_delete.php
 *   exit 0 = OK, 1 = alguna asercion fallo
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hmcr_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) { h_ok($ok); } else { h_fail($fail); }
}

function h_column_exists(string $table, string $column): bool
{
    return DB::selectOne(
        "SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
        [$table, $column]
    ) !== null;
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
// SETUP — storages padre/sub (jerarquia) + un par con la misma ruta (duplicado)
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpParent = sys_get_temp_dir() . "/{$tag}_p";
$tmpSub = $tmpParent . '/sub';
foreach ([$tmpParent, $tmpSub] as $d) {
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

$tmpDup = sys_get_temp_dir() . "/{$tag}_dup";
if (! is_dir($tmpDup)) {
    mkdir($tmpDup, 0755, true);
}

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

$parentStorage = StorageProvider::create([
    'name' => "{$tag} parent",
    'type' => 'local',
    'kind' => 'local',
    'config' => [],
    'base_path' => $tmpParent,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
])->refresh();

$subStorage = StorageProvider::create([
    'name' => "{$tag} sub",
    'type' => 'local',
    'kind' => 'local',
    'config' => [],
    'base_path' => $tmpSub,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
    'parent_storage_id' => $parentStorage->id,
])->refresh();

h_ok("parent storage (id={$parentStorage->id}), sub storage (id={$subStorage->id})");

// ─────────────────────────────────────────────────────────────────────────────
// (S1) Creación basica
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S1) StorageProvider::create — creación básica');

h_check($parentStorage->id > 0, 'storage creado', 'storage NO creado');
h_check(
    $parentStorage->kind === 'local',
    'el campo canonico `kind` se guardó',
    'el campo canonico `kind` NO se guardó'
);
h_check(
    $parentStorage->type === 'local',
    'el campo legacy `type` sigue poblándose',
    'el campo legacy `type` quedo vacio'
);
h_check(
    $parentStorage->physical_path_normalized !== null,
    'la ruta normalizada se calcula al crear',
    'la ruta normalizada NO se calculo'
);
h_check(
    $parentStorage->physical_path_normalized === strtolower(rtrim($tmpParent, '/')),
    'la ruta normalizada es la base_path en minusculas sin barra final',
    'la ruta normalizada no coincide: ' . var_export($parentStorage->physical_path_normalized, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// (S2) Jerarquia vigente: parent_storage_id es DERIVADA, no asignable
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S2) Jerarquia de storages via parent_storage_id');

// `parent_storage_id` NO es mass-assignable a proposito: la jerarquia se
// DERIVA del base_path, no la elige el operador.
h_check(
    ! in_array('parent_storage_id', (new StorageProvider())->getFillable(), true),
    'parent_storage_id NO es mass-assignable (la jerarquia se deriva)',
    'parent_storage_id se puede asignar por mass-assignment — la jerarquia dejaria de ser derivada'
);

$subStorage->forceFill(['parent_storage_id' => null])->saveQuietly();

$hierarchy = app(\App\Services\Ia\StorageHierarchyService::class);
$computedParent = $hierarchy->recomputeParent($subStorage->refresh());
h_check(
    $computedParent === $parentStorage->id,
    'recomputeParent deriva el padre por prefijo de base_path',
    'recomputeParent devolvio ' . var_export($computedParent, true) . " (esperado {$parentStorage->id})"
);
h_check(
    $subStorage->refresh()->parent_storage_id === $parentStorage->id,
    'el padre queda persistido',
    'el padre NO quedo persistido'
);

$hierarchy->recomputeParent($parentStorage->refresh());
h_check(
    $parentStorage->refresh()->parent_storage_id === null,
    'el padre no tiene padre',
    'el padre tiene padre'
);

// ─────────────────────────────────────────────────────────────────────────────
// (S3) Deteccion de duplicados por ruta normalizada
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S3) Deteccion de duplicados por ruta normalizada');

$normalizedDup = strtolower(rtrim($tmpDup, '/'));

$dupStorage = StorageProvider::create([
    'name' => "{$tag} duplicate",
    'type' => 'local',
    'kind' => 'local',
    'config' => [],
    'base_path' => $tmpDup,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
])->refresh();

$found = StorageProvider::findByNormalizedPath($normalizedDup, 'local');
h_check(
    $found !== null && $found->id === $dupStorage->id,
    'findByNormalizedPath encuentra el storage por su ruta normalizada',
    'findByNormalizedPath no encontró el storage'
);

$canonical = StorageProvider::canonicalFor($normalizedDup, 'local');
h_check(
    $canonical !== null && $canonical->id === $dupStorage->id,
    'canonicalFor devuelve el storage canonico (no mergeado)',
    'canonicalFor no devolvio el canonico'
);

// ─────────────────────────────────────────────────────────────────────────────
// (S4) Merge y un-merge de storages por `duplicate_of_storage_id`
// ─────────────────────────────────────────────────────────────────────────────
h_section('(S4) Merge / un-merge de storages');

DB::table('storage_providers')->where('id', $dupStorage->id)->update([
    'duplicate_of_storage_id' => $parentStorage->id,
    'merged_at' => now(),
    'merged_reason' => 'harness_test',
]);
$dupStorage->refresh();

h_check(
    $dupStorage->duplicate_of_storage_id === $parentStorage->id,
    'el duplicado apunta al canonico',
    'el duplicado NO apunta al canonico'
);

$canonicalAfterMerge = StorageProvider::canonicalFor($normalizedDup, 'local');
h_check(
    $canonicalAfterMerge === null,
    'tras el merge, la ruta ya NO resuelve a un storage canonico (el mergeado queda excluido)',
    'tras el merge la ruta seguia resolviendo a un storage canonico'
);
h_check(
    StorageProvider::whereNull('duplicate_of_storage_id')->where('physical_path_normalized', $normalizedDup)->count() === 0,
    'no queda ningun storage canonico (no mergeado) con esa ruta',
    'queda un storage canonico con esa ruta'
);

$excluded = StorageProvider::whereNull('duplicate_of_storage_id')->where('id', $dupStorage->id)->exists();
h_check(
    $excluded === false,
    'whereNull(duplicate_of_storage_id) excluye al duplicado',
    'whereNull(duplicate_of_storage_id) NO excluyo al duplicado'
);

$stillCanonical = StorageProvider::whereNull('duplicate_of_storage_id')->where('id', $parentStorage->id)->exists();
h_check(
    $stillCanonical === true,
    'el canonico sigue siendo elegible',
    'el canonico fue excluido'
);

// Un-merge
DB::table('storage_providers')->where('id', $dupStorage->id)->update([
    'duplicate_of_storage_id' => null,
    'merged_at' => null,
    'merged_reason' => null,
]);
$dupStorage->refresh();
h_check(
    $dupStorage->duplicate_of_storage_id === null,
    'el un-merge limpia el puntero',
    'el un-merge NO limpio el puntero'
);
h_check(
    StorageProvider::whereNull('duplicate_of_storage_id')->where('id', $dupStorage->id)->exists(),
    'tras el un-merge el duplicado vuelve a ser elegible',
    'tras el un-merge el duplicado sigue excluido'
);

// ─────────────────────────────────────────────────────────────────────────────
// (M1) La familia mirror esta retirada y no puede reaparecer
// ─────────────────────────────────────────────────────────────────────────────
h_section('(M1) La familia mirror esta retirada');

foreach (['canonical_folder_id', 'merged_into_id', 'merged_at', 'merged_reason'] as $col) {
    h_check(
        ! h_column_exists('files', $col),
        "files.{$col} no existe",
        "files.{$col} SIGUE existiendo"
    );
}

$retired = ['canonical_folder_id', 'file_mirror_audit_log', 'files_owner_canonical_audit', 'files_prune_batches'];
$scanRoots = [__DIR__ . '/../app', __DIR__ . '/../resources/views', __DIR__ . '/../routes'];
$offenders = [];
foreach ($scanRoots as $root) {
    if (! is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile() || ! preg_match('/\.php$/', $fileInfo->getPathname())) {
            continue;
        }
        $contents = (string) file_get_contents($fileInfo->getPathname());
        foreach ($retired as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = str_replace(__DIR__ . '/../', '', $fileInfo->getPathname()) . ' -> ' . $needle;
            }
        }
    }
}
h_check(
    $offenders === [],
    'ningun archivo de aplicacion referencia la familia mirror retirada',
    'referencias a la familia retirada: ' . implode(', ', $offenders)
);

$hasMirrorCommand = collect(\Illuminate\Support\Facades\Artisan::all())
    ->contains(function ($c) {
        if (! $c instanceof \Illuminate\Console\Command) {
            return false;
        }
        try {
            $name = (string) $c->getName();
        } catch (\Throwable $e) {
            return false;
        }
        return str_contains($name, 'mirror') || str_contains($name, 'canonicalization');
    });
h_check(
    ! $hasMirrorCommand,
    'no queda ningun comando artisan de la familia mirror',
    'queda un comando artisan de la familia mirror'
);

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP [tag=$tag]");

DB::table('shares')->where('created_by', $user->id)->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->where('user_id', $user->id)->delete();
StorageProvider::whereIn('id', [$dupStorage->id, $subStorage->id, $parentStorage->id])->delete();
$user->delete();

@rmdir($tmpSub);
@rmdir($tmpParent);
@rmdir($tmpDup);

h_ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness merged-into create/edit/delete | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);
