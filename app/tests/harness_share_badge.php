<?php
/**
 * Harness de la feature `share-badge-azul`.
 *
 * Contrato: la columna Acciones de Mis Archivos diferencia a nivel visual
 * qué carpetas/archivos ya tienen enlace compartido generado. El servidor
 * anota cada entrada del listado con `has_share_link` y la UI pinta el
 * botón "Compartir" con fondo azul cuando el flag es true.
 *
 * Qué verifica:
 *   1. Listado BD-first anota `has_share_link` en todas las entradas.
 *   2. `POST /shares` (store) incrementa `folder_gen:{storage}:{parent}`
 *      para invalidar la cache `folder_listing:*`, y la siguiente carga
 *      muestra el flag en true.
 *   3. El cache hit conserva el flag y se refresca cuando folder_gen cambia
 *      (TTL de hasta 86400 s no congela el badge).
 *   4. `DELETE /shares/{id}` (destroy) revoca, bump-ea folder_gen y el flag
 *      vuelve a false.
 *   5. `POST /shares/bulk-delete` resuelve los pares (storage, parent) ANTES
 *      del delete (FK ON DELETE CASCADE) y bump-ea cada par afectado.
 *   6. El listado fs-first (`meta.fs_primary === true`) también anota el flag.
 *
 * Uso: cd app && php tests/harness_share_badge.php
 * Exit code: 0 = todas las aserciones pasan, 1 = alguna falla.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FileController;
use App\Http\Controllers\ShareController;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

$tag = 'hsb_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

function h_request(string $uri, string $method = 'GET', array $params = []): Request
{
    $request = Request::create($uri, $method, $params, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * @return array{status:int, body:array}
 */
function h_call(callable $fn): array
{
    try {
        $response = $fn();
    } catch (ValidationException $e) {
        return ['status' => 422, 'body' => $e->errors()];
    }

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true) ?? [],
    ];
}

/**
 * @param  array<string,mixed>  $listing
 * @return array<int,mixed>
 */
function h_files(array $listing): array
{
    return $listing['body']['files'] ?? [];
}

/**
 * @param  array<int,mixed>  $files
 */
function h_flag(array $files, int $fileId): mixed
{
    foreach ($files as $f) {
        if ((int) ($f['id'] ?? -1) === $fileId) {
            return $f['has_share_link'] ?? '__missing__';
        }
    }

    return '__not_in_listing__';
}

echo "Harness share-badge-azul (tag: {$tag})\n";

$admin = null;
$storageId = null;
$folderAId = null;
$folderBId = null;
$fsFolderId = null;
$shareIds = [];
$baseDir = null;
$settingsBackup = [];

try {
    h_section('Fixtures');

    $admin = User::create([
        'email' => $tag . '_admin@harness.local',
        'username' => $tag . '_admin',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('harness_dummy'),
        'role' => 'admin',
        'status' => 'active',
    ]);

    $baseDir = "/tmp/{$tag}";
    @mkdir($baseDir, 0777, true);

    $storageId = (int) DB::table('storage_providers')->insertGetId([
        'name' => $tag . '_storage',
        'kind' => 'local',
        'type' => 'local',
        'base_path' => $baseDir,
        'is_personal' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Carpeta A y B en el root de BD (sin presencia en disco: solo BD-first).
    $folderAId = (int) DB::table('files')->insertGetId([
        'name' => $tag . '_carpeta_a',
        'path' => $tag . '_carpeta_a',
        'storage_provider_id' => $storageId,
        'owner_id' => $admin->id,
        'parent_id' => null,
        'is_folder' => true,
    ]);
    $folderBId = (int) DB::table('files')->insertGetId([
        'name' => $tag . '_carpeta_b',
        'path' => $tag . '_carpeta_b',
        'storage_provider_id' => $storageId,
        'owner_id' => $admin->id,
        'parent_id' => null,
        'is_folder' => true,
    ]);

    // Carpeta fs-first: existe EN DISCO y tiene fila en BD (id resuelto por
    // FilesystemListingService::resolveFileId).
    @mkdir($baseDir . '/' . $tag . '_fs', 0777, true);
    $fsFolderId = (int) DB::table('files')->insertGetId([
        'name' => $tag . '_fs',
        'path' => $tag . '_fs',
        'storage_provider_id' => $storageId,
        'owner_id' => $admin->id,
        'parent_id' => null,
        'is_folder' => true,
    ]);

    h_ok("fixtures creadas: storage {$storageId}, folders {$folderAId}/{$folderBId}/{$fsFolderId}");

    Session::put('user_id', $admin->id);
    $fileController = app(FileController::class);
    $shareController = app(ShareController::class);

    $genKey = "folder_gen:{$storageId}:null";
    $indexUri = "/files?storage_id={$storageId}";
    $list = fn () => h_call(fn () => $fileController->index(h_request($indexUri, 'GET', ['storage_id' => $storageId])));

    // ------------------------------------------------------------------
    h_section('1) Listado BD-first anota has_share_link');
    // ------------------------------------------------------------------
    $l1 = $list();
    h_check($l1['status'] === 200, 'el listado responde 200', 'respondió ' . $l1['status']);
    h_check(h_flag(h_files($l1), $folderAId) === false, 'folder A sin share: has_share_link=false', 'folder A: ' . var_export(h_flag(h_files($l1), $folderAId), true));
    h_check(h_flag(h_files($l1), $folderBId) === false, 'folder B sin share: has_share_link=false', 'folder B: ' . var_export(h_flag(h_files($l1), $folderBId), true));

    // ------------------------------------------------------------------
    h_section('2) store() bump-ea folder_gen y el flag pasa a true');
    // ------------------------------------------------------------------
    $genBefore = (int) Cache::get($genKey, 0);
    $created = h_call(fn () => $shareController->store(h_request('/shares', 'POST', [
        'file_id' => $folderAId,
        'permissions' => 'read',
    ])));
    h_check($created['status'] === 201, 'POST /shares responde 201', 'respondió ' . $created['status']);
    $shareId = (int) ($created['body']['id'] ?? 0);
    h_check($shareId > 0, 'el share devolvió id', 'id: ' . var_export($created['body']['id'] ?? null, true));
    $shareIds[] = $shareId;

    $genAfter = (int) Cache::get($genKey, 0);
    h_check($genAfter > $genBefore, "folder_gen bump-eado ({$genBefore} → {$genAfter})", "folder_gen sin cambio ({$genBefore} → {$genAfter})");

    $l2 = $list();
    h_check(h_flag(h_files($l2), $folderAId) === true, 'folder A con share: has_share_link=true', 'folder A: ' . var_export(h_flag(h_files($l2), $folderAId), true));
    h_check(h_flag(h_files($l2), $folderBId) === false, 'folder B sigue en false', 'folder B: ' . var_export(h_flag(h_files($l2), $folderBId), true));

    // ------------------------------------------------------------------
    h_section('3) Cache hit conserva el flag (folder_gen es la invalidación)');
    // ------------------------------------------------------------------
    $l2b = $list();
    h_check(h_flag(h_files($l2b), $folderAId) === true, 'segunda carga (cache hit) sigue en true', 'folder A: ' . var_export(h_flag(h_files($l2b), $folderAId), true));
    h_check($l2b['body'] === $l2['body'], 'el payload cacheado es idéntico al computado');

    // ------------------------------------------------------------------
    h_section('4) destroy() revoca, bump-ea y apaga el badge');
    // ------------------------------------------------------------------
    $genBefore2 = (int) Cache::get($genKey, 0);
    $deleted = h_call(fn () => $shareController->destroy($shareId));
    h_check($deleted['status'] === 200, 'DELETE /shares/{id} responde 200', 'respondió ' . $deleted['status']);
    $shareIds = array_values(array_filter($shareIds, fn ($id) => $id !== $shareId));

    $genAfter2 = (int) Cache::get($genKey, 0);
    h_check($genAfter2 > $genBefore2, "folder_gen bump-eado al revocar ({$genBefore2} → {$genAfter2})", "folder_gen sin cambio ({$genBefore2} → {$genAfter2})");

    $l3 = $list();
    h_check(h_flag(h_files($l3), $folderAId) === false, 'folder A tras revocar: has_share_link=false', 'folder A: ' . var_export(h_flag(h_files($l3), $folderAId), true));

    // ------------------------------------------------------------------
    h_section('5) bulk-delete: pares resueltos antes del delete (FK CASCADE)');
    // ------------------------------------------------------------------
    $sA = h_call(fn () => $shareController->store(h_request('/shares', 'POST', ['file_id' => $folderAId, 'permissions' => 'read'])));
    $sB = h_call(fn () => $shareController->store(h_request('/shares', 'POST', ['file_id' => $folderBId, 'permissions' => 'read'])));
    $bulkIds = array_filter([(int) ($sA['body']['id'] ?? 0), (int) ($sB['body']['id'] ?? 0)], fn ($id) => $id > 0);
    $shareIds = array_merge($shareIds, $bulkIds);
    h_check(count($bulkIds) === 2, 'dos shares creados para la depuración', 'ids: ' . json_encode($bulkIds));

    $l4 = $list();
    h_check(h_flag(h_files($l4), $folderAId) === true && h_flag(h_files($l4), $folderBId) === true,
        'ambos folders en true antes de la depuración',
        'A=' . var_export(h_flag(h_files($l4), $folderAId), true) . ' B=' . var_export(h_flag(h_files($l4), $folderBId), true));

    $genBefore3 = (int) Cache::get($genKey, 0);
    $bulk = h_call(fn () => $shareController->bulkDelete(h_request('/shares/bulk-delete', 'POST', [
        'ids' => array_values($bulkIds),
        'confirm_count' => count($bulkIds),
    ])));
    h_check($bulk['status'] === 200, 'bulk-delete responde 200', 'respondió ' . $bulk['status'] . ' ' . json_encode($bulk['body']));
    h_check((int) ($bulk['body']['deleted_count'] ?? 0) === 2, 'deleted_count=2', 'deleted_count=' . var_export($bulk['body']['deleted_count'] ?? null, true));

    $genAfter3 = (int) Cache::get($genKey, 0);
    h_check($genAfter3 > $genBefore3, "folder_gen bump-eado por bulk-delete ({$genBefore3} → {$genAfter3})", "folder_gen sin cambio ({$genBefore3} → {$genAfter3})");

    $l5 = $list();
    h_check(h_flag(h_files($l5), $folderAId) === false && h_flag(h_files($l5), $folderBId) === false,
        'ambos folders en false tras la depuración',
        'A=' . var_export(h_flag(h_files($l5), $folderAId), true) . ' B=' . var_export(h_flag(h_files($l5), $folderBId), true));

    // ------------------------------------------------------------------
    h_section('6) Listado fs-first anota el flag');
    // ------------------------------------------------------------------
    $settingsBackup['mis_archivos.fs_primary_enabled'] = SystemSetting::get('mis_archivos.fs_primary_enabled');
    $settingsBackup['mis_archivos.fs_primary_canary_storage_ids'] = SystemSetting::get('mis_archivos.fs_primary_canary_storage_ids');
    SystemSetting::set('mis_archivos.fs_primary_enabled', '1');
    SystemSetting::set('mis_archivos.fs_primary_canary_storage_ids', json_encode([$storageId]));

    $fsShare = h_call(fn () => $shareController->store(h_request('/shares', 'POST', ['file_id' => $fsFolderId, 'permissions' => 'read'])));
    h_check($fsShare['status'] === 201, 'share creado para la carpeta fs', 'respondió ' . $fsShare['status']);
    $shareIds[] = (int) ($fsShare['body']['id'] ?? 0);

    $lf = h_call(fn () => $fileController->index(h_request($indexUri, 'GET', ['storage_id' => $storageId])));
    h_check(($lf['body']['meta']['fs_primary'] ?? null) === true, 'la respuesta viene por fs-first (meta.fs_primary=true)', 'meta: ' . json_encode($lf['body']['meta'] ?? null));
    h_check(h_flag(h_files($lf), $fsFolderId) === true, 'carpeta fs con share: has_share_link=true', 'flag: ' . var_export(h_flag(h_files($lf), $fsFolderId), true));

    $fsNoShare = h_flag(h_files($lf), $folderBId);
    h_check($fsNoShare === '__not_in_listing__' || $fsNoShare === false,
        'las carpetas solo-BD no aparecen en fs-first (o aparecen en false)',
        'flag folder B: ' . var_export($fsNoShare, true));
} catch (Throwable $e) {
    h_fail('EXCEPCION NO CONTROLADA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    h_section('Limpieza');

    foreach (array_filter($shareIds) as $id) {
        DB::table('shares')->where('id', $id)->delete();
    }
    DB::table('shares')->where('token', 'like', $tag . '%')->delete();

    if ($folderAId) {
        DB::table('files')->where('id', $folderAId)->delete();
    }
    if ($folderBId) {
        DB::table('files')->where('id', $folderBId)->delete();
    }
    if ($fsFolderId) {
        DB::table('files')->where('id', $fsFolderId)->delete();
    }
    if ($storageId) {
        DB::table('storage_providers')->where('id', $storageId)->delete();
    }
    if ($admin !== null) {
        $admin->delete();
    }
    if ($baseDir !== null) {
        exec('rm -rf ' . escapeshellarg($baseDir));
    }

    foreach ($settingsBackup as $key => $value) {
        if ($value === null) {
            DB::table('system_settings')->where('key', $key)->delete();
        } else {
            SystemSetting::set($key, $value);
        }
    }
    Cache::forget("folder_gen:{$storageId}:null");

    h_ok('fixtures eliminadas (shares, files, storage, user, /tmp y system_settings restaurados)');
}

echo "\n";

if ($failures === 0) {
    echo "OK: todas las aserciones pasan\n";
    exit(0);
}

echo "FALLA: {$failures} asercion(es)\n";
exit(1);
