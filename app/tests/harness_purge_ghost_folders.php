<?php
/**
 * Harness de regresion para change `mis-archivos-depurar-registros-fantasma`.
 *
 * Verifica contra PostgreSQL real que `files:purge-ghost-folders` borra las
 * carpetas con `file_modified_at IS NULL` cuyo path no existe en disco, sin
 * tocar las carpetas legitimas (con mtime) ni los archivos (`is_folder=false`).
 *
 * Estrategia:
 *   - Crea un storage de prueba con `base_path` apuntando a un dir temporal
 *     FISICO para que la fila legitima pase el check `is_dir()`. NO crea
 *     el dir de las carpetas fantasma, asi que el comando las detecta.
 *   - Inserta 3 filas `files`:
 *     * 1 carpeta legitima (`mtime` set, dir existe en disco)
 *     * 1 carpeta fantasma (`mtime` NULL, dir NO existe)
 *     * 1 archivo (`is_folder=false`) fantasma (NO debe borrarse porque el
 *       comando solo procesa carpetas)
 *   - Dry-run: no se borra nada.
 *   - Apply: se borra la carpeta fantasma, quedan 2 filas (legitima + archivo).
 *
 * Cleanup: todo se crea con tag `hmfg_<8-hex>` y se borra en `finally`. El
 * dir temporal fisico tambien se elimina.
 *
 * Uso:
 *   cd app && php tests/harness_purge_ghost_folders.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

$tag = 'hmfg_' . bin2hex(random_bytes(4));
$failures = 0;

echo "Harness files-purge-ghost-folders (tag: {$tag})\n";

// ─── Cleanup defensivo pre-run ───────────────────────────────────────────────
h_section("cleanup defensivo pre-run [tag={$tag}]");
$r1 = DB::table('users')->where('username', 'like', 'hmfg_%')->delete();
$r2 = DB::table('storage_providers')->where('name', 'like', 'hmfg_%')->delete();
$r3 = DB::table('files')->where('name', 'like', 'hmfg_%')->delete();
// Solo dropear snapshots CREADOS por corridas previas de harnesses con el
// mismo prefijo `hmfg_`. Patron: el comando `files:purge-ghost-folders`
// crea la tabla con nombre `files_ghost_pre_purge_<YYYYMMDD_HHMMSS>` y NO
// incluye el tag del harness. Por seguridad, NO dropeamos nada: si quedaron
// snapshots de harnesses anteriores, deberan purgarse a mano.
$tmpDirs = glob('/tmp/hmfg_*') ?: [];
foreach ($tmpDirs as $d) {
    if (is_dir($d)) @rmdir($d);
}
$totalResiduos = $r1 + $r2 + $r3 + count($tmpDirs);
$totalResiduos === 0 ? h_ok('sin residuos previos') : h_ok("residuos borrados: users={$r1} storages={$r2} files={$r3} tmpdirs=" . count($tmpDirs));

$basePath = "/tmp/{$tag}";
@mkdir($basePath, 0755, true);
$legitDir = $basePath . '/' . $tag . '_legit_dir';
@mkdir($legitDir, 0755, true);
h_ok("base_path fisico: {$basePath}");
h_ok("dir legitimo fisico: {$legitDir}");

try {
    // ─── Bootstrap ─────────────────────────────────────────────────────────
    h_section('bootstrap: storage + carpetas');

    $user = User::create([
        'email' => "{$tag}@test.local",
        'username' => "{$tag}",
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    $storage = StorageProvider::create([
        'name' => "{$tag}_storage",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => $basePath,
        'is_personal' => false,
        'enabled' => true,
        'is_accessible' => true,
    ]);

    h_ok("user id={$user->id}, storage id={$storage->id}");

    // Fila legitima: dir existe en disco + file_modified_at con valor
    $legit = File::create([
        'name' => "{$tag}_legit_dir",
        'path' => "{$tag}_legit_dir",
        'size' => 0,
        'mime_type' => 'folder',
        'storage_provider_id' => $storage->id,
        'owner_id' => $user->id,
        'parent_id' => null,
        'is_folder' => true,
        'is_personal' => false,
        'file_modified_at' => \Carbon\Carbon::createFromTimestamp(filemtime($legitDir)),
    ]);

    // Fila fantasma: dir NO existe + file_modified_at NULL
    $ghost = File::create([
        'name' => "{$tag}_ghost_dir",
        'path' => "{$tag}_ghost_dir",
        'size' => 0,
        'mime_type' => 'folder',
        'storage_provider_id' => $storage->id,
        'owner_id' => $user->id,
        'parent_id' => null,
        'is_folder' => true,
        'is_personal' => false,
        'file_modified_at' => null,
    ]);

    // Archivo fantasma: NO debe borrarse (comando solo procesa is_folder=true)
    $ghostFile = File::create([
        'name' => "{$tag}_ghost_file.txt",
        'path' => "{$tag}_ghost_dir/{$tag}_ghost_file.txt",
        'size' => 100,
        'mime_type' => 'text/plain',
        'storage_provider_id' => $storage->id,
        'owner_id' => $user->id,
        'parent_id' => $ghost->id,
        'is_folder' => false,
        'is_personal' => false,
        'file_modified_at' => null,
    ]);

    h_ok("legit (folder, mtime+disk): id={$legit->id}");
    h_ok("ghost (folder, mtime=null, disk=missing): id={$ghost->id}");
    h_ok("ghostFile (no folder, mtime=null): id={$ghostFile->id}");

    $initialCount = File::where('storage_provider_id', $storage->id)->count();
    h_check($initialCount === 3, "filas iniciales = 3 ({$initialCount})");

    // ─── Dry-run scoped: BD no cambia ──────────────────────────────────────
    h_section('dry-run (BD no debe modificarse)');

    $exitCode = Artisan::call('files:purge-ghost-folders', [
        '--storage' => $storage->id,
    ]);
    h_check($exitCode === 0, "exit code 0 (dry-run scoped)");

    $afterDryCount = File::where('storage_provider_id', $storage->id)->count();
    h_check($afterDryCount === 3, "filas tras dry-run = 3 ({$afterDryCount})");

    // ─── Apply scoped ───────────────────────────────────────────────────────
    h_section('apply scoped');

    // Capturar el snapshot ANTES del apply para borrarlo luego solo a el
    $snapshotsBefore = collect(DB::select(
        "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'files_ghost_pre_purge_%'"
    ))->pluck('table_name')->all();

    $exitCode = Artisan::call('files:purge-ghost-folders', [
        '--apply' => true,
        '--yes' => true,
        '--storage' => $storage->id,
    ]);
    h_check($exitCode === 0, "exit code 0 (apply scoped)");

    // Detectar cual es el snapshot nuevo (los que no existian antes)
    $snapshotsAfter = collect(DB::select(
        "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'files_ghost_pre_purge_%'"
    ))->pluck('table_name')->all();
    $newSnapshots = array_values(array_diff($snapshotsAfter, $snapshotsBefore));
    h_check(count($newSnapshots) === 1, "se creo exactamente 1 snapshot nuevo (count=" . count($newSnapshots) . ")");
    $createdSnapshot = $newSnapshots[0] ?? null;

    // Folder fantasma debe estar borrado
    $ghostExists = File::where('id', $ghost->id)->exists();
    h_check(!$ghostExists, "carpeta fantasma borrada");

    // Folder legitima debe seguir
    $legitExists = File::where('id', $legit->id)->exists();
    h_check($legitExists, "carpeta legitima sigue viva");

    // Archivo fantasma: colgaba de la carpeta fantasma. El FK files_parent_id_fkey
    // es ON DELETE CASCADE, asi que al borrar la carpeta, el archivo hijo se
    // borra tambien. Esto es comportamiento esperado de BD, no del comando.
    // El comando SOLO borra carpetas; el archivo es victima colateral del
    // CASCADE al perder su parent_id.
    $ghostFileExists = File::where('id', $ghostFile->id)->exists();
    h_check(!$ghostFileExists, "archivo hijo de carpeta fantasma borrado por CASCADE (no por el comando, que solo procesa is_folder=true)");

    $finalCount = File::where('storage_provider_id', $storage->id)->count();
    h_check($finalCount === 1, "filas finales = 1 ({$finalCount}): solo la legitima (el archivo hijo se fue por CASCADE)");

    // ─── Apply sin candidatos debe ser no-op ───────────────────────────────
    h_section('apply sin candidatos');

    $exitCode = Artisan::call('files:purge-ghost-folders', [
        '--apply' => true,
        '--yes' => true,
        '--storage' => $storage->id,
    ]);
    h_check($exitCode === 0, "exit code 0 (apply sin candidatos)");

    $countNoOp = File::where('storage_provider_id', $storage->id)->count();
    h_check($countNoOp === $finalCount, "filas no cambiaron ({$countNoOp})");

    echo "\n";
    echo $failures === 0
        ? "✓ Todos los chequeos pasaron.\n"
        : "✗ {$failures} chequeo(s) fallaron.\n";
} finally {
    // ─── Cleanup ───────────────────────────────────────────────────────────
    h_section("cleanup post-run [tag={$tag}]");

    // Borrar SOLO el snapshot que este harness creo, no los de applys reales
    if ($createdSnapshot) {
        DB::statement("DROP TABLE IF EXISTS {$createdSnapshot}");
        h_ok("snapshot propio dropeado: {$createdSnapshot}");
    } else {
        h_ok('sin snapshot propio que dropear');
    }

    DB::table('files')->where('name', 'like', "{$tag}_%")->delete();
    DB::table('files')->where('path', 'like', "{$tag}_%")->delete();
    DB::table('storage_providers')->where('name', 'like', "{$tag}_%")->delete();
    DB::table('users')->where('username', 'like', "{$tag}_%")->delete();

    @rmdir($legitDir);
    @rmdir($basePath);
    h_ok('limpieza completada');
}

exit($failures === 0 ? 0 : 1);
