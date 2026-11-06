<?php
/**
 * Harness de regresión para el change `schema-clarity-rename-merged-into`.
 *
 * CONTEXTO HISTÓRICO
 * ------------------
 * Este harness nació para verificar el rename `files.merged_into_id` ->
 * `files.canonical_folder_id` (y `storage_providers.merged_into_id` ->
 * `storage_providers.duplicate_of_storage_id`).
 *
 * El 2026-09-21 la migration `2026_09_20_120000_drop_mirror_columns_and_
 * restore_baseline` desmontó DELIBERADAMENTE la familia mirror/canónico de
 * `files` (columnas `canonical_folder_id`, `merged_at`, `merged_reason`,
 * `base_path_snapshot`, `availability_state`, `last_verified_at`,
 * `missing_since_at`) junto con las tablas `file_mirror_audit_log`,
 * `files_owner_canonical_audit` y `files_prune_batches`. El proyecto decidió
 * que el modelo de identidad vigente es `storage_providers.parent_storage_id`
 * + la equivalencia por ruta normalizada.
 *
 * Qué pasó con este harness: CRASHEABA (excepción de SQL no capturada) en
 * lugar de reportar FAIL. Un guardarraíl que aborta no señala el defecto: es
 * peor que no tenerlo. Este change (`fix-shares-module-regression`) lo
 * reescribe para que:
 *
 *   1. Verifique el estado VIGENTE: la familia mirror esta retirada de
 *      `files` y no puede reaparecer por descuido.
 *   2. Verifique lo que SOBREVIVE: `storage_providers.duplicate_of_storage_id`
 *      con su FK, su indice y su semantica.
 *   3. Verifique que NINGUN codigo de aplicacion consulta las columnas ni
 *      las tablas retiradas (una query contra una columna inexistente es un
 *      HTTP 500 en produccion).
 *
 * Tag prefijo: `hmrc_<8-hex>` (merged-into rename compat).
 *
 * Uso:
 *   cd app && php tests/harness_merged_into_rename_compat.php
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

$tag = 'hmrc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) { h_ok($ok); } else { h_fail($fail); }
}

/** Devuelve true si la columna existe en la tabla. */
function h_column_exists(string $table, string $column): bool
{
    return DB::selectOne(
        "SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
        [$table, $column]
    ) !== null;
}

/** Devuelve true si la tabla existe. */
function h_table_exists(string $table): bool
{
    return DB::selectOne(
        "SELECT table_name FROM information_schema.tables WHERE table_name = ?",
        [$table]
    ) !== null;
}

// ─────────────────────────────────────────────────────────────────────────────
// (0) CLEANUP defensivo
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");

$staleUsers = User::where('username', 'LIKE', 'hmrc_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
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
// (1) La familia mirror/canonico esta RETIRADA de `files`
// ─────────────────────────────────────────────────────────────────────────────
h_section('(1) Retirada de la familia mirror en `files`');

foreach (['canonical_folder_id', 'merged_into_id', 'merged_at', 'merged_reason', 'base_path_snapshot'] as $col) {
    h_check(
        ! h_column_exists('files', $col),
        "files.{$col} no existe (retirada intencional)",
        "files.{$col} SIGUE existiendo — la retirada no se aplico"
    );
}

// Las tres columnas de disponibilidad se re-agregan con otro proposito (estado
// del recurso para el modulo de compartidos), asi que su presencia es correcta.
h_check(
    h_column_exists('files', 'availability_state'),
    'files.availability_state existe (estado de disponibilidad del recurso)',
    'files.availability_state NO existe'
);

// ─────────────────────────────────────────────────────────────────────────────
// (2) Las tablas de auditoria mirror estan retiradas
// ─────────────────────────────────────────────────────────────────────────────
h_section('(2) Retirada de las tablas de auditoria mirror');

foreach (['file_mirror_audit_log', 'files_owner_canonical_audit', 'files_prune_batches'] as $table) {
    h_check(
        ! h_table_exists($table),
        "la tabla {$table} no existe (retirada intencional)",
        "la tabla {$table} SIGUE existiendo"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// (3) Lo que SOBREVIVE: storage_providers.duplicate_of_storage_id
// ─────────────────────────────────────────────────────────────────────────────
h_section('(3) Contrato vigente: storage_providers.duplicate_of_storage_id');

h_check(
    h_column_exists('storage_providers', 'duplicate_of_storage_id'),
    'storage_providers.duplicate_of_storage_id existe',
    'storage_providers.duplicate_of_storage_id NO existe'
);
h_check(
    ! h_column_exists('storage_providers', 'merged_into_id'),
    'storage_providers.merged_into_id NO existe (rename completo)',
    'storage_providers.merged_into_id SIGUE existiendo — rename incompleto'
);

$spFk = DB::selectOne("
    SELECT conname FROM pg_constraint
    WHERE conrelid = 'storage_providers'::regclass AND conname = 'storage_providers_duplicate_of_storage_id_fkey'
");
h_check($spFk !== null, 'FK storage_providers_duplicate_of_storage_id_fkey existe', 'FK storage_providers_duplicate_of_storage_id_fkey NO existe');

$spIdx = DB::selectOne("
    SELECT indexname FROM pg_indexes
    WHERE tablename = 'storage_providers' AND indexname = 'storage_providers_duplicate_of_storage_id_idx'
");
h_check($spIdx !== null, 'index storage_providers_duplicate_of_storage_id_idx existe', 'index storage_providers_duplicate_of_storage_id_idx NO existe');

// ─────────────────────────────────────────────────────────────────────────────
// (4) SETUP: un par de storages duplicados
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpDir = sys_get_temp_dir() . "/{$tag}_sp";
if (! is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
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
    'kind' => 'local',
    'config' => [],
    'base_path' => $tmpDir,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
])->refresh();

$spDuplicate = StorageProvider::create([
    'name' => "{$tag} duplicate",
    'type' => 'local',
    'kind' => 'local',
    'config' => [],
    'base_path' => $tmpDir . '/sub',
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'is_personal' => false,
])->refresh();

h_ok("storages: canonical={$spCanonical->id}, duplicate={$spDuplicate->id}");

// Marcar el duplicado como mergeado hacia el canonico
DB::table('storage_providers')->where('id', $spDuplicate->id)->update([
    'duplicate_of_storage_id' => $spCanonical->id,
    'merged_at' => now(),
    'merged_reason' => 'harness_test',
    'enabled' => false,
]);
$spDuplicate->refresh();

// ─────────────────────────────────────────────────────────────────────────────
// (5) Semantica del puntero
// ─────────────────────────────────────────────────────────────────────────────
h_section('(5) Semantica de duplicate_of_storage_id');

$countSp = (int) DB::table('storage_providers')
    ->where('duplicate_of_storage_id', $spCanonical->id)
    ->count();
h_check(
    $countSp === 1,
    "storage_providers WHERE duplicate_of_storage_id = {$spCanonical->id} = 1",
    "storage_providers WHERE duplicate_of_storage_id = {$spCanonical->id} = {$countSp} (esperado 1)"
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
    'StorageProvider::whereNull(duplicate_of_storage_id) NO excluyo el duplicate'
);

$canonicalNotDuplicate = StorageProvider::whereNull('duplicate_of_storage_id')
    ->where('id', $spCanonical->id)
    ->exists();
h_check(
    $canonicalNotDuplicate === true,
    'el storage canonico sigue siendo elegible (whereNull)',
    'el storage canonico fue excluido por whereNull'
);

// ─────────────────────────────────────────────────────────────────────────────
// (6) Ningun codigo de aplicacion consulta la familia retirada
// ─────────────────────────────────────────────────────────────────────────────
h_section('(6) Ningun codigo de aplicacion consulta la familia retirada');

$retired = [
    'canonical_folder_id' => 'columna retirada de files',
    'file_mirror_audit_log' => 'tabla de auditoria retirada',
    'files_owner_canonical_audit' => 'tabla de auditoria retirada',
    'files_prune_batches' => 'tabla de lotes retirada',
];

$scanRoots = [
    __DIR__ . '/../app',
    __DIR__ . '/../resources/views',
    __DIR__ . '/../routes',
];

$offenders = [];
foreach ($scanRoots as $root) {
    if (! is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        if (! preg_match('/\.(php|blade\.php)$/', $path)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        foreach ($retired as $needle => $description) {
            if (str_contains($contents, $needle)) {
                $offenders[] = str_replace(__DIR__ . '/../', '', $path) . ' referencia ' . $needle . ' (' . $description . ')';
            }
        }
    }
}

h_check(
    $offenders === [],
    'ningun archivo de aplicacion referencia la familia mirror retirada',
    'referencias a la familia retirada:\n        - ' . implode("\n        - ", $offenders)
);

// El comando `shares:repair-mirror-targets` y el de impacto de
// canonicalizacion no deben seguir registrados: operan sobre columnas y
// tablas que ya no existen.
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
    'no queda ningun comando artisan que opere sobre la familia mirror',
    'queda un comando artisan que opera sobre la familia mirror'
);

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP [tag=$tag]");

DB::table('shares')->where('created_by', $user->id)->delete();
File::where('owner_id', $user->id)->delete();
DB::table('user_storages')->where('user_id', $user->id)->delete();
StorageProvider::where('id', $spDuplicate->id)->delete();
StorageProvider::where('id', $spCanonical->id)->delete();
$user->delete();

@rmdir($tmpDir . '/sub');
@rmdir($tmpDir);

h_ok('cleanup completo');

echo "\n════════════════════════════════════════════════════════════════\n";
echo " Harness merged-into rename compat | Failures: $failures\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($failures > 0 ? 1 : 0);
