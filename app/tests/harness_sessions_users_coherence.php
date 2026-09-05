<?php
/**
 * Harness de validación integral del change `normalize-sessions-users-coherence`.
 * Ejecuta los 4 escenarios reales contra BD PostgreSQL y Redis de la app.
 *
 * Uso: php tests/harness_sessions_users_coherence.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tag = 'harness_' . substr(bin2hex(random_bytes(4)), 0, 8);
$createdUserIds = [];
$createdSessionIds = [];
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

// Simular una sesión "loggeada" — escribe en la MISMA conexión Redis donde
// Laravel persiste sesiones (config/database.php: redis.session, DB 2), con el
// formato de clave {redis_prefix}{cache_prefix}{session_id} que construye
// CacheBasedSessionHandler::write. Cache::put NO sirve porque va a la
// conexión cache (DB 1).
function simulateLogin(string $sid): void {
    $redisPrefix = (string) config('database.redis.options.prefix', '');
    $cachePrefix = (string) config('cache.prefix', '');
    $fullKey = $redisPrefix . $cachePrefix . $sid;
    \Illuminate\Support\Facades\Redis::connection('session')
        ->setex($fullKey, 3600, serialize(['harness' => true]));
    Cache::put("session_valid:{$sid}", '1', 30);
}
function assertSessionGone(string $sid): bool {
    $redisPrefix = (string) config('database.redis.options.prefix', '');
    $cachePrefix = (string) config('cache.prefix', '');
    $fullKey = $redisPrefix . $cachePrefix . $sid;
    $stillInSessionStore = \Illuminate\Support\Facades\Redis::connection('session')->exists($fullKey) > 0;
    return !$stillInSessionStore && !Cache::has("session_valid:{$sid}");
}

header_section:
h_section("Harness para change normalize-sessions-users-coherence [tag=$tag]");

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP");
$users = [];
for ($i = 1; $i <= 4; $i++) {
    $email = "{$tag}_u{$i}@harness.local";
    $u = User::create([
        'email' => $email,
        'username' => "{$tag}_u{$i}",
        'password_hash' => Hash::make('Secret#123'),
        'role' => 'user',
        'status' => User::STATUS_ACTIVE,
        'personal_quota_bytes' => 0,
        'personal_used_bytes' => 0,
    ]);
    $users[$i] = $u;
    $createdUserIds[] = $u->id;
    h_ok("creado user #$i (id={$u->id}, email=$email)");
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: Hallazgo 1 — User::sessions() HasMany funciona
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 1 — User::sessions() relation");
$u1 = $users[1];
$sid1 = "{$tag}_sid1";
UserSession::create([
    'user_id' => $u1->id,
    'session_id' => $sid1,
    'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0',
    'created_at' => now(),
    'last_activity_at' => now(),
    'expires_at' => now()->addHour(),
]);
$createdSessionIds[] = $sid1;

$viaRelation = $u1->sessions()->where('session_id', $sid1)->first();
$viaDirect   = UserSession::where('user_id', $u1->id)->where('session_id', $sid1)->first();

if ($viaRelation && $viaDirect && $viaRelation->id === $viaDirect->id && $viaRelation->session_id === $sid1) {
    h_ok("User::sessions() devuelve la fila esperada (id={$viaRelation->id})");
} else {
    h_fail("User::sessions() NO devuelve la fila esperada");
}

if ($viaRelation->user->id === $u1->id) {
    h_ok("UserSession::user() (inverse) apunta al User correcto");
} else {
    h_fail("UserSession::user() no resuelve correctamente");
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: killAllUserSessions limpia DB + Redis
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 2 — killAllUserSessions limpia DB + Redis");
$u2 = $users[2];
$sid2 = "{$tag}_sid2";
UserSession::create([
    'user_id' => $u2->id,
    'session_id' => $sid2,
    'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0',
    'created_at' => now(),
    'last_activity_at' => now(),
    'expires_at' => now()->addHour(),
]);
$createdSessionIds[] = $sid2;

simulateLogin($sid2);
if (assertSessionGone($sid2) === false) {
    h_ok("Redis session (DB 2) + cache valid presentes antes de evict");
} else {
    h_fail("Redis session NO se seteó (precondición rota)");
}

$svc = app(SessionService::class);
$killed = $svc->killAllUserSessions($u2);

if ($killed === 1) h_ok("killAllUserSessions devolvió 1");
else h_fail("killAllUserSessions devolvió $killed (esperado 1)");

$stillInDb = UserSession::where('user_id', $u2->id)->where('session_id', $sid2)->exists();
if (!$stillInDb) h_ok("fila en user_sessions eliminada");
else h_fail("fila en user_sessions SIGUE presente");

if (assertSessionGone($sid2)) {
    h_ok("clave Redis de sesión Y session_valid eliminadas");
} else {
    h_fail("clave Redis SIGUE presente tras killSession");
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3: SessionTracker detecta user no activo
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 3 — User no activo: eviction vía SessionTracker");
$u3 = $users[3];
$sid3 = "{$tag}_sid3";
UserSession::create([
    'user_id' => $u3->id,
    'session_id' => $sid3,
    'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0',
    'created_at' => now(),
    'last_activity_at' => now(),
    'expires_at' => now()->addHour(),
]);
$createdSessionIds[] = $sid3;
simulateLogin($sid3);

if (User::find($u3->id)->isActive()) h_ok("precondición: usuario activo antes del cambio");
else h_fail("precondición rota: usuario ya no estaba activo");

// Cambiar status a 'disabled'
$u3->update(['status' => User::STATUS_DISABLED]);

// Bloque nuevo de SessionTracker
$record = UserSession::where('session_id', $sid3)->first();
$userFromDb = User::find($record->user_id);
if ($record && $userFromDb && !$userFromDb->isActive()) {
    $svc->killSession($record);
    h_ok("middleware-equivalent: isActive()=false detectado, killSession invocado");
} else {
    h_fail("precondición rota al re-leer");
}

if (!UserSession::where('user_id', $u3->id)->where('session_id', $sid3)->exists()) {
    h_ok("sesión evictada de DB");
} else {
    h_fail("sesión SIGUE en DB tras killSession");
}

if (assertSessionGone($sid3)) {
    h_ok("sesión evictada de Redis (session + session_valid)");
} else {
    h_fail("clave Redis SIGUE presente tras killSession");
}

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4: UserObserver limpia Redis al eliminar usuario
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 4 — UserObserver limpia Redis en User::delete()");
$u4 = $users[4];
$sid4 = "{$tag}_sid4";
UserSession::create([
    'user_id' => $u4->id,
    'session_id' => $sid4,
    'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0',
    'created_at' => now(),
    'last_activity_at' => now(),
    'expires_at' => now()->addHour(),
]);
$createdSessionIds[] = $sid4;
simulateLogin($sid4);

$preCache = assertSessionGone($sid4) === false;
$preDb    = UserSession::where('user_id', $u4->id)->count();
if ($preCache && $preDb === 1) {
    h_ok("precondición: 1 sesión en DB + claves Redis antes del delete");
} else {
    h_fail("precondición rota: cache=$preCache, db=$preDb");
}

// El observer debe dispararse en deleting() (antes del cascade DB)
$u4->delete();

if (User::find($u4->id) === null) h_ok("User eliminado de DB");
else h_fail("User SIGUE en DB");

if (UserSession::where('user_id', $u4->id)->count() === 0) h_ok("user_sessions cascada a 0 filas");
else h_fail("user_sessions NO cascó completamente");

if (assertSessionGone($sid4)) {
    h_ok("Redis limpiado por observer (sin zombie)");
} else {
    h_fail("ZOMBIE en Redis: clave $sid4 sigue presente tras delete");
}

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
h_section("TEARDOWN");
foreach ($createdUserIds as $uid) {
    try {
        $u = User::find($uid);
        if ($u) {
            UserSession::where('user_id', $uid)->delete();
            $u->delete();
        }
    } catch (\Throwable $e) {
        echo "  ! cleanup error for user $uid: " . $e->getMessage() . "\n";
    }
}
foreach ($createdSessionIds as $sid) {
    Cache::forget($sid);
    Cache::forget("session_valid:{$sid}");
}
h_ok("limpieza completada");

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "✅ TODOS LOS ESCENARIOS PASARON — 0 fallos\n";
    exit(0);
} else {
    echo "❌ $failures FALLO(S) — revisar arriba\n";
    exit(1);
}
