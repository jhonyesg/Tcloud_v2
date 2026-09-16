<?php
/**
 * Harness para change `mis-archivos-transcript-viewer` (tasks 2.4).
 * Verifica los 3 casos del nuevo endpoint FileController::transcription:
 *   A) archivo sin transcripción done → 404
 *   B) archivo con transcripción done + user sin transcription_access → 404 opaco
 *   C) archivo con transcripción done + user con transcription_access → 200 + meta+segments
 *
 * Uso: php /tmp/kilo/harness_task_2_4.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Ia\MentionsSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

$failures = 0;
function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

$tag = 'task24_' . substr(bin2hex(random_bytes(3)), 0, 6);

// ─────────────────────────────────────────────────────────────────────────────
// SETUP — crear storage + usuario + archivo + transcripción done
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP [tag=$tag]");

$admin = User::where('role', 'admin')->first();
if (!$admin) {
    h_fail("no hay admin en BD, abort"); exit(2);
}

$user = User::where('id', '!=', $admin->id)->first();
if (!$user) {
    h_fail("no hay usuario no-admin para testear");
    exit(2);
}

// Buscar un storage con transcription_enabled
$storage = StorageProvider::where('transcription_enabled', true)->first();
if (!$storage) {
    h_fail("no hay storage con transcription_enabled=true en BD");
    exit(2);
}
h_ok("storage elegido: id={$storage->id}, name={$storage->name}");

// Crear un archivo en el storage
$fileRow = File::create([
    'name' => "test_{$tag}.mp4",
    'path' => "/test/{$tag}.mp4",
    'size' => 0,
    'mime_type' => 'video/mp4',
    'storage_provider_id' => $storage->id,
    'owner_id' => $admin->id,
    'parent_id' => null,
    'is_folder' => false,
]);
h_ok("file creado: id={$fileRow->id}, mime={$fileRow->mime_type}");

// Crear transcripción done para ese archivo
$transcription = Transcription::create([
    'file_id' => $fileRow->id,
    'original_name' => $fileRow->name,
    'state' => Transcription::STATE_DONE,
]);
h_ok("transcripción done creada: id={$transcription->id}");

// ─────────────────────────────────────────────────────────────────────────────
// CASE A — archivo sin transcripción done → 404
// ─────────────────────────────────────────────────────────────────────────────
h_section("CASE A: archivo sin transcripción done");

$otherFile = File::where('id', '!=', $fileRow->id)->first();
if (!$otherFile) {
    // crear uno sin transcripción
    $otherFile = File::create([
        'name' => "no_trans_{$tag}.mp4",
        'path' => "/test/no_trans_{$tag}.mp4",
        'size' => 0,
        'mime_type' => 'video/mp4',
        'storage_provider_id' => $storage->id,
        'owner_id' => $admin->id,
        'parent_id' => null,
        'is_folder' => false,
    ]);
    h_ok("archivo sin transcripción creado: id={$otherFile->id}");
}

Session::put('user_id', $user->id);
$ctrl = new FileController();
$res = $ctrl->transcription(new Request(), $otherFile, app(MentionsSearchService::class));
$status = $res->getStatusCode();
$status === 404
    ? h_ok("archivo sin transcripción → 404 (status={$status})")
    : h_fail("archivo sin transcripción → esperaba 404, recibí status={$status}");

// ─────────────────────────────────────────────────────────────────────────────
// CASE B — archivo con transcripción done + user SIN transcription_access → 404
// ─────────────────────────────────────────────────────────────────────────────
h_section("CASE B: archivo con transcripción + user SIN transcription_access");

DB::table('user_storages')->where('user_id', $user->id)
    ->where('storage_provider_id', $storage->id)
    ->update(['transcription_access' => false]);
h_ok("forzado transcription_access=false para user={$user->id}, storage={$storage->id}");

$res = $ctrl->transcription(new Request(), $fileRow, app(MentionsSearchService::class));
$status = $res->getStatusCode();
$body = json_decode($res->getContent(), true);
$status === 404
    ? h_ok("user sin access → 404 opaco (status={$status})")
    : h_fail("user sin access → esperaba 404, recibí status={$status}");

if (is_array($body) && isset($body['error'])) {
    h_ok("respuesta 404 contiene solo {\"error\":...} (no revela existencia)");
} else {
    h_fail("respuesta 404 debería tener solo clave 'error', recibí: " . json_encode($body));
}

// ─────────────────────────────────────────────────────────────────────────────
// CASE C — archivo con transcripción done + user CON transcription_access → 200
// ─────────────────────────────────────────────────────────────────────────────
h_section("CASE C: archivo con transcripción + user CON transcription_access");

DB::table('user_storages')->where('user_id', $user->id)
    ->where('storage_provider_id', $storage->id)
    ->update(['transcription_access' => true]);
h_ok("forzado transcription_access=true para user={$user->id}, storage={$storage->id}");

$res = $ctrl->transcription(new Request(), $fileRow, app(MentionsSearchService::class));
$status = $res->getStatusCode();
$body = json_decode($res->getContent(), true);

if ($status === 200 && is_array($body)) {
    h_ok("status=200 con cuerpo JSON");

    $requiredKeys = ['transcription', 'segments', 'first_index', 'last_index', 'total_segments'];
    $missing = array_diff($requiredKeys, array_keys($body));
    empty($missing)
        ? h_ok("shape completo: " . implode('+', $requiredKeys))
        : h_fail("shape incompleto, faltan: " . implode(',', $missing));

    if (isset($body['transcription']['id']) && $body['transcription']['id'] === $transcription->id) {
        h_ok("transcription.id coincide con el creado ({$transcription->id})");
    } else {
        h_fail("transcription.id esperado={$transcription->id}, recibí=" . ($body['transcription']['id'] ?? 'null'));
    }

    if (isset($body['transcription']['file_id']) && $body['transcription']['file_id'] === $fileRow->id) {
        h_ok("transcription.file_id coincide con el file ({$fileRow->id})");
    } else {
        h_fail("transcription.file_id esperado={$fileRow->id}, recibí=" . ($body['transcription']['file_id'] ?? 'null'));
    }
} else {
    h_fail("esperaba 200, recibí status={$status} body=" . json_encode($body));
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("CLEANUP");
DB::table('transcriptions')->where('file_id', $fileRow->id)->delete();
DB::table('transcriptions')->where('file_id', $otherFile->id)->delete();
DB::table('files')->where('id', $fileRow->id)->delete();
DB::table('files')->where('id', $otherFile->id)->delete();
Session::forget('user_id');
h_ok("archivos y transcripciones de prueba eliminados");

echo "\n=== RESULTADO ===\n";
if ($failures === 0) {
    echo "TODOS LOS CHECKS PASARON ✓\n";
    exit(0);
}
echo "{$failures} CHECKS FALLARON ✗\n";
exit(1);
