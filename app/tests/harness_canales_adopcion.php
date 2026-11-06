<?php
/**
 * Harness de regresión para change `medios-puntuales-cupos-y-adopcion-remota`.
 *
 * Valida la detección y adopción no destructiva de canales que existen en el
 * grabador y no tienen fila en `canales`.
 *
 * Estrategia:
 *   - `Http::fake()` simula el grabador: un canal "de la familia" (adoptable),
 *     un canal de otra familia bajo el mismo ruta_base (sugerido), un canal en
 *     subdirectorio anidado (sugerido) y un canal fuera de todo ruta_base.
 *   - Se verifica la clasificación, la adopción (INSERT espejo), la
 *     idempotencia y que NO se emitió ninguna escritura contra el grabador.
 *   - Todo con tag `hmad_<8hex>` y cleanup en `finally`.
 *
 * Uso:
 *   cd app && php tests/harness_canales_adopcion.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Canal;
use App\Models\Grabador;
use App\Models\User;
use App\Services\GrabacionesPuntuales\AdopcionRemotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$tag = 'hmad_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness canales-adopcion (tag: {$tag})\n";

$baseRemota = '/disco/harness/' . $tag;
$grabador = null;
$usuario = null;

$remotos = [
    // adoptable: hijo directo del ruta_base + familia "solicitudes"
    [
        'id' => 990001,
        'codigo' => 'Solicitudes_05',
        'nombre' => 'Solicitudes_05',
        'ruta_descarga' => $baseRemota . '/Solicitudes_05',
        'formato_salida' => null,
        'activo' => true,
        'duracion_grabacion' => '00:21:00',
        'ffmpeg_args_pre' => '-re',
        'ffmpeg_args_post' => '-acodec libmp3lame',
        'link_origen' => null,
        'detalle' => null,
    ],
    // sugerido: hijo directo pero de OTRA familia
    [
        'id' => 990002,
        'codigo' => 'Telemedellin',
        'nombre' => 'Telemedellin',
        'ruta_descarga' => $baseRemota . '/Telemedellin',
        'formato_salida' => '.mp4',
        'activo' => true,
    ],
    // sugerido: subdirectorio anidado
    [
        'id' => 990003,
        'codigo' => 'RD_BLU',
        'nombre' => 'RD_BLU',
        'ruta_descarga' => $baseRemota . '/RD1/Antioquia/BLU',
        'formato_salida' => '.mp4',
        'activo' => true,
    ],
    // fuera de alcance: no cuelga de ningún ruta_base
    [
        'id' => 990004,
        'codigo' => 'Ajeno_01',
        'nombre' => 'Ajeno_01',
        'ruta_descarga' => '/otra/ruta/completamente/distinta/Ajeno_01',
        'formato_salida' => '.mp3',
        'activo' => true,
    ],
];

try {
    h_section('cleanup defensivo pre-run');
    DB::table('grabador_usuario')->whereIn('user_id', function ($q) {
        $q->select('id')->from('users')->where('username', 'like', 'hmad_%');
    })->delete();
    Canal::whereIn('grabador_id', function ($q) {
        $q->select('id')->from('grabadores')->where('nombre', 'like', 'hmad_%');
    })->delete();
    Grabador::where('nombre', 'like', 'hmad_%')->delete();
    User::where('username', 'like', 'hmad_%')->delete();
    h_ok('residuos hmad_* eliminados');

    h_section('fixtures');
    $grabador = Grabador::create([
        'nombre' => $tag,
        'tipo' => 'radio',
        'ip' => '127.0.0.' . random_int(2, 250),
        'puerto' => random_int(10000, 60000),
        'base_url' => 'http://grabador.harness/api',
        'activo' => true,
        'observaciones' => 'harness',
    ]);

    $usuario = User::create([
        'email' => $tag . '@harness.local',
        'username' => $tag,
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);

    DB::table('grabador_usuario')->insert([
        'grabador_id' => $grabador->id,
        'user_id' => $usuario->id,
        'limite_canales' => 5,
        'ruta_base' => $baseRemota,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Slots locales que definen la "familia" Solicitudes_XX
    for ($i = 1; $i <= 4; $i++) {
        $slot = 'Solicitudes_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        Canal::create([
            'grabador_id' => $grabador->id,
            'usuario_id' => $usuario->id,
            'slot_nombre' => $slot,
            'api_canal_id' => 989990 + $i,
            'ruta_destino' => $baseRemota . '/' . $slot,
            'activo' => true,
        ]);
    }
    // Un local sin contraparte remota (debe reportarse en sin_match)
    Canal::create([
        'grabador_id' => $grabador->id,
        'usuario_id' => $usuario->id,
        'slot_nombre' => 'Huerfano_Local',
        'api_canal_id' => 989999,
        'ruta_destino' => $baseRemota . '/Huerfano_Local',
        'activo' => true,
    ]);

    h_ok("grabador id={$grabador->id}, usuario id={$usuario->id}, 4 slots + 1 huérfano local");

    $remotosConLocales = $remotos;
    // Los 4 slots locales también aparecen en el remoto, ya sincronizados
    for ($i = 1; $i <= 4; $i++) {
        $slot = 'Solicitudes_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $remotosConLocales[] = [
            'id' => 989990 + $i,
            'codigo' => strtolower($slot),
            'nombre' => $slot,
            'ruta_descarga' => $baseRemota . '/' . $slot,
            'formato_salida' => '.mp3',
            'activo' => true,
        ];
    }

    Http::fake([
        'grabador.harness/api/canales' => Http::response(['data' => $remotosConLocales], 200),
        'grabador.harness/*' => Http::response(['success' => true], 200),
    ]);

    /** @var AdopcionRemotaService $servicio */
    $servicio = app(AdopcionRemotaService::class);

    // ── (a) detección ────────────────────────────────────────────────────────
    h_section('(a) detección de remotos sin fila local');
    $resultado = $servicio->detectar($grabador);

    h_check($resultado['total_remotos'] === 8, 'total remotos = 8', 'total remotos: ' . $resultado['total_remotos']);
    h_check($resultado['ya_ok'] === 4, 'ya_ok = 4 (slots sincronizados)', 'ya_ok: ' . $resultado['ya_ok']);
    h_check($resultado['sin_match'] === 1, 'sin_match = 1 (Huerfano_Local)', 'sin_match: ' . $resultado['sin_match']);
    // Los "detectados" son los que entran en alcance (adoptable + sugerido).
    // Ajeno_01 no cuelga de ningún ruta_base: cuenta en fuera_de_alcance.
    h_check(count($resultado['remotos_detectados']) === 3, 'detectados = 3 (1 adoptable + 2 sugeridos)', 'detectados: ' . count($resultado['remotos_detectados']));
    h_check($resultado['fuera_de_alcance'] === 1, 'fuera_de_alcance = 1 (Ajeno_01)', 'fuera: ' . $resultado['fuera_de_alcance']);

    $porId = [];
    foreach ($resultado['remotos_detectados'] as $d) {
        $porId[$d['api_canal_id']] = $d;
    }

    // ── (b) adoptable ─────────────────────────────────────────────────────────
    h_section('(b) Solicitudes_05 debe ser adoptable');
    h_check(isset($porId[990001]), 'Solicitudes_05 detectado');
    h_check(($porId[990001]['confianza'] ?? null) === 'adoptable', 'confianza = adoptable', 'confianza: ' . var_export($porId[990001]['confianza'] ?? null, true));
    h_check(($porId[990001]['user_id'] ?? null) === $usuario->id, 'par propuesto = usuario del pivote');
    h_check(($porId[990001]['hijo_directo'] ?? null) === true, 'hijo_directo = true');
    h_check(($porId[990001]['misma_familia'] ?? null) === true, 'misma_familia = true');

    // ── (c) sugerido ──────────────────────────────────────────────────────────
    h_section('(c) Telemedellin y ruta anidada deben ser sugerido, nunca adoptable');
    h_check(($porId[990002]['confianza'] ?? null) === 'sugerido', 'Telemedellin = sugerido', 'confianza: ' . var_export($porId[990002]['confianza'] ?? null, true));
    h_check(($porId[990002]['misma_familia'] ?? null) === false, 'Telemedellin: misma_familia = false');
    h_check(($porId[990003]['confianza'] ?? null) === 'sugerido', 'ruta anidada RD1/... = sugerido', 'confianza: ' . var_export($porId[990003]['confianza'] ?? null, true));
    h_check(($porId[990003]['hijo_directo'] ?? null) === false, 'ruta anidada: hijo_directo = false');

    // ── fuera de alcance ──────────────────────────────────────────────────────
    h_section('(c2) canal fuera de todo ruta_base no se detecta');
    h_check(!isset($porId[990004]), 'Ajeno_01 no está en detectados (fuera de alcance)');

    // ── (d) adopción crea una sola fila ───────────────────────────────────────
    h_section('(d) adopción crea una fila espejo');
    $antes = Canal::where('grabador_id', $grabador->id)->count();
    $r = $servicio->adoptar($grabador, $usuario->id, 990001);
    $despues = Canal::where('grabador_id', $grabador->id)->count();

    h_check(($r['ok'] ?? false) === true, 'adopción ok', 'mensaje: ' . ($r['mensaje'] ?? ''));
    h_check(($r['accion'] ?? null) === 'adoptado', 'accion = adoptado', 'accion: ' . var_export($r['accion'] ?? null, true));
    h_check($despues === $antes + 1, "una sola fila creada ({$antes} → {$despues})");

    $adoptado = Canal::where('grabador_id', $grabador->id)->where('api_canal_id', 990001)->first();
    h_check($adoptado !== null, 'fila local con api_canal_id = 990001');
    h_check($adoptado && $adoptado->slot_nombre === 'Solicitudes_05', 'slot_nombre = Solicitudes_05');
    h_check($adoptado && (int) $adoptado->usuario_id === $usuario->id, 'usuario_id correcto');
    h_check($adoptado && $adoptado->ruta_destino === $baseRemota . '/Solicitudes_05', 'ruta_destino espejada del remoto');

    // ── (e) idempotencia ──────────────────────────────────────────────────────
    h_section('(e) adopción repetida es idempotente');
    $r2 = $servicio->adoptar($grabador, $usuario->id, 990001);
    $despues2 = Canal::where('grabador_id', $grabador->id)->where('api_canal_id', 990001)->count();

    h_check(($r2['accion'] ?? null) === 'ya_ok', 'segunda adopción = ya_ok', 'accion: ' . var_export($r2['accion'] ?? null, true));
    h_check($despues2 === 1, 'sigue habiendo exactamente 1 fila');

    // ── (f) no se emitió ninguna escritura al remoto ──────────────────────────
    h_section('(f) adopción no escribe en el grabador');
    Http::assertNotSent(function ($request) {
        return !in_array(strtoupper($request->method()), ['GET'], true);
    });
    h_ok('solo se emitieron GET al grabador');

    // ── (g) formato_salida nulo resuelto por tipo ─────────────────────────────
    h_section('(g) formato_salida nulo se resuelve por grabadores.tipo');
    h_check($adoptado && $adoptado->formato_salida === '.mp3', 'grabador radio → .mp3', 'formato: ' . var_export($adoptado->formato_salida ?? null, true));

    // ── (h) adopción no bloqueada por cupo ────────────────────────────────────
    h_section('(h) adopción procede aunque el par quede excedido');
    DB::table('grabador_usuario')
        ->where('grabador_id', $grabador->id)
        ->where('user_id', $usuario->id)
        ->update(['limite_canales' => 2, 'updated_at' => now()]);

    $r3 = $servicio->adoptar($grabador, $usuario->id, 990002);
    h_check(($r3['ok'] ?? false) === true, 'adopción con par excedido procede (no se bloquea por cupo)');

    $cupo = app(App\Services\GrabacionesPuntuales\CupoCanalesService::class);
    $estado = $cupo->resumen($grabador->id, $usuario->id);
    h_check($estado !== null && $estado['usados'] > $estado['limite'], 'el par queda excedido y visible (usados > limite)');

    // ── (i) clasificación directa de casos límite ─────────────────────────────
    h_section('(i) clasificar() en casos límite');
    $pares = DB::table('grabador_usuario')->where('grabador_id', $grabador->id)->get();
    $locales = Canal::where('grabador_id', $grabador->id)->get();

    $igualABase = $servicio->clasificar([
        'id' => 990010,
        'codigo' => 'X',
        'nombre' => 'X',
        'ruta_descarga' => $baseRemota,
    ], $pares, $locales);
    h_check($igualABase === null, 'ruta == ruta_base no es un slot → null');

    $caso = $servicio->clasificar([
        'id' => 990011,
        'codigo' => 'Solicitudes_09',
        'nombre' => 'Solicitudes_09',
        'ruta_descarga' => $baseRemota . '/Solicitudes_09',
    ], $pares, $locales);
    h_check(($caso['confianza'] ?? null) === 'adoptable', 'Solicitudes_09 (misma familia) → adoptable');

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
