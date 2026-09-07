<?php
/**
 * Harness de validación del change `2026-09-05-mis-avisos-mentions-viewer`.
 *
 * Ejecuta contra PostgreSQL real. Consultas ligeras y acotadas:
 *   1. Visor (MentionsSearchService):
 *      - visibleTranscription: visibilidad por transcription_access y 404-like.
 *      - pageVisibleSegments: ventana anclada + cursores after/before.
 *      - Capabilities por fila: can_view_file / can_clip según permisos,
 *        editor de medios y tipo de storage.
 *      - todayHits: filtros storage_ids (intersección), keyword_id, q corto.
 *      - hitRow: deep-link file_url con ?t=.
 *   2. Editor de medios (MediaClipController::canAccessFile):
 *      - owner / permiso read → permitido; sin pivote en el storage → denegado;
 *        admin → ok; archivo inexistente → denegado.
 *
 * Uso: php tests/harness_mis_avisos_viewer.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\MediaClipController;
use App\Services\Ia\MentionsSearchService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$tag = 'hmv_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $okMsg, string $failMsg = ''): void
{
    $cond ? h_ok($okMsg) : h_fail($failMsg ?: $okMsg);
}

echo "Harness mis-avisos-mentions-viewer (tag: {$tag})\n";

// ─── Datos temporales (borrados al final) ──────────────────────────────────
$adminId = (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);

$mkUser = function (string $suffix, bool $editor) use ($tag): int {
    return (int) DB::table('users')->insertGetId([
        'username' => "{$tag}_{$suffix}",
        'email' => "{$tag}_{$suffix}@test.local",
        'password_hash' => 'x', 'role' => 'user',
        'media_editor_enabled' => $editor,
        'created_at' => now(), 'updated_at' => now(),
    ]);
};
$userRead = $mkUser('A', true);      // read + access + editor ON
$userNoEditor = $mkUser('B', false); // read + access pero editor OFF
$userOther = $mkUser('C', true);     // solo pivote en el storage remoto

// Storage local temporal + storage s3 temporal (para capability can_clip).
$storageLocalId = (int) DB::table('storage_providers')->insertGetId([
    'name' => "{$tag}_local", 'type' => 'local', 'base_path' => "/tmp/{$tag}",
    'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
]);
$storageRemoteId = (int) DB::table('storage_providers')->insertGetId([
    'name' => "{$tag}_s3", 'type' => 's3', 'base_path' => "/tmp/{$tag}_s3",
    'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
]);

DB::table('user_storages')->insert([
    ['user_id' => $userRead, 'storage_provider_id' => $storageLocalId, 'permissions' => 'read', 'transcription_access' => true, 'assigned_at' => now()],
    ['user_id' => $userNoEditor, 'storage_provider_id' => $storageLocalId, 'permissions' => 'read', 'transcription_access' => true, 'assigned_at' => now()],
    ['user_id' => $userRead, 'storage_provider_id' => $storageRemoteId, 'permissions' => 'read', 'transcription_access' => true, 'assigned_at' => now()],
    ['user_id' => $userOther, 'storage_provider_id' => $storageRemoteId, 'permissions' => 'read', 'transcription_access' => true, 'assigned_at' => now()],
]);

// File + transcription + 40 segmentos ligeros (storage local).
$fileId = (int) DB::table('files')->insertGetId([
    'name' => "{$tag}_emision.mp4", 'path' => "{$tag}/emision.mp4", 'size' => 1000,
    'mime_type' => 'video/mp4', 'storage_provider_id' => $storageLocalId,
    'owner_id' => $userRead, 'is_folder' => false, 'created_at' => now(), 'updated_at' => now(),
]);
$transcriptionId = (int) DB::table('transcriptions')->insertGetId([
    'file_id' => $fileId, 'state' => 'done', 'duration_seconds' => 120,
    'created_at' => now(), 'updated_at' => now(),
]);
$segmentIds = [];
for ($i = 0; $i < 40; $i++) {
    $segmentIds[$i] = (int) DB::table('transcription_segments')->insertGetId([
        'transcription_id' => $transcriptionId, 'segment_index' => $i,
        'start_seconds' => $i * 3, 'end_seconds' => $i * 3 + 3,
        'text_raw' => "segmento {$i} del tag {$tag}", 'text' => "segmento {$i} del tag {$tag}",
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// Keyword + registro del user A + hit en el segmento 20 (local).
$keywordId = (int) DB::table('keywords')->insertGetId([
    'text' => "{$tag}clave", 'normalized' => "{$tag}clave", 'created_at' => now(), 'updated_at' => now(),
]);
DB::table('user_keyword')->insert(['user_id' => $userRead, 'keyword_id' => $keywordId, 'created_at' => now()]);
DB::table('user_keyword')->insert(['user_id' => $userNoEditor, 'keyword_id' => $keywordId, 'created_at' => now()]);
DB::table('segment_keyword_hits')->insert([
    'transcription_id' => $transcriptionId, 'segment_id' => $segmentIds[20],
    'keyword_id' => $keywordId, 'snippet' => "texto con {$tag}clave dentro",
    'matched_at' => now(),
]);
$hitLocalSegmentId = $segmentIds[20];

// Transcripción remota (s3) con hit, para can_clip=false por tipo storage.
$fileRemoteId = (int) DB::table('files')->insertGetId([
    'name' => "{$tag}_remoto.mp4", 'path' => "{$tag}/remoto.mp4", 'size' => 1000,
    'mime_type' => 'video/mp4', 'storage_provider_id' => $storageRemoteId,
    'owner_id' => $adminId, 'is_folder' => false, 'created_at' => now(), 'updated_at' => now(),
]);
$transcriptionRemoteId = (int) DB::table('transcriptions')->insertGetId([
    'file_id' => $fileRemoteId, 'state' => 'done', 'created_at' => now(), 'updated_at' => now(),
]);
$segRemoteId = (int) DB::table('transcription_segments')->insertGetId([
    'transcription_id' => $transcriptionRemoteId, 'segment_index' => 0,
    'start_seconds' => 0, 'end_seconds' => 3,
    'text_raw' => "remoto {$tag}", 'text' => "remoto {$tag}",
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('segment_keyword_hits')->insert([
    'transcription_id' => $transcriptionRemoteId, 'segment_id' => $segRemoteId,
    'keyword_id' => $keywordId, 'snippet' => "remoto {$tag} clip", 'matched_at' => now(),
]);

$service = new MentionsSearchService();
$userReadModel = User::findOrFail($userRead);
$userNoEditorModel = User::findOrFail($userNoEditor);
$userOtherModel = User::findOrFail($userOther);

try {
    // ─── 1. Visor ──────────────────────────────────────────────────────────
    h_section('visibleTranscription: visibilidad y capabilities');

    $meta = $service->visibleTranscription($userReadModel, $transcriptionId);
    h_check($meta !== null, 'Usuario con read+acceso ve la transcripción');
    h_check(($meta['can_view_file'] ?? false) === true, 'can_view_file=true con permiso read');
    h_check(($meta['can_clip'] ?? false) === true, 'can_clip=true (editor ON + storage local + read)');
    h_check(($meta['total_segments'] ?? 0) === 40, 'total_segments=40');
    h_check(($meta['file_id'] ?? 0) === $fileId, 'file_id correcto');

    $metaNoEditor = $service->visibleTranscription($userNoEditorModel, $transcriptionId);
    h_check($metaNoEditor !== null && ($metaNoEditor['can_view_file'] ?? false) === true, 'Sin editor: can_view_file sigue en true');
    h_check($metaNoEditor !== null && ($metaNoEditor['can_clip'] ?? true) === false, 'Sin editor: can_clip=false');

    h_check($service->visibleTranscription($userOtherModel, $transcriptionId) === null,
        'Usuario sin access en el storage → null (404 sin revelar existencia)', 'Usuario sin access NO recibió null');

    // ─── Ventana anclada + cursores ────────────────────────────────────────
    h_section('pageVisibleSegments: ventana anclada y cursores');

    $window = $service->pageVisibleSegments($userReadModel, $transcriptionId, $hitLocalSegmentId);
    h_check($window !== null, 'Ventana anclada disponible');
    $idxs = array_column($window['segments'] ?? [], 'segment_index');
    h_check(in_array(20, $idxs), 'La ventana contiene el segmento ancla (índice 20)');
    h_check(min($idxs) <= 0, 'Ventana recortada por el inicio (clamp)');
    h_check(count($idxs) === 40, 'Ventana limitada por config (40 segmentos < window 120)');

    // Páginas pequeñas vía limit para ejercitar cursores con 40 segmentos.
    $page1 = $service->pageVisibleSegments($userReadModel, $transcriptionId, null, null, null, 10);
    $last1 = $page1['last_index'];
    h_check($page1['first_index'] === 0 && $last1 === 9, 'Primera página limitada (0..9)');

    $page2 = $service->pageVisibleSegments($userReadModel, $transcriptionId, null, $last1, null, 10);
    h_check(!empty($page2['segments']) && $page2['segments'][0]['segment_index'] > $last1
        && $page2['segments'][0]['segment_index'] === 10,
        'Cursor after_index entrega la página siguiente ordenada asc');

    $prev = $service->pageVisibleSegments($userReadModel, $transcriptionId, null, null, 5, 10);
    h_check(!empty($prev['segments']) && end($prev['segments'])['segment_index'] < 5,
        'Cursor before_index entrega página anterior re-ordenada asc');

    h_check($service->pageVisibleSegments($userOtherModel, $transcriptionId, $hitLocalSegmentId) === null,
        'Ventana para usuario sin acceso → null');

    // ─── Feed con filtros + hitRow ─────────────────────────────────────────
    h_section('todayHits: filtros y capabilities en fila');

    $feed = $service->todayHits($userReadModel);
    h_check($feed->total() === 2, 'Feed del día contiene los 2 hits creados (local + remoto)');
    $row = collect($feed->items())->first(fn ($r) => $r['file_id'] === $fileId);
    h_check($row !== null && str_contains((string) $row['file_url'], "/files/{$fileId}/view?t=60"),
        'file_url deep-link a /view?t= (seg 60)');
    h_check($row !== null && ($row['can_view_file'] ?? false) === true && ($row['can_clip'] ?? false) === true,
        'Capabilities de fila correctas (read+editor+local)');
    h_check($row !== null && ($row['segment_id'] ?? null) === $hitLocalSegmentId, 'segment_id expuesto para anclar el modal');

    $remoteRow = collect($feed->items())->first(fn ($r) => $r['file_id'] === $fileRemoteId);
    h_check($remoteRow !== null && ($remoteRow['can_view_file'] ?? false) === true, 'Hit remoto: can_view_file=true con read');
    h_check($remoteRow !== null && ($remoteRow['can_clip'] ?? true) === false, 'Hit remoto: can_clip=false (storage no local)');

    $feedFiltered = $service->todayHits($userReadModel, ['storage_ids' => [999999]]);
    h_check($feedFiltered->total() === 0, 'storage_ids fuera del acceso → 0 resultados (intersección)');

    $feedKw = $service->todayHits($userReadModel, ['keyword_id' => $keywordId + 1000]);
    h_check($feedKw->total() === 0, 'keyword_id inexistente → 0 resultados');

    $feedShortQ = $service->todayHits($userReadModel, ['q' => 'ab']);
    h_check($feedShortQ->total() === 2, 'Término corto (< mínimo) se ignora sin filtrar');

    $feedQ = $service->todayHits($userReadModel, ['q' => $tag]);
    h_check($feedQ->total() === 2, 'Término válido filtra por snippet/segmento');

    $feedOtherUser = $service->todayHits($userNoEditorModel, ['q' => $tag]);
    h_check($feedOtherUser->total() === 1, 'Cada cliente solo ve hits de sus storages con acceso');

    // ─── 2. Editor de medios: acceso al archivo ────────────────────────────
    h_section('MediaClipController::canAccessFile (fix-along)');

    $controller = new MediaClipController();
    $method = (new \ReflectionClass($controller))->getMethod('canAccessFile');
    $method->setAccessible(true);

    $fileLocal = \App\Models\File::find($fileId);
    $fileRemote = \App\Models\File::find($fileRemoteId);

    h_check($method->invoke($controller, $userReadModel, $fileLocal) === true, 'Owner+read accede al archivo propio');
    h_check($method->invoke($controller, $userOtherModel, $fileLocal) === false, 'Sin pivote en el storage del archivo → denegado');
    h_check($method->invoke($controller, $userReadModel, $fileRemote) === true, 'Read en storage remoto → permitido (lectura)');
    h_check($method->invoke($controller, User::findOrFail($adminId), $fileLocal) === true, 'Admin siempre accede');
    h_check($method->invoke($controller, $userReadModel, null) === false, 'Archivo inexistente → denegado');

} finally {
    // ─── Limpieza por tag ──────────────────────────────────────────────────
    DB::table('segment_keyword_hits')->whereIn('transcription_id', [$transcriptionId, $transcriptionRemoteId])->delete();
    DB::table('transcription_segments')->whereIn('transcription_id', [$transcriptionId, $transcriptionRemoteId])->delete();
    DB::table('transcriptions')->whereIn('id', [$transcriptionId, $transcriptionRemoteId])->delete();
    DB::table('files')->whereIn('id', [$fileId, $fileRemoteId])->delete();
    DB::table('user_keyword')->where('keyword_id', $keywordId)->delete();
    DB::table('keywords')->where('id', $keywordId)->delete();
    DB::table('user_storages')->whereIn('storage_provider_id', [$storageLocalId, $storageRemoteId])->delete();
    DB::table('storage_providers')->whereIn('id', [$storageLocalId, $storageRemoteId])->delete();
    DB::table('users')->whereIn('id', [$userRead, $userNoEditor, $userOther])->delete();
    echo "\nLimpieza completada (tag {$tag}).\n";
}

echo $failures === 0
    ? "\nTODOS LOS CHECKS OK\n"
    : "\n{$failures} CHECK(S) FALLARON\n";
exit($failures === 0 ? 0 : 1);
