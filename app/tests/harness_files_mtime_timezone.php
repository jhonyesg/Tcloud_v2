<?php
/**
 * Harness de regresion del change `fix-fechas-mis-archivos-shares`.
 *
 * Verifica contra PostgreSQL real que `files.file_modified_at` se escribe con
 * la hora correcta (mtime del disco en America/Bogota) y que el desfase +5 h
 * de la regresion `b07e434` no reaparece.
 *
 * Que verifica:
 *   1. `FilesystemTime::fromTimestamp()` devuelve el instante real en Bogota.
 *   2. `FilesystemTime::fromPath()` lee el mtime del disco sin desfase.
 *   3. Escritura end-to-end: un modelo `File` creado con
 *      `FilesystemTime::fromPath()` persiste el epoch correcto (round-trip
 *      DB -> epoch = mtime original).
 *   4. Control negativo: crear con `Carbon::createFromTimestamp($ts)` sin zona
 *      persiste +18000 s (documenta el bug y protege la regresion).
 *   5. `files:fix-modified-at-timezone --dry-run` reporta la fila desfasada y
 *      NO la modifica.
 *   6. `files:fix-modified-at-timezone --apply --yes` corrige esa fila al
 *      valor del mtime y deja una tabla snapshot; corre dos veces para probar
 *      idempotencia.
 *
 * Estrategia: storage de prueba con base_path a un dir temporal FISICO real,
 * un archivo real con `touch()` a un epoch fijo, filas `files` con tag unico.
 * Cleanup en `finally` (filas, storage, usuario, dir temporal, snapshot).
 *
 * Uso:
 *   cd app && php tests/harness_files_mtime_timezone.php
 *   exit 0 = OK, exit 1 = alguna asercion fallo
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\MountGuard;
use App\Models\User;
use App\Support\FilesystemTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function h_ok(string $msg): void  { echo "  [OK]  $msg\n"; }
function h_fail(string $msg): void { echo "  [FAIL] $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

$tag = 'hmftz_' . bin2hex(random_bytes(4));
$failures = 0;

/**
 * Epoch fijo: 2026-11-05 20:42:24 -05, el archivo real reportado por el
 * operador (city_05112026_202204.mp4).
 */
const MTIME = 1793929344;
const EXPECTED_LOCAL = '2026-11-05 20:42:24';

echo "Harness files-mtime-timezone (tag: {$tag})\n";

// ─── Helpers ────────────────────────────────────────────────────────────────
function epochOf(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->getTimestamp();
    }

    $ts = strtotime((string) $value);

    return $ts === false ? null : $ts;
}

function dropSnapshots(string $tag): int
{
    $dropped = 0;
    $rows = DB::select("
        SELECT tablename FROM pg_tables
        WHERE schemaname = current_schema()
          AND tablename LIKE 'files_mtime_pre_fix_%'
    ");

    foreach ($rows as $row) {
        // Solo dropear snapshots que contengan filas del tag del harness.
        $exists = DB::selectOne(
            "SELECT 1 FROM {$row->tablename} WHERE name LIKE ? LIMIT 1",
            [$tag . '%']
        );
        if ($exists) {
            DB::statement("DROP TABLE IF EXISTS {$row->tablename}");
            $dropped++;
        }
    }

    return $dropped;
}

$basePath = "/tmp/{$tag}";
@mkdir($basePath, 0755, true);
// Dos archivos fisicos distintos: `files` tiene UNIQUE(storage_provider_id, path),
// asi que good y bad necesitan paths propios. Los dos con el mismo mtime.
$physicalGood = "{$basePath}/{$tag}_good.mp4";
$physicalBad = "{$basePath}/{$tag}_bad.mp4";
file_put_contents($physicalGood, str_repeat('x', 2048));
file_put_contents($physicalBad, str_repeat('y', 2048));
touch($physicalGood, MTIME);
touch($physicalBad, MTIME);
clearstatcache(true, $physicalGood);
clearstatcache(true, $physicalBad);

try {
    // ─── 1/2. Helper ───────────────────────────────────────────────────────
    h_section('1/2. FilesystemTime (helper central)');

    $fromTs = FilesystemTime::fromTimestamp(MTIME);
    h_check($fromTs !== null && $fromTs->timestamp === MTIME,
        'fromTimestamp conserva el instante');
    h_check($fromTs !== null && $fromTs->format('Y-m-d H:i:s') === EXPECTED_LOCAL,
        'fromTimestamp formatea la hora Bogota real (' . EXPECTED_LOCAL . ')',
        'fromTimestamp formateo ' . ($fromTs?->format('Y-m-d H:i:s') ?? 'null'));

    $fromPath = FilesystemTime::fromPath($physicalGood);
    h_check($fromPath !== null && $fromPath->timestamp === MTIME,
        'fromPath lee el mtime del disco sin desfase',
        'fromPath devolvio ' . ($fromPath?->toIso8601String() ?? 'null'));

    // ─── 2b. Guard de mounts NFS por ANCESTRO ──────────────────────────────
    h_section('2b. guard NFS: detecta el mount que CONTIENE el base_path');

    // Los base_path de Mis Archivos son subdirectorios del mount (ej.
    // .../Disco_D/backup), asi que comparar solo el path exacto dejaria pasar
    // un `is_dir()` sobre un NFS colgado en modo hard (bloqueo indefinido).
    $guardProbe = new class extends MountGuard {
        public function __construct() {}
        public function mounts(): array
        {
            return [
                '/mnt/nfs_disk' => 'nfs4',
                '/mnt/nfs_disk/sub' => 'ext4',
                '/var/data' => 'ext4',
            ];
        }
    };

    $cmd = new \App\Console\Commands\FixFilesModifiedAtTimezoneCommand();
    $probe = new \ReflectionMethod($cmd, 'enclosingNetworkMount');
    $probe->setAccessible(true);

    h_check($probe->invoke($cmd, '/mnt/nfs_disk/backup/03_City_BK', $guardProbe) === '/mnt/nfs_disk',
        'detecta el mount NFS ancestro de un base_path anidado');
    h_check($probe->invoke($cmd, '/mnt/nfs_disk/sub/file', $guardProbe) === null,
        'NO marca red cuando el path cae en un mount local anidado');
    h_check($probe->invoke($cmd, '/var/data/files', $guardProbe) === null,
        'NO marca red para un mount local');

    // ─── 3. Bootstrap de fixtures ──────────────────────────────────────────
    h_section('3. bootstrap de fixtures');

    $user = User::create([
        'email' => "{$tag}@test.local",
        'username' => $tag,
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'role' => 'user',
    ]);

    $storage = StorageProvider::create([
        'name' => "{$tag}_storage",
        'kind' => 'local',
        'type' => 'local',
        'base_path' => $basePath,
        'enabled' => true,
    ]);

    $good = File::create([
        'name' => "{$tag}_good.mp4",
        'path' => "{$tag}_good.mp4",
        'size' => 2048,
        'mime_type' => 'video/mp4',
        'storage_provider_id' => $storage->id,
        'owner_id' => $user->id,
        'parent_id' => null,
        'is_folder' => false,
        'is_personal' => false,
        'file_modified_at' => FilesystemTime::fromPath($physicalGood),
    ]);

    $bad = File::create([
        'name' => "{$tag}_bad.mp4",
        'path' => "{$tag}_bad.mp4",
        'size' => 2048,
        'mime_type' => 'video/mp4',
        'storage_provider_id' => $storage->id,
        'owner_id' => $user->id,
        'parent_id' => null,
        'is_folder' => false,
        'is_personal' => false,
        // Control negativo DELIBERADO: reproduce el bug original.
        'file_modified_at' => CarbonImmutable::createFromTimestamp(MTIME),
    ]);

    h_check($good->id > 0 && $bad->id > 0, 'fixtures insertadas (good/bad)');

    // ─── 3. Round-trip DB del camino correcto ──────────────────────────────
    h_section('3. round-trip DB con FilesystemTime');

    $goodRaw = DB::table('files')->where('id', $good->id)->value('file_modified_at');
    $goodEpoch = epochOf($goodRaw);
    h_check($goodEpoch === MTIME,
        "file_modified_at persiste el epoch correcto ({$goodRaw})",
        "file_modified_at persistio {$goodRaw} (epoch " . var_export($goodEpoch, true) . ", esperado " . MTIME . ')');

    // ─── 4. Control negativo: el bug original sigue siendo detectable ──────
    h_section('4. control negativo (createFromTimestamp sin zona)');

    $badRaw = DB::table('files')->where('id', $bad->id)->value('file_modified_at');
    $badEpoch = epochOf($badRaw);
    h_check($badEpoch === MTIME + 18000,
        "sin zona se persiste +5h ({$badRaw})",
        "sin zona persistio {$badRaw} (epoch " . var_export($badEpoch, true) . ', esperado ' . (MTIME + 18000) . ')');

    // ─── 5. dry-run del comando ────────────────────────────────────────────
    h_section('5. files:fix-modified-at-timezone --dry-run');

    Artisan::call('files:fix-modified-at-timezone', [
        '--storage' => $storage->id,
        '--since' => '2000-01-01',
    ]);
    $dryOut = Artisan::output();
    h_check(str_contains($dryOut, 'DRY-RUN'), 'dry-run explicito en la salida');

    $badAfterDry = epochOf(DB::table('files')->where('id', $bad->id)->value('file_modified_at'));
    h_check($badAfterDry === MTIME + 18000, 'dry-run NO modifica la fila desfasada',
        'dry-run modifico la fila: ' . var_export($badAfterDry, true));

    // ─── 6. apply del comando ──────────────────────────────────────────────
    h_section('6. files:fix-modified-at-timezone --apply');

    Artisan::call('files:fix-modified-at-timezone', [
        '--storage' => $storage->id,
        '--since' => '2000-01-01',
        '--apply' => true,
        '--yes' => true,
    ]);
    $applyOut = Artisan::output();

    $badAfterApply = epochOf(DB::table('files')->where('id', $bad->id)->value('file_modified_at'));
    h_check($badAfterApply === MTIME,
        'apply corrige la fila al mtime real (epoch ' . MTIME . ')',
        'apply dejo epoch ' . var_export($badAfterApply, true));
    h_check((bool) preg_match('/files_mtime_pre_fix_\d{8}_\d{6}/', $applyOut),
        'apply reporta el snapshot creado',
        'apply no reporto snapshot: ' . trim(substr($applyOut, -200)));

    $goodAfterApply = epochOf(DB::table('files')->where('id', $good->id)->value('file_modified_at'));
    h_check($goodAfterApply === MTIME, 'apply no altera una fila ya correcta',
        'apply altero la fila buena: ' . var_export($goodAfterApply, true));

    // ─── 6b. idempotencia ──────────────────────────────────────────────────
    h_section('6b. idempotencia del comando');

    Artisan::call('files:fix-modified-at-timezone', [
        '--storage' => $storage->id,
        '--since' => '2000-01-01',
        '--apply' => true,
        '--yes' => true,
    ]);
    $secondOut = Artisan::output();
    $badSecond = epochOf(DB::table('files')->where('id', $bad->id)->value('file_modified_at'));

    h_check($badSecond === MTIME, 'segunda corrida no cambia el valor');
    h_check(str_contains($secondOut, 'Nada que corregir'),
        'segunda corrida reporta "Nada que corregir"',
        'segunda corrida no reporto idempotencia: ' . trim(substr($secondOut, -200)));

} finally {
    // ─── Cleanup ───────────────────────────────────────────────────────────
    h_section('cleanup');

    DB::table('files')->where('name', 'like', "{$tag}%")->delete();
    DB::table('storage_providers')->where('name', "{$tag}_storage")->delete();
    DB::table('users')->where('username', $tag)->delete();
    @unlink($physicalGood);
    @unlink($physicalBad);
    @rmdir($basePath);

    $dropped = dropSnapshots($tag);
    h_ok("cleanup completo (snapshots dropeados: {$dropped})");
}

echo "\n";
if ($failures === 0) {
    echo "RESULTADO: OK\n";
    exit(0);
}

echo "RESULTADO: {$failures} fallo(s)\n";
exit(1);
