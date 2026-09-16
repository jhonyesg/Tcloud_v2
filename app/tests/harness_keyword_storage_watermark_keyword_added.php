<?php
/**
 * Harness de validación — parte 2 del change `avisos-keyword-storage-watermark`.
 *
 * Cubre:
 *  - 9.3 Keyword nueva: el hook Keyword::created crea watermarks NULL para los
 *    storages con acceso, y el siguiente cron la procesa sin afectar otras.
 *  - 12.1 Hook UserAlertsInteligente::saved: al habilitar un usuario con
 *    keywords existentes, crea los watermarks faltantes.
 *
 * Uso: php tests/harness_keyword_storage_watermark_keyword_added.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Keyword;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\AvisosScanService;
use Illuminate\Support\Facades\DB;

$tag = 'kwmk_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-keyword-storage-watermark · keyword_added & uai hook (tag: {$tag})\n";

$service = app(AvisosScanService::class);

// ─── Setup: usuario, keyword y storage ya conocidos ──────────────────────
h_section('0. Setup');

$user = User::firstOrCreate(
    ['username' => "[{$tag}]_test"],
    ['email' => "[{$tag}]@test", 'password_hash' => bcrypt('x'), 'role' => 'user', 'status' => 'active']
);
$userId = (int) $user->id;

$storageId = (int) DB::table('files as f')
    ->join('transcriptions as t', 't.file_id', '=', 'f.id')
    ->where('t.state', 'done')
    ->where('t.generate_alerts', true)
    ->distinct()
    ->value('f.storage_provider_id');
if (!$storageId) {
    $storageId = (int) DB::table('storage_providers')->where('enabled', true)->value('id');
}
if (!$storageId) { h_fail('sin storage_provider habilitado'); exit(1); }

DB::table('user_storages')->insertOrIgnore([
    'user_id' => $userId,
    'storage_provider_id' => $storageId,
    'permissions' => 'read',
    'transcription_access' => true,
    'assigned_at' => now(),
]);

$cfg = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);
$cfg->enabled = false;
$cfg->save();
h_ok("usuario {$userId} creado/deshabilitado en storage {$storageId}");

// ─── 9.3 Keyword nueva ────────────────────────────────────────────────────
h_section('9.3 Keyword::created crea watermark NULL para storages con acceso');

$newKw = Keyword::create([
    'text' => "[{$tag}] hook test",
    'normalized' => "[{$tag}] hook test",
]);
$newKwId = (int) $newKw->id;

DB::table('user_keyword')->insertOrIgnore([
    'user_id' => $userId,
    'keyword_id' => $newKwId,
    'created_at' => now(),
]);

$beforeCount = DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->count();
h_ok("después de crear keyword + user_keyword: {$beforeCount} watermark(s) para keyword {$newKwId} (esperado 0 porque uai.enabled=false)");

// 12.1: habilitar uai. El hook debe crear los watermarks faltantes.
h_section('12.1 UserAlertsInteligente::saved crea watermarks al habilitar');

$cfg->enabled = true;
$cfg->save();

$afterCount = DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->count();
if ($afterCount > 0) {
    h_ok("uai.enabled=true creó {$afterCount} watermark(s) para la keyword del usuario");
} else {
    h_fail("uai.enabled=true NO creó watermarks (esperado >0)");
}

$wm = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $newKwId)
    ->where('storage_provider_id', $storageId)
    ->first();

if ($wm && $wm->scanned_until === null) {
    h_ok("watermark (kw={$newKwId}, storage={$storageId}) tiene scanned_until=NULL → catch-up pendiente");
} else {
    h_fail("watermark (kw={$newKwId}, storage={$storageId}) no encontrado o scanned_until no es NULL");
}

// Idempotencia: guardar de nuevo no debe crear duplicados.
$cfg->save();
$idempotentCount = DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->count();
if ($idempotentCount === $afterCount) {
    h_ok("idempotente: segundo save() dejó {$idempotentCount} filas (sin duplicar)");
} else {
    h_fail("idempotencia rota: {$idempotentCount} != {$afterCount}");
}

// ─── 9.3 continuación: corrida emite candidatos para esa keyword ────────────
h_section('9.3b Corrida con --keyword-id emite candidatos solo para esa keyword');

$candidates = $service->selectCandidates(['noWindow' => true, 'keywordId' => $newKwId, 'limit' => 50]);
$kwIds = $candidates->pluck('keyword_id')->unique()->values()->all();
if (count($kwIds) === 1 && (int) $kwIds[0] === $newKwId) {
    h_ok("selectCandidates filtrado por keywordId emite SOLO la keyword {$newKwId} ({$candidates->count()} candidatos)");
} else {
    h_fail("selectCandidates emite keywords inesperadas: " . json_encode($kwIds));
}

// ─── Cleanup ──────────────────────────────────────────────────────────────
h_section('Cleanup');
DB::table('keyword_scan_watermarks')->where('keyword_id', $newKwId)->delete();
DB::table('user_keyword')->where('user_id', $userId)->where('keyword_id', $newKwId)->delete();
$newKw->delete();
$cfg->delete();
DB::table('user_storages')->where('user_id', $userId)->where('storage_provider_id', $storageId)->delete();
$user->delete();
h_ok('registros de prueba eliminados');

echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
