<?php
/**
 * Harness de regresión — change `fix-session-service-redis-prefix-double`.
 *
 * Cubre el contrato de `SessionService`:
 *  1. `sessionExistsInRedis($sid)` retorna true para sesión viva
 *  2. `killSession()` elimina fila de BD + clave Redis (DBSIZE decrementa)
 *  3. `killAllUserSessions($user)` limpia todas las sesiones de un usuario
 *     (DBSIZE decrementa en exactamente N sesiones)
 *
 * Uso: php tests/harness_session_service_kill_redis_key.php
 * Exit: 0 = OK, 1 = alguna aserción falló
 *
 * Estrategia:
 *  - Lee/escribe solo filas con prefijo `hsr_<tag>` en `user_sessions`.
 *  - Mide `DBSIZE` de la DB Redis `session` antes/después de cada kill.
 *  - Cleanup defensivo al inicio (por si una corrida anterior dejó basura).
 *  - finally cleanup elimina filas `hsr_*` y limpia claves `hsr_*` en Redis.
 *
 * Si el helper `sessionRedisKey()` volviera a duplicar `redis.options.prefix`,
 * las aserciones 2 y 3 fallarían porque la clave Redis no se borraría.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;

$failures = 0;

function h_ok(string $msg): void { echo "  ✓ $msg\n"; }
function h_fail(string $msg): void { echo "  ✗ $msg\n"; $GLOBALS['failures']++; }
function h_section(string $msg): void { echo "\n=== $msg ===\n"; }
function h_check(bool $cond, string $ok, string $fail): void {
    if ($cond) h_ok($ok); else h_fail($fail);
}

echo "Harness session-service-redis-prefix-double\n";

// 'hsr' = harness session redis
$tag = 'hsr_' . substr(bin2hex(random_bytes(4)), 0, 8);
echo "Tag: $tag\n";

// ─── Cleanup defensivo de corridas previas ──────────────────────────────
DB::table('user_sessions')
    ->whereIn('session_id', function ($q) use ($tag) {
        $q->select('session_id')->from('user_sessions')
          ->where('session_id', 'LIKE', $tag . '%');
    })
    ->delete();

// Limpieza de posibles keys Redis huérfanas de runs anteriores del harness
$cachePrefix = config('cache.prefix', '');
// phpredis ya aplica $redis.options.prefix; el helper sessionRedisKey compone
// solo $cachePrefix + $sid. Para buscar la clave directamente con el client,
// hay que aportar solo el cache.prefix (phpredis añade el redis.prefix encima).
$prefix = $cachePrefix;
$client = Redis::connection('session')->client();
$cursor = null;
$staleKilled = 0;
do {
    $keys = $client->scan($cursor, $prefix . $tag . '*', 100);
    if (is_array($keys)) {
        foreach ($keys as $k) {
            $client->del($k);
            $staleKilled++;
        }
    }
} while ($cursor !== 0 && $cursor !== '0' && !empty($keys));
echo "Cleanup previo: {$staleKilled} claves stale eliminadas.\n";

try {
    $svc = app(SessionService::class);

    // ─── Crear usuario de prueba ─────────────────────────────────────────
    $user = User::create([
        'email' => $tag . '_user@test.local',
        'username' => $tag . '_user',
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
        'media_editor_enabled' => true,
        'media_editor_clip_limit' => 0,
    ]);
    echo "Usuario de prueba: id={$user->id}\n";

    $sizeBeforeAll = (int) $client->dbSize();
    echo "DBSIZE inicial: $sizeBeforeAll\n";

    // ─────────────────────────────────────────────────────────────────────
    // Aserción A — sesión individual
    // ─────────────────────────────────────────────────────────────────────
    h_section('Aserción A: killSession() borra fila DB + clave Redis');

    Session::start();
    Session::put('__harness_probe__', 'A');
    Session::save();
    $sid = Session::getId();
    $sizeAfterCreate = (int) $client->dbSize();

    DB::table('user_sessions')->insert([
        'user_id'          => $user->id,
        'session_id'       => $sid,
        'created_at'       => now(),
        'last_activity_at' => now(),
        'expires_at'       => now()->addMinutes(120),
    ]);
    $us = UserSession::where('session_id', $sid)->first();
    h_check($us !== null, "Fila user_sessions insertada (sid={$sid})", "Fila no insertada");

    $existsBefore = $svc->sessionExistsInRedis($sid);
    h_check($existsBefore, 'sessionExistsInRedis = true pre-kill', 'sessionExistsInRedis = false pre-kill');

    $dbBeforeSize = (int) $client->dbSize();

    $svc->killSession($us);

    $existsAfter = $svc->sessionExistsInRedis($sid);
    h_check(!$existsAfter, 'sessionExistsInRedis = false post-kill', 'sessionExistsInRedis = true post-kill (LA CLAVE SIGUE VIVA EN REDIS)');

    $rowAfter = DB::table('user_sessions')->where('session_id', $sid)->exists();
    h_check(!$rowAfter, 'Fila user_sessions eliminada', 'Fila user_sessions todavía existe');

    $dbAfterSize = (int) $client->dbSize();
    h_check(
        $dbAfterSize === $dbBeforeSize - 1,
        "DBSIZE decrementó en 1 (de {$dbBeforeSize} a {$dbAfterSize})",
        "DBSIZE NO decrementó (antes={$dbBeforeSize}, después={$dbAfterSize}). ESTE ES EL BUG QUE EL FIX ARREGLA."
    );

    // ─────────────────────────────────────────────────────────────────────
    // Aserción B — multi-sesión con killAllUserSessions
    // ─────────────────────────────────────────────────────────────────────
    h_section('Aserción B: killAllUserSessions() borra N sesiones');

    $sizeBeforeBatch = (int) $client->dbSize();
    $bSids = [];
    $bCount = 3;
    for ($i = 0; $i < $bCount; $i++) {
        Session::start();
        Session::put('__harness_probe__', 'B' . $i);
        Session::save();
        $s = Session::getId();
        $bSids[] = $s;
        DB::table('user_sessions')->insert([
            'user_id'          => $user->id,
            'session_id'       => $s,
            'created_at'       => now(),
            'last_activity_at' => now(),
            'expires_at'       => now()->addMinutes(120),
        ]);
        Session::regenerate(false);  // genera ID nuevo para la próxima iteración
    }

    $sizeAfterBatchCreate = (int) $client->dbSize();
    $expectedDeltaCreate = $sizeAfterBatchCreate - $sizeBeforeBatch;
    h_check(
        $expectedDeltaCreate >= $bCount,
        "Tras crear {$bCount} sesiones, DBSIZE subió al menos {$bCount} (Δ={$expectedDeltaCreate})",
        "DBSIZE no refleja creación (Δ={$expectedDeltaCreate})"
    );

    $killed = $svc->killAllUserSessions($user);
    h_check($killed === $bCount, "killAllUserSessions retorna {$bCount}", "killAllUserSessions retornó {$killed}, esperado {$bCount}");

    $rowsAfter = (int) DB::table('user_sessions')->where('user_id', $user->id)->count();
    h_check($rowsAfter === 0, "0 filas user_sessions restantes para usuario {$user->id}", "Quedan {$rowsAfter} filas");

    $sizeAfterBatch = (int) $client->dbSize();
    $expectedDeltaAfter = $sizeAfterBatchCreate - $sizeAfterBatch;
    h_check(
        $expectedDeltaAfter >= $bCount,
        "DBSIZE decrementó al menos {$bCount} tras killAll (Δ={$expectedDeltaAfter})",
        "DBSIZE NO decrementó tras killAllUserSessions (Δ={$expectedDeltaAfter}). ESTE ES EL BUG QUE EL FIX ARREGLA."
    );

    // ─────────────────────────────────────────────────────────────────────
    // Aserción C — sanity: la clave que construye sessionRedisKey() apunta
    // a la clave real escrita por Laravel (verificación adicional a A)
    // ─────────────────────────────────────────────────────────────────────
    h_section('Aserción C: la clave que construye sessionRedisKey() coincide con la clave real');

    Session::regenerate(false);
    Session::put('__harness_probe__', 'C');
    Session::save();
    $sidC = Session::getId();

    $expectedKeyAfterPhpRedis = $prefix . $sidC;  // phpredis aplica prefix encima de lo que pasamos
    $existsRaw = $client->exists($expectedKeyAfterPhpRedis);
    h_check(
        (int) $existsRaw > 0,
        "Clave '{$expectedKeyAfterPhpRedis}' existe en Redis (prefijo aplicado correctamente)",
        "Clave esperada '{$expectedKeyAfterPhpRedis}' NO existe (el session handler escribe con otro formato)"
    );

    $existsInService = $svc->sessionExistsInRedis($sidC);
    h_check(
        $existsInService,
        'sessionExistsInRedis retorna true para sesión creada',
        'sessionExistsInRedis retorna false aunque la sesión esté viva en Redis (debería ser true)'
    );

    // Cleanup
    DB::table('user_sessions')->where('session_id', $sidC)->delete();

    h_section('Resumen');
    echo "  Fallos: $failures\n";

} finally {
    // ─── Cleanup final ────────────────────────────────────────────────
    DB::table('user_sessions')->where('session_id', 'LIKE', $tag . '%')->delete();
    DB::table('users')->where('email', $tag . '%')->delete();

    // Limpiar posibles claves Redis residuales del harness
    $cursor = null;
    do {
        $keys = $client->scan($cursor, $prefix . $tag . '*', 100);
        if (is_array($keys)) {
            foreach ($keys as $k) $client->del($k);
        }
    } while ($cursor !== 0 && $cursor !== '0' && !empty($keys));
}

exit($failures === 0 ? 0 : 1);
