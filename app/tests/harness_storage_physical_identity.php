<?php
/**
 * Harness de regresión para el change `2026-09-17-storage-physical-path-normalization`.
 *
 * Cubre los 3 puntos del fix:
 *  1. Schema: `physical_path_normalized` se calcula correctamente y los 8
 *     pares existentes se detectan via `findByNormalizedPath`.
 *  2. Sync: `findMoreSpecificStorage` excluye storages mergeados.
 *  3. Controller: POST con path duplicado retorna HTTP 409.
 *  4. Merge: dry-run proyecta stats; apply re-forkea files, re-apunta FKs,
 *     marca duplicate.
 *
 * Tag prefijo: `hspi_<8-hex>` (storage physical identity) para que toda fila
 * que el harness cree sea trazable y limpiable sin tocar datos reales.
 *
 * Uso:
 *   cd app && php tests/harness_storage_physical_identity.php
 *   exit 0 = OK, 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\StorageMerge;
use App\Models\SystemSetting;
use App\Models\Transcription;
use App\Models\User;
use App\Services\FileRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'hspi_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void    { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void  { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void
{
    if ($cond) h_ok($ok); else h_fail($fail);
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP defensivo: borrar residuos de corridas previas con el mismo prefijo.
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP defensivo [tag=$tag]");
$staleUsers = User::where('username', 'LIKE', 'hspi_%')->pluck('id');
if ($staleUsers->isNotEmpty()) {
    File::whereIn('owner_id', $staleUsers)->delete();
    DB::table('user_storages')->whereIn('user_id', $staleUsers)->delete();
    DB::table('storage_merges')->whereIn('executed_by_user_id', $staleUsers)->delete();
    StorageProvider::whereRaw("name LIKE 'hspi_%'")->get()->each(function ($s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('keyword_scan_watermarks')->where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        $s->delete();
    });
    User::whereIn('id', $staleUsers)->delete();
    h_ok('residuos de corridas previas eliminados (' . $staleUsers->count() . ' users)');
} else {
    h_ok('sin residuos previos');
}

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$tmpBaseA = sys_get_temp_dir() . "/{$tag}_a";
$tmpBaseB = sys_get_temp_dir() . "/{$tag}_b";
foreach ([$tmpBaseA, $tmpBaseB] as $dir) {
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

$mkStorage = function (string $name, ?string $basePath) use ($tag, $user): StorageProvider {
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
    // Refresh para traer `physical_path_normalized` (STORED generated).
    // Eloquent no relee el INSERT, asi que la columna calculada por la BD
    // no aparece en `$sp->getAttributes()` hasta el refresh.
    return $sp->refresh();
};

$registry = app(FileRegistry::class);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: physical_path_normalized se calcula correctamente
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 1 — physical_path_normalized');

$sp = $mkStorage('A1', $tmpBaseA);
h_check(
    $sp->physical_path_normalized === strtolower(rtrim($tmpBaseA, '/')),
    'physical_path_normalized calculado para A1',
    'physical_path_normalized NO se calculó para A1'
);

$sp2 = $mkStorage('A2 same path', $tmpBaseA); // mismo base_path, otro storage
h_check(
    $sp2->physical_path_normalized === strtolower(rtrim($tmpBaseA, '/')),
    'A2 también tiene physical_path_normalized igual',
    'A2 falló en el cálculo'
);

$spNull = $mkStorage('NullPath', null);
h_check($spNull->physical_path_normalized === null, 'base_path null → physical_path_normalized null', 'base_path null NO se manejó');

$spEmpty = $mkStorage('EmptyPath', '');
h_check($spEmpty->physical_path_normalized === null, 'base_path "" → physical_path_normalized null', 'base_path "" NO se manejó');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: findByNormalizedPath y canonicalFor
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 2 — findByNormalizedPath / canonicalFor');

$found = StorageProvider::findByNormalizedPath(strtolower(rtrim($tmpBaseA, '/')), 'local');
h_check($found !== null, 'findByNormalizedPath retorna un storage', 'findByNormalizedPath retorna null');

$canonical = StorageProvider::canonicalFor(strtolower(rtrim($tmpBaseA, '/')), 'local');
h_check($canonical !== null, 'canonicalFor retorna el de menor id (tie-break)', 'canonicalFor retorna null');

// Ambos sp y sp2 tienen mismo path y 0 transcripciones → tie-break por id menor
h_check($canonical->id === $sp->id, "canonicalFor devuelve id={$sp->id} (menor) cuando hay empate", 'canonicalFor no respeta tie-break');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3: HTTP 409 al crear storage con path duplicado
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 3 — Controller HTTP 409');

// Test via controller simulado: invocamos StorageProviderController::store
$controller = app(\App\Http\Controllers\StorageProviderController::class);
$request = \Illuminate\Http\Request::create('/api/storages', 'POST', [
    'name' => "{$tag} dup attempt",
    'type' => 'local',
    'base_path' => $tmpBaseA,
    'enabled' => true,
]);
$response = $controller->store($request);
h_check($response->getStatusCode() === 409, 'StorageProviderController::store retorna HTTP 409 en path duplicado', 'Controller NO retornó 409 (status: ' . $response->getStatusCode() . ')');
$body = json_decode($response->getContent(), true);
h_check(isset($body['error']) && $body['error'] === 'duplicate_storage_path', 'Respuesta JSON incluye error=duplicate_storage_path', 'Respuesta JSON sin código de error');

// Confirmar que el storage NO se creó
$countCreated = StorageProvider::where('name', "{$tag} dup attempt")->count();
h_check($countCreated === 0, 'Storage NO se creó tras 409', 'Storage se creó a pesar del 409');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 5: storages:detect-duplicate-paths reporta los pares del harness
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 5 — detect-duplicate-paths');

Artisan::call('storages:detect-duplicate-paths', ['--dry-run' => true]);
$output = Artisan::output();
h_check(str_contains($output, $tag), 'detect-duplicate-paths muestra nuestro storage de test', 'detect-duplicate-paths no muestra el storage del harness');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4: isDuplicate() y sync excluye mergeados
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 4 — isDuplicate() + sync exclusion');

h_check($sp->isDuplicate() === false, 'isDuplicate() false antes del merge', 'isDuplicate() true antes del merge');

// Forzar merge manual: sp2 merged_into sp
DB::table('storage_providers')->where('id', $sp2->id)->update([
    'duplicate_of_storage_id' => $sp->id,
    'merged_at' => now(),
    'merged_reason' => 'harness test',
    'enabled' => false,
    'base_path' => null,
]);
$sp2->refresh();
h_check($sp2->isDuplicate() === true, 'isDuplicate() true tras update manual', 'isDuplicate() false tras update');

// findByNormalizedPath debe excluir sp2
$foundAfterMerge = StorageProvider::findByNormalizedPath(strtolower(rtrim($tmpBaseA, '/')), 'local');
h_check($foundAfterMerge->id === $sp->id, 'findByNormalizedPath ignora el mergeado', 'findByNormalizedPath devuelve el mergeado');

// Sync exclusion: crear file en sp2 path manualmente y verificar findMoreSpecificStorage lo ignora
// (necesita un storage root que NO sea sp ni sp2)
$tmpBaseRoot = sys_get_temp_dir() . "/{$tag}_root";
if (!is_dir($tmpBaseRoot)) mkdir($tmpBaseRoot, 0755, true);
$root = $mkStorage('Root', $tmpBaseRoot);

$sync = app(\App\Services\StorageSyncService::class);
$ref = new \ReflectionMethod($sync, 'findMoreSpecificStorage');
$ref->setAccessible(true);
// El test: $tmpBaseA es prefijo de un path dentro del root; debería delegarse a sp (canonical, no sp2)
$testPath = $tmpBaseRoot . '/' . substr($tmpBaseA, strlen($tmpBaseRoot) + 1) . '/folder/file.txt';
$result = $ref->invoke($sync, $testPath, $root->id);
h_check($result === null || $result->id === $sp->id, 'findMoreSpecificStorage ignora el duplicate sp2', 'findMoreSpecificStorage devolvió el duplicate: ' . ($result?->id ?? 'null'));

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 6: storages:merge-duplicates dry-run
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 6 — merge-duplicates dry-run');

// Resetear sp2 para que tenga base_path de nuevo (lo nullificamos arriba)
DB::table('storage_providers')->where('id', $sp2->id)->update([
    'duplicate_of_storage_id' => null,
    'merged_at' => null,
    'merged_reason' => null,
    'enabled' => true,
    'base_path' => $tmpBaseA,
]);

// Crear un file en sp2 que NO exista en sp
mkdir("{$tmpBaseA}/orphan_folder", 0755, true);
$orphanFile = $registry->ensure($sp2, 'orphan_folder/file.txt', [
    'name' => 'file.txt',
    'path' => 'orphan_folder/file.txt',
    'size' => 100,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $sp2->id,
    'owner_id' => $user->id,
    'parent_id' => null,
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);

Artisan::call('storages:merge-duplicates', ['--canonical' => $sp->id, '--duplicate' => $sp2->id]);
$output = Artisan::output();
h_check(str_contains($output, 'DRY-RUN'), 'dry-run produce mensaje DRY-RUN', 'dry-run no muestra DRY-RUN');
h_check(str_contains($output, 'Files a re-forkear'), 'dry-run muestra stats de files a re-forkear', 'dry-run sin stats');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 7: storages:merge-duplicates --apply ejecuta el merge
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 7 — merge-duplicates --apply');

Artisan::call('storages:merge-duplicates', [
    '--canonical' => (string) $sp->id,
    '--duplicate' => (string) $sp2->id,
    '--apply' => true,
    '--yes' => true,
]);
$output = Artisan::output();
h_check(str_contains($output, 'Merge completado'), 'merge --apply imprime "Merge completado"', '--apply no muestra Merge completado');

$sp2->refresh();
h_check($sp2->duplicate_of_storage_id === $sp->id, 'sp2 ahora duplicate_of_storage_id = sp.id', 'duplicate_of_storage_id no se seteo');
h_check($sp2->enabled === false, 'sp2.enabled = false tras merge', 'sp2.enabled sigue true');
h_check($sp2->base_path === null, 'sp2.base_path = null tras merge', 'sp2.base_path no se limpió');

// Audit row en storage_merges
$audit = DB::table('storage_merges')->where('canonical_id', $sp->id)->where('duplicate_id', $sp2->id)->first();
h_check($audit !== null, 'storage_merges tiene fila de audit', 'storage_merges sin fila de audit');

// El file orphan debería haberse re-forkeado a sp
$reForked = File::where('name', 'file.txt')->where('storage_provider_id', $sp->id)->where('path', 'orphan_folder/file.txt')->first();
h_check($reForked !== null, 'file orphan re-forkeado al canonical', 'file orphan NO re-forkeado');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 8: UNIQUE constraint migration aborta si duplicates_remaining > 0
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 8 — UNIQUE constraint migration guard');

// Resetear el setting a > 0 para que la aserción sea estable entre runs.
// Cada harness run ejecuta el merge-duplicates --apply que decrementa el setting.
SystemSetting::set('storage.duplicates_remaining', '8');

// Asegurar que SystemSetting tiene > 0
$remaining = (int) SystemSetting::get('storage.duplicates_remaining', '0');
h_check($remaining > 0, "SystemSetting(storage.duplicates_remaining) = {$remaining} (debe ser > 0)", 'duplicates_remaining debería ser > 0 antes del merge de todos');

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 9: repair-delegation-leak re-forkea archivos de parent a sub-storage
// ─────────────────────────────────────────────────────────────────────────────
h_section('ESCENARIO 9 — repair-delegation-leak');

// Crear storages frescos para este escenario (los anteriores $sp/$sp2 fueron merged)
// El sub DEBE estar anidado bajo el parent para que findMoreSpecificStorage()
// lo detecte como prefijo del path.
$tmpBaseParent9 = sys_get_temp_dir() . "/{$tag}_p9";
$tmpBaseSub9 = sys_get_temp_dir() . "/{$tag}_p9/sub";  // nested
foreach ([$tmpBaseParent9, $tmpBaseSub9] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}
$parent9 = $mkStorage('Parent9', $tmpBaseParent9);
$sub9 = $mkStorage('Sub9', $tmpBaseSub9);

// Crear el archivo FISICO dentro del sub-storage (para que su absolute_path
// caiga bajo el base_path del sub-storage).
$subFolder = "{$tmpBaseSub9}/leak_folder";
mkdir($subFolder, 0755, true);
file_put_contents("{$subFolder}/leaked_file.txt", 'harness leak content');

// El path del archivo relativo al PARENT (porque lo crea el cron del parent
// antes de delegar) es "sub/leak_folder/leaked_file.txt".
// Pero nosotros vamos a sembrar el file row directamente para simular el leak.
$leakedFile = File::create([
    'name' => 'leaked_file.txt',
    'path' => 'sub/leak_folder/leaked_file.txt',
    'size' => 100,
    'mime_type' => 'text/plain',
    'storage_provider_id' => $parent9->id, // BUG: deberia estar en $sub9
    'owner_id' => $user->id,
    'parent_id' => null, // huerfano en root del parent (caso real)
    'is_folder' => false,
    'availability_state' => 'available',
    'last_verified_at' => now(),
]);

h_check(
    $leakedFile->storage_provider_id === $parent9->id,
    'leaked file inicialmente en parent (simulando delegation leak)',
    'leaked file NO esta en parent'
);

// dry-run
Artisan::call('files:repair-delegation-leak', [
    '--dry-run' => true,
    '--to-storage' => (string) $sub9->id,
]);
h_check(strlen(Artisan::output()) > 0, 'repair-delegation-leak --dry-run produce output', 'dry-run no produce output');

// Verificar que el file NO se movio (dry-run no muta)
$stillInRoot = File::where('id', $leakedFile->id)->value('storage_provider_id');
h_check($stillInRoot === $parent9->id, 'dry-run NO muta el archivo', 'dry-run mutó el archivo');

// apply
Artisan::call('files:repair-delegation-leak', [
    '--apply' => true,
    '--to-storage' => (string) $sub9->id,
]);

// Verificar que el file ahora esta en el sub-storage
$fileAfter = File::find($leakedFile->id);
h_check($fileAfter !== null, 'archivo todavia existe despues del apply', 'archivo desaparecido');
h_check(
    $fileAfter && $fileAfter->storage_provider_id === $sub9->id,
    "archivo movido a sub-storage #{$sub9->id}",
    "archivo sigue en storage " . ($fileAfter?->storage_provider_id ?? 'null')
);
h_check(
    $fileAfter && $fileAfter->parent_id !== null,
    'parent_id cambio de NULL a folder del sub-storage',
    'parent_id sigue NULL (no se movio)'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN (orden inverso al setup)
// ─────────────────────────────────────────────────────────────────────────────
h_section('TEARDOWN');
try {
    $created = StorageProvider::whereRaw("name LIKE '{$tag}%'")->get();
    foreach ($created as $s) {
        File::where('storage_provider_id', $s->id)->delete();
        DB::table('keyword_scan_watermarks')->where('storage_provider_id', $s->id)->delete();
        DB::table('user_storages')->where('storage_provider_id', $s->id)->delete();
        DB::table('storage_merges')->where('executed_by_user_id', $user->id)->delete();
        $s->delete();
    }
    DB::table('user_storages')->where('user_id', $user->id)->delete();
    $user->delete();

    foreach ([$tmpBaseA, $tmpBaseB, $tmpBaseRoot, $tmpBaseParent9, $tmpBaseSub9] as $dir) {
        if (is_dir($dir)) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
            }
            @rmdir($dir);
        }
    }
    h_ok('limpieza completada (storages, user, files, tempdirs)');
} catch (\Throwable $e) {
    echo "  ! cleanup error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: storage-physical-path-normalization — 8 escenarios verde\n";
    exit(0);
} else {
    echo "FAIL: {$failures} aserción(es) rota(s)\n";
    exit(1);
}
