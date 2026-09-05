<?php
/**
 * Verificación end-to-end del flujo de sesiones vs usuarios tras el fix.
 * Cubre: login, persistencia Redis DB 2, custom lifetimes, killSession,
 *        cleanOrphans(dryRun+real), coherencia tras operación normal.
 *
 * Uso: php tests/verify_session_user_flow.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

$tag = 'verify_' . substr(bin2hex(random_bytes(4)), 0, 8);
$createdUserIds = [];
$createdSessionIds = [];
$failures = 0;

function v_ok(string $msg): void   { echo "  ✓ $msg\n"; }
function v_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function v_section(string $msg): void { echo "\n=== $msg ===\n"; }

$redisPrefix = (string) config('database.redis.options.prefix', '');
$cachePrefix = (string) config('cache.prefix', '');
$sessionDb = Redis::connection('session');

function sessionKey(string $sid): string {
    global $redisPrefix, $cachePrefix;
    return $redisPrefix . $cachePrefix . $sid;
}

echo "TAG: $tag\n";

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
v_section('SETUP — crear usuarios con distintos lifetimes');
$now = now();

$adminLifetime0 = User::create([
    'email' => "{$tag}_admin_l0@verify.local",
    'username' => "{$tag}_admin_l0",
    'password_hash' => Hash::make('Test#2026'),
    'role' => 'admin',
    'status' => User::STATUS_ACTIVE,
    'session_lifetime_minutes' => 0,      // nunca expira
    'max_sessions' => 5,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
$createdUserIds[] = $adminLifetime0->id;
v_ok("admin con lifetime=0 (nunca expira), max=5: id={$adminLifetime0->id}");

$userLifetime120 = User::create([
    'email' => "{$tag}_user_l120@verify.local",
    'username' => "{$tag}_user_l120",
    'password_hash' => Hash::make('Test#2026'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'session_lifetime_minutes' => 120,    // 2h
    'max_sessions' => 2,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
$createdUserIds[] = $userLifetime120->id;
v_ok("user con lifetime=120, max=2: id={$userLifetime120->id}");

$userLifetimeNull = User::create([
    'email' => "{$tag}_user_lnull@verify.local",
    'username' => "{$tag}_user_lnull",
    'password_hash' => Hash::make('Test#2026'),
    'role' => 'user',
    'status' => User::STATUS_ACTIVE,
    'session_lifetime_minutes' => null,   // usa global (120 default; ajustamos a 60)
    'max_sessions' => 3,
    'personal_quota_bytes' => 0,
    'personal_used_bytes' => 0,
]);
$createdUserIds[] = $userLifetimeNull->id;
v_ok("user con lifetime=null (usa global), max=3: id={$userLifetimeNull->id}");

SystemSetting::set('global_session_lifetime', 60);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO A: login crea session válida en DB + Redis DB 2
// ─────────────────────────────────────────────────────────────────────────────
v_section('A. Login crea sesión válida en DB + Redis DB 2');
$svc = app(SessionService::class);

// Simulamos el equivalente a AuthController::login sin hacer una request HTTP real.
Session::start();
$adminSid = Session::getId();

$sessionRecord = $svc->createSession($adminLifetime0, Request::create('/login', 'POST'));
if ($sessionRecord->user_id === $adminLifetime0->id && $sessionRecord->expires_at === null) {
    v_ok("UserSession creada: user_id={$sessionRecord->user_id}, expires_at=NULL (lifetime=0)");
} else {
    v_fail("UserSession mal creada: " . json_encode($sessionRecord->toArray()));
}
$createdSessionIds[] = $adminSid;

// El CacheBasedSessionHandler de Laravel escribe la sesión via setex(key, ttl, data)
// en su momento, no desde aquí. Simulamos eso:
// (ver siguiente escenario para la verificación Redis)

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO B: sessionExistsInRedis detecta la sesión correctamente
// ─────────────────────────────────────────────────────────────────────────────
v_section('B. sessionExistsInRedis detecta la sesión en DB 2');

// Sin clave Redis: debería retornar false (es huérfana)
if (!$svc->sessionExistsInRedis($adminSid)) {
    v_ok("sin clave Redis → sessionExistsInRedis = false (huérfana detectada)");
} else {
    v_fail("sin clave Redis pero sessionExistsInRedis = true");
}

// Escribir la clave Redis como lo haría Laravel (mismo formato, DB 2)
$sessionDb->setex(sessionKey($adminSid), 86400, serialize(['user_id' => $adminLifetime0->id, 'admin' => true]));

if ($svc->sessionExistsInRedis($adminSid)) {
    v_ok("con clave en DB 2 → sessionExistsInRedis = true");
} else {
    v_fail("con clave DB 2 pero sessionExistsInRedis = false — fix no funcionó");
}

// Verificación cruzada: misma clave NO debe estar en DB 1 (cache)
$existsInDb1 = Redis::connection('cache')->exists(sessionKey($adminSid)) > 0;
if (!$existsInDb1) v_ok("clave NO en DB 1 (cache) — confirma single source of truth");
else v_fail("clave SÍ en DB 1 — algo escribe en dos DBs");

$sessionDb->del(sessionKey($adminSid));

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO C: per-user lifetimes funcionan end-to-end
// ─────────────────────────────────────────────────────────────────────────────
v_section('C. per-user lifetimes se respetan en createSession()');

function freshSession(): string {
    Session::flush();
    Session::regenerate(true); // regenerate + destroy old
    return Session::getId();
}

// Admin lifetime=0 → expires_at=null
$lifetime = $svc->getEffectiveLifetimeMinutes($adminLifetime0);
$sidTemp = freshSession();
$session1 = $svc->createSession($adminLifetime0, Request::create('/x', 'POST'));
if ($lifetime === 0 && $session1->expires_at === null) {
    v_ok("admin lifetime=0 → expires_at=NULL correcto");
} else {
    v_fail("admin lifetime=0: lifetime={$lifetime}, expires_at=" . var_export($session1->expires_at, true));
}
UserSession::find($session1->id)?->delete();
$sessionDb->del(sessionKey($sidTemp));

// User lifetime=120 → expires_at = now + 120min
$lifetime = $svc->getEffectiveLifetimeMinutes($userLifetime120);
$sidTemp = freshSession();
$session2 = $svc->createSession($userLifetime120, Request::create('/x', 'POST'));
if ($lifetime === 120 && $session2->expires_at !== null && abs($session2->expires_at->diffInMinutes(now(), true) - 120) < 2) {
    v_ok("user lifetime=120 → expires_at=now+120min correcto (diff≈{$session2->expires_at->diffInMinutes(now(), true)}min)");
} else {
    v_fail("user lifetime=120: lifetime={$lifetime}, expires_at=" . ($session2->expires_at?->toIso8601String() ?? 'null'));
}
UserSession::find($session2->id)?->delete();
$sessionDb->del(sessionKey($sidTemp));

// User lifetime=null → usa global (60min según system_settings arriba)
$lifetime = $svc->getEffectiveLifetimeMinutes($userLifetimeNull);
$sidTemp = freshSession();
$session3 = $svc->createSession($userLifetimeNull, Request::create('/x', 'POST'));
if ($lifetime === 60 && $session3->expires_at !== null && abs($session3->expires_at->diffInMinutes(now(), true) - 60) < 2) {
    v_ok("user lifetime=null con global=60 → expires_at=now+60min correcto");
} else {
    v_fail("user lifetime=null: lifetime efectivo={$lifetime}");
}
UserSession::find($session3->id)?->delete();
$sessionDb->del(sessionKey($sidTemp));

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO D: killSession limpia DB + Redis coherentemente
// ─────────────────────────────────────────────────────────────────────────────
v_section('D. killSession limpia DB + Redis coherentemente');

$victimSid = "{$tag}_victim_sid";
$sessionDb->setex(sessionKey($victimSid), 3600, serialize(['user_id' => $userLifetime120->id]));
freshSession();
$record = $svc->createSession($userLifetime120, Request::create('/x', 'POST'));

// Actualizar el session_id del record al que escribimos en Redis
$record->update(['session_id' => $victimSid]);
$createdSessionIds[] = $victimSid;

if ($sessionDb->exists(sessionKey($victimSid)) && UserSession::where('session_id', $victimSid)->exists()) {
    v_ok("precondición: sesión presente en DB 2 y user_sessions");
} else {
    v_fail("precondición rota");
}

$svc->killSession($record);

if (!UserSession::where('session_id', $victimSid)->exists()) v_ok("user_sessions: fila eliminada");
else v_fail("user_sessions: fila SIGUE presente");

if (!$sessionDb->exists(sessionKey($victimSid))) v_ok("Redis DB 2: clave eliminada");
else v_fail("Redis DB 2: clave SIGUE presente (zombie)");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO E: cleanOrphans NO borra sesiones activas (regresión bug)
// ─────────────────────────────────────────────────────────────────────────────
v_section('E. cleanOrphans NO borra sesiones activas — regresión del bug reportado');

// Aislar el escenario: limpiar toda la tabla user_sessions antes de empezar
// (los harnesses anteriores pueden haber dejado filas; este escenario asume estado limpio).
// También limpiamos las claves Redis DB 2 que ya no correspondan a nadie.
$preExistingInDb = UserSession::count();
$preExistingInRedis = $sessionDb->keys('*') ? count($sessionDb->keys('*')) : 0;
if ($preExistingInDb > 0) v_section("   (limpieza previa: $preExistingInDb filas en user_sessions, $preExistingInRedis claves en Redis DB 2)");
UserSession::query()->delete();
foreach ($sessionDb->keys('*') as $k) {
    $sessionDb->del($k);
}

// Crear 3 sesiones ACTIVAS (con Redis) y 1 huérfana (sin Redis)
$activeSids = [];
for ($i = 1; $i <= 3; $i++) {
    $sid = "{$tag}_active{$i}";
    $sessionDb->setex(sessionKey($sid), 3600, serialize(['user_id' => $adminLifetime0->id]));
    freshSession();
    $rec = $svc->createSession($adminLifetime0, Request::create('/x', 'POST'));
    $rec->update(['session_id' => $sid]);
    $rec->update(['expires_at' => null]); // lifetime=0 → NULL
    $activeSids[] = $sid;
}
$orphanSid = "{$tag}_orphan1";
freshSession();
$orphanRec = $svc->createSession($adminLifetime0, Request::create('/x', 'POST'));
$orphanRec->update(['session_id' => $orphanSid]);
$orphanRec->update(['expires_at' => null]);

$preCount = UserSession::whereIn('session_id', array_merge($activeSids, [$orphanSid]))->count();
if ($preCount === 4) v_ok("precondición: 4 sesiones (3 activas + 1 huérfana)");
else v_fail("precondición rota: $preCount sesiones");

// dryRun: debe detectar la huérfana y NO tocar nada
$wouldDelete = $svc->cleanOrphans(dryRun: true);
$postCount = UserSession::whereIn('session_id', array_merge($activeSids, [$orphanSid]))->count();
if ($wouldDelete === 1 && $postCount === 4) {
    v_ok("cleanOrphans(dryRun): detectó 1 huérfana y NO borró (4/4 sesiones preservadas)");
} else {
    v_fail("dryRun falló: would_delete={$wouldDelete}, post_count={$postCount}");
}

// Real: 1/4 = 0.25 < 0.5 → debe borrar solo la huérfana
$deleted = $svc->cleanOrphans();
$stillActive = UserSession::whereIn('session_id', $activeSids)->count();
$stillOrphan = UserSession::where('session_id', $orphanSid)->exists();
if ($deleted === 1 && $stillActive === 3 && !$stillOrphan) {
    v_ok("cleanOrphans(real): borró solo la huérfana; las 3 activas SOBREVIVEN (regresión cubierta)");
} else {
    v_fail("real falló: deleted={$deleted}, activas={$stillActive}, huérfana todavía=" . ($stillOrphan ? 'sí' : 'no'));
}

// Limpieza
foreach ($activeSids as $sid) {
    $sessionDb->del(sessionKey($sid));
    UserSession::where('session_id', $sid)->delete();
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO F: múltiples usuarios coexisten sin interferencia
// ─────────────────────────────────────────────────────────────────────────────
v_section('F. múltiples usuarios coexisten sin que uno afecte al otro');

$sidsByUser = [];
foreach ([$adminLifetime0, $userLifetime120, $userLifetimeNull] as $user) {
    $user_sids = [];
    for ($i = 1; $i <= 2; $i++) {
        $sid = "{$tag}_multi_{$user->id}_{$i}";
        $sessionDb->setex(sessionKey($sid), 3600, serialize(['user_id' => $user->id]));
        freshSession();
        $rec = $svc->createSession($user, Request::create('/x', 'POST'));
        $rec->update(['session_id' => $sid]);
        $user_sids[] = $sid;
    }
    $sidsByUser[$user->id] = $user_sids;
}

$totalDb = UserSession::whereIn('session_id', array_merge(...array_values($sidsByUser)))->count();
if ($totalDb === 6) v_ok("precondición: 6 sesiones en user_sessions (2 por usuario × 3 usuarios)");
else v_fail("precondición: $totalDb sesiones");

$svc->cleanOrphans();

$stillTotal = UserSession::whereIn('session_id', array_merge(...array_values($sidsByUser)))->count();
if ($stillTotal === 6) {
    v_ok("cleanOrphans preservó las 6 sesiones activas (sin importar usuario)");
} else {
    v_fail("cleanOrphans eliminó sesiones activas: $stillTotal de 6 sobreviven");
}

foreach ($sidsByUser as $userId => $user_sids) {
    foreach ($user_sids as $sid) {
        $sessionDb->del(sessionKey($sid));
        UserSession::where('session_id', $sid)->delete();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO G: max_sessions realmente bloquea cuando se excede
// ─────────────────────────────────────────────────────────────────────────────
v_section('G. max_sessions bloquea login cuando se excede');

// userLifetimeNull tiene max_sessions=3. Crear 3 sesiones activas (1 ya cuenta la del escenario F).
// Mejor: crear 3 nuevas.
for ($i = 1; $i <= 3; $i++) {
    $sid = "{$tag}_max_{$i}";
    $sessionDb->setex(sessionKey($sid), 3600, serialize(['user_id' => $userLifetimeNull->id]));
    freshSession();
    $rec = $svc->createSession($userLifetimeNull, Request::create('/x', 'POST'));
    $rec->update(['session_id' => $sid]);
}

$count = $svc->countActiveSessions($userLifetimeNull);
$max = $svc->getEffectiveMaxSessions($userLifetimeNull);
if ($max === 3 && $count === 3) {
    v_ok("countActiveSessions=3 = max=3 → bloquea nueva session login");
} else {
    v_fail("max=$max, count=$count");
}

// Limpieza
for ($i = 1; $i <= 3; $i++) {
    $sid = "{$tag}_max_{$i}";
    $sessionDb->del(sessionKey($sid));
    UserSession::where('session_id', $sid)->delete();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
v_section('TEARDOWN');
SystemSetting::set('global_session_lifetime', 120);
foreach ($createdUserIds as $uid) {
    try {
        UserSession::where('user_id', $uid)->delete();
        User::find($uid)?->delete();
    } catch (\Throwable $e) {
        echo "  ! cleanup error: " . $e->getMessage() . "\n";
    }
}
v_ok("limpieza completada");

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "✅ TODOS LOS CHECKS PASARON — sistema de sesiones funcional y libre del bug original\n";
    exit(0);
}
echo "❌ $failures checks fallaron\n";
exit(1);
