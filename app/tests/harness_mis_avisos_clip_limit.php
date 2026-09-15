<?php
/**
 * Harness de regresión para change `verify-mis-avisos-clip-counts-toward-editor-limit`.
 *
 * Verifica contra PostgreSQL real que los recortes generados desde Mis Avisos
 * (vía `MediaClipController::clip()`) consumen el cupo mensual del editor de
 * medios (`media_editor_clip_limit`), salvo previews, fallos y admin.
 *
 * Estrategia (decisiones de diseño 2 + 4):
 *   - Conteo: se inserta `MediaEditJob` directo (status='done' / 'failed') y
 *     se mide con `User::mediaEditorClipsThisMonth()`. NO se ejecuta la
 *     pipeline ffmpeg para crear jobs.
 *   - Guarda 403: se invoca `MediaClipController::clip($request, $id)` con un
 *     `Request` sintético. La guarda en `MediaClipController.php:126` fires
 *     ANTES de cualquier llamada ffmpeg, así que se exercise sin pipeline.
 *   - Preview: se invoca `clip()` con `preview=true`. El guard no debe
 *     disparar (verifica que `!$isPreview && $hasReachedClipLimit()` está
 *     correctamente cableado). El resto puede fallar 5xx en fixture fake;
 *     eso prueba que el guard NO bloqueó.
 *   - Admin / limit=0: se valida por la lógica de `User::hasReachedClipLimit()`.
 *
 * Cleanup: todo se crea con tag `hmcl_<8-hex>` y se borra en `finally` por
 * ese tag. Cleanup defensivo al inicio borra residuos de corridas previas.
 *
 * Uso:
 *   cd app && php tests/harness_mis_avisos_clip_limit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\MediaClipController;
use App\Models\MediaEditJob;
use App\Models\User;
use App\Models\StorageProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

$tag = 'hmcl_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness mis-avisos-clip-limit (tag: {$tag})\n";

// ─── Cleanup defensivo: borrar residuos hmcl_* de corridas previas ────────────
h_section("cleanup defensivo pre-run [tag={$tag}]");
$residuosUsers = DB::table('users')->where('username', 'like', 'hmcl_%')->delete();
$residuosStorages = DB::table('storage_providers')->where('name', 'like', 'hmcl_%')->delete();
$residuosFiles = DB::table('files')->where('name', 'like', 'hmcl_%')->delete();
$residuosJobs = DB::table('media_edit_jobs')->where('source_file_name', 'like', 'hmcl_%')->delete();
$totalResiduos = $residuosUsers + $residuosStorages + $residuosFiles + $residuosJobs;
h_ok("residuos borrados: users={$residuosUsers} storages={$residuosStorages} files={$residuosFiles} jobs={$residuosJobs} (total {$totalResiduos})");

// ─── Bootstrap: 1 cliente + 1 admin ──────────────────────────────────────────
h_section('bootstrap: cliente + admin');

$cliente = User::create([
    'email' => "{$tag}_cliente@test.local",
    'username' => "{$tag}_cliente",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'media_editor_enabled' => true,
    'media_editor_clip_limit' => 2,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);

$admin = User::create([
    'email' => "{$tag}_admin@test.local",
    'username' => "{$tag}_admin",
    'password_hash' => Hash::make('Secret#123'),
    'role' => 'admin',
    'status' => User::STATUS_ACTIVE,
    'media_editor_enabled' => true,
    'media_editor_clip_limit' => 1,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);

h_ok("cliente creado id={$cliente->id} limit=2");
h_ok("admin creado id={$admin->id} limit=1 (admin bypass activo)");

$verify = DB::table('users')
    ->whereIn('id', [$cliente->id, $admin->id])
    ->select('id', 'username', 'role', 'media_editor_clip_limit')
    ->get()
    ->toArray();
h_check(count($verify) === 2, 'SELECT users WHERE id IN (...) devuelve 2 filas');

// ─── Storage local temporal ──────────────────────────────────────────────────
h_section('storage local temporal');

$tmpBase = sys_get_temp_dir() . "/{$tag}";
if (!mkdir($tmpBase, 0755, true) && !is_dir($tmpBase)) {
    fwrite(STDERR, "no se pudo crear {$tmpBase}\n");
    exit(2);
}

$storage = StorageProvider::create([
    'name' => "{$tag}_local",
    'type' => 'local',
    'config' => [],
    'base_path' => $tmpBase,
    'enabled' => true,
    'is_accessible' => true,
    'last_checked_at' => now(),
    'transcription_enabled' => false,
    'folder_layout' => 'flat',
    'allow_parent_overlap' => false,
    'is_personal' => false,
    'kind' => 'local',
]);
h_ok("storage id={$storage->id} base_path={$tmpBase}");

DB::table('user_storages')->insert([
    'user_id' => $cliente->id,
    'storage_provider_id' => $storage->id,
    'permissions' => 'full',
    'can_create_shares' => true,
    'transcription_access' => true,
    'assigned_at' => now(),
]);
DB::table('user_storages')->insert([
    'user_id' => $admin->id,
    'storage_provider_id' => $storage->id,
    'permissions' => 'full',
    'can_create_shares' => true,
    'transcription_access' => true,
    'assigned_at' => now(),
]);
h_ok("user_storages: cliente→storage (full), admin→storage (full)");

// ─── Archivo real en el storage ──────────────────────────────────────────────
h_section('archivo real en el storage');

$filePath = "emision.mp4";
$absolutePath = "{$tmpBase}/{$filePath}";
file_put_contents($absolutePath, "fake mp4 fixture for {$tag}");

$file = DB::table('files')->insertGetId([
    'name' => "{$tag}_emision.mp4",
    'path' => $filePath,
    'size' => filesize($absolutePath),
    'mime_type' => 'video/mp4',
    'storage_provider_id' => $storage->id,
    'owner_id' => $cliente->id,
    'is_folder' => false,
    'availability_state' => 'available',
    'file_modified_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);
h_ok("file id={$file} path={$absolutePath} (size=" . filesize($absolutePath) . " bytes)");
h_check(file_exists($absolutePath), 'archivo físicamente presente en disco');

// ─── Transcripción + 3 segmentos ────────────────────────────────────────────
h_section('transcripción + segmentos');

$transcriptionId = DB::table('transcriptions')->insertGetId([
    'file_id' => $file,
    'state' => 'done',
    'duration_seconds' => 30,
    'created_at' => now(),
    'updated_at' => now(),
]);

$segmentIds = [];
for ($i = 0; $i < 3; $i++) {
    $segmentIds[$i] = DB::table('transcription_segments')->insertGetId([
        'transcription_id' => $transcriptionId,
        'segment_index' => $i,
        'start_seconds' => $i * 10,
        'end_seconds' => ($i + 1) * 10,
        'text_raw' => "segmento {$i} {$tag}",
        'text' => "segmento {$i} {$tag}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
$segCount = DB::table('transcription_segments')->where('transcription_id', $transcriptionId)->count();
h_ok("transcripción id={$transcriptionId} con segmentos [" . implode(',', $segmentIds) . "]");
h_check($segCount === 3, "SELECT count(*) FROM transcription_segments WHERE transcription_id={$transcriptionId} = 3");

// ─── Helpers para invocar clip() con session ─────────────────────────────────
$invokeClip = function (User $user, int $fileId, bool $isPreview, array $segments = [['start' => 0, 'end' => 5]]) use ($tag) {
    Session::put('user_id', $user->id);
    $request = Request::create(
        "/media/clip/{$fileId}",
        'POST',
        ['preview' => $isPreview ? 'true' : 'false', 'segments' => $segments]
    );
    $controller = new MediaClipController();
    return $controller->clip($request, $fileId);
};

// ─── ASERCIONES ──────────────────────────────────────────────────────────────
try {
    // 3.1 ─── Aserción (a): un clip confirmado desde Mis Avisos cuenta ───────
    h_section('Aserción (a): 1 clip = count=1');

    $countBefore = $cliente->mediaEditorClipsThisMonth();
    $hasBefore = $cliente->hasReachedClipLimit();

    $job1 = MediaEditJob::create([
        'user_id' => $cliente->id,
        'source_file_id' => $file,
        'source_file_name' => "{$tag}_emision.mp4",
        'segments_json' => [['start' => 0, 'end' => 5]],
        'output_filename' => "{$tag}_emision_corte.mp4",
        'status' => 'done',
    ]);
    $cliente->refresh();

    $countAfter = $cliente->mediaEditorClipsThisMonth();
    $hasAfter = $cliente->hasReachedClipLimit();

    h_check($countBefore === 0, "count pre=0 (sano)", "count pre={$countBefore} (esperaba 0)");
    h_check($countAfter === 1, "count post=1 tras insertar job done id={$job1->id}", "count post={$countAfter}");
    h_check($hasBefore === false, "hasReachedClipLimit pre=false");
    h_check($hasAfter === false, "hasReachedClipLimit post=false (limit=2, count=1)");

    // 3.2 ─── Aserción (b): preview no cuenta (guard no dispara) ─────────────
    h_section('Aserción (b): preview=true NO dispara el guard del 403');

    $countBeforeB = $cliente->mediaEditorClipsThisMonth();
    $response = $invokeClip($cliente, $file, true, [['start' => 0, 'end' => 5]]);
    $countAfterB = $cliente->mediaEditorClipsThisMonth();

    $status = $response->getStatusCode();
    $bodyExcerpt = substr($response->getContent(), 0, 200);

    h_check($status !== 403, "preview=true status={$status} (NO debe ser 403)", "preview=true status={$status} — ¡el guard del 403 disparó!");
    h_check($countBeforeB === $countAfterB, "count pre={$countBeforeB} == post={$countAfterB} (preview NO incrementa)");
    h_ok("body excerpt: " . str_replace("\n", ' ', $bodyExcerpt));

    // 3.3 ─── Aserción (c): 2° clip → count=2=limit, hasReached=true ────────
    h_section('Aserción (c): 2° clip → count=limit, hasReached=true');

    $job2 = MediaEditJob::create([
        'user_id' => $cliente->id,
        'source_file_id' => $file,
        'source_file_name' => "{$tag}_emision.mp4",
        'segments_json' => [['start' => 5, 'end' => 10]],
        'output_filename' => "{$tag}_emision_corte2.mp4",
        'status' => 'done',
    ]);
    $cliente->refresh();

    $countC = $cliente->mediaEditorClipsThisMonth();
    $hasC = $cliente->hasReachedClipLimit();

    h_check($countC === 2, "count=2 tras 2do job id={$job2->id}", "count={$countC}");
    h_check($hasC === true, "hasReachedClipLimit=true (count >= limit=2)");

    // 3.4 ─── Aserción (d): 3er intento → HTTP 403 con "Límite" ──────────────
    h_section('Aserción (d): 3er intento clip (no preview) → HTTP 403');

    $responseD = $invokeClip($cliente, $file, false, [['start' => 0, 'end' => 3]]);
    $statusD = $responseD->getStatusCode();
    $bodyD = $responseD->getContent();

    h_check($statusD === 403, "status=403 (guard del límite dispara)", "status={$statusD} (esperaba 403)");
    // response()->json() escapa 'í' como \u00ed en el body. Aceptamos ambas formas.
    h_check(
        str_contains($bodyD, 'Límite') || str_contains($bodyD, 'L\u00edmite'),
        'body contiene "Límite" (o su forma unicode-escaped)',
        'body NO contiene "Límite" ni "L\u00edmite"'
    );
    h_ok("body completo: {$bodyD}");

    // 4.1 ─── Caso admin: 3 jobs, admin bypass ───────────────────────────────
    h_section('Caso admin: limit=1 + 3 jobs done + admin bypass');

    $admin->refresh();
    $countAdminPre = $admin->mediaEditorClipsThisMonth();

    for ($i = 0; $i < 3; $i++) {
        MediaEditJob::create([
            'user_id' => $admin->id,
            'source_file_id' => $file,
            'source_file_name' => "{$tag}_emision.mp4",
            'segments_json' => [['start' => $i, 'end' => $i + 1]],
            'output_filename' => "{$tag}_admin_corte{$i}.mp4",
            'status' => 'done',
        ]);
    }
    $admin->refresh();

    $countAdminPost = $admin->mediaEditorClipsThisMonth();
    $hasAdmin = $admin->hasReachedClipLimit();

    h_check($countAdminPost === 3, "admin count=3 (3 jobs done insertados)", "admin count={$countAdminPost}");
    h_check($hasAdmin === false, "admin hasReachedClipLimit=false (admin bypass aunque count >= limit)");

    // 4.2 ─── Caso failed: status='failed' no cuenta ─────────────────────────
    h_section("Caso failed: status='failed' NO incrementa count");

    $countFailedPre = $cliente->mediaEditorClipsThisMonth();
    $jobFailed = MediaEditJob::create([
        'user_id' => $cliente->id,
        'source_file_id' => $file,
        'source_file_name' => "{$tag}_emision.mp4",
        'segments_json' => [['start' => 0, 'end' => 2]],
        'output_filename' => "{$tag}_emision_failed.mp4",
        'status' => 'failed',
        'error_message' => 'fake failure for harness',
    ]);
    $cliente->refresh();
    $countFailedPost = $cliente->mediaEditorClipsThisMonth();

    h_check($countFailedPre === $countFailedPost, "count pre={$countFailedPre} == post={$countFailedPost} (failed NO cuenta)");
    h_ok("job failed id={$jobFailed->id} insertado pero NO entró en el conteo");

    // 4.3 ─── Caso limit=0: ilimitado ────────────────────────────────────────
    h_section('Caso limit=0: ilimitado (hasReachedClipLimit=false)');

    $unlimitedUser = User::create([
        'email' => "{$tag}_unlimited@test.local",
        'username' => "{$tag}_unlimited",
        'password_hash' => Hash::make('Secret#123'),
        'role' => 'user',
        'status' => User::STATUS_ACTIVE,
        'media_editor_enabled' => true,
        'media_editor_clip_limit' => 0,
        'personal_quota_bytes' => 0,
        'personal_used_bytes' => 0,
    ]);

    for ($i = 0; $i < 5; $i++) {
        MediaEditJob::create([
            'user_id' => $unlimitedUser->id,
            'source_file_id' => $file,
            'source_file_name' => "{$tag}_emision.mp4",
            'segments_json' => [['start' => $i, 'end' => $i + 1]],
            'output_filename' => "{$tag}_unlimited_corte{$i}.mp4",
            'status' => 'done',
        ]);
    }
    $unlimitedUser->refresh();

    h_check((int) $unlimitedUser->media_editor_clip_limit === 0, "media_editor_clip_limit=0");
    h_check($unlimitedUser->hasReachedClipLimit() === false, "hasReachedClipLimit=false (ilimitado, aunque count=5)");
    h_check($unlimitedUser->mediaEditorClipsThisMonth() === 5, "mediaEditorClipsThisMonth=5 (sigue contando, solo que el guard no dispara)");

    // 4.4 ─── (opcional) Caso sin editor: 403 antes del cupo ─────────────────
    h_section('Caso sin editor: canUseMediaEditor=false → 403 ANTES del cupo');

    $noEditorUser = User::create([
        'email' => "{$tag}_noeditor@test.local",
        'username' => "{$tag}_noeditor",
        'password_hash' => Hash::make('Secret#123'),
        'role' => 'user',
        'status' => User::STATUS_ACTIVE,
        'media_editor_enabled' => false,
        'media_editor_clip_limit' => 5,
        'personal_quota_bytes' => 0,
        'personal_used_bytes' => 0,
    ]);
    DB::table('user_storages')->insert([
        'user_id' => $noEditorUser->id,
        'storage_provider_id' => $storage->id,
        'permissions' => 'read',
        'can_create_shares' => false,
        'transcription_access' => true,
        'assigned_at' => now(),
    ]);

    $responseNoEditor = $invokeClip($noEditorUser, $file, false, [['start' => 0, 'end' => 3]]);
    $statusNoEditor = $responseNoEditor->getStatusCode();
    $bodyNoEditor = $responseNoEditor->getContent();

    h_check($statusNoEditor === 403, "status=403 (canUseMediaEditor=false)", "status={$statusNoEditor}");
    h_check(str_contains($bodyNoEditor, 'Editor de medios no habilitado') || str_contains($bodyNoEditor, 'Editor'),
        'body menciona "Editor de medios no habilitado"', "body: {$bodyNoEditor}");

} finally {
    // ─── Cleanup en orden inverso ───────────────────────────────────────────
    h_section('cleanup [finally]');

    $preUsers = DB::table('users')->where('username', 'like', 'hmcl_%')->count();
    $preStorages = DB::table('storage_providers')->where('name', 'like', 'hmcl_%')->count();
    $preFiles = DB::table('files')->where('name', 'like', 'hmcl_%')->count();
    $preJobs = DB::table('media_edit_jobs')->where('source_file_name', 'like', 'hmcl_%')->count();
    $preSegs = DB::table('transcription_segments')->where('text', 'like', 'hmcl_%')->count();
    $preTrans = DB::table('transcriptions')->whereIn('id', function ($q) {
        $q->select('transcription_id')->from('transcription_segments')->where('text', 'like', 'hmcl_%');
    })->count();
    h_ok("pre-cleanup: users={$preUsers} storages={$preStorages} files={$preFiles} jobs={$preJobs} segs={$preSegs} trans={$preTrans}");

    DB::table('media_edit_jobs')->where('source_file_name', 'like', 'hmcl_%')->delete();
    DB::table('user_storages')->whereIn('user_id', function ($q) {
        $q->select('id')->from('users')->where('username', 'like', 'hmcl_%');
    })->delete();
    DB::table('transcription_segments')->where('text', 'like', 'hmcl_%')->delete();
    DB::table('transcriptions')->whereIn('id', function ($q) {
        $q->select('transcription_id')->from('transcription_segments')->where('text', 'like', 'hmcl_%');
    })->delete();
    DB::table('files')->where('name', 'like', 'hmcl_%')->delete();
    DB::table('storage_providers')->where('name', 'like', 'hmcl_%')->delete();
    DB::table('users')->where('username', 'like', 'hmcl_%')->delete();

    @unlink($absolutePath);
    @rmdir($tmpBase);

    $postUsers = DB::table('users')->where('username', 'like', 'hmcl_%')->count();
    $postStorages = DB::table('storage_providers')->where('name', 'like', 'hmcl_%')->count();
    $postFiles = DB::table('files')->where('name', 'like', 'hmcl_%')->count();
    $postJobs = DB::table('media_edit_jobs')->where('source_file_name', 'like', 'hmcl_%')->count();
    $postSegs = DB::table('transcription_segments')->where('text', 'like', 'hmcl_%')->count();
    $postTrans = DB::table('transcriptions')->whereIn('id', function ($q) {
        $q->select('transcription_id')->from('transcription_segments')->where('text', 'like', 'hmcl_%');
    })->count();
    h_check($postUsers === 0, "post users=0", "post users={$postUsers}");
    h_check($postStorages === 0, "post storages=0", "post storages={$postStorages}");
    h_check($postFiles === 0, "post files=0", "post files={$postFiles}");
    h_check($postJobs === 0, "post jobs=0", "post jobs={$postJobs}");
    h_check($postSegs === 0, "post segs=0", "post segs={$postSegs}");
    h_check($postTrans === 0, "post trans=0", "post trans={$postTrans}");
    h_check(!file_exists($absolutePath), "archivo físico borrado");
    h_check(!is_dir($tmpBase), "directorio temporal borrado");
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "OK: MediaClipController::clip() respeta media_editor_clip_limit\n";
    echo "(Mis Avisos → clip cuenta; preview/failed/admin/limit=0 NO)\n";
    exit(0);
} else {
    echo "FAIL: {$failures} check(s) fallaron\n";
    exit(1);
}
