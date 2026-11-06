<?php
/**
 * Harness de regresion: contrato de borrado de carpetas en Mis Archivos.
 *
 * Cubre la regresion 2026-10-05: carpetas cuyo contenido pertenece a otro
 * usuario del sistema (p. ej. `root:root`, creadas por un cron que corre como
 * root) NO se podian borrar desde la UI. El controlador borraba las filas de
 * BD de todos modos y respondia "Deleted", asi que la carpeta reaparecia en el
 * siguiente listado y el operador veia un falso exito.
 *
 * Contrato verificado (via reflexion sobre FileController):
 *   A. `deleteRecursive()` purga TODO el arbol fisico (incluye archivos sin
 *      fila en BD), borra las filas y devuelve NULL cuando todo va bien.
 *   B. `deleteRecursive()` devuelve el path del elemento que fallo y CONSERVA
 *      las filas de BD cuando el borrado fisico falla.
 *   C. `purgeDirectoryTree()` elimina contenido NO rastreado en BD.
 *
 * IMPORTANTE: las aserciones B/C solo son significativas ejecutando como
 * `www` (uid != 0), porque root ignora los bits de permiso. Si se corre como
 * root, el harness lo advierte y salta las aserciones dependientes de euid.
 *
 * Uso:
 *   cd app
 *   sudo -u www php tests/harness_folder_delete_contract.php
 *   php tests/harness_folder_delete_contract.php   # happy-path solo
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\StorageProvider;
use App\Models\User;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

$tag = 'hmdf_' . bin2hex(random_bytes(4));
$failures = 0;
$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;

echo "Harness folder-delete-contract (tag: {$tag}, euid=" . ($euid ?? '?') . ")\n";

$basePath = "/tmp/{$tag}";
@mkdir($basePath, 0755, true);

$controller = new FileController();
$ref = new ReflectionClass(FileController::class);
$deleteRecM = $ref->getMethod('deleteRecursive');    $deleteRecM->setAccessible(true);
$deleteFileM = $ref->getMethod('deleteFile');        $deleteFileM->setAccessible(true);
$purgeM = $ref->getMethod('purgeDirectoryTree');     $purgeM->setAccessible(true);

try {
    h_section('bootstrap: user + storage + arbol');

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

    function hmdf_mkfile(string $abs, string $content = 'x'): void
    {
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, $content);
    }

    function hmdf_mkfolder(StorageProvider $storage, User $user, string $name, string $relPath, ?int $parentId): File
    {
        return File::create([
            'name' => $name,
            'path' => $relPath,
            'size' => 0,
            'mime_type' => 'folder',
            'storage_provider_id' => $storage->id,
            'owner_id' => $user->id,
            'parent_id' => $parentId,
            'is_folder' => true,
            'is_personal' => false,
        ]);
    }

    // ─── Caso A: subarbol 100% borrable, con contenido NO rastreado ────────
    h_section('A. subarbol borrable (con archivos sin fila BD) -> deleteRecursive NULL');

    $okDirAbs = "{$basePath}/{$tag}_ok";
    $okSubAbs = "{$okDirAbs}/sub";
    hmdf_mkfile("{$okSubAbs}/f.txt");
    // Archivo fisico NO rastreado en BD (simula contenido de cron root).
    hmdf_mkfile("{$okSubAbs}/untracked.pdf", 'pdf');
    @chmod($okDirAbs, 0777); @chmod($okSubAbs, 0777);

    $okFolder = hmdf_mkfolder($storage, $user, "{$tag}_ok", "{$tag}_ok", null);
    $subFolder = hmdf_mkfolder($storage, $user, 'sub', "{$tag}_ok/sub", $okFolder->id);
    $leaf = File::create([
        'name' => 'f.txt', 'path' => "{$tag}_ok/sub/f.txt", 'size' => 2,
        'mime_type' => 'text/plain', 'storage_provider_id' => $storage->id,
        'owner_id' => $user->id, 'parent_id' => $subFolder->id,
        'is_folder' => false, 'is_personal' => false,
    ]);

    $ret = $deleteRecM->invoke($controller, $okFolder->fresh());
    h_check($ret === null, 'deleteRecursive devuelve NULL', 'deleteRecursive devolvio ' . var_export($ret, true));
    h_check(!is_dir($okDirAbs), 'dir fisico borrado');
    h_check(!file_exists("{$okSubAbs}/untracked.pdf"), 'archivo NO rastreado borrado');
    h_check(File::where('id', $okFolder->id)->doesntExist(), 'fila carpeta raiz borrada');
    h_check(File::where('id', $subFolder->id)->doesntExist(), 'fila subcarpeta borrada');
    h_check(File::where('id', $leaf->id)->doesntExist(), 'fila archivo hoja borrada');

    // ─── Caso B: archivo individual (deleteFile) ───────────────────────────
    h_section('B. archivo individual -> deleteFile true + disco + BD');

    hmdf_mkfile("{$basePath}/{$tag}_solo.txt", 'hola');
    $solo = File::create([
        'name' => "{$tag}_solo.txt", 'path' => "{$tag}_solo.txt", 'size' => 4,
        'mime_type' => 'text/plain', 'storage_provider_id' => $storage->id,
        'owner_id' => $user->id, 'parent_id' => null,
        'is_folder' => false, 'is_personal' => false,
    ]);
    $retFile = $deleteFileM->invoke($controller, $solo->fresh());
    h_check($retFile === true, 'deleteFile devuelve true', 'deleteFile devolvio ' . var_export($retFile, true));
    h_check(!file_exists("{$basePath}/{$tag}_solo.txt"), 'archivo fisico borrado');
    h_check(File::where('id', $solo->id)->doesntExist(), 'fila archivo borrada');

    // ─── Caso C: contenido no escribible (solo como no-root) ───────────────
    h_section('C. contenido no escribible -> deleteRecursive devuelve path, BD conservada');

    if ($euid === 0) {
        echo "  [skip] corriendo como root: los bits de permiso no aplican. Repetir con `sudo -u www`.\n";
    } else {
        $lockDirAbs = "{$basePath}/{$tag}_locked";
        hmdf_mkfile("{$lockDirAbs}/inner/f.txt");
        // El dir `inner` queda sin permiso de escritura para www: el archivo
        // `f.txt` esta dentro y `inner` no es vacio ni escribible.
        @chmod("{$lockDirAbs}/inner", 0555);

        $lockFolder = hmdf_mkfolder($storage, $user, "{$tag}_locked", "{$tag}_locked", null);
        $innerFolder = hmdf_mkfolder($storage, $user, 'inner', "{$tag}_locked/inner", $lockFolder->id);
        File::create([
            'name' => 'f.txt', 'path' => "{$tag}_locked/inner/f.txt", 'size' => 2,
            'mime_type' => 'text/plain', 'storage_provider_id' => $storage->id,
            'owner_id' => $user->id, 'parent_id' => $innerFolder->id,
            'is_folder' => false, 'is_personal' => false,
        ]);

        // purgeDirectoryTree sobre un dir no escribible -> devuelve path.
        $purgeFail = $purgeM->invoke($controller, "{$lockDirAbs}/inner");
        h_check($purgeFail !== null, 'purgeDirectoryTree devuelve path en fallo',
            'purgeDirectoryTree devolvio NULL con inner 0555');

        $retC = $deleteRecM->invoke($controller, $lockFolder->fresh());
        h_check(is_string($retC) && $retC !== '', 'deleteRecursive devuelve path de fallo',
            'deleteRecursive devolvio ' . var_export($retC, true));
        h_check(File::where('id', $lockFolder->id)->exists(), 'fila carpeta raiz CONSERVADA');
        h_check(File::where('id', $innerFolder->id)->exists(), 'fila subcarpeta CONSERVADA');
        h_check(is_dir($lockDirAbs), 'dir fisico sigue presente');

        @chmod("{$lockDirAbs}/inner", 0777);
    }

    echo "\n=== RESUMEN ===  failures={$failures}\n";
    $exitCode = $failures === 0 ? 0 : 1;
} catch (\Throwable $e) {
    echo "  ✗ EXCEPCION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $exitCode = 2;
} finally {
    // Cleanup por tag.
    try {
        $storageIds = StorageProvider::where('name', 'like', "{$tag}%")->pluck('id')->all();
        if ($storageIds) {
            File::whereIn('storage_provider_id', $storageIds)->delete();
        }
        StorageProvider::where('name', 'like', "{$tag}%")->delete();
        User::where('username', 'like', "{$tag}%")->delete();
    } catch (\Throwable $e) {
        echo "  [cleanup] warning: " . $e->getMessage() . "\n";
    }
    if (is_dir($basePath)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            @chmod($f->getPathname(), 0777);
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @chmod($basePath, 0777);
        @rmdir($basePath);
    }
}

exit($exitCode ?? 1);
