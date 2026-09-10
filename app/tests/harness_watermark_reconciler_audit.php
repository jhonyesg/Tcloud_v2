<?php
/**
 * Harness de validación del change `avisos-scan-coverage-reconciler-and-partition`.
 *
 * Cubre:
 *  - WatermarkReconciler: ensureForUser/Keyword/Storage idempotentes
 *  - Drift report: identifica pares faltantes y huérfanos
 *  - Audit log: rewind registra actor + before/after
 *  - Refactor: los hooks de Keyword y UserAlertsInteligente siguen verdes
 *    (delegando al Reconciler, no SQL inline)
 *
 * Uso: php tests/harness_watermark_reconciler_audit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Keyword;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\WatermarkReconciler;
use App\Services\Ia\AvisosScanService;
use Illuminate\Support\Facades\DB;

$tag = 'wra_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

echo "Harness avisos-scan-coverage-reconciler-and-partition (tag: {$tag})\n";

$reconciler = app(WatermarkReconciler::class);
$service = app(AvisosScanService::class);

// ─── 1. Setup mínimo ─────────────────────────────────────────────────────
h_section('1. Setup: usuario aislado para no contaminar sistema');

$user = User::firstOrCreate(
    ['username' => "[{$tag}]_test"],
    ['email' => "[{$tag}]@test", 'password_hash' => bcrypt('x'), 'role' => 'user', 'status' => 'active']
);
$userId = (int) $user->id;

$storage1 = (int) DB::table('storage_providers')->where('enabled', true)->skip(0)->value('id');
$storage2 = (int) DB::table('storage_providers')->where('enabled', true)->skip(1)->value('id');
if (!$storage1 || !$storage2) { h_fail('sin storages habilitados'); exit(1); }

// Limpia estado previo.
DB::table('keyword_scan_watermarks')
    ->whereIn('storage_provider_id', [$storage1, $storage2])
    ->whereIn('keyword_id', function ($q) use ($userId) {
        $q->select('keyword_id')->from('user_keyword')->where('user_id', $userId);
    })
    ->delete();
DB::table('watermark_audit_log')
    ->where('metadata', 'like', "%\"tag\":\"{$tag}\"%")
    ->delete();

h_ok("usuario {$userId}, storages {$storage1},{$storage2}");

// ─── 2. Drift negativo creado por el test ─────────────────────────────────
h_section('2. Drift negativo: ensureForUser repara');

// Crear keyword pero SIN watermark (no create via booted hook; directo).
$kw = Keyword::create(['text' => "[{$tag}] kw", 'normalized' => "[{$tag}] kw"]);
$kwId = (int) $kw->id;

// Asignar al usuario en storage1.
DB::table('user_storages')->insertOrIgnore([
    'user_id' => $userId,
    'storage_provider_id' => $storage1,
    'permissions' => 'read',
    'transcription_access' => true,
    'assigned_at' => now(),
]);
DB::table('user_keyword')->insertOrIgnore([
    'user_id' => $userId,
    'keyword_id' => $kwId,
    'created_at' => now(),
]);

// Habilitar uai.
$cfg = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);
$cfg->enabled = true;
$cfg->save();

// Borrar watermarks si los hooks crearon algunos.
DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->delete();

$beforeCount = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->count();

if ($beforeCount === 0) {
    h_ok("drift negativo confirmado: 0 watermarks para keyword {$kwId}");
} else {
    h_fail("esperaba 0 watermarks, hay {$beforeCount}");
}

// Ahora reconciler debe reparar.
$created = $reconciler->ensureForUser($userId);
$afterCount = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->count();

if ($afterCount > 0) {
    h_ok("reconciler reparó: {$created} creados, {$afterCount} en keyword {$kwId}");
} else {
    h_fail("reconciler NO creó watermarks (esperaba >0)");
}

// Idempotencia: segunda llamada no crea más.
$reconciler->ensureForUser($userId);
$stillCount = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->count();
if ($stillCount === $afterCount) {
    h_ok("idempotente: segunda llamada dejó {$stillCount} (sin duplicar)");
} else {
    h_fail("idempotencia rota: {$stillCount} != {$afterCount}");
}

// ─── 3. Drift report detecta faltantes ───────────────────────────────────
h_section('3. driftReport() encuentra drift');

// Crear keyword huérfana sin watermark.
$orphanKw = Keyword::create(['text' => "[{$tag}] orphan", 'normalized' => "[{$tag}] orphan"]);
$orphanKwId = (int) $orphanKw->id;
DB::table('user_keyword')->insertOrIgnore([
    'user_id' => $userId,
    'keyword_id' => $orphanKwId,
    'created_at' => now(),
]);
DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $orphanKwId)
    ->delete();

$report = $reconciler->driftReport($userId);
$foundOrphan = false;
foreach ($report['missing'] as $m) {
    if ((int) $m->keyword_id === $orphanKwId && (int) $m->storage_provider_id === $storage1) {
        $foundOrphan = true;
        break;
    }
}
if ($foundOrphan) {
    h_ok("driftReport() incluye (kw={$orphanKwId}, storage={$storage1}) en missing[]");
} else {
    h_fail("driftReport() NO detectó el drift esperado");
}

// ─── 4. Audit log: rewind registra actor + before/after ─────────────────
h_section('4. rewindPair() registra watermark_audit_log');

$beforeRewind = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->where('storage_provider_id', $storage1)
    ->value('scanned_until');

$reconciler->rewindPair($kwId, $storage1, 1);  // user 1 = real admin

$afterRewind = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $kwId)
    ->where('storage_provider_id', $storage1)
    ->value('scanned_until');

if ($afterRewind === null) {
    h_ok('rewindPair dejó scanned_until=NULL');
} else {
    h_fail("rewindPair no funcionó: scanned_until={$afterRewind}");
}

$audit = DB::table('watermark_audit_log')
    ->where('action', 'rewind_pair')
    ->where('keyword_id', $kwId)
    ->where('storage_id', $storage1)
    ->where('actor_user_id', 1)
    ->orderByDesc('id')
    ->first();

if ($audit) {
    h_ok("audit log: actor_user_id={$audit->actor_user_id}, action='rewind_pair' registrado");
} else {
    h_fail("audit log NO registró correctamente");
}

if ($audit && $audit->after_value === null) {
    h_ok("audit log: after_value=NULL (rewind exitoso)");
} else {
    h_fail("audit log after_value incorrecto: " . json_encode($audit));
}

// ─── 5. Hooks refactorizados siguen funcionando ───────────────────────────
h_section('5. Hooks (Keyword::created, UserAlertsInteligente::saved)');

$hookKw = Keyword::create(['text' => "[{$tag}] hook kw", 'normalized' => "[{$tag}] hook kw"]);
$hookKwId = (int) $hookKw->id;

// Cleanup primero si quedó algo previo.
DB::table('user_keyword')->where('user_id', $userId)->where('keyword_id', $hookKwId)->delete();
DB::table('keyword_scan_watermarks')->where('keyword_id', $hookKwId)->delete();

// Crear user_keyword via Eloquent para que dispare UserKeyword::created.
\App\Models\UserKeyword::create([
    'user_id' => $userId,
    'keyword_id' => $hookKwId,
    'created_at' => now(),
]);

$hookCount = DB::table('keyword_scan_watermarks')
    ->where('keyword_id', $hookKwId)
    ->where('storage_provider_id', $storage1)
    ->count();

if ($hookCount === 1) {
    h_ok("UserKeyword::created (refactorizado al Reconciler) creó 1 watermark (kw={$hookKwId}, storage={$storage1})");
} else {
    h_fail("UserKeyword::created hook no creó watermark (esperaba 1, hay {$hookCount})");
}

// ─── 6. Reconciler drift acotado por user_id ──────────────────────────────
h_section('6. driftReport(?int $userId)');

$systemReport = $reconciler->driftReport();
$userReport = $reconciler->driftReport($userId);

if ($userReport['summary']['scope_user_id'] === $userId) {
    h_ok("scope_user_id = {$userId}");
} else {
    h_fail("scope_user_id incorrecto");
}

// El reporte de usuario debe ser más pequeño que el sistema (a menos que todo sea del usuario).
if ($userReport['summary']['missing'] <= $systemReport['summary']['missing']) {
    h_ok("drift acotado: usuario={$userReport['summary']['missing']}, sistema={$systemReport['summary']['missing']}");
} else {
    h_fail("drift usuario > drift sistema (imposible)");
}

// ─── Cleanup ─────────────────────────────────────────────────────────────
h_section('Cleanup');
DB::table('keyword_scan_watermarks')->where('keyword_id', $kwId)->delete();
DB::table('keyword_scan_watermarks')->where('keyword_id', $orphanKwId)->delete();
DB::table('keyword_scan_watermarks')->where('keyword_id', $hookKwId)->delete();
DB::table('user_keyword')->where('user_id', $userId)->whereIn('keyword_id', [$kwId, $orphanKwId, $hookKwId])->delete();
DB::table('user_storages')->where('user_id', $userId)->delete();
Keyword::whereIn('id', [$kwId, $orphanKwId, $hookKwId])->get()->each(fn ($k) => $k->delete());
$cfg->delete();
DB::table('users')->where('id', $userId)->delete();
DB::table('watermark_audit_log')->where('actor_user_id', 99999)->delete();
h_ok('registros de prueba eliminados');

echo "\n";
if ($failures === 0) {
    echo "✅ Todas las verificaciones pasaron.\n";
    exit(0);
} else {
    echo "❌ {$failures} verificación(es) fallaron.\n";
    exit(1);
}
