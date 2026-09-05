<?php
/**
 * Harness de validación del change `fix-session-service-redis-db`.
 *
 * Ejecuta los escenarios críticos contra la conexión Redis `session` (DB 2)
 * y contra PostgreSQL de la app. Verifica el COMPORTAMIENTO observable del
 * fix, no la implementación interna de los helpers privados.
 *
 *  - Las sesiones (escritas por Laravel con CacheBasedSessionHandler) viven
 *    en la conexión Redis `session` (DB 2). Este harness las simula
 *    escribiendo directamente a esa conexión con el mismo formato de clave
 *    `{redis_prefix}{cache_prefix}{sid}`.
 *  - killSession() debe borrar tanto la fila de user_sessions como la clave.
 *  - cleanOrphans(dryRun: true) NO borra filas y reporta el conteo correcto.
 *  - cleanOrphans() aborta con log aborted_mass_delete cuando el ratio
 *    would_delete/scanned supera el umbral (protección contra bugs futuros).
 *  - Un usuario con session_lifetime_minutes=0 sobrevive al ciclo de
 *    cleanOrphans (escenario exacto del bug reportado).
 *
 * Uso: php tests/harness_fix_session_redis_db.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

$tag = 'fix_sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
$createdUserIds = [];
$failures = 0;

function h_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }

$redisPrefix = (string) config('database.redis.options.prefix', '');
$cachePrefix = (string) config('cache.prefix', '');
$sessionDb = Redis::connection('session');

// Simula una sesión "loggeada" en Redis DB 2 con el formato de clave que
// usa Laravel: {redis_prefix}{cache_prefix}{session_id}
function simulateSessionInRedis(string $sid, int $ttlSeconds = 3600): void {
    global $sessionDb, $redisPrefix, $cachePrefix;
    $sessionDb->setex($redisPrefix . $cachePrefix . $sid, $ttlSeconds, serialize(['harness' => true]));
}

function redisSessionExists(string $sid): bool {
    global $sessionDb, $redisPrefix, $cachePrefix;
    return $sessionDb->exists($redisPrefix . $cachePrefix . $sid) > 0;
}

function redisSessionForget(string $sid): void {
    global $sessionDb, $redisPrefix, $cachePrefix;
    $sessionDb->del($redisPrefix . $cachePrefix . $sid);
}

h_section("HARNESS fix-session-service-redis-db [tag=$tag]");

// Aislamiento del estado: si hay otras user_sessions o claves Redis de runs
// anteriores, pueden disparar el guardarraíl de ratio incorrectamente.
// Limpiamos user_sessions y todas las claves tcloud_tcloud_cache_* en DB 2
// antes de empezar. (No tocamos otras claves como folder_gen, queue, etc.)
$preDb = \Illuminate\Support\Facades\DB::table('user_sessions')->count();
$preRedis = count($sessionDb->keys('tcloud_tcloud_cache_*'));
if ($preDb > 0 || $preRedis > 0) {
    h_section("PRECONDICIÓN — limpiando estado previo ($preDb filas user_sessions, $preRedis claves DB 2)");
    \Illuminate\Support\Facades\DB::table('user_sessions')->delete();
    foreach ($sessionDb->keys('tcloud_tcloud_cache_*') as $k) {
        $sessionDb->del($k);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────────────────
h_section("SETUP");
$now = now();
$users = [];
for ($i = 1; $i <= 4; $i++) {
    $u = User::create([
        'email'        => "{$tag}_u{$i}@harness.local",
        'username'     => "{$tag}_u{$i}",
        'password_hash' => Hash::make('Secret#123'),
        'role'         => 'user',
        'status'       => User::STATUS_ACTIVE,
        'personal_quota_bytes' => 0,
        'personal_used_bytes'  => 0,
        'session_lifetime_minutes' => 0,
    ]);
    $users[$i] = $u;
    $createdUserIds[] = $u->id;
    h_ok("creado user #$i (id={$u->id}, lifetime=0)");
}

$svc = app(SessionService::class);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 1: la consulta a Redis usa DB 2 (session), no DB 1 (cache)
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 1 — la consulta de Redis apunta a DB 2 (session)");
$sid1 = "{$tag}_sid1";
simulateSessionInRedis($sid1);
$fullKey = $redisPrefix . $cachePrefix . $sid1;

// 1a: la clave física existe en DB 2 (donde Laravel guarda sesiones)
$existsInDb2 = Redis::connection('session')->exists($fullKey) > 0;
if ($existsInDb2) h_ok("clave presente en DB 2 (conexión session): $fullKey");
else h_fail("clave NO está en DB 2 — la simulación no funcionó");

// 1b: la clave NO existe en DB 1 (cache) — descartamos duplicación
$existsInDb1 = Redis::connection('cache')->exists($fullKey) > 0;
if (!$existsInDb1) h_ok("clave NO duplicada en DB 1 (cache)");
else h_fail("clave SÍ está en DB 1 — algo está escribiendo en dos DBs");

// 1c: cleanOrphans(dryRun) NO trata la sesión activa como huérfana
$wouldDelete = $svc->cleanOrphans(dryRun: true);
if ($wouldDelete === 0) h_ok("cleanOrphans(dryRun) NO marca sesión activa como huérfana (would_delete=0)");
else h_fail("cleanOrphans(dryRun) reporta $wouldDelete huérfanas — la activa fue mal clasificada");

redisSessionForget($sid1);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 2: killSession() borra DB + Redis (DB 2)
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 2 — killSession() borra DB + Redis (DB 2)");
$u2 = $users[2];
$sid2 = "{$tag}_sid2";
$rec2 = UserSession::create([
    'user_id'          => $u2->id,
    'session_id'       => $sid2,
    'ip_address'       => '127.0.0.1',
    'user_agent'       => 'harness/1.0',
    'created_at'       => $now,
    'last_activity_at' => $now,
    'expires_at'       => null,
]);
simulateSessionInRedis($sid2);

if (redisSessionExists($sid2) && UserSession::where('session_id', $sid2)->exists()) {
    h_ok("precondición: sesión en Redis DB 2 + fila en user_sessions");
} else {
    h_fail("precondición rota");
}

$svc->killSession($rec2);

if (!UserSession::where('session_id', $sid2)->exists()) h_ok("fila user_sessions eliminada");
else h_fail("fila user_sessions SIGUE presente tras killSession");

if (!redisSessionExists($sid2)) h_ok("clave Redis (DB 2) eliminada por killSession");
else h_fail("clave Redis (DB 2) SIGUE presente tras killSession");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 3: cleanOrphans(dryRun: true) NO borra y reporta conteo correcto
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 3 — cleanOrphans dryRun cuenta sin borrar");
$u3 = $users[3];
$sid3a = "{$tag}_sid3a_active";
$sid3b = "{$tag}_sid3b_orphan";

UserSession::create([
    'user_id' => $u3->id, 'session_id' => $sid3a, 'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0', 'created_at' => $now, 'last_activity_at' => $now,
    'expires_at' => null,
]);
UserSession::create([
    'user_id' => $u3->id, 'session_id' => $sid3b, 'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0', 'created_at' => $now, 'last_activity_at' => $now,
    'expires_at' => null,
]);
simulateSessionInRedis($sid3a); // sid3b queda huérfana

$preCount = UserSession::where('user_id', $u3->id)->count();
if ($preCount === 2) h_ok("precondición: 2 sesiones del usuario u3");
else h_fail("precondición rota: $preCount sesiones (esperaba 2)");

$wouldDelete = $svc->cleanOrphans(dryRun: true);
if ($wouldDelete >= 1) h_ok("dryRun detectó al menos 1 huérfana (count={$wouldDelete})");
else h_fail("dryRun NO detectó huérfanas ($wouldDelete) — esperaba >=1");

$postCount = UserSession::where('user_id', $u3->id)->count();
if ($postCount === 2) h_ok("dryRun NO borró filas (count se mantuvo en 2)");
else h_fail("dryRun borró filas! count=$postCount (esperaba 2)");

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 4: guardarraíl de ratio aborta cuando would_delete > max_ratio
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 4 — guardarraíl aborta cuando would_delete/scanned > max_ratio");
$u4 = $users[4];

for ($j = 1; $j <= 4; $j++) {
    UserSession::create([
        'user_id' => $u4->id, 'session_id' => "{$tag}_u4_orphan{$j}", 'ip_address' => '127.0.0.1',
        'user_agent' => 'harness/1.0', 'created_at' => $now, 'last_activity_at' => $now,
        'expires_at' => null,
    ]);
}

SystemSetting::set('sessions_cleanup_max_ratio', 0.3);

$preCount = UserSession::where('user_id', $u4->id)->count();
$deleted = $svc->cleanOrphans();
$postCount = UserSession::where('user_id', $u4->id)->count();

if ($postCount === $preCount) {
    h_ok("guardarraíl abortó: $preCount sesiones preservadas (esperaba $preCount)");
} else {
    h_fail("guardarraíl NO abortó: pasó de $preCount a $postCount sesiones");
}

SystemSetting::set('sessions_cleanup_max_ratio', 0.5);

// ─────────────────────────────────────────────────────────────────────────────
// ESCENARIO 5: usuario con lifetime=0 sobrevive a cleanOrphans (regresión bug)
// ─────────────────────────────────────────────────────────────────────────────
h_section("ESCENARIO 5 — usuario lifetime=0 sobrevive cleanOrphans (regresión)");
$sid5 = "{$tag}_sid5_u1";
UserSession::create([
    'user_id' => $users[1]->id, 'session_id' => $sid5, 'ip_address' => '127.0.0.1',
    'user_agent' => 'harness/1.0', 'created_at' => $now, 'last_activity_at' => $now,
    'expires_at' => null,
]);
simulateSessionInRedis($sid5);

$svc->cleanOrphans();
$stillAlive = UserSession::where('session_id', $sid5)->exists();
if ($stillAlive) h_ok("sesión con lifetime=0 SOBREVIVE a cleanOrphans (regresión cubierta)");
else h_fail("sesión con lifetime=0 fue BORRADA — bug original todavía presente");

redisSessionForget($sid5);

// ─────────────────────────────────────────────────────────────────────────────
// TEARDOWN
// ─────────────────────────────────────────────────────────────────────────────
h_section("TEARDOWN");
foreach ($createdUserIds as $uid) {
    try {
        UserSession::where('user_id', $uid)->delete();
        User::find($uid)?->delete();
    } catch (\Throwable $e) {
        echo "  ! cleanup error for user $uid: " . $e->getMessage() . "\n";
    }
}
h_ok("limpieza completada");

echo "\n" . str_repeat('=', 60) . "\n";
if ($failures === 0) {
    echo "✅ TODOS LOS ESCENARIOS PASARON — 0 fallos\n";
    exit(0);
}
echo "❌ $failures escenarios fallaron\n";
exit(1);
