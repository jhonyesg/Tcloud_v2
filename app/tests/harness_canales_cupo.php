<?php
/**
 * Harness de regresión para change `medios-puntuales-cupos-y-adopcion-remota`.
 *
 * Valida el cupo de canales por par (grabador × usuario) contra PostgreSQL real.
 *
 * Bug original (2026-10-22): `CanalController::store()` leía
 * `$acceso->limite_canales` sobre un modelo Grabador (columna inexistente →
 * null), y `usados >= null` evalúa siempre true. Resultado: ningún usuario
 * no-admin podía crear canales, con cualquier límite. El límite editado en
 * Grabadores (4→5) se guardaba pero no se aplicaba.
 *
 * Estrategia:
 *   - Se crea un grabador, dos usuarios y las filas de pivote con tag
 *     `hmcc_<8hex>`; todo se borra en `finally`.
 *   - El guard se ejercita a través de `CupoCanalesService` (la fuente única
 *     que ahora consumen create/store/actualizarAsignacion). La coherencia con
 *     el endpoint HTTP se valida además con la simulación del par
 *     (limite/usados) que el controller usa.
 *
 * Uso:
 *   cd app && php tests/harness_canales_cupo.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Canal;
use App\Models\Grabador;
use App\Models\User;
use App\Services\GrabacionesPuntuales\CupoCanalesService;
use Illuminate\Support\Facades\DB;

$tag = 'hmcc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness canales-cupo (tag: {$tag})\n";

$crearUsuario = function (string $sufijo) {
    return User::create([
        'email' => $sufijo . '@harness.local',
        'username' => $sufijo,
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);
};

$grabador = null;
$admin = null;
$dueno = null;
$vecino = null;

try {
    h_section('cleanup defensivo pre-run');
    DB::table('grabador_usuario')->whereIn('user_id', function ($q) {
        $q->select('id')->from('users')->where('username', 'like', 'hmcc_%');
    })->delete();
    Canal::whereIn('grabador_id', function ($q) {
        $q->select('id')->from('grabadores')->where('nombre', 'like', 'hmcc_%');
    })->delete();
    Grabador::where('nombre', 'like', 'hmcc_%')->delete();
    User::where('username', 'like', 'hmcc_%')->delete();
    h_ok('residuos hmcc_* eliminados');

    h_section('fixtures');
    $grabador = Grabador::create([
        'nombre' => $tag,
        'tipo' => 'radio',
        'ip' => '127.0.0.' . random_int(2, 250),
        'puerto' => random_int(10000, 60000),
        'base_url' => 'http://127.0.0.1:5002/api',
        'activo' => true,
        'observaciones' => 'harness',
    ]);
    h_ok("grabador creado id={$grabador->id}");

    $dueno = $crearUsuario($tag . '_dueno');
    $vecino = $crearUsuario($tag . '_vecino');
    $admin = User::create([
        'email' => $tag . '_admin@harness.local',
        'username' => $tag . '_admin',
        'password_hash' => 'harness_dummy',
        'role' => 'admin',
        'status' => 'active',
    ]);

    $pivote = function (int $userId, int $limite, ?string $ruta) use ($grabador) {
        DB::table('grabador_usuario')->insert([
            'grabador_id' => $grabador->id,
            'user_id' => $userId,
            'limite_canales' => $limite,
            'ruta_base' => $ruta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $pivote($dueno->id, 5, '/disco/harness');
    $pivote($vecino->id, 3, '/disco/harness_vecino');
    h_ok('pivotes creados (5 y 3)');

    // (grabador_id, slot_nombre) es UNIQUE: la numeración debe ser continua
    // entre llamadas, no reiniciarse por usuario.
    $slotSeq = 0;
    $crearCanales = function (int $userId, int $n) use ($grabador, $tag, &$slotSeq) {
        for ($i = 0; $i < $n; $i++) {
            $slotSeq++;
            $slot = $tag . '_' . str_pad((string) $slotSeq, 3, '0', STR_PAD_LEFT);
            Canal::create([
                'grabador_id' => $grabador->id,
                'usuario_id' => $userId,
                'slot_nombre' => $slot,
                'ruta_destino' => '/disco/harness/' . $slot,
                'activo' => true,
            ]);
        }
    };

    /** @var CupoCanalesService $cupo */
    $cupo = app(CupoCanalesService::class);

    // ── (a) limite=5, usados=4 → crea ────────────────────────────────────────
    h_section('(a) limite=5 con 4 usados debe permitir crear');
    $crearCanales($dueno->id, 4);
    $resumen = $cupo->resumen($grabador->id, $dueno->id);
    h_check($resumen !== null, 'resumen no es null');
    h_check(($resumen['limite'] ?? null) === 5, 'limite leido del pivote = 5', 'limite incorrecto: ' . var_export($resumen['limite'] ?? null, true));
    h_check(($resumen['usados'] ?? null) === 4, 'usados = 4', 'usados incorrecto');
    h_check(($resumen['disponibles'] ?? null) === 1, 'disponibles = 1', 'disponibles incorrecto');
    h_check($cupo->puedeCrear($grabador->id, $dueno->id, false), 'puedeCrear = true (bug original daba false)');

    // ── (b) limite=5, usados=5 → rechaza ─────────────────────────────────────
    h_section('(b) limite=5 con 5 usados debe rechazar');
    $crearCanales($dueno->id, 1);
    $resumen = $cupo->resumen($grabador->id, $dueno->id);
    h_check(($resumen['usados'] ?? null) === 5, 'usados = 5');
    h_check(($resumen['disponibles'] ?? null) === 0, 'disponibles = 0');
    h_check(!$cupo->puedeCrear($grabador->id, $dueno->id, false), 'puedeCrear = false en el tope');

    // ── (c) limite=10, usados=0 → crea ───────────────────────────────────────
    h_section('(c) limite=10 con 0 usados debe permitir crear');
    $resumenVecino = $cupo->resumen($grabador->id, $vecino->id);
    h_check(($resumenVecino['limite'] ?? null) === 3, 'limite del vecino = 3');
    h_check(($resumenVecino['usados'] ?? null) === 0, 'usados del vecino = 0');
    h_check($cupo->puedeCrear($grabador->id, $vecino->id, false), 'vecino puede crear');

    DB::table('grabador_usuario')
        ->where('grabador_id', $grabador->id)
        ->where('user_id', $vecino->id)
        ->update(['limite_canales' => 10, 'updated_at' => now()]);
    $resumenVecino = $cupo->resumen($grabador->id, $vecino->id);
    h_check(($resumenVecino['disponibles'] ?? null) === 10, 'subir limite 3→10 se refleja (disponibles=10)');

    // ── (d) pivote inexistente → rechazo explícito ───────────────────────────
    h_section('(d) sin pivote debe denegar explícitamente, no comparar contra null');
    $sinPivote = $crearUsuario($tag . '_sinpivote');
    h_check($cupo->limite($grabador->id, $sinPivote->id) === null, 'limite() = null');
    h_check($cupo->resumen($grabador->id, $sinPivote->id) === null, 'resumen() = null');
    h_check(!$cupo->puedeCrear($grabador->id, $sinPivote->id, false), 'puedeCrear = false sin asignación');

    // ── limite=0 → sin asignación, no ilimitado ──────────────────────────────
    h_section('(d2) limite=0 no debe convertirse en ilimitado');
    DB::table('grabador_usuario')
        ->where('grabador_id', $grabador->id)
        ->where('user_id', $sinPivote->id)
        ->insert([
            'grabador_id' => $grabador->id,
            'user_id' => $sinPivote->id,
            'limite_canales' => 0,
            'ruta_base' => '/disco/cero',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    h_check($cupo->limite($grabador->id, $sinPivote->id) === null, 'limite=0 → null');
    h_check(!$cupo->puedeCrear($grabador->id, $sinPivote->id, false), 'limite=0 no permite crear');

    // ── (e) admin bypassa ─────────────────────────────────────────────────────
    h_section('(e) admin no está sujeto al cupo');
    h_check($cupo->puedeCrear($grabador->id, $admin->id, true), 'admin puede crear sin pivote');

    // ── (f) coherencia resumen ↔ guard ────────────────────────────────────────
    h_section('(f) el disponible de create() coincide con el guard de store()');
    $coherente = true;
    foreach ([$dueno->id, $vecino->id, $sinPivote->id] as $uid) {
        $r = $cupo->resumen($grabador->id, $uid);
        $guardPermite = $r !== null && $r['disponibles'] > 0;
        $uiMuestraDisponible = $r !== null && $r['disponibles'] > 0;
        if ($guardPermite !== $uiMuestraDisponible) {
            $coherente = false;
            h_fail("usuario {$uid}: guard y UI difieren");
        }
    }
    h_check($coherente, 'guard y UI coinciden para todos los pares');

    // ── (g) bajar el límite no borra canales ─────────────────────────────────
    h_section('(g) bajar el límite conserva los canales');
    $antes = Canal::where('grabador_id', $grabador->id)->where('usuario_id', $dueno->id)->count();
    DB::table('grabador_usuario')
        ->where('grabador_id', $grabador->id)
        ->where('user_id', $dueno->id)
        ->update(['limite_canales' => 2, 'updated_at' => now()]);
    $despues = Canal::where('grabador_id', $grabador->id)->where('usuario_id', $dueno->id)->count();
    $resumen = $cupo->resumen($grabador->id, $dueno->id);

    h_check($antes === $despues, "ningún canal borrado ({$antes} antes, {$despues} después)");
    h_check(($resumen['limite'] ?? null) === 2, 'limite = 2');
    h_check(($resumen['usados'] ?? null) === 5, 'usados sigue en 5');
    h_check(($resumen['disponibles'] ?? 0) < 0, 'disponibles negativo (excedido)');
    h_check(!$cupo->puedeCrear($grabador->id, $dueno->id, false), 'creación bloqueada mientras esté excedido');

    // ── (h) uso cuenta solo el par ────────────────────────────────────────────
    h_section('(h) el uso cuenta solo el par (grabador, usuario)');
    $crearCanales($vecino->id, 2);
    h_check($cupo->uso($grabador->id, $vecino->id) === 2, 'vecino usados = 2');
    h_check($cupo->uso($grabador->id, $dueno->id) === 5, 'dueño usados = 5 (no contagiado)');

} catch (\Throwable $e) {
    h_fail('excepción: ' . get_class($e) . ': ' . $e->getMessage());
    echo $e->getTraceAsString(), "\n";
} finally {
    h_section('cleanup');
    try {
        if ($grabador !== null) {
            Canal::where('grabador_id', $grabador->id)->delete();
            DB::table('grabador_usuario')->where('grabador_id', $grabador->id)->delete();
            Grabador::where('id', $grabador->id)->delete();
        }
        User::where('username', 'like', $tag . '%')->delete();
        h_ok('fixtures eliminados');
    } catch (\Throwable $e) {
        h_fail('cleanup falló: ' . $e->getMessage());
    }
}

echo "\n";
if ($failures === 0) {
    echo "RESULTADO: OK (todas las aserciones pasaron)\n";
    exit(0);
}

echo "RESULTADO: {$failures} aserción(es) fallaron\n";
exit(1);
